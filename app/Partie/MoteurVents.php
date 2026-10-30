<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * LES VENTS DE COULOIR — la géométrie de la poussée (*Spirit Queen's Torment* q. 12 « The Tower of Air », livret G0053 p. 15 ;
 * lot H des restes, 2026-10-29).
 *
 * « If the roll result is a shield, they move 2 additional squares in the direction of their choice. If the roll result is a
 * skull, they move 2 squares in the direction of Zargon's choice. »
 *
 * Ce fichier décide QUELLES poussées sont légales et OÙ elles finissent. Il ne lance aucun dé et ne touche à aucune vie :
 * `ResolveurTour::pousserDuVent()` applique le résultat (pièges, rivière, position). Un seul point de passage pour la géométrie :
 * le menu (les directions offertes), le résolveur (la liste blanche revalidée) et le choix de Zargon lisent tous ici.
 *
 * Règles TRANCHÉES ici (le livret ne les donne pas) :
 *  - une direction = l'une des quatre cardinales, dans l'ordre FIXE E, O, S, N (celui de `Grille::DIRECTIONS`) : c'est l'ordre
 *    d'affichage du choix et le départage de Zargon ;
 *  - la poussée avance d'AU PLUS `$cases` cases, une à une. Elle s'arrête AVANT un mur, un meuble, une porte fermée, un portail,
 *    une case inconnue (le brouillard) et toute figure (héros, monstre, allié) : on ne traverse rien de tout cela ;
 *  - elle FINIT sur la dernière case où l'on peut s'arrêter (`arretInterdit()` : figure, Mare, Brasier). Une direction sans aucune
 *    case d'arrêt n'est pas légale : elle n'est jamais offerte, et Zargon ne la choisit pas ;
 *  - Zargon choisit la direction dont la case d'arrivée est la plus proche d'un MONSTRE VIVANT (distance de Manhattan, égalité
 *    départagée par l'ordre E, O, S, N). Sans monstre vivant, la première direction légale dans cet ordre.
 */
final class MoteurVents
{
    /** Ordre fixe des directions : E, O, S, N — celui de `Grille::DIRECTIONS`. */
    public const DIRECTIONS = [
        'e' => [1, 0],
        'o' => [-1, 0],
        's' => [0, 1],
        'n' => [0, -1],
    ];

    /** Le nom que le joueur lit après « vers » : « vers l'est », « vers le sud ». */
    public const NOMS = [
        'e' => "l'est",
        'o' => "l'ouest",
        's' => 'le sud',
        'n' => 'le nord',
    ];

    /**
     * La poussée d'un héros depuis (`$x`, `$y`) dans `$direction`, sur AU PLUS `$cases` cases. `null` si la direction est inconnue
     * ou si aucune case d'arrêt ne s'offre dans cette direction.
     *
     * @return array{pas: list<array{x: int, y: int}>, fin: array{x: int, y: int}}|null  `pas` : les cases traversées, la dernière est `fin`
     */
    public function poussee(Grille $grille, int $x, int $y, string $direction, int $cases): ?array
    {
        if (! isset(self::DIRECTIONS[$direction]) || $cases < 1) {
            return null;
        }

        [$dx, $dy] = self::DIRECTIONS[$direction];

        $pas = [];
        $finIndex = null;
        $cx = $x;
        $cy = $y;

        for ($k = 0; $k < $cases; $k++) {
            $nx = $cx + $dx;
            $ny = $cy + $dy;

            // Un mur, un meuble, une porte fermée, un portail, une case inconnue, une figure : la poussée s'arrête AVANT.
            if (! $grille->estTraversable($nx, $ny) || $grille->estOccupeeParFigure($nx, $ny)
                || $grille->porteBloqueEntre($cx, $cy, $nx, $ny) || $grille->estPortail($nx, $ny)) {
                break;
            }

            $pas[] = ['x' => $nx, 'y' => $ny];

            // La dernière case où l'on peut S'ARRÊTER est la fin ; une Mare ou un Brasier se traverse sans y finir.
            if (! $grille->arretInterdit($nx, $ny)) {
                $finIndex = count($pas) - 1;
            }

            $cx = $nx;
            $cy = $ny;
        }

        if ($finIndex === null) {
            return null;
        }

        return ['pas' => array_slice($pas, 0, $finIndex + 1), 'fin' => $pas[$finIndex]];
    }

    /**
     * Les directions LÉGALES depuis (`$x`, `$y`), dans l'ordre fixe E, O, S, N. C'est la liste blanche du choix du héros.
     *
     * @return list<string>
     */
    public function directions(Grille $grille, int $x, int $y, int $cases): array
    {
        $legales = [];

        foreach (array_keys(self::DIRECTIONS) as $direction) {
            if ($this->poussee($grille, $x, $y, $direction, $cases) !== null) {
                $legales[] = $direction;
            }
        }

        return $legales;
    }

    /**
     * Le choix de ZARGON : parmi les directions légales, celle qui pousse le héros au plus près d'un monstre vivant. Égalité :
     * l'ordre fixe E, O, S, N. Aucune direction légale : null.
     *
     * @param  list<array{x: int, y: int}>  $monstres  les cases des monstres VIVANTS (actifs et posés)
     */
    public function directionDeZargon(Grille $grille, int $x, int $y, int $cases, array $monstres): ?string
    {
        $meilleure = null;
        $meilleureDistance = null;

        foreach ($this->directions($grille, $x, $y, $cases) as $direction) {
            if ($monstres === []) {
                return $direction; // sans monstre vivant : la première légale, dans l'ordre fixe
            }

            $fin = $this->poussee($grille, $x, $y, $direction, $cases)['fin'];
            $distance = min(array_map(
                fn (array $m) => abs($m['x'] - $fin['x']) + abs($m['y'] - $fin['y']),
                $monstres,
            ));

            // Strictement plus proche seulement : à égalité, la première direction de l'ordre fixe reste.
            if ($meilleureDistance === null || $distance < $meilleureDistance) {
                $meilleure = $direction;
                $meilleureDistance = $distance;
            }
        }

        return $meilleure;
    }
}
