<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Spell Scroll — Warmth (Frozen Horror), PORTÉE le 2026-09-06 (phase 5 du
 * plan glace, `docs/plan-glace-et-degats-mind.md`) : « This spell restores up
 * to 3 Body Points to the spellcaster or any one hero of their choice. »
 *
 * Sort « Chaleur » (SortSeeder, `element: parchemin` — comme Trésor sans
 * Péril/Récupération Psychique/Éclair : aucune école, jamais dans le grimoire
 * d'un magicien/elfe). Zéro mécanique neuve : `soin_pv_body`, déjà lu par
 * ResolveurTour pour Eau de Guérison/Soin du Corps. Le parchemin lui-même est
 * un sous-produit automatique de `ObjetSeeder` (une ligne « Parchemin : X »
 * par sort semé) — rien à ajouter côté objets.
 *
 * Ces tests jouent la VRAIE route /choix (`lire_parchemin`, doc 09-01 :
 * action séparée d'un sort connu, un parchemin se consomme dans tous les cas)
 * pour prouver que la carte fait EN JEU ce que son texte dit — pas seulement
 * qu'elle existe au catalogue.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, // les parchemins dérivent des sorts
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

function optionsMenuWarmth(Groupe $groupe, JoueurAuthentifiable $joueur, Personnage $hero)
{
    GenererMenu::dispatchSync($groupe->id, (int) $joueur->id, (int) $hero->id);

    return collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $joueur->id))['menu']['options']);
}

it('seed la Chaleur : élément parchemin (aucune école), soin fixe de 3, cible héros', function () {
    $sort = Sort::where('nom', 'Chaleur')->firstOrFail();

    expect($sort->element)->toBe('parchemin')
        ->and($sort->type)->toBe('utilitaire')
        ->and($sort->difficulte_parchemin)->toBeGreaterThanOrEqual(1)
        ->and($sort->effet['cible'])->toBe('heros')
        ->and($sort->effet['soin_pv_body'])->toBe(3);
});

it('dérive automatiquement un « Parchemin : Chaleur » du catalogue (sous-produit d\'ObjetSeeder)', function () {
    $objet = Objet::where('nom', 'Parchemin : Chaleur')->firstOrFail();

    expect($objet->categorie)->toBe('parchemin')
        ->and($objet->effet['sort_nom'])->toBe('Chaleur')
        ->and($objet->effet['sort_id'])->toBe(Sort::where('nom', 'Chaleur')->value('id'));
});

it('soigne jusqu\'à 3 PV de Body plafonnés au maximum — un NON-lanceur qui lit le parchemin sur un allié', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $barbare = creerHeros($alice, $groupe, 'Albrecht', 1); // Mind 2, non-lanceur (S1)

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2); // pv_body_max 8 par défaut

    $ligne = Inventaire::create([
        'personnage_id' => $barbare->id,
        'objet_id' => Objet::where('nom', 'Parchemin : Chaleur')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // ⚠ APRÈS le démarrage : DemarreurQuete remet tous les héros actifs à
    // PLEIN PV (P2, doc 01 §13) — réduire avant serait effacé au lancement.
    $brunhilde->update(['pv_body' => 6]); // max 8 → un soin de 3 est plafonné à +2

    // Jet de Mind (difficulté 2, attribut_mind 2 → 2 dés) : deux crânes, succès net.
    desFiges(array_fill(0, 10, 1));

    optionsMenuWarmth($groupe, $alice, $barbare);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => "parchemin:{$ligne->id}", 'cible_id' => $brunhilde->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.type', 'parchemin')
        ->assertJsonPath('resultat.lanceur_de_sorts', false)
        ->assertJsonPath('resultat.jet.issue', 'reussite')
        ->assertJsonPath('resultat.consomme', true)
        ->assertJsonPath('resultat.gaspille', false)
        ->assertJsonPath('resultat.soin', 2)
        ->assertJsonPath('resultat.pv_body_apres', 8);

    expect($brunhilde->fresh()->pv_body)->toBe(8)
        ->and(Inventaire::find($ligne->id))->toBeNull(); // consommé, comme tout parchemin (S1)
});

it('soigne le LANCEUR lui-même (« au lanceur ou à un héros de son choix ») — réussite automatique, aucun jet', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $mage = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']); // lanceur (S1)

    $ligne = Inventaire::create([
        'personnage_id' => $mage->id,
        'objet_id' => Objet::where('nom', 'Parchemin : Chaleur')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // ⚠ APRÈS le démarrage — voir le commentaire du test précédent.
    $mage->update(['pv_body' => 5]); // max 8 → le soin de 3 tient tout entier

    optionsMenuWarmth($groupe, $alice, $mage);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => "parchemin:{$ligne->id}", 'cible_id' => $mage->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.lanceur_de_sorts', true) // magicien : succès d'office (S1)
        ->assertJsonMissingPath('resultat.jet')
        ->assertJsonPath('resultat.gaspille', false)
        ->assertJsonPath('resultat.soin', 3)
        ->assertJsonPath('resultat.pv_body_apres', 8);

    expect($mage->fresh()->pv_body)->toBe(8)
        ->and(Inventaire::find($ligne->id))->toBeNull();
});
