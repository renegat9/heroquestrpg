<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Mobilier;
use App\Models\Quete;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\JournalCombat;
use App\Partie\MenuMoteur;
use App\Partie\MoteurMobilier;
use App\Partie\ResolveurTour;
use App\Partie\SceneDeTable;
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
use Illuminate\Support\Facades\Http;

/*
 * MOBILIER ATTAQUABLE AU COMBAT (2026-10-04) — la TROISIÈME voie de
 * destruction du mobilier, après la fouille et le jet de Body
 * (`DestructionMobilierTest`) : un meuble à PV/défense qu'on frappe comme un
 * monstre jusqu'à épuiser ses PV. Sources : Crystal Cluster (*Jungles of
 * Delthrak*, F9907 p. 4-5, « can be destroyed as a monster […] with 6 Body
 * Points […] cannot defend »), Haut Autel et Coffre du Dread (*Wizards of
 * Morcar*, G1504 p. 2-3/35/39, « may be attacked using normal combat »).
 *
 * ⚠ Même parti que `DestructionMobilierTest`/`RepousserTest` : une quête
 * MINIMALE (carte 7×7 tout en sol) plutôt qu'un donjon procédural — on éprouve
 * une mécanique, pas un placement.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/**
 * Quête minimale (7×7 tout en sol), un héros en (3,3), et UNE pièce de
 * mobilier posée en (4,3) — donc orthogonalement au contact.
 *
 * @return array{alice: \App\Auth\JoueurAuthentifiable, groupe: \App\Models\Groupe, heros: \App\Models\Personnage, quete: Quete, etatHeros: EtatPersonnageQuete, type: Mobilier}
 */
function queteAvecMeubleAttaquable(string $nomMeuble, array $herosAttrs = [], array $entreeSup = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);

    $type = Mobilier::where('nom', $nomMeuble)->firstOrFail();

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => 7,
        'hauteur' => 7,
        'grille' => [
            'largeur' => 7, 'hauteur' => 7,
            'cases' => array_fill(0, 7, array_fill(0, 7, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'epreuves' => [],
            'mobilier' => [[
                'mobilier_id' => $type->id,
                'x' => 4, 'y' => 3, 'l' => 1, 'h' => 1, 'salle' => 0,
                ...$entreeSup,
            ]],
            'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => 3, 'position_y' => 3,
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete->fresh()->load('carte'), 'etatHeros' => $etatHeros, 'type' => $type];
}

/** Les options du dernier menu généré pour ce héros. */
function optionsMenuMobilierAttaquable(array $ctx): array
{
    $methode = new ReflectionMethod(MenuMoteur::class, 'generer');
    $menu = $methode->invoke(app(MenuMoteur::class), $ctx['groupe']->fresh(), $ctx['heros']->fresh());

    return $menu['options'] ?? [];
}

/** Attaque le meuble d'index 0 avec des dés figés (attaque PUIS défense, dans cet ordre), et rend le payload. */
function attaquerMeuble(array $ctx, array $des): array
{
    desFiges($des);

    $option = [
        'id' => 'attaquer_mobilier_0',
        'libelle' => 'Attaquer',
        'type' => 'attaquer_mobilier',
        'parametres' => ['mobilier' => 0, 'nom' => $ctx['type']->nom],
    ];

    // ⚠ `app()` APRÈS `desFiges()` : le lanceur est injecté à la construction.
    return (new ReflectionMethod(ResolveurTour::class, 'resoudreAttaqueMobilier'))->invoke(
        app(ResolveurTour::class),
        $ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh(),
        $option, ['type' => 'personnage', 'id' => $ctx['heros']->id, 'nom' => $ctx['heros']->nom],
    );
}

// =====================================================================
// LE MENU — « Attaquer », jamais « Fracasser », pour ces meubles-là
// =====================================================================

it('propose « Attaquer » pour un meuble à PV/défense, et jamais « Fracasser »', function () {
    $ctx = queteAvecMeubleAttaquable('Amas de cristal');

    expect($ctx['type']->pv_body)->toBe(6)
        ->and($ctx['type']->defense_dice)->toBe(0)
        // Aucune source ne décrit un jet de Body pour ce meuble : la voie
        // `difficulte_destruction` reste fermée.
        ->and($ctx['type']->difficulte_destruction)->toBeNull();

    $options = optionsMenuMobilierAttaquable($ctx);
    $ids = collect($options)->pluck('id')->all();

    expect($ids)->toContain('attaquer_mobilier_0')
        ->and($ids)->not->toContain('detruire_mobilier_0');

    $option = collect($options)->firstWhere('id', 'attaquer_mobilier_0');
    expect($option['type'])->toBe('attaquer_mobilier')
        ->and($option['creneau'])->toBe('action')
        ->and($option['libelle'])->toContain('6 PV');
});

it('un meuble ORDINAIRE (Table) ne propose ni « Attaquer » ni « Fracasser » au-delà de ce qu\'il porte', function () {
    $ctx = queteAvecMeubleAttaquable('Table');

    expect($ctx['type']->pv_body)->toBeNull();

    $ids = collect(optionsMenuMobilierAttaquable($ctx))->pluck('id')->all();

    expect($ids)->not->toContain('attaquer_mobilier_0');
});

// =====================================================================
// LE COMBAT — un coup qui ne suffit pas laisse le meuble ENTAMÉ
// =====================================================================

it('un coup qui ne suffit pas réduit les PV sans détruire, et reste attaquable SANS LIMITE', function () {
    // Albrecht (barbare, des_attaque 3) contre l'Amas de cristal (6 PV, 0 dé
    // de défense) : 3 crânes sur 3 dés d'attaque, aucun dé de défense à lancer.
    $ctx = queteAvecMeubleAttaquable('Amas de cristal');

    $payload = attaquerMeuble($ctx, [1, 1, 1]);

    expect($payload['degats'])->toBe(3)
        ->and($payload['pv_body_avant'])->toBe(6)
        ->and($payload['pv_body_apres'])->toBe(3)
        ->and($payload['detruit'])->toBeFalse();

    // ⚠ PAS de garde « une tentative par héros » : contrairement à
    // `detruire_mobilier_*`, l'option reste offerte AU MÊME héros après un
    // coup qui n'a pas suffi — un monstre qu'on frappe ne se lasse pas d'être
    // frappé.
    $ids = collect(optionsMenuMobilierAttaquable($ctx))->pluck('id')->all();
    expect($ids)->toContain('attaquer_mobilier_0');

    // Et la carte le dit : la pièce bloque TOUJOURS la vue (6 PV restants > 0).
    $grille = FabriqueGrille::pour($ctx['quete']->fresh()->load('carte'));
    expect($grille->ligneDeVue(3, 3, 5, 3))->toBeFalse();

    // Un second coup achève le meuble — PV courants repartis de 3, pas de 6.
    $second = attaquerMeuble($ctx, [1, 1, 1]);
    expect($second['pv_body_avant'])->toBe(3)
        ->and($second['pv_body_apres'])->toBe(0)
        ->and($second['detruit'])->toBeTrue();
});

it('détruit, le meuble cesse de bloquer mouvement ET vue, et disparaît de la carte publiée', function () {
    // Un héros surpuissant (des_attaque 6) one-shot l'Amas de cristal (6 PV).
    $ctx = queteAvecMeubleAttaquable('Amas de cristal', ['des_attaque' => 6]);

    $avant = FabriqueGrille::pour($ctx['quete']);
    expect($avant->estTraversable(4, 3))->toBeFalse()
        ->and($avant->ligneDeVue(3, 3, 5, 3))->toBeFalse();

    $payload = attaquerMeuble($ctx, array_fill(0, 6, 1));

    expect($payload['detruit'] ?? false)->toBeTrue()
        ->and($payload['pv_body_apres'])->toBe(0);

    $apres = FabriqueGrille::pour($ctx['quete']->fresh()->load('carte'));
    expect($apres->estTraversable(4, 3))->toBeTrue()
        ->and($apres->ligneDeVue(3, 3, 5, 3))->toBeTrue();

    $table = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    expect(collect($table['carte']['mobilier'])->pluck('nom'))->not->toContain('Amas de cristal');
});

// =====================================================================
// LA DÉFENSE — boucliers BLANCS, pas noirs (G1504 p. 10)
// =====================================================================

it('le meuble défend avec les boucliers BLANCS, jamais les noirs', function () {
    // Haut Autel : 6 PV, 4 dés de défense (G1504 p. 39). 3 dés d'attaque
    // (crânes), 4 dés de défense tous à bouclier NOIR (valeur 6) : un
    // défenseur de type Monstre les compterait (4 boucliers, 0 dégât) ; un
    // défenseur de type Héros — ce que ce meuble doit être — ne compte AUCUN
    // bouclier noir, donc les 3 dégâts passent intégralement.
    $ctx = queteAvecMeubleAttaquable('Haut Autel');

    expect($ctx['type']->defense_dice)->toBe(4);

    $payload = attaquerMeuble($ctx, [1, 1, 1, 6, 6, 6, 6]);

    expect($payload['touches'])->toBe(3)
        ->and($payload['boucliers'])->toBe(0)
        ->and($payload['degats'])->toBe(3)
        ->and($payload['pv_body_apres'])->toBe(3);
});

it('à l\'inverse, des boucliers BLANCS parent bien — même dés, couleur changée', function () {
    $ctx = queteAvecMeubleAttaquable('Haut Autel');

    // Mêmes 3 crânes, mais les 4 dés de défense tombent sur bouclier BLANC
    // (valeur 4 ou 5) cette fois : les 4 boucliers annulent largement les
    // 3 crânes (dégâts = max(0, 3 − 4) = 0).
    $payload = attaquerMeuble($ctx, [1, 1, 1, 4, 4, 4, 4]);

    expect($payload['touches'])->toBe(3)
        ->and($payload['boucliers'])->toBe(4)
        ->and($payload['degats'])->toBe(0)
        ->and($payload['pv_body_apres'])->toBe(6)
        ->and($payload['detruit'])->toBeFalse();
});

// =====================================================================
// LE PAYLOAD — EtatGroupe publie la DÉCISION, jamais à recalculer
// =====================================================================

it('EtatGroupe publie pv_body/defense_dice/pv_restants pour un meuble attaquable', function () {
    $ctx = queteAvecMeubleAttaquable('Coffre du Dread');
    $table = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    $meuble = collect($table['carte']['mobilier'])->firstWhere('nom', 'Coffre du Dread');

    expect($meuble['pv_body'])->toBe(1)
        ->and($meuble['defense_dice'])->toBe(6)
        ->and($meuble['pv_restants'])->toBe(1);
});

it('et publie null pour un meuble ORDINAIRE, qui ne porte pas de barre de vie', function () {
    $ctx = queteAvecMeubleAttaquable('Table');
    $table = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    $meuble = collect($table['carte']['mobilier'])->firstWhere('nom', 'Table');

    expect($meuble['pv_body'])->toBeNull()
        ->and($meuble['defense_dice'])->toBeNull()
        ->and($meuble['pv_restants'])->toBeNull();
});

it('pv_restants reflète les coups déjà portés, publié par le serveur', function () {
    $ctx = queteAvecMeubleAttaquable('Coffre du Dread'); // 1 PV, 6 dés de défense

    // Raté : 0 crâne sur les 3 dés d'attaque (barbare). Le Coffre du Dread
    // tient encore (1 PV) — les 6 dés de défense ne sont même pas déterminants
    // puisqu'il n'y a aucune touche à parer, mais `Combat` les lance quand
    // même (même règle qu'un combat ordinaire).
    attaquerMeuble($ctx, [4, 4, 4, 4, 4, 4, 4, 4, 4]);

    $table = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());
    $meuble = collect($table['carte']['mobilier'])->firstWhere('nom', 'Coffre du Dread');
    expect($meuble['pv_restants'])->toBe(1);
});

// =====================================================================
// ANNONCÉ — journal ET scène de table, jamais muet
// =====================================================================

it('la destruction au combat est ANNONCÉE au journal et sur la scène de table', function () {
    $ctx = queteAvecMeubleAttaquable('Amas de cristal', ['des_attaque' => 6]);

    $payload = attaquerMeuble($ctx, array_fill(0, 6, 1));

    $lignes = app(JournalCombat::class)->depuisResultat($payload, $ctx['heros']->nom);
    expect($lignes)->not->toBe([])
        ->and(collect($lignes)->pluck('ton'))->toContain('mort');

    $scenes = app(SceneDeTable::class)->depuisResultat($payload, $ctx['heros']->fresh());
    expect($scenes)->not->toBe([]);
    $scene = collect($scenes)->first();
    expect($scene['genre'])->toBe('attaque')
        ->and(collect($scene['acteurs'])->pluck('role'))->toContain('defenseur');
});

it('un coup qui entame SEULEMENT le meuble est aussi annoncé, pas seulement sa destruction', function () {
    $ctx = queteAvecMeubleAttaquable('Amas de cristal');

    $payload = attaquerMeuble($ctx, [1, 1, 1]); // 3 dégâts sur 6 PV, pas détruit

    $lignes = app(JournalCombat::class)->depuisResultat($payload, $ctx['heros']->nom);
    expect($lignes)->not->toBe([])
        ->and(collect($lignes)->pluck('ton'))->toContain('degats');
});
