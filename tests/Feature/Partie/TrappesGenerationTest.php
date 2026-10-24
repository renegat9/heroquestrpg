<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\Grille;
use App\Partie\MoteurPortes;
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
use Illuminate\Support\Facades\Http;

/*
 * TRAPPES DE TÉLÉPORTATION — POSE dans un donjon généré (EXT-CARTE2, 2026-10-23).
 *
 * Les règles de pose, écrites dans `AssembleurCarte::placerTerrains()` (et `paireTrappePosable()`), sont
 * vérifiées ici sur des cartes RÉELLEMENT assemblées, graine par graine :
 *  1. deux SALLES distinctes, jamais la salle 0, jamais la salle de l'objectif ;
 *  2. chaque extrémité ATTEIGNABLE depuis le départ sans franchir une porte verrouillée, secrète, de pierre
 *     ou à levier : la trappe ne contourne jamais un verrou ;
 *  3. aucune extrémité sur un PARVIS (voisine orthogonale d'une porte, d'un levier ou d'une épreuve) ;
 *  4. une paire est TOUJOURS entière : deux cases, même `paire_id`, jamais une trappe seule.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

/** Assemble une carte réelle avec N paires de trappes voulues, sous le thème donné (`null` = aucun). */
function trappesAssembler(int $graine, ?string $theme, int $paires = 1): array
{
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
    $gabarit->structure = [...$gabarit->structure, 'terrains' => ['min' => 0, 'max' => 0, 'trappes' => ['min' => $paires, 'max' => $paires]]];

    return app(AssembleurCarte::class)->assembler(
        $gabarit, $graine, bestiaire: $theme === null ? null : BestiaireGroupe::auto($theme),
    );
}

/**
 * Les paires de trappes d'une carte : une entrée par paire, les deux cases. Une trappe sans sa jumelle
 * ferait échouer `count($case) === 2` plus bas.
 *
 * @return list<list<array{x: int, y: int, paire_id: string}>>
 */
function trappesDeLaCarte(array $carte): array
{
    $id = (int) Terrain::where('nom', 'Trappe de téléportation')->firstOrFail()->id;
    $parPaire = [];

    foreach ($carte['terrain'] as $t) {
        if ((int) $t['terrain_id'] === $id) {
            $parPaire[(string) ($t['paire_id'] ?? '')][] = ['x' => (int) $t['x'], 'y' => (int) $t['y'], 'paire_id' => (string) ($t['paire_id'] ?? '')];
        }
    }

    return array_values($parPaire);
}

/** @return list<int> index des salles qui contiennent un élément-objectif */
function sallesDeLObjectif(array $carte): array
{
    $salles = [];

    foreach ((array) ($carte['mobilier'] ?? []) as $meuble) {
        if (! empty($meuble['objectif']) && isset($meuble['salle'])) {
            $salles[] = (int) $meuble['salle'];
        }
    }

    return $salles;
}

/** Les cases de toutes les portes VERROUILLÉES (verrou, secrète, de pierre, à levier) : ce qu'on ne franchit pas. */
function casesDesVerrous(array $carte): array
{
    $cases = [];

    foreach ((array) ($carte['portes'] ?? []) as $porte) {
        $ferme = in_array($porte['etat'] ?? null, [MoteurPortes::ETAT_VERROUILLEE, MoteurPortes::ETAT_SECRETE], true)
            || ! empty($porte['verrou']);

        if ($ferme) {
            $cases[] = Grille::caseEmbrasure($porte, $carte['salles']);
        }
    }

    return $cases;
}

// ---------------------------------------------------------------------
// 1. Les trappes ne sortent que sous leurs deux boîtes — et jamais seules
// ---------------------------------------------------------------------

it('ne pose des trappes que sous les thèmes Mage du Miroir et Kellar\'s Keep — et en pose sous eux', function () {
    foreach ([null, 'horreur_des_glaces', 'jungles_delthrak', 'horde_ogre'] as $theme) {
        foreach ([3, 11, 42] as $graine) {
            expect(trappesDeLaCarte(trappesAssembler($graine, $theme, 1)))->toBe([], "thème {$theme}, graine {$graine}");
        }
    }

    foreach (['mage_du_miroir', 'kellars_keep'] as $theme) {
        $avec = 0;

        foreach (range(1, 40) as $graine) {
            $avec += trappesDeLaCarte(trappesAssembler($graine, $theme, 1)) !== [] ? 1 : 0;
        }

        expect($avec)->toBeGreaterThan(5, "sous {$theme}, une part des donjons porte une paire de trappes");
    }
});

it('une trappe n\'est jamais tirée seule : chaque case porte un paire_id et chaque paire compte deux cases', function () {
    foreach (range(1, 40) as $graine) {
        $carte = trappesAssembler($graine, 'mage_du_miroir', 2);
        $id = (int) Terrain::where('nom', 'Trappe de téléportation')->firstOrFail()->id;
        $cases = array_filter($carte['terrain'], fn ($t) => (int) $t['terrain_id'] === $id);

        foreach ($cases as $t) {
            expect($t['paire_id'] ?? null)->not->toBeNull("graine {$graine} : une trappe sans paire_id");
        }

        foreach (trappesDeLaCarte($carte) as $paire) {
            expect(count($paire))->toBe(2, "graine {$graine} : une paire doit être ENTIÈRE");
        }
    }
});

it('un seul enregistrement de catalogue sert les DEUX boîtes (boites_partagees)', function () {
    $trappes = Terrain::where('nom', 'Trappe de téléportation')->get();

    expect($trappes)->toHaveCount(1)
        ->and($trappes->first()->boites())->toBe(['mage_du_miroir', 'kellars_keep'])
        ->and($trappes->first()->effet)->toMatchArray(['teleportation' => 'contact', 'degats_au_passage' => 1]);
});

// ---------------------------------------------------------------------
// 2. La règle de POSE, vérifiée sur des cartes assemblées
// ---------------------------------------------------------------------

// ⚠ La salle de l'OBJECTIF n'est exercée par AUCUNE de ces graines : `ELEMENT_OBJECTIF_FINAL` ne déclare
// d'élément-objectif que pour Wizards of Morcar (le Haut Autel). Sous Mage du Miroir et Kellar's Keep, la
// salle finale n'en porte pas : l'exclusion est écrite et tient si une carte en porte un, mais ce test ne
// la voit pas tant que la boîte n'en déclare pas. Écrit, pas oublié.
it('chaque extrémité est dans une salle distincte, jamais la salle 0 (ni la salle de l\'objectif, quand la carte en porte un)', function () {
    foreach (['mage_du_miroir', 'kellars_keep'] as $theme) {
        foreach (range(1, 40) as $graine) {
            $carte = trappesAssembler($graine, $theme, 1);
            $objectif = sallesDeLObjectif($carte);

            foreach (trappesDeLaCarte($carte) as $paire) {
                [$a, $b] = $paire;
                $ia = Salles::indexDe($carte['salles'], $a['x'], $a['y']);
                $ib = Salles::indexDe($carte['salles'], $b['x'], $b['y']);

                expect($ia)->not->toBeNull("{$theme}, graine {$graine} : extrémité hors salle")
                    ->and($ib)->not->toBeNull()
                    ->and($ia)->not->toBe($ib, "{$theme}, graine {$graine} : deux extrémités dans la MÊME salle")
                    ->and($ia)->not->toBe(0, "{$theme}, graine {$graine} : extrémité dans la salle de départ")
                    ->and($ib)->not->toBe(0)
                    ->and(in_array($ia, $objectif, true))->toBeFalse("{$theme}, graine {$graine} : extrémité dans la salle de l'objectif")
                    ->and(in_array($ib, $objectif, true))->toBeFalse();
            }
        }
    }

});

it('chaque extrémité est atteignable depuis le départ SANS franchir une porte verrouillée ni secrète', function () {
    foreach (['mage_du_miroir', 'kellars_keep'] as $theme) {
        foreach (range(1, 40) as $graine) {
            $carte = trappesAssembler($graine, $theme, 1);
            $verrous = casesDesVerrous($carte);

            // Obstacles : les portes fermées à clé, secrètes ou de pierre, et les terrains qui bloquent la marche.
            // Le mobilier bloquant n'est pas compté : il ne peut que RESTREINDRE l'accès, la règle tient donc.
            $bloquantes = array_map(fn (array $c) => ['x' => $c['x'], 'y' => $c['y']], $verrous);
            $bloquantesTerrain = Terrain::query()->where('bloque_mouvement', true)->pluck('id')->map(fn ($v) => (int) $v)->all();

            foreach ($carte['terrain'] as $t) {
                if (in_array((int) $t['terrain_id'], $bloquantesTerrain, true)) {
                    $bloquantes[] = ['x' => (int) $t['x'], 'y' => (int) $t['y']];
                }
            }

            $grille = new Grille($carte['cases']);
            $grille->obstruer($bloquantes);

            $depart = $carte['spawn_heros'][0];
            $atteignables = $grille->casesAtteignables((int) $depart['x'], (int) $depart['y'], 100000);
            $atteignables["{$depart['x']},{$depart['y']}"] = true;

            foreach (trappesDeLaCarte($carte) as $paire) {
                foreach ($paire as $extremite) {
                    expect(isset($atteignables["{$extremite['x']},{$extremite['y']}"]))->toBeTrue(
                        "{$theme}, graine {$graine} : extrémité ({$extremite['x']},{$extremite['y']}) derrière un verrou",
                    );
                }
            }
        }
    }
});

it('aucune extrémité n\'est sur un PARVIS : ni voisine orthogonale d\'une porte, d\'un levier ou d\'une épreuve', function () {
    foreach (['mage_du_miroir', 'kellars_keep'] as $theme) {
        foreach (range(1, 40) as $graine) {
            $carte = trappesAssembler($graine, $theme, 1);

            $sources = [];
            foreach ((array) ($carte['portes'] ?? []) as $porte) {
                foreach (Grille::casesPorte($porte) as $c) {
                    $sources[] = $c;
                }
            }
            foreach (['leviers', 'epreuves'] as $cle) {
                foreach ((array) ($carte[$cle] ?? []) as $element) {
                    $sources[] = ['x' => (int) $element['x'], 'y' => (int) $element['y']];
                }
            }

            foreach (trappesDeLaCarte($carte) as $paire) {
                foreach ($paire as $extremite) {
                    foreach ($sources as $source) {
                        expect(Grille::sontAdjacentes($extremite['x'], $extremite['y'], $source['x'], $source['y']))->toBeFalse(
                            "{$theme}, graine {$graine} : extrémité sur le parvis de ({$source['x']},{$source['y']})",
                        );
                    }
                }
            }
        }
    }
});

it('sans `terrains.trappes`, le gabarit ne dit pas COMBIEN : aucune paire, même sous le thème Mage du Miroir', function () {
    // Le thème dit SI, le gabarit dit COMBIEN : sans compte, rien n'est posé.
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
    $structure = $gabarit->structure;
    unset($structure['terrains']['trappes']);
    $gabarit->structure = $structure;

    foreach (range(1, 10) as $graine) {
        $carte = app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: BestiaireGroupe::auto('mage_du_miroir'));
        expect(trappesDeLaCarte($carte))->toBe([], "graine {$graine} : le gabarit ne dit pas COMBIEN");
    }
});
