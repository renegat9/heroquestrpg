<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Carte;

/**
 * Vue tactique de la carte assemblée : traversabilité et plus courts chemins
 * ORTHOGONAUX (doc 03 §12 — pas de diagonale) par parcours en largeur (BFS).
 *
 * Les cases occupées (héros — y compris tombés, C4 : ils occupent leur case —
 * et monstres actifs) sont infranchissables ; le désengagement reste libre
 * (C3 : aucune attaque d'opportunité).
 */
final class Grille
{
    /** Ordre d'exploration fixe → comportements scriptés déterministes. */
    private const DIRECTIONS = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    /** @var array<string, true> */
    private array $occupees = [];

    /**
     * @param  list<list<string>>  $cases  m = mur, s = sol, p = porte
     */
    public function __construct(private readonly array $cases) {}

    public static function depuisCarte(Carte $carte): self
    {
        return new self($carte->grille['cases'] ?? []);
    }

    /**
     * @param  list<array{x: int, y: int}>  $positions
     */
    public function occuper(array $positions): void
    {
        foreach ($positions as $position) {
            $this->occupees["{$position['x']},{$position['y']}"] = true;
        }
    }

    public function estTraversable(int $x, int $y): bool
    {
        $case = $this->cases[$y][$x] ?? 'm';

        return ($case === 's' || $case === 'p') && ! isset($this->occupees["{$x},{$y}"]);
    }

    public function sontAdjacentes(int $x1, int $y1, int $x2, int $y2): bool
    {
        return abs($x1 - $x2) + abs($y1 - $y2) === 1;
    }

    /**
     * Plus court chemin orthogonal (cases occupées exclues, départ inclus
     * d'office) ; null si l'arrivée est inaccessible.
     *
     * @return list<array{x: int, y: int}>|null étapes SANS la case de départ
     */
    public function chemin(int $departX, int $departY, int $arriveeX, int $arriveeY): ?array
    {
        if ($departX === $arriveeX && $departY === $arriveeY) {
            return [];
        }

        $parents = $this->parcours($departX, $departY, $arriveeX, $arriveeY);
        $cle = "{$arriveeX},{$arriveeY}";

        if (! isset($parents[$cle])) {
            return null;
        }

        $chemin = [];
        while ($cle !== "{$departX},{$departY}") {
            [$x, $y] = array_map(intval(...), explode(',', $cle));
            $chemin[] = ['x' => $x, 'y' => $y];
            $cle = $parents[$cle];
        }

        return array_reverse($chemin);
    }

    /**
     * Distance de déplacement (nb de pas orthogonaux) ; null si inaccessible.
     */
    public function distance(int $departX, int $departY, int $arriveeX, int $arriveeY): ?int
    {
        $chemin = $this->chemin($departX, $departY, $arriveeX, $arriveeY);

        return $chemin === null ? null : count($chemin);
    }

    /**
     * BFS depuis le départ ; s'arrête dès que l'arrivée est atteinte.
     *
     * @return array<string, string> clé case → clé case parente
     */
    private function parcours(int $departX, int $departY, int $arriveeX, int $arriveeY): array
    {
        $depart = "{$departX},{$departY}";
        $file = [[$departX, $departY]];
        $parents = [];
        $vus = [$depart => true];

        while ($file !== []) {
            [$x, $y] = array_shift($file);

            foreach (self::DIRECTIONS as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                $cle = "{$nx},{$ny}";

                if (isset($vus[$cle]) || ! $this->estTraversable($nx, $ny)) {
                    continue;
                }

                $vus[$cle] = true;
                $parents[$cle] = "{$x},{$y}";

                if ($nx === $arriveeX && $ny === $arriveeY) {
                    return $parents;
                }

                $file[] = [$nx, $ny];
            }
        }

        return $parents;
    }
}
