<?php

declare(strict_types=1);

use App\Models\Personnage;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LES DEUX VOIES DE L'ELFE (Mage of the Mirror — décision de René, 2026-08-11).
 *
 * À la création, il prend une **école élémentaire** OU **3 sorts du répertoire
 * elfique**. La différence n'est pas la liste mais l'ENGAGEMENT : l'école est
 * définitive, les 3 sorts elfiques se rechoisissent au hub entre deux quêtes.
 * C'est ce qui donne son prix à une voie qui offre 8 sorts pour 3 emplacements.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, SortSeeder::class, ObjetSeeder::class, CompetenceSeeder::class]);
});

/** Les identifiants des `n` premiers sorts du répertoire elfique. */
function troisSortsElfiques(int $n = 3): array
{
    return Sort::where('element', MoteurSorts::REPERTOIRE_ELFIQUE)
        ->orderBy('id')->limit($n)->pluck('id')->all();
}

it('crée un Elfe sur la VOIE ELFIQUE : trois sorts choisis, aucune école', function () {
    connecterJoueur('alice');
    $choisis = troisSortsElfiques();

    $this->postJson('/api/personnages', [
        'nom' => 'Silfen', 'classe' => 'elfe', 'sorts_elfiques' => $choisis,
    ])->assertCreated();

    $elfe = Personnage::where('nom', 'Silfen')->firstOrFail();
    $sorts = app(MoteurSorts::class);

    expect($elfe->sorts()->pluck('sorts.id')->sort()->values()->all())->toBe($choisis)
        ->and($sorts->elementsConnus($elfe))->toBe([MoteurSorts::REPERTOIRE_ELFIQUE])
        ->and($sorts->aRepertoireElfique($elfe))->toBeTrue();
});

it('crée un Elfe sur une ÉCOLE : ses 3 sorts élémentaires, aucun elfique', function () {
    connecterJoueur('alice');

    $this->postJson('/api/personnages', [
        'nom' => 'Aerin', 'classe' => 'elfe', 'elements' => ['feu'],
    ])->assertCreated();

    $elfe = Personnage::where('nom', 'Aerin')->firstOrFail();

    expect(app(MoteurSorts::class)->elementsConnus($elfe))->toBe(['feu'])
        ->and(app(MoteurSorts::class)->aRepertoireElfique($elfe))->toBeFalse();
});

it('refuse les DEUX voies à la fois — ce serait six sorts au lieu de trois', function () {
    connecterJoueur('alice');

    $this->postJson('/api/personnages', [
        'nom' => 'Gourmand', 'classe' => 'elfe',
        'elements' => ['feu'], 'sorts_elfiques' => troisSortsElfiques(),
    ])->assertStatus(422);
});

it('garde la voie historique quand le client ne dit RIEN', function () {
    connecterJoueur('alice');

    // Anciens clients, seeders, helpers de test : le silence vaut école par
    // défaut (eau). Rien ne doit casser pour ne pas avoir choisi.
    $this->postJson('/api/personnages', ['nom' => 'Muet', 'classe' => 'elfe'])->assertCreated();

    expect(app(MoteurSorts::class)->elementsConnus(Personnage::where('nom', 'Muet')->firstOrFail()))
        ->toBe(['eau']);
});

it('interdit le répertoire elfique à toute autre classe', function () {
    connecterJoueur('alice');

    $this->postJson('/api/personnages', [
        'nom' => 'Krogar', 'classe' => 'barbare', 'sorts_elfiques' => troisSortsElfiques(),
    ])->assertStatus(422);
});

it('exige exactement TROIS sorts, distincts et du répertoire', function () {
    connecterJoueur('alice');

    // Deux au lieu de trois.
    $this->postJson('/api/personnages', [
        'nom' => 'Court', 'classe' => 'elfe', 'sorts_elfiques' => troisSortsElfiques(2),
    ])->assertStatus(422);

    // Trois identifiants valides mais qui ne sont PAS elfiques.
    $elementaires = Sort::whereIn('element', MoteurSorts::ELEMENTS)->orderBy('id')->limit(3)->pluck('id')->all();

    $this->postJson('/api/personnages', [
        'nom' => 'Tricheur', 'classe' => 'elfe', 'sorts_elfiques' => $elementaires,
    ])->assertStatus(422);

    expect(Personnage::where('nom', 'Tricheur')->exists())->toBeFalse();
});

it('RECHOISIT les trois sorts au hub, et remplace les précédents', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();

    $this->postJson('/api/personnages', [
        'nom' => 'Silfen', 'classe' => 'elfe', 'sorts_elfiques' => troisSortsElfiques(),
    ])->assertCreated();

    $elfe = Personnage::where('nom', 'Silfen')->firstOrFail();
    $groupe->personnages()->attach($elfe->id, ['ordre_initiative' => 1, 'actif' => true]);
    $elfe->update(['groupe_actif_id' => $groupe->id]);

    // Trois AUTRES sorts du répertoire (il en compte 8).
    $nouveaux = Sort::where('element', MoteurSorts::REPERTOIRE_ELFIQUE)
        ->whereNotIn('id', troisSortsElfiques())
        ->orderBy('id')->limit(3)->pluck('id')->all();

    $this->putJson('/api/groupes/table-1/sorts-elfiques', [
        'personnage_id' => $elfe->id, 'sorts' => $nouveaux,
    ])->assertOk()->assertJsonCount(3, 'sorts');

    // ⚠ Ce sont bien les nouveaux, et SEULEMENT eux : rechoisir remplace.
    expect($elfe->fresh()->sorts()->pluck('sorts.id')->sort()->values()->all())->toBe($nouveaux);
});

it('refuse le rechoix EN QUÊTE : on n\'échange pas ses sorts dans le donjon', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();

    $this->postJson('/api/personnages', [
        'nom' => 'Silfen', 'classe' => 'elfe', 'sorts_elfiques' => troisSortsElfiques(),
    ])->assertCreated();

    $elfe = Personnage::where('nom', 'Silfen')->firstOrFail();
    $groupe->personnages()->attach($elfe->id, ['ordre_initiative' => 1, 'actif' => true]);
    $groupe->update(['phase' => 'quete']);

    $this->putJson('/api/groupes/table-1/sorts-elfiques', [
        'personnage_id' => $elfe->id, 'sorts' => troisSortsElfiques(),
    ])->assertStatus(422);
});

it('refuse le rechoix à un Elfe parti sur une ÉCOLE — ce choix est définitif', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();

    $this->postJson('/api/personnages', [
        'nom' => 'Aerin', 'classe' => 'elfe', 'elements' => ['feu'],
    ])->assertCreated();

    $elfe = Personnage::where('nom', 'Aerin')->firstOrFail();
    $groupe->personnages()->attach($elfe->id, ['ordre_initiative' => 1, 'actif' => true]);

    // Sans cette garde, la voie élémentaire serait strictement meilleure :
    // même liberté de rechoix, PLUS la progression par l'arbre.
    $this->putJson('/api/groupes/table-1/sorts-elfiques', [
        'personnage_id' => $elfe->id, 'sorts' => troisSortsElfiques(),
    ])->assertStatus(422);

    expect(app(MoteurSorts::class)->elementsConnus($elfe->fresh()))->toBe(['feu']);
});

it('refuse le rechoix pour le héros d\'un autre joueur, et pour un non-Elfe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();

    $this->postJson('/api/personnages', [
        'nom' => 'Silfen', 'classe' => 'elfe', 'sorts_elfiques' => troisSortsElfiques(),
    ])->assertCreated();
    $elfe = Personnage::where('nom', 'Silfen')->firstOrFail();
    $groupe->personnages()->attach($elfe->id, ['ordre_initiative' => 1, 'actif' => true]);

    $barbare = creerHeros($alice, $groupe, 'Krogar', 2);

    // Pas un Elfe.
    $this->putJson('/api/groupes/table-1/sorts-elfiques', [
        'personnage_id' => $barbare->id, 'sorts' => troisSortsElfiques(),
    ])->assertStatus(422);

    // Héros d'un autre joueur.
    $bob = App\Auth\JoueurAuthentifiable::create([
        'pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret',
    ]);
    $this->actingAs($bob, 'joueur')
        ->putJson('/api/groupes/table-1/sorts-elfiques', [
            'personnage_id' => $elfe->id, 'sorts' => troisSortsElfiques(),
        ])->assertStatus(422);
});
