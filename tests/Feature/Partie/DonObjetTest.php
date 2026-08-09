<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\ForgeAmelioration;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ForgeAmeliorationSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Don d'objets entre héros du groupe, au hub (doc 01 §7).
 *
 * Comble le dernier trou d'inventaire du projet : rien ne permettait de
 * transmettre une pièce à un allié — un artefact tombé au mauvais héros restait
 * inutilisable à vie.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, ForgeAmeliorationSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Ligne d'inventaire d'un héros (objet du catalogue, par nom). */
function donnerAuSac(int $personnageId, string $nomObjet, string $emplacement = 'sac', int $quantite = 1): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $personnageId,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => $quantite,
    ]);
}

/**
 * Groupe au hub avec deux joueurs, un héros chacun.
 *
 * @return array{0: JoueurAuthentifiable, 1: Groupe, 2: Personnage, 3: Personnage, 4: JoueurAuthentifiable}
 */
function groupeAuHub(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'barbare']);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2, ['classe' => 'magicien']);

    $groupe->update(['phase' => 'hub']);

    return [$alice, $groupe, $albrecht, $brunhilde, $bob];
}

it('transmet un objet du sac au héros d\'un AUTRE joueur', function () {
    [, , $albrecht, $brunhilde] = groupeAuHub();

    $ligne = donnerAuSac($albrecht->id, 'Épée courte');

    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $ligne->id,
        'vers_personnage_id' => $brunhilde->id,
    ])->assertOk()
        ->assertJsonPath('don.objet', 'Épée courte')
        ->assertJsonPath('don.vers.nom', 'Brunhilde');

    $apres = $ligne->fresh();
    expect((int) $apres->personnage_id)->toBe($brunhilde->id)
        ->and($apres->emplacement)->toBe('sac'); // jamais équipé d'office
});

it('DÉPLACE la ligne pour préserver les améliorations de Forge', function () {
    [, , $albrecht, $brunhilde] = groupeAuHub();

    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();
    $ligne = donnerAuSac($albrecht->id, 'Épée courte');
    $ligne->update(['ameliorations' => [['id' => $affutee->id, 'nom' => 'Affûtée', 'effet' => $affutee->effet]]]);

    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $ligne->id,
        'vers_personnage_id' => $brunhilde->id,
    ])->assertOk();

    // Recréer la ligne au lieu de la déplacer perdrait l'amélioration en
    // silence : le receveur hériterait d'une épée ordinaire.
    $apres = $ligne->fresh();
    expect((int) $apres->personnage_id)->toBe($brunhilde->id)
        ->and($apres->ameliorations)->toHaveCount(1)
        ->and($apres->ameliorations[0]['nom'])->toBe('Affûtée')
        // Et une seule ligne au total — pas de duplication.
        ->and(Inventaire::where('objet_id', $ligne->objet_id)->count())->toBe(1);
});

it('EMPILE les consommables chez le receveur et décrémente la pile du donneur', function () {
    [, , $albrecht, $brunhilde] = groupeAuHub();

    $potion = Objet::where('nom', 'Potion de soin')->firstOrFail();
    $source = donnerAuSac($albrecht->id, 'Potion de soin', 'consommable', 3);
    donnerAuSac($brunhilde->id, 'Potion de soin', 'consommable', 1);

    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $source->id,
        'vers_personnage_id' => $brunhilde->id,
        'quantite' => 2,
    ])->assertOk()->assertJsonPath('don.quantite', 2);

    expect((int) $source->fresh()->quantite)->toBe(1);

    $chezElle = Inventaire::where('personnage_id', $brunhilde->id)->where('objet_id', $potion->id)->get();
    expect($chezElle)->toHaveCount(1)                       // empilé, pas dupliqué
        ->and((int) $chezElle->first()->quantite)->toBe(3); // 1 + 2
});

it('supprime la ligne du donneur quand il donne toute sa pile', function () {
    [, , $albrecht, $brunhilde] = groupeAuHub();

    $source = donnerAuSac($albrecht->id, 'Potion de soin', 'consommable', 2);

    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $source->id,
        'vers_personnage_id' => $brunhilde->id,
        'quantite' => 2,
    ])->assertOk();

    expect(Inventaire::whereKey($source->id)->exists())->toBeFalse()
        ->and(Inventaire::where('personnage_id', $brunhilde->id)->sum('quantite'))->toBe(2);
});

it('fait circuler un ARTEFACT : l\'arme unique appartient au groupe', function () {
    [, , $albrecht, $brunhilde] = groupeAuHub();

    // Trouvé par Brunhilde, il revient au barbare qui saura s'en servir.
    $ligne = donnerAuSac($brunhilde->id, 'Hache du Roi sous la Montagne');

    test()->actingAs(JoueurAuthentifiable::where('identifiant', 'bob')->firstOrFail(), 'joueur')
        ->postJson('/api/groupes/table-1/dons', [
            'personnage_id' => $brunhilde->id,
            'inventaire_id' => $ligne->id,
            'vers_personnage_id' => $albrecht->id,
        ])->assertOk();

    expect((int) $ligne->fresh()->personnage_id)->toBe($albrecht->id);
});

it('refuse si le sac du receveur est plein', function () {
    [, , $albrecht, $brunhilde] = groupeAuHub();

    $babiole = Objet::where('categorie', '!=', 'consommable')->where('rarete', 'commun')->firstOrFail();
    for ($i = 0; $i < 12; $i++) {
        Inventaire::create([
            'personnage_id' => $brunhilde->id, 'objet_id' => $babiole->id,
            'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }

    $ligne = donnerAuSac($albrecht->id, 'Épée courte');

    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $ligne->id,
        'vers_personnage_id' => $brunhilde->id,
    ])->assertStatus(422)->assertJsonValidationErrors('vers_personnage_id');

    expect((int) $ligne->fresh()->personnage_id)->toBe($albrecht->id);
});

it('laisse un donneur EN DÉPASSEMENT se délester — c\'est la façon de régulariser', function () {
    [, , $albrecht, $brunhilde] = groupeAuHub();

    // Sac au-delà de la capacité (butin de quête remis en dépassement).
    $babiole = Objet::where('categorie', '!=', 'consommable')->where('rarete', 'commun')->firstOrFail();
    for ($i = 0; $i < 12; $i++) {
        Inventaire::create([
            'personnage_id' => $albrecht->id, 'objet_id' => $babiole->id,
            'emplacement' => 'sac', 'quantite' => 1,
        ]);
    }
    $ligne = donnerAuSac($albrecht->id, 'Épée courte');

    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $ligne->id,
        'vers_personnage_id' => $brunhilde->id,
    ])->assertOk();

    expect((int) $ligne->fresh()->personnage_id)->toBe($brunhilde->id);
});

it('refuse de donner une pièce ÉQUIPÉE sans la déséquiper d\'abord', function () {
    [, , $albrecht, $brunhilde] = groupeAuHub();

    $ligne = donnerAuSac($albrecht->id, 'Épée courte', 'arme_principale');

    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $ligne->id,
        'vers_personnage_id' => $brunhilde->id,
    ])->assertStatus(422)->assertJsonValidationErrors('inventaire_id');

    expect((int) $ligne->fresh()->personnage_id)->toBe($albrecht->id);
});

it('refuse hors du hub, depuis le héros d\'autrui, ou vers un non-membre', function () {
    [$alice, $groupe, $albrecht, $brunhilde] = groupeAuHub();

    $ligne = donnerAuSac($albrecht->id, 'Épée courte');
    $aLui = donnerAuSac($brunhilde->id, 'Dague');

    // Depuis un héros qui n'est pas le sien : alice ne dispose pas du sac de Brunhilde.
    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $brunhilde->id,
        'inventaire_id' => $aLui->id,
        'vers_personnage_id' => $albrecht->id,
    ])->assertStatus(422)->assertJsonValidationErrors('personnage_id');

    // Vers un personnage hors du groupe.
    $etranger = creerHeros($alice, creerGroupe('table-2'), 'Solitaire', 1);
    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $ligne->id,
        'vers_personnage_id' => $etranger->id,
    ])->assertStatus(422)->assertJsonValidationErrors('vers_personnage_id');

    // Vers soi-même.
    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $ligne->id,
        'vers_personnage_id' => $albrecht->id,
    ])->assertStatus(422);

    // En pleine quête : le partage est une affaire de hub.
    $groupe->update(['phase' => 'quete']);
    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $ligne->id,
        'vers_personnage_id' => $brunhilde->id,
    ])->assertStatus(422)->assertJsonValidationErrors('phase');

    expect((int) $ligne->fresh()->personnage_id)->toBe($albrecht->id);
});

it('refuse une quantité supérieure à la pile', function () {
    [, , $albrecht, $brunhilde] = groupeAuHub();

    $source = donnerAuSac($albrecht->id, 'Potion de soin', 'consommable', 2);

    $this->postJson('/api/groupes/table-1/dons', [
        'personnage_id' => $albrecht->id,
        'inventaire_id' => $source->id,
        'vers_personnage_id' => $brunhilde->id,
        'quantite' => 5,
    ])->assertStatus(422)->assertJsonValidationErrors('quantite');

    expect((int) $source->fresh()->quantite)->toBe(2);
});
