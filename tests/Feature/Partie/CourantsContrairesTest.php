<?php

declare(strict_types=1);

use App\Engine\MotsClesTerrain;
use App\Models\Carte;
use App\Models\GabaritQuete;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\Salles;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;

/*
 * LOT H DES RESTES — les COURANTS CONTRAIRES (Spirit Queen's Torment, quête 11 « The Tower of Water », livret G0053 p. 14 note B) :
 *
 *   « Currents in these rooms press against the heroes. Each square in these rooms takes 2 movement points to traverse.
 *     Monsters are unaffected. »
 *
 * Une SALLE ENTIÈRE (`pose: salle`), coût 2 pour les héros et les alliés, coût 1 pour les monstres — par la clé EXISTANTE
 * `ignore_par_monstres` (la Rivière gelée), lue en un seul point, `FabriqueGrille::pour()`. Aucune clé nouvelle.
 */

beforeEach(function () {
    config(['services.anthropic.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

/** Le courant de la boîte, lu au catalogue — pas recopié. */
function courantRestes(): Terrain
{
    return Terrain::where('nom', 'Courant contraire')->firstOrFail();
}

/**
 * Une carte assemblée par le VRAI assembleur, sous un thème, avec COMBIEN de salles couvertes par le courant (le gabarit dit
 * combien ; le thème dit lesquels). Aucun autre terrain ordinaire : on éprouve la pose, pas le reste du tirage.
 *
 * @return array<string, mixed>
 */
function assemblerCourantsRestes(int $graine, ?string $theme = 'spirit_queen', int $salles = 1): array
{
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
    $gabarit->structure = [...$gabarit->structure, 'terrains' => [
        'min' => 0, 'max' => 0, 'courants' => ['min' => $salles, 'max' => $salles],
    ]];

    return app(AssembleurCarte::class)->assembler(
        $gabarit, $graine, bestiaire: $theme === null ? null : BestiaireGroupe::auto($theme),
    );
}

/**
 * Une quête à la main : une salle de départ 6×1 (sans courant) puis une salle 6×1 de courants, reliées par un couloir.
 * Le sol est tenu par `cases` ; les courants par `terrain`. Assez pour éprouver le coût par qui marche.
 *
 * @return Quete
 */
function queteCourantsRestes(): Quete
{
    $groupe = creerGroupe('table-courants-restes-'.uniqid());
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Quête de test — courants contraires',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $courant = courantRestes();
    $terrain = [];
    foreach (range(3, 5) as $x) {
        $terrain[] = ['x' => $x, 'y' => 0, 'terrain_id' => $courant->id, 'groupe_id' => 'salle-1'];
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 6, 'hauteur' => 1,
        'grille' => [
            'largeur' => 6, 'hauteur' => 1,
            'cases' => [array_fill(0, 6, 's')],
            'salles' => [
                ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 1, 'mediane_y' => 0],
                ['x' => 3, 'y' => 0, 'largeur' => 3, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 4, 'mediane_y' => 0],
            ],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'mobilier' => [], 'epreuves' => [], 'aretes' => [],
            'terrain' => $terrain,
            'spawn_heros' => [['x' => 0, 'y' => 0]], 'spawn_monstres' => [],
        ],
    ]);

    return $quete->fresh()->load('carte');
}

// =====================================================================
// 1. LE CATALOGUE ET LE GABARIT — la pose et la clé de comptage existent
// =====================================================================

it('déclare le Courant contraire : coût 2, boîte spirit_queen, pose SALLE, et la SEULE clé ignore_par_monstres', function () {
    $courant = courantRestes();

    expect($courant->cout_deplacement)->toBe(2)
        ->and($courant->boite)->toBe('spirit_queen')
        ->and($courant->pose)->toBe('salle')
        ->and($courant->bloque_mouvement)->toBeFalse()
        ->and($courant->bloque_vue)->toBeFalse()
        ->and($courant->effet)->toBe(['ignore_par_monstres' => true]);

    // Pas de clé nouvelle : la règle « les monstres ne paient pas » est déjà lue au même point, par la Rivière gelée.
    expect(MotsClesTerrain::connue('ignore_par_monstres'))->toBeTrue();
});

it('donne au gabarit une clé `courants` (combien de salles) sur les quatre gabarits qui portent des terrains', function () {
    foreach (GabaritQuete::query()->get() as $gabarit) {
        $terrains = (array) ($gabarit->structure['terrains'] ?? []);

        if ($terrains === []) {
            continue;
        }

        expect(array_key_exists('courants', $terrains))->toBeTrue("gabarit « {$gabarit->nom} » sans clé courants")
            ->and($terrains['courants']['min'] ?? null)->toBe(0)
            ->and($terrains['courants']['max'] ?? null)->toBe(1);
    }
});

// =====================================================================
// 2. LE PLACEMENT — une salle entière, sous le thème seulement, jamais la salle de départ
// =====================================================================

it('pose le courant sur UNE SALLE ENTIÈRE : toutes les cases libres de la salle, et d\'une seule salle', function () {
    $idCourant = courantRestes()->id;
    $vu = 0;

    foreach (range(1, 30) as $graine) {
        $carte = assemblerCourantsRestes($graine);
        $cases = collect($carte['terrain'])->where('terrain_id', $idCourant);

        if ($cases->isEmpty()) {
            continue;
        }

        $vu++;

        // Une seule salle, jamais la salle 0 (le départ).
        $salles = $cases->map(fn (array $t) => Salles::indexDe($carte['salles'], $t['x'], $t['y']))->unique()->values();
        expect($salles->count())->toBe(1, "graine {$graine} : le courant touche {$salles->count()} salles")
            ->and($salles->first())->not->toBe(0, "graine {$graine} : courant dans la salle de départ");

        // Une plaque par salle, nommée « salle-N ».
        expect($cases->pluck('groupe_id')->unique()->values()->all())->toHaveCount(1)
            ->and((string) $cases->first()['groupe_id'])->toStartWith('salle-');

        // Toutes les cases SOL de la salle sont couvertes, sauf celles qu'une autre couche tient déjà.
        $salle = $carte['salles'][$salles->first()];
        $couvertes = $cases->map(fn (array $t) => "{$t['x']},{$t['y']}")->all();
        $autres = [];
        foreach ((array) ($carte['mobilier'] ?? []) as $m) {
            for ($dx = 0; $dx < (int) ($m['l'] ?? 1); $dx++) {
                for ($dy = 0; $dy < (int) ($m['h'] ?? 1); $dy++) {
                    $autres[(($m['x'] + $dx).','.($m['y'] + $dy))] = true;
                }
            }
        }
        foreach ([...(array) ($carte['pieges'] ?? []), ...(array) ($carte['leviers'] ?? []), ...(array) ($carte['epreuves'] ?? [])] as $p) {
            $autres["{$p['x']},{$p['y']}"] = true;
        }
        foreach ((array) $carte['terrain'] as $t) {
            if ($t['terrain_id'] !== $idCourant) {
                $autres["{$t['x']},{$t['y']}"] = true;
            }
        }
        foreach ((array) ($carte['portes'] ?? []) as $porte) {
            foreach (\App\Partie\Grille::casesPorte($porte) as $c) {
                $autres["{$c['x']},{$c['y']}"] = true;
            }
        }

        for ($r = 0; $r < $salle['hauteur']; $r++) {
            for ($c = 0; $c < $salle['largeur']; $c++) {
                $x = $salle['x'] + $c;
                $y = $salle['y'] + $r;

                if (($carte['cases'][$y][$x] ?? 'm') !== 's' || isset($autres["{$x},{$y}"])) {
                    continue;
                }

                expect(in_array("{$x},{$y}", $couvertes, true))->toBeTrue("graine {$graine} : case libre ({$x},{$y}) de la salle non couverte");
            }
        }
    }

    expect($vu)->toBeGreaterThan(0, 'le courant n\'a jamais été posé : le test ne prouve rien');
});

it('ne pose le courant QUE sous le thème spirit_queen — ni sans thème, ni sous les autres', function () {
    $idCourant = courantRestes()->id;

    foreach ([null, 'horreur_des_glaces', 'horde_ogre', 'jungles_delthrak'] as $theme) {
        foreach (range(1, 12) as $graine) {
            $carte = assemblerCourantsRestes($graine, $theme);

            expect(collect($carte['terrain'])->contains(fn (array $t) => $t['terrain_id'] === $idCourant))
                ->toBeFalse("thème {$theme} : courant posé");
        }
    }
});

// =====================================================================
// 3. LE COÛT — 2 pour un héros et un allié, 1 pour un monstre (« Monsters are unaffected »)
// =====================================================================

it('fait payer 2 points par case aux HÉROS, mais pas aux monstres, qui passent au coût normal', function () {
    $quete = queteCourantsRestes();

    // Le héros : aucune exception, c'est la grille qui le décrit — un cas par qui marche.
    $heros = FabriqueGrille::pour($quete, exceptPersonnageId: 999991, franchitAllies: true);
    expect($heros->coutDeplacement(3, 0))->toBe(2)
        ->and($heros->coutDeplacement(4, 0))->toBe(2)
        ->and($heros->coutDeplacement(5, 0))->toBe(2)
        ->and($heros->coutDeplacement(1, 0))->toBe(1, 'hors courant : coût normal');

    // Le monstre : la grille bâtie POUR lui (`exceptInstanceId`) ne paie pas la salle.
    $monstre = FabriqueGrille::pour($quete, exceptInstanceId: 999992);
    expect($monstre->coutDeplacement(3, 0))->toBe(1)
        ->and($monstre->coutDeplacement(4, 0))->toBe(1)
        ->and($monstre->coutDeplacement(5, 0))->toBe(1);

    // L'allié (mercenaire) : du côté des héros, il paie comme eux.
    $allie = FabriqueGrille::pour($quete, exceptMercenaireId: 999993, franchitAllies: true);
    expect($allie->coutDeplacement(4, 0))->toBe(2);
});

it('compte 2 points par case de courant dans le trajet d\'un héros : le coût est celui de la route, jamais le nombre de cases', function () {
    $quete = queteCourantsRestes();
    $heros = FabriqueGrille::pour($quete, exceptPersonnageId: 999991, franchitAllies: true);

    // De (0,0) à (5,0) : 0→1→2 coûte 1+1, puis 3→4→5 coûte 2+2+2 : 8 points pour 5 pas.
    $chemin = $heros->chemin(0, 0, 5, 0);

    expect(count($chemin))->toBe(5)
        ->and($heros->coutChemin($chemin))->toBe(8)
        ->and($heros->pasAffordables($chemin, 6))->toBe(4, 'six points ne passent que quatre cases, et la salle compte double');
});
