<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\FabriqueGrille;
use App\Partie\ResolveurTour;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Validation\ValidationException;

/*
 * RIVIÈRE GELÉE (Icy River, doc 18 §4) — dernier poste du plan glace
 * (docs/plan-glace-et-degats-mind.md §2 et §3 phase 4) : « coûte 2 cases de
 * déplacement par case ». `Grille::coutDeplacement()` existait déjà (posé par
 * un agent précédent, `TerrainCarteTest`) mais n'était consommé par AUCUNE
 * BFS — ce fichier prouve le branchement, sur un parcours PONDÉRÉ (Dijkstra),
 * des cinq points du plan :
 *  1. traverser 3 cases de rivière coûte 6 points, pas 3 ;
 *  2. un héros à 1 point restant devant une case à 2 NE PEUT PAS y entrer, et
 *     la case n'est pas offerte ;
 *  3. la PORTÉE n'est pas affectée : distance() reste GÉOMÉTRIQUE — le cas qui
 *     ne se verrait qu'en partie, un tir qui rate sans raison ;
 *  4. l'ADJACENCE n'est pas affectée (corps-à-corps par-dessus une case chère) ;
 *  5. le chemin choisi CONTOURNE la rivière quand le détour est moins cher —
 *     la preuve que c'est du Dijkstra, pas une BFS avec pénalité après coup.
 *
 * ⚠ Fonctions LOCALES à ce fichier, pas importées de `TerrainCarteTest` /
 * `TerrainEnJeuTest` : une exécution ciblée (`./vendor/bin/pest
 * tests/Feature/Partie/RiviereGeleeTest.php`) ne charge QUE ce fichier, et les
 * fonctions top-level d'un autre test n'existeraient alors pas. Même patron
 * que ces deux fichiers (chacun a le sien), noms distincts pour ne rien
 * redéclarer quand la suite COMPLÈTE charge tout.
 */

beforeEach(function () {
    $this->seed([GabaritQueteSeeder::class, TerrainSeeder::class]);
});

function idRiviereGelee(): int
{
    return (int) Terrain::where('nom', 'Rivière gelée')->value('id');
}

/**
 * Quête + carte construites À LA MAIN (pas de génération procédurale), avec
 * une couche `terrain` explicite — patron de `TerrainCarteTest::queteAvecCarteEtTerrain()`.
 *
 * @param  list<list<string>>  $cases  m = mur, s = sol
 * @param  list<array{x: int, y: int, terrain_id: int}>  $terrain
 */
function queteAvecRiviereGelee(array $cases, array $terrain): Quete
{
    $groupe = Groupe::create([
        'identifiant' => 'table-riviere-'.uniqid(),
        'nom' => 'Les Lames du Crépuscule',
        'theme' => 'Cryptes maudites sous la cité',
        'longueur' => 'courte',
        'nb_quetes_total' => 3,
        'phase' => 'hub',
    ]);
    $gabarit = GabaritQuete::query()->where('type_jalon', 'normale')->firstOrFail();

    $largeur = count($cases[0] ?? []);
    $hauteur = count($cases);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — Rivière gelée',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => $hauteur,
            'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [],
            'leviers' => [], 'pieges' => [], 'mobilier' => [], 'epreuves' => [],
            'terrain' => $terrain,
            'glace' => [],
            'spawn_heros' => [], 'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    return $quete->fresh();
}

/**
 * Même carte, mais JOUABLE (un héros positionné, quête active sur le groupe)
 * — pour les preuves qui passent par la vraie résolution de tour (coût
 * réellement décompté, refus 422), pas seulement par `Grille` en direct.
 *
 * @param  list<list<string>>  $cases
 * @param  array{x: int, y: int}  $herosPos
 * @param  list<array{x: int, y: int, terrain_id: int}>  $terrain
 * @param  array<string, mixed>  $herosAttrs
 * @return array{groupe: Groupe, quete: Quete, heros: Personnage, etatHeros: EtatPersonnageQuete}
 */
function sceneAvecRiviereGelee(array $cases, array $herosPos, array $terrain, array $herosAttrs = []): array
{
    $quete = queteAvecRiviereGelee($cases, $terrain);
    $groupe = $quete->groupe;
    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);

    $joueur = connecterJoueur('riviere-'.uniqid());
    $heros = creerHeros($joueur, $groupe, 'Testeur', 1, $herosAttrs);

    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id,
        'personnage_id' => $heros->id,
        'position_x' => $herosPos['x'],
        'position_y' => $herosPos['y'],
        'tombe' => false,
        'a_joue' => false,
    ]);

    return ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros, 'etatHeros' => $etatHeros];
}

function optionDeplacementRiviere(): array
{
    return ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'];
}

// =======================================================================
// 1. TROIS cases de rivière coûtent SIX points, pas trois
// =======================================================================

it('traverser 3 cases de Rivière gelée coûte 6 points de déplacement, pas 3', function () {
    $riviere = idRiviereGelee();
    $scene = sceneAvecRiviereGelee(
        [array_fill(0, 5, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [
            ['x' => 1, 'y' => 0, 'terrain_id' => $riviere],
            ['x' => 2, 'y' => 0, 'terrain_id' => $riviere],
            ['x' => 3, 'y' => 0, 'terrain_id' => $riviere],
        ],
        // base 5 + d6 = 1 (crâne, AUCUNE face bouclier blanc — ce test isole
        // le COÛT, pas les dégâts « sur bouclier blanc » de la rivière,
        // preuvés séparément dans TerrainEnJeuTest) → 6 points tout ronds :
        // exactement le prix des 3 cases de rivière, 0 restant après.
        herosAttrs: ['deplacement_base' => 5],
    );
    $pvAvant = (int) $scene['heros']->pv_body;

    desFiges(array_fill(0, 40, 1));

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacementRiviere(), ['x' => 3, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect($resultat['distance'])->toBe(3, 'trois CASES parcourues — le champ `distance` compte des cases, pas des points')
        ->and($resultat['deplacement_restant'])->toBe(0, '6 points dépensés pour 3 cases à 2, pas 3 pour 3 cases à 1')
        ->and((int) $etat->position_x)->toBe(3)
        ->and((int) $etat->position_y)->toBe(0)
        ->and((int) $etat->deplacement_restant)->toBe(0)
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pvAvant, 'crâne partout : aucun dégât, ce test isole le coût');
});

// =======================================================================
// 2. CAS LIMITE — 1 point restant devant une case à 2
// =======================================================================

it('une case de rivière (coût 2) n\'est PAS offerte à un héros à qui il ne reste qu\'1 point, et le devient avec 2', function () {
    $riviere = idRiviereGelee();
    $quete = queteAvecRiviereGelee(
        [array_fill(0, 4, 's')],
        [['x' => 1, 'y' => 0, 'terrain_id' => $riviere]],
    );
    $grille = FabriqueGrille::pour($quete);

    // ⚠ Ni entrée, ni « arrêt à moitié » : avec un budget de 1, la case n'est
    // même pas dans l'ensemble atteignable — le menu ne peut donc pas
    // l'offrir, puisqu'il n'offre que ce que `casesAtteignables()` rend.
    expect($grille->casesAtteignables(0, 0, 1))->not->toHaveKey('1,0');

    // Avec exactement son coût (2), elle DEVIENT atteignable — preuve que le
    // refus ci-dessus tient au BUDGET, pas à un blocage de la case elle-même.
    expect($grille->casesAtteignables(0, 0, 2))->toHaveKey('1,0');
});

it('le résolveur refuse d\'entrer sur la rivière avec 1 seul point restant — jamais une entrée à moitié', function () {
    $riviere = idRiviereGelee();
    $scene = sceneAvecRiviereGelee(
        [array_fill(0, 4, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [['x' => 1, 'y' => 0, 'terrain_id' => $riviere]],
        // base 0 + d6 = 1 → exactement 1 point, un de moins que le coût (2).
        herosAttrs: ['deplacement_base' => 0],
    );

    desFiges(array_fill(0, 40, 1));

    expect(fn () => app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacementRiviere(), ['x' => 1, 'y' => 0],
    ))->toThrow(ValidationException::class);

    $etat = $scene['etatHeros']->fresh();
    expect((int) $etat->position_x)->toBe(0, 'refusé : le héros n\'a PAS bougé, ni même « à moitié »')
        ->and((int) $etat->position_y)->toBe(0);
});

// =======================================================================
// 3. La PORTÉE n'est PAS affectée — distance() reste GÉOMÉTRIQUE
// =======================================================================

it("la portée n'est pas affectée par la glace : distance() compte des PAS, jamais des points, même quand la ligne traverse de la rivière", function () {
    $riviere = idRiviereGelee();
    $quete = queteAvecRiviereGelee(
        [array_fill(0, 6, 's')],
        [
            ['x' => 2, 'y' => 0, 'terrain_id' => $riviere],
            ['x' => 3, 'y' => 0, 'terrain_id' => $riviere],
        ],
    );
    $grille = FabriqueGrille::pour($quete);

    // 5 pas géométriques de (0,0) à (5,0) — JAMAIS 7 (le coût réel du trajet,
    // 3×1 + 2×2 = 7) : un tireur à 5 cases touche sa cible à 5 cases, que de
    // la glace se trouve sur la ligne ou non. C'est LE cas qui ne se verrait
    // qu'en partie : un tir qui rate sans raison si `distance()` se mettait à
    // compter des coûts.
    expect($grille->distance(0, 0, 5, 0))->toBe(5)
        // Le CHEMIN, lui, est bien pondéré : son coût réel est 7, preuve que
        // les deux parcours cohabitent sans se confondre.
        ->and($grille->coutChemin($grille->chemin(0, 0, 5, 0)))->toBe(7);
});

// =======================================================================
// 4. L'ADJACENCE n'est pas affectée
// =======================================================================

it('deux cases voisines restent adjacentes même si l\'une coûte 2 — le corps-à-corps par-dessus une rivière', function () {
    $riviere = idRiviereGelee();
    $quete = queteAvecRiviereGelee(
        [array_fill(0, 3, 's')],
        [['x' => 1, 'y' => 0, 'terrain_id' => $riviere]],
    );
    $grille = FabriqueGrille::pour($quete);

    // (0,0) et (1,0) [rivière, coût 2] restent voisines au sens ORTHOGONAL —
    // l'adjacence ne consulte jamais coutDeplacement(), c'est de la pure
    // géométrie de coordonnées.
    expect($grille->sontAdjacentes(0, 0, 1, 0))->toBeTrue()
        ->and($grille->distance(0, 0, 1, 0))->toBe(1);
});

// =======================================================================
// 5. Le chemin CONTOURNE la rivière quand c'est moins cher — Dijkstra,
//    pas une BFS avec pénalité ajoutée après coup
// =======================================================================

it('contourne la rivière quand le détour coûte MOINS cher que la traverser — la preuve que c\'est du Dijkstra', function () {
    $riviere = idRiviereGelee();

    // Couloir à DEUX rangées (y=0 dégagée, y=1 barrée de 3 cases de rivière
    // consécutives, x=2..4) large de 7 colonnes (x=0..6). Départ (0,1),
    // arrivée (6,1) :
    //  - le trajet DIRECT (rangée y=1, 6 cases) coûte 1+2+2+2+1+1 = 9 ;
    //  - le DÉTOUR par la rangée y=0 (8 cases : monter, longer, redescendre)
    //    coûte 8×1 = 8 — MOINS cher, malgré DEUX cases de plus.
    // Une BFS-plus-pénalité choisirait le trajet le plus COURT (6 cases,
    // coût 9) puis se contenterait d'en rapporter le prix ; seul un VRAI
    // Dijkstra explore et retient le détour, moins cher mais plus long.
    $cases = [array_fill(0, 7, 's'), array_fill(0, 7, 's')];
    $quete = queteAvecRiviereGelee($cases, [
        ['x' => 2, 'y' => 1, 'terrain_id' => $riviere],
        ['x' => 3, 'y' => 1, 'terrain_id' => $riviere],
        ['x' => 4, 'y' => 1, 'terrain_id' => $riviere],
    ]);
    $grille = FabriqueGrille::pour($quete);

    $chemin = $grille->chemin(0, 1, 6, 1);

    expect($chemin)->not->toBeNull()
        ->and($chemin)->toHaveCount(8, 'le détour (8 cases), pas le trajet direct (6 cases)')
        ->and($grille->coutChemin($chemin))->toBe(8, 'moins cher que les 9 points du trajet direct')
        ->and($grille->distance(0, 1, 6, 1))->toBe(6, 'distance() reste géométrique : 6 pas, le plus court en CASES');

    // Aucune case du chemin retenu ne foule la rivière : la preuve directe du
    // contournement, pas seulement une coïncidence de compte/coût.
    foreach ($chemin as $case) {
        expect(($case['x'] === 2 || $case['x'] === 3 || $case['x'] === 4) && $case['y'] === 1)
            ->toBeFalse("le détour ne devrait jamais fouler ({$case['x']},{$case['y']})");
    }
});
