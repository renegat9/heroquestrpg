<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\CapacitesInnees;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Les dernières capacités de carte du ROGUE et du CHEVALIER.
 *
 * *Ambidextrie* passe par le canal de la Potion d'héroïsme (une frappe de plus
 * au-delà du créneau) ; *Défi du chevalier* est le seul déclencheur de réaction
 * du jeu qui ne soit pas un coup encaissé — une bête qui surgit.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/** Met une arme en main principale (les dés d'attaque ne changent pas ici). */
function armerLaMainDe(App\Models\Personnage $heros, string $nom): void
{
    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'arme_principale',
    ]);
}

it('AMBIDEXTRIE offre une frappe de plus après un coup de dague', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'rogue']);
    armerLaMainDe($ctx['heros'], 'Dague');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 40, 4)); // boucliers : la cible survit, on veut le tour d'après

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.ambidextrie', true)
        ->assertJsonPath('resultat.attaque_supplementaire', true);

    // Le créneau d'action est pris, mais la frappe offerte est PROPOSÉE — c'est
    // exactement le trou par lequel la Potion d'héroïsme était injouable.
    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];

    expect(collect($menu['options'])->pluck('id'))->toContain('attaquer');
});

it('AMBIDEXTRIE ne se déclenche pas à la hache, ni deux fois dans le tour', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'rogue']);
    armerLaMainDe($ctx['heros'], 'Hachette'); // ni dague ni épée courte

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 40, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonMissingPath('resultat.ambidextrie');

    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse();

    // « Once per turn » : le compteur du TOUR, pas celui de la quête.
    $capacites = app(CapacitesInnees::class);
    $capacites->consommer($ctx['heros'], $ctx['etatHeros']->fresh(), 'attaque_supplementaire_arme');

    expect($capacites->disponible($ctx['heros'], $ctx['etatHeros']->fresh(), 'attaque_supplementaire_arme'))
        ->toBeFalse()
        ->and($ctx['etatHeros']->fresh()->capacites_utilisees)->toBeNull(); // pas la quête
});

it('DÉFI DU CHEVALIER est proposé quand un errant surgit dans sa salle', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $fouilleur = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $chevalier = creerHeros($bob, $groupe, 'Roland', 2, ['classe' => 'chevalier']);
    Inventaire::create([
        'personnage_id' => $chevalier->id,
        'objet_id' => Objet::where('nom', 'Bouclier')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = App\Models\Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatFouilleur = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $fouilleur->id)->firstOrFail();
    $etatChevalier = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $chevalier->id)->firstOrFail();

    // Les deux héros démarrent dans la salle 0 : c'est bien « la même salle ».
    empilerCarteFouille($quete, ['issue' => 'errant']);
    desFiges(array_fill(0, 40, 4)); // le coup du défi ne doit pas tuer le Chevalier

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('errant');

    // ⚠ La proposition va au CHEVALIER, pas au fouilleur : c'est lui qui décide
    // de prendre la bête sur lui.
    expect($etatFouilleur->fresh()->reaction_en_attente)->toBeNull();

    $attente = $etatChevalier->fresh()->reaction_en_attente;

    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe('defi_errant')
        ->and($attente['instance_id'])->toBe($resultat['monstre']['instance_id']);

    $errant = App\Models\InstanceMonstre::findOrFail($resultat['monstre']['instance_id']);

    $this->actingAs($bob, 'joueur')
        ->postJson('/api/groupes/table-1/reaction', [
            'personnage_id' => $chevalier->id, 'accepte' => true,
        ])->assertOk()->assertJsonPath('reaction.active', true);

    // Placé AU CONTACT du Chevalier, et il a frappé : le prix du défi.
    $errant->refresh();
    $distance = abs((int) $errant->position_x - (int) $etatChevalier->position_x)
        + abs((int) $errant->position_y - (int) $etatChevalier->position_y);

    expect($distance)->toBe(1)
        ->and(app(CapacitesInnees::class)
            ->disponible($chevalier->fresh(), $etatChevalier->fresh(), 'defi_errant'))->toBeFalse();
});

it('ne propose aucun défi au fouilleur lui-même', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $chevalier = creerHeros($alice, $groupe, 'Roland', 1, ['classe' => 'chevalier']);
    Inventaire::create([
        'personnage_id' => $chevalier->id,
        'objet_id' => Objet::where('nom', 'Bouclier')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = App\Models\Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $chevalier->id)->firstOrFail();

    empilerCarteFouille($quete, ['issue' => 'errant']);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])->assertStatus(202);

    // L'errant vient DÉJÀ le chercher : se défier soi-même ne changerait rien
    // et gaspillerait une capacité « once per quest ».
    expect($etat->fresh()->reaction_en_attente)->toBeNull();
});
