<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\GabaritQuete;
use App\Models\Mobilier;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
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
 * LES EMPRISES DU MOBILIER — décisions de René (2026-10-16), prises sur des dessins mesurés
 * à l'échelle ≈ 0,9 :
 *   - 1×3 (3 cases) : bibliothèque, armoire, râtelier d'armes, cheminée ;
 *   - 3×2 (6 cases) : table, tombeau (couché, comme dessiné), établi d'alchimiste.
 * La table du sorcier reste HORS catalogue (drapeaux de vue et table de fouille non sourcés).
 *
 * Ce fichier tient quatre garde-fous :
 *  - la DONNÉE (les tailles au catalogue, la cheminée, meuble du jeu de base) ;
 *  - la MIGRATION qui les porte sur une base existante, aller-retour compris ;
 *  - une quête EN COURS, dont les emprises sont figées dans la carte à la pose ;
 *  - la GÉNÉRATION : plancher de cases jouables, connexité depuis UN seuil, adossement de
 *    tout meuble mural, et refus PROPRE d'une pièce qui ne tient pas dans sa salle.
 */

beforeEach(function () {
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

/** @return array{0: int, 1: int}  [largeur, hauteur] du catalogue */
function tailleCatalogue(string $nom): array
{
    $meuble = Mobilier::where('nom', $nom)->firstOrFail();

    return [(int) $meuble->largeur, (int) $meuble->hauteur];
}

/** Une quête EN COURS dont la carte porte EXACTEMENT ces entrées de mobilier (grille 6×6 vide). */
function queteAvecMeubles(array $mobilier): Quete
{
    $groupe = creerGroupe('table-meubles-'.uniqid());

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Quête de test — mobilier',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 6, 'hauteur' => 6,
        'grille' => [
            'largeur' => 6, 'hauteur' => 6,
            'cases' => array_fill(0, 6, array_fill(0, 6, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 6, 'hauteur' => 6, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => $mobilier, 'terrain' => [],
            'spawn_heros' => [['x' => 0, 'y' => 5]], 'spawn_monstres' => [],
        ],
    ]);

    return $quete->fresh()->load('carte');
}

/**
 * Assemble un donjon complet sous le bestiaire `$boite` (`null` = aucun bestiaire,
 * donc seul le mobilier sans boîte entre au catalogue).
 *
 * @return array<string, mixed>
 */
function assemblerAvecBoite(int $graine, ?string $boite): array
{
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();

    return app(AssembleurCarte::class)->assembler(
        $gabarit, $graine, bestiaire: $boite === null ? null : BestiaireGroupe::auto($boite),
    );
}

/**
 * Pose le mobilier d'UNE salle à meubler de `$largeur`×`$hauteur` cases, derrière une
 * porte, en appelant le placement RÉEL (`AssembleurCarte::placerMobilier()`, privé). La
 * salle de départ 3×3 n'est pas meublée. Rend les meubles posés DANS la salle à meubler.
 *
 * @return list<array{mobilier_id: int, x: int, y: int, l: int, h: int, salle: int}>
 */
function meublerSalleSynthetique(int $largeur, int $hauteur, int $graine): array
{
    $largeurGrille = 4 + $largeur + 1;
    $hauteurGrille = max(3, $hauteur) + 1;
    $cases = array_fill(0, $hauteurGrille, array_fill(0, $largeurGrille, 'm'));

    for ($y = 0; $y < 3; $y++) {
        for ($x = 0; $x < 3; $x++) {
            $cases[$y][$x] = 's';
        }
    }

    $cases[1][3] = 's'; // la porte : deux cases, une de chaque côté de l'arête
    for ($y = 0; $y < $hauteur; $y++) {
        for ($x = 4; $x < 4 + $largeur; $x++) {
            $cases[$y][$x] = 's';
        }
    }

    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 1, 'mediane_y' => 1],
        ['x' => 4, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur, 'theme' => 'generique', 'mediane_x' => 4, 'mediane_y' => 0],
    ];

    $placer = new ReflectionMethod(AssembleurCarte::class, 'placerMobilier');
    $mobilier = $placer->invoke(
        app(AssembleurCarte::class), $cases, $salles, [['x' => 3, 'y' => 1, 'cote' => 'e']], [], [],
        fn () => $graine, [], null, null,
    );

    return array_values(array_filter($mobilier, fn (array $m) => ($m['salle'] ?? null) === 1));
}

// =====================================================================
// 1. LA DONNÉE
// =====================================================================

it('pose les emprises décidées : 1×3 pour bibliothèque, armoire, râtelier ; 3×2 pour table, tombeau, établi', function () {
    expect(tailleCatalogue('Bibliothèque'))->toBe([3, 1])
        ->and(tailleCatalogue('Armoire'))->toBe([3, 1])
        ->and(tailleCatalogue('Râtelier d\'armes'))->toBe([1, 3])
        ->and(tailleCatalogue('Table'))->toBe([3, 2])
        ->and(tailleCatalogue('Tombeau'))->toBe([3, 2])
        ->and(tailleCatalogue('Établi d\'alchimiste'))->toBe([3, 2]);
});

it('porte la Cheminée au catalogue du JEU DE BASE : 1×3, dressée contre le mur, bloquante, ni fouillable ni destructible', function () {
    $cheminee = Mobilier::where('nom', 'Cheminée')->firstOrFail();

    expect((int) $cheminee->largeur)->toBe(3)
        ->and((int) $cheminee->hauteur)->toBe(1)
        ->and((bool) $cheminee->bloque_mouvement)->toBeTrue()
        ->and((bool) $cheminee->bloque_vue)->toBeTrue()
        ->and((bool) $cheminee->adosse_au_mur)->toBeTrue()
        ->and((bool) $cheminee->fouillable)->toBeFalse()
        ->and($cheminee->difficulte_destruction)->toBeNull()
        ->and($cheminee->pv_body)->toBeNull()
        ->and($cheminee->boite)->toBeNull('meuble du jeu de base (RB p. 4) : aucune boîte');
});

it('ne déclare AUCUNE table du sorcier ni portant : leur emprise n\'est pas une donnée qu\'on peut seeder', function () {
    expect(Mobilier::whereIn('nom', ['Table du sorcier', 'Portant'])->count())->toBe(0);
});

// =====================================================================
// 2. LA MIGRATION — une base existante reçoit les mêmes tailles
// =====================================================================

it('la migration du 2026-10-16 pose les six emprises décidées, et son retour rend les anciennes', function () {
    $migration = require database_path('migrations/2026_10_16_130000_mobilier_trois_cases.php');

    $migration->down();
    expect(tailleCatalogue('Bibliothèque'))->toBe([2, 1])
        ->and(tailleCatalogue('Armoire'))->toBe([2, 1])
        ->and(tailleCatalogue('Râtelier d\'armes'))->toBe([1, 2])
        ->and(tailleCatalogue('Table'))->toBe([2, 1])
        ->and(tailleCatalogue('Tombeau'))->toBe([1, 2])
        ->and(tailleCatalogue('Établi d\'alchimiste'))->toBe([1, 2]);

    $migration->up();
    expect(tailleCatalogue('Bibliothèque'))->toBe([3, 1])
        ->and(tailleCatalogue('Armoire'))->toBe([3, 1])
        ->and(tailleCatalogue('Râtelier d\'armes'))->toBe([1, 3])
        ->and(tailleCatalogue('Table'))->toBe([3, 2])
        ->and(tailleCatalogue('Tombeau'))->toBe([3, 2])
        ->and(tailleCatalogue('Établi d\'alchimiste'))->toBe([3, 2]);
});

// =====================================================================
// 3. UNE QUÊTE EN COURS GARDE SES EMPRISES — relire le catalogue ne les change pas
// =====================================================================

it('une carte assemblée AVANT le changement garde sa bibliothèque à 2 cases : le catalogue dit 3, la carte dit ce qu\'elle a posé', function () {
    $idBibliotheque = (int) Mobilier::where('nom', 'Bibliothèque')->value('id');

    // Posée à 2 cases (l = 2) — l'emprise qu'elle avait au moment de sa pose.
    $ancienne = queteAvecMeubles([['mobilier_id' => $idBibliotheque, 'x' => 2, 'y' => 0, 'l' => 2, 'h' => 1, 'salle' => 0]]);
    $grille = FabriqueGrille::pour($ancienne);

    expect($grille->estTraversable(2, 0))->toBeFalse()
        ->and($grille->estTraversable(3, 0))->toBeFalse()
        ->and($grille->estTraversable(4, 0))->toBeTrue('la 3e case n\'appartient pas à une pièce posée à 2 cases');

    // Une carte posée APRÈS le changement porte ses 3 cases, et les bloque.
    $nouvelle = queteAvecMeubles([['mobilier_id' => $idBibliotheque, 'x' => 2, 'y' => 0, 'l' => 3, 'h' => 1, 'salle' => 0]]);
    $grilleNouvelle = FabriqueGrille::pour($nouvelle);

    expect($grilleNouvelle->estTraversable(4, 0))->toBeFalse();
});

it('une carte assemblée AVANT garde sa table (2×1) et son tombeau (1×2) : le catalogue dit 3×2, la carte garde ce qu\'elle a posé', function () {
    $idTable = (int) Mobilier::where('nom', 'Table')->value('id');
    $idTombeau = (int) Mobilier::where('nom', 'Tombeau')->value('id');

    $ancienne = queteAvecMeubles([
        ['mobilier_id' => $idTable, 'x' => 0, 'y' => 0, 'l' => 2, 'h' => 1, 'salle' => 0],
        ['mobilier_id' => $idTombeau, 'x' => 0, 'y' => 3, 'l' => 1, 'h' => 2, 'salle' => 0],
    ]);
    $grille = FabriqueGrille::pour($ancienne);

    // Table : (0,0) et (1,0) occupées, (2,0) libre. Tombeau : (0,3) et (0,4) occupées, (1,3) libre.
    expect($grille->estTraversable(1, 0))->toBeFalse()
        ->and($grille->estTraversable(2, 0))->toBeTrue()
        ->and($grille->estTraversable(0, 4))->toBeFalse()
        ->and($grille->estTraversable(1, 3))->toBeTrue();
});

// =====================================================================
// 4. LA GÉNÉRATION — sur des dizaines de graines, sous chaque bestiaire
// =====================================================================

it('ne laisse JAMAIS une salle sous le plancher de cases jouables, ni un groupe coupé de son seuil, et tient l\'adossement de tout meuble mural, sur 60 graines et 4 bestiaires', function () {
    $minimum = (new ReflectionClassConstant(AssembleurCarte::class, 'CASES_JOUABLES_MINIMUM'))->getValue();
    $murales = Mobilier::where('adosse_au_mur', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    $idCheminee = (int) Mobilier::where('nom', 'Cheminée')->value('id');
    $compte = ['trois' => 0, 'six' => 0, 'murales' => 0, 'cheminees' => 0];

    foreach ([null, 'first_light', 'jungles_delthrak', 'horreur_des_glaces'] as $boite) {
        foreach (range(1, 60) as $graine) {
            $carte = assemblerAvecBoite($graine, $boite);
            $cases = $carte['cases'];
            $etiquette = "graine {$graine}, boîte ".($boite ?? '∅');

            foreach ($carte['salles'] as $i => $salle) {
                if ($i === 0) {
                    continue; // salle de départ : le groupe y démarre empilé, aucun meuble
                }

                // Sol de la salle, et cases recouvertes par ses meubles.
                $sol = [];
                for ($y = $salle['y']; $y < $salle['y'] + $salle['hauteur']; $y++) {
                    for ($x = $salle['x']; $x < $salle['x'] + $salle['largeur']; $x++) {
                        if (($cases[$y][$x] ?? 'm') === 's') {
                            $sol["{$x},{$y}"] = ['x' => $x, 'y' => $y];
                        }
                    }
                }

                $occupees = [];
                foreach ($carte['mobilier'] as $meuble) {
                    if (($meuble['salle'] ?? null) !== $i) {
                        continue;
                    }
                    for ($dx = 0; $dx < $meuble['l']; $dx++) {
                        for ($dy = 0; $dy < $meuble['h']; $dy++) {
                            $occupees[($meuble['x'] + $dx).','.($meuble['y'] + $dy)] = true;
                        }
                    }
                }

                // Garde-fou 1 : le plancher de cases jouables (§2.12 ter), sur les cases RESTANTES.
                $libres = array_diff_key($sol, $occupees);
                expect(count($libres))->toBeGreaterThanOrEqual($minimum, "{$etiquette}, salle {$i}");

                // Garde-fou 2 : depuis UN seuil, on atteint toutes les cases libres (connexité réelle).
                $seuils = [];
                foreach ($carte['portes'] as $porte) {
                    foreach (Grille::casesPorte($porte) as $case) {
                        if (isset($libres["{$case['x']},{$case['y']}"])) {
                            $seuils[] = $case;
                        }
                    }
                }

                if ($seuils !== []) {
                    $vus = ["{$seuils[0]['x']},{$seuils[0]['y']}" => true];
                    $file = [$seuils[0]];
                    while ($file !== []) {
                        $c = array_pop($file);
                        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                            $cle = ($c['x'] + $dx).','.($c['y'] + $dy);
                            if (isset($libres[$cle]) && ! isset($vus[$cle])) {
                                $vus[$cle] = true;
                                $file[] = ['x' => $c['x'] + $dx, 'y' => $c['y'] + $dy];
                            }
                        }
                    }

                    expect(count($vus))->toBe(count($libres), "{$etiquette}, salle {$i} : une case libre isolée");
                }
            }

            foreach ($carte['mobilier'] as $meuble) {
                $surface = $meuble['l'] * $meuble['h'];
                $compte['trois'] += $surface === 3 ? 1 : 0;
                $compte['six'] += $surface === 6 ? 1 : 0;
            }

            // Adossement : TOUT meuble déclaré mural (bibliothèque, armoire, râtelier, cheminée)
            // touche un mur le long de son grand axe.
            foreach ($carte['mobilier'] as $meuble) {
                if ((int) $meuble['mobilier_id'] === $idCheminee) {
                    $compte['cheminees']++;
                }

                if (! in_array((int) $meuble['mobilier_id'], $murales, true)) {
                    continue;
                }

                $compte['murales']++;
                $cotes = match (true) {
                    $meuble['l'] > $meuble['h'] => [[0, -1], [0, 1]],
                    $meuble['h'] > $meuble['l'] => [[-1, 0], [1, 0]],
                    default => [[0, -1], [0, 1], [-1, 0], [1, 0]],
                };

                $adosse = false;
                for ($dx = 0; $dx < $meuble['l']; $dx++) {
                    for ($dy = 0; $dy < $meuble['h']; $dy++) {
                        foreach ($cotes as [$ox, $oy]) {
                            if (($cases[$meuble['y'] + $dy + $oy][$meuble['x'] + $dx + $ox] ?? 'm') === 'm') {
                                $adosse = true;
                            }
                        }
                    }
                }

                expect($adosse)->toBeTrue("{$etiquette} : meuble mural non adossé");
            }
        }
    }

    // Preuves positives : chaque taille, et les murs, apparaissent vraiment sur ces graines.
    expect($compte['trois'])->toBeGreaterThan(0)
        ->and($compte['six'])->toBeGreaterThan(0)
        ->and($compte['murales'])->toBeGreaterThan(0)
        ->and($compte['cheminees'])->toBeGreaterThan(0);
});

// =====================================================================
// 5. LA PETITE SALLE — une pièce de 6 cases est REFUSÉE, jamais forcée
// =====================================================================

it('refuse PROPREMENT une pièce de 6 cases dans une salle de 9 cases : le plancher de 6 cases jouables la retire', function () {
    foreach (range(1, 120) as $graine) {
        $meubles = meublerSalleSynthetique(3, 3, $graine);
        $surfaces = array_map(fn (array $m) => $m['l'] * $m['h'], $meubles);

        // 9 cases de sol, 6 jouables au minimum : au plus 3 cases de meubles, et jamais une pièce de 6.
        expect(array_sum($surfaces))->toBeLessThanOrEqual(9 - 6, "graine {$graine}")
            ->and(in_array(6, $surfaces, true))->toBeFalse("graine {$graine} : une pièce de 6 cases dans 9");

        // Chaque pièce posée reste DANS la salle : jamais une case clippée ou hors sol.
        foreach ($meubles as $m) {
            for ($dx = 0; $dx < $m['l']; $dx++) {
                for ($dy = 0; $dy < $m['h']; $dy++) {
                    expect($m['x'] + $dx)->toBeGreaterThanOrEqual(4)->toBeLessThanOrEqual(6)
                        ->and($m['y'] + $dy)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(2);
                }
            }
        }
    }
});

it('une salle de 25 cases reçoit bien des pièces de 6 cases (contrôle positif), et garde au moins 6 cases libres', function () {
    $six = 0;

    foreach (range(1, 60) as $graine) {
        $meubles = meublerSalleSynthetique(5, 5, $graine);
        $surfaces = array_map(fn (array $m) => $m['l'] * $m['h'], $meubles);

        expect(array_sum($surfaces))->toBeLessThanOrEqual(25 - 6, "graine {$graine}");
        $six += count(array_filter($surfaces, fn (int $s) => $s === 6));
    }

    expect($six)->toBeGreaterThan(0);
});
