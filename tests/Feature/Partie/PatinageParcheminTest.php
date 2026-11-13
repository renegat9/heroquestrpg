<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\MoteurSorts;
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
use App\Jobs\GenererMenu;

/*
 * SPELL SCROLL — SKATE (Frozen Horror, Artifact Reference p. 35 — `reference/18_extensions.md` §3), PORTÉ le 2026-11-12 :
 * « This spell enables the spellcaster to move quickly through icy caverns and corridors. The spellcaster adds 6 to
 * their red dice movement roll and may pass through monsters and heroes during movement. The spell lasts only one turn. »
 *
 * Patinage (SortSeeder, `element: parchemin`, `cible: soi`) porte TROIS clés, toutes lues par des lecteurs existants :
 *  - `bonus_deplacement: 6` → `MoteurSorts::bonusDes()`, lu au point de passage unique de l'allonge du tour
 *    (`ResolveurTour::pointsDeplacement()` et son miroir `MenuMoteur::porteeDuTour()`, d'où viennent les destinations) ;
 *  - `franchit_figures: true` → `MoteurSorts::franchitFigures()`, la même traversée que Voile de Brume ;
 *  - `duree: ce_tour` → expire à « Terminer le tour » (`ResolveurTour`, `expirerBuffs(CE_TOUR)`).
 *
 * Le +6 entre dans la RÉSERVE du tour au premier pas : un parchemin lu après ce premier pas ne donnerait aucune case, et
 * serait détruit pour rien. D'où la garde de `MoteurSorts::options()` — il n'est pas offert une fois la réserve ouverte.
 *
 * Ces tests jouent les VRAIES routes (`/choix`, `/menu`) sur une carte écrite à la main : un couloir d'une case de large,
 * fermé, où un gobelin bloque le passage. Sans Patinage, rien au-delà du gobelin ; avec, les cases jusqu'à dix pas.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, // le parchemin dérive du sort
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Un couloir de 14 cases (ligne 1), murs au-dessus et au-dessous, un Gobelin révélé en (2,1) qui le bloque. Le héros part
 * de (0,1) avec un jet de déplacement FIXÉ à 4 : la portée est donc 4 sans Patinage, 10 avec.
 *
 * @return array{alice: \App\Auth\JoueurAuthentifiable, groupe: \App\Models\Groupe, heros: Personnage, quete: Quete, etat: EtatPersonnageQuete}
 */
function couloirPatinage(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1); // barbare : non-lanceur, Mind 2

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();

    $largeur = 14;
    $cases = [
        array_fill(0, $largeur, 'm'),
        array_fill(0, $largeur, 's'),
        array_fill(0, $largeur, 'm'),
    ];

    $quete->carte->update([
        'largeur' => $largeur,
        'hauteur' => 3,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => 3,
            'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [], 'pieges' => [], 'mobilier' => [], 'terrain' => [], 'leviers' => [], 'epreuves' => [],
            'spawn_heros' => [['x' => 0, 'y' => 1]], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);
    $quete->update(['salles_decouvertes' => [0]]);

    // Une seule figure, révélée, au milieu du couloir : les autres monstres de la quête sont mis hors jeu.
    $quete->instancesMonstres()->update(['etat' => 'vaincu', 'revele' => true]);
    $gobelin = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $gobelin->update([
        'monstre_id' => App\Models\Monstre::where('nom_base', 'Gobelin')->value('id'),
        'etat' => 'actif', 'revele' => true, 'position_x' => 2, 'position_y' => 1,
    ]);

    $etat->update([
        'position_x' => 0, 'position_y' => 1,
        'deplacement_tour' => 4, 'deplacement_restant' => null,
        'a_deplace' => false, 'a_joue' => false, 'a_agi' => false, 'tombe' => false,
        'detail_deplacement_tour' => null,
    ]);

    Cache::forget(GenererMenu::cleMenu($groupe->id, $alice->id));

    return compact('alice', 'groupe', 'heros', 'quete', 'etat');
}

/** Donne le parchemin de Patinage au sac du héros. */
function donnerPatinage(Personnage $heros): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Parchemin : Patinage')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);
}

/** Le menu RÉEL du héros (pas un reconstruit). @return list<array<string, mixed>> */
function menuPatinage(\App\Auth\JoueurAuthentifiable $alice): array
{
    Cache::forget(GenererMenu::cleMenu(\App\Models\Groupe::where('identifiant', 'table-1')->value('id'), $alice->id));

    return test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options');
}

/** Les cases que PUBLIE le menu pour « Se déplacer ». @return list<array{x: int, y: int, cout: int}> */
function destinationsPatinage(\App\Auth\JoueurAuthentifiable $alice): array
{
    $option = collect(menuPatinage($alice))->firstWhere('id', 'se_deplacer');

    expect($option)->not->toBeNull('« Se déplacer » devrait être offert');

    return $option['parametres']['destinations'];
}

// ==================================================================
// LE CATALOGUE
// ==================================================================

it('seed Patinage : parchemin (aucune école), cible soi, +6 au déplacement, traversée des figures, ce tour', function () {
    $sort = Sort::where('nom', 'Patinage')->firstOrFail();

    expect($sort->element)->toBe('parchemin')
        ->and($sort->type)->toBe('utilitaire')
        ->and($sort->effet['cible'])->toBe('soi')
        ->and($sort->effet['bonus_deplacement'])->toBe(6)
        ->and($sort->effet['franchit_figures'])->toBeTrue()
        ->and($sort->effet['duree'])->toBe('ce_tour');
});

it('dérive un « Parchemin : Patinage » au sac, comme tout parchemin', function () {
    $objet = Objet::where('nom', 'Parchemin : Patinage')->firstOrFail();

    expect($objet->categorie)->toBe('parchemin')
        ->and($objet->effet['sort_nom'])->toBe('Patinage')
        ->and($objet->effet['sort_id'])->toBe(Sort::where('nom', 'Patinage')->value('id'));
});

// ==================================================================
// EN JEU — le menu et le résolveur
// ==================================================================

it('sans parchemin, le gobelin bloque le couloir : aucune case au-delà de lui, portée 4 oblige', function () {
    $ctx = couloirPatinage();

    $cases = collect(destinationsPatinage($ctx['alice']));

    expect($cases->pluck('x')->max())->toBe(1, 'le gobelin en (2,1) ferme le couloir : seule la case (1,1) est atteignable')
        ->and($cases->contains(fn ($c) => $c['x'] >= 3))->toBeFalse();
});

it('lu avant le premier pas, Patinage porte l\'allonge à 10 et traverse le gobelin : des cases au-delà de lui', function () {
    $ctx = couloirPatinage();
    $ligne = donnerPatinage($ctx['heros']);

    // Jet de Mind (difficulté 2, Mind 2) : deux crânes, succès.
    desFiges(array_fill(0, 10, 1));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => "parchemin:{$ligne->id}"],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.consomme', true)
        ->assertJsonPath('resultat.gaspille', false);

    $cases = collect(destinationsPatinage($ctx['alice']));

    // Portée 4 + 6 = 10 : la case la plus lointaine est à dix pas du départ, et l'on passe la figure.
    expect($cases->pluck('x')->max())->toBe(10)
        ->and($cases->contains(fn ($c) => $c['x'] === 3 && $c['y'] === 1))->toBeTrue('la case juste derrière le gobelin est atteignable')
        ->and($cases->contains(fn ($c) => $c['x'] === 2))->toBeFalse('la case du gobelin n\'est jamais une destination')
        ->and($cases->firstWhere('x', 10)['cout'])->toBe(10);
});

it('le résolveur ACCEPTE la destination au-delà du gobelin, et le héros y arrive sans s\'arrêter sur la figure', function () {
    $ctx = couloirPatinage();
    $ligne = donnerPatinage($ctx['heros']);
    desFiges(array_fill(0, 10, 1));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => "parchemin:{$ligne->id}"],
    ])->assertStatus(202);

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => 10, 'y' => 1],
    ])->assertStatus(202)->assertJsonPath('resultat.vers', ['x' => 10, 'y' => 1]);

    expect([(int) $ctx['etat']->fresh()->position_x, (int) $ctx['etat']->fresh()->position_y])->toBe([10, 1]);
});

it('témoin : sans parchemin, le même trajet vers (10,1) est refusé', function () {
    $ctx = couloirPatinage();

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => 10, 'y' => 1],
    ])->assertStatus(422);
});

it('le parchemin est CONSOMMÉ à la lecture', function () {
    $ctx = couloirPatinage();
    $ligne = donnerPatinage($ctx['heros']);
    desFiges(array_fill(0, 10, 1));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => "parchemin:{$ligne->id}"],
    ])->assertStatus(202);

    expect(Inventaire::find($ligne->id))->toBeNull();
});

it('le bonus NE SURVIT PAS au tour : « Terminer le tour » le retire, et le menu suivant ne porte plus 10', function () {
    $ctx = couloirPatinage();
    $ligne = donnerPatinage($ctx['heros']);
    desFiges(array_fill(0, 10, 1));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => "parchemin:{$ligne->id}"],
    ])->assertStatus(202);

    $heros = $ctx['heros']->fresh();
    expect(app(MoteurSorts::class)->bonusDes($heros, 'bonus_deplacement'))->toBe(6, 'le bonus tient pendant le tour')
        ->and(app(MoteurSorts::class)->franchitFigures($heros))->toBeTrue();

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $heros = $ctx['heros']->fresh();
    expect(app(MoteurSorts::class)->bonusDes($heros, 'bonus_deplacement'))->toBe(0, '« ce tour » : expiré à la fin du tour du porteur')
        ->and(app(MoteurSorts::class)->franchitFigures($heros))->toBeFalse();

    // Un nouveau tour : le gobelin a pu bouger pendant la phase des monstres — on le remet en place, le jet est de nouveau 4.
    $ctx['quete']->instancesMonstres()->where('etat', 'actif')->update(['position_x' => 2, 'position_y' => 1]);
    EtatPersonnageQuete::whereKey($ctx['etat']->id)->update([
        'position_x' => 0, 'position_y' => 1, 'deplacement_tour' => 4, 'deplacement_restant' => null,
        'a_deplace' => false, 'a_joue' => false, 'a_agi' => false,
    ]);

    $cases = collect(destinationsPatinage($ctx['alice']));
    expect($cases->pluck('x')->max())->toBe(1, 'sans le parchemin, le gobelin referme le couloir à nouveau');
});

it('après le premier pas, le parchemin n\'est plus offert : sa réserve est déjà ouverte, il ne donnerait rien', function () {
    $ctx = couloirPatinage();
    donnerPatinage($ctx['heros']);

    // Le héros a fait un pas : la réserve du tour est ouverte (restant 3).
    EtatPersonnageQuete::whereKey($ctx['etat']->id)->update(['deplacement_restant' => 3, 'a_deplace' => true]);

    $options = collect(menuPatinage($ctx['alice']));
    $lecture = $options->firstWhere('id', 'lire_parchemin');

    expect($lecture)->toBeNull('aucun parchemin n\'est offert une fois la réserve ouverte — un bouton qui ne donnerait rien');
});

it('le parchemin est offert AVANT le premier pas, comme entrée de « Lire un parchemin »', function () {
    $ctx = couloirPatinage();
    $ligne = donnerPatinage($ctx['heros']);

    $lecture = collect(menuPatinage($ctx['alice']))->firstWhere('id', 'lire_parchemin');

    expect($lecture)->not->toBeNull()
        ->and(collect($lecture['parametres']['parchemins'])->pluck('cle')->all())->toContain("parchemin:{$ligne->id}");
});
