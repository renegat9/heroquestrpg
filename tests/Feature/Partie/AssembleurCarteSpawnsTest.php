<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Partie\AssembleurCarte;
use App\Partie\Grille;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\TuileSeeder;

/**
 * Verdict §2.12 / §2.12 bis — placements de départ.
 *
 * Rappel du piège de vocabulaire : la taille déclarée d'une salle INCLUT son
 * mur. Une salle « 5×5 » n'a que 3×3 = 9 cases utiles, une « 4×4 » en a 5.
 * L'assembleur étant déterministe à graine fixe, on peut poser des assertions
 * dures sur plusieurs graines.
 */
beforeEach(function () {
    $this->seed([TuileSeeder::class, GabaritQueteSeeder::class]);
});

function cartesDeTest(int $nb = 12): array
{
    $assembleur = app(AssembleurCarte::class);
    $gabarits = GabaritQuete::all();
    $cartes = [];

    foreach (range(1, $nb) as $graine) {
        $gabarit = $gabarits[$graine % count($gabarits)];
        $cartes[] = $assembleur->assembler($gabarit, $graine * 7919);
    }

    return $cartes;
}

it('ne fait jamais démarrer un héros sur une case de porte', function () {
    foreach (cartesDeTest() as $carte) {
        $casesPorte = [];
        foreach ($carte['portes'] as $porte) {
            foreach (Grille::casesPorte($porte) as $c) {
                $casesPorte["{$c['x']},{$c['y']}"] = true;
            }
        }

        foreach ($carte['spawn_heros'] as $spawn) {
            expect(isset($casesPorte["{$spawn['x']},{$spawn['y']}"]))->toBeFalse(
                "Un héros démarre dans l'encadrement d'une porte ({$spawn['x']},{$spawn['y']}) — "
                .'il bouche la ligne de vue de tout le groupe.',
            );
        }
    }
});

it('n\'enferme aucun héros à son placement de départ', function () {
    foreach (cartesDeTest() as $carte) {
        // Cas réel du test de jeu : 4 héros, attribués dans l'ordre d'initiative.
        $places = array_slice($carte['spawn_heros'], 0, 4);
        $occupees = [];
        foreach ($places as $p) {
            $occupees["{$p['x']},{$p['y']}"] = true;
        }

        $grille = new Grille($carte['cases']);

        foreach ($places as $i => $p) {
            $libre = false;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $p['x'] + $dx;
                $ny = $p['y'] + $dy;
                if ($grille->estTraversable($nx, $ny) && ! isset($occupees["{$nx},{$ny}"])) {
                    $libre = true;
                    break;
                }
            }

            expect($libre)->toBeTrue(
                "Le héros n°{$i} démarre encerclé en ({$p['x']},{$p['y']}) : aucune case adjacente libre, "
                .'il perd tout son déplacement du tour 1.',
            );
        }
    }
});

it('laisse des cases libres dans chaque salle peuplée de monstres', function () {
    foreach (cartesDeTest() as $carte) {
        $parSalle = [];

        foreach ($carte['spawn_monstres'] as $spawn) {
            foreach ($carte['salles'] as $i => $s) {
                if ($spawn['x'] >= $s['x'] && $spawn['x'] < $s['x'] + $s['largeur']
                    && $spawn['y'] >= $s['y'] && $spawn['y'] < $s['y'] + $s['hauteur']) {
                    $parSalle[$i] = ($parSalle[$i] ?? 0) + 1;
                    break;
                }
            }
        }

        $grille = new Grille($carte['cases']);

        foreach ($parSalle as $i => $nb) {
            $s = $carte['salles'][$i];
            $utiles = 0;
            for ($y = $s['y']; $y < $s['y'] + $s['hauteur']; $y++) {
                for ($x = $s['x']; $x < $s['x'] + $s['largeur']; $x++) {
                    if ($grille->estTraversable($x, $y)) {
                        $utiles++;
                    }
                }
            }

            expect($nb)->toBeLessThan(
                $utiles,
                "La salle {$i} a {$utiles} cases utiles pour {$nb} emplacements de monstres : "
                .'elle serait impénétrable.',
            );
        }
    }
});
