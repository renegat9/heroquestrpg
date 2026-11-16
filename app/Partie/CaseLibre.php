<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * OÙ POSER une figure qui surgit près d'une case — le seul calcul de « la case libre la plus proche ».
 *
 * Sorti de `ResolveurTour::caseLibreProche()` (errant, mort-vivant du tombeau) le 2026-11-15, quand les notes de quête
 * du mode classique ont eu besoin de la même réponse pour une apparition dont la case est prise : deux copies d'une
 * règle trop simple pour qu'on la voie dériver, c'est le défaut le plus répété de ce projet.
 *
 * 1. Les voisines : les HUIT (orthogonales et diagonales — la carte d'errant dit « adjacent OR DIAGONAL »), ou les
 *    quatre orthogonales si `$orthogonal`.
 * 2. Repli, quand toutes sont prises : l'anneau Manhattan croissant, rayon 2 puis 3.
 *
 * ⚠ **JAMAIS DE L'AUTRE CÔTÉ D'UN MUR** (2026-11-15). Une candidate doit être JOIGNABLE depuis la case d'origine en
 * trois pas au plus, sans traverser ni mur, ni porte close, ni roche — l'occupation, elle, est ignorée pour ce
 * parcours (on contourne par-dessus un meuble ou une figure : c'est la case d'arrivée qui doit être libre). Vu en jeu
 * sur une carte à murs en arêtes : une apparition dont la case était prise naissait dans le COULOIR, derrière le mur
 * de la salle — la voisine « x + 1 » y est du sol, puisqu'un mur n'y occupe plus de case. Sur une carte à murs épais
 * le défaut existait aussi, plus rarement : l'anneau de rayon 2 enjambait un mur d'une case.
 *
 * `$grille` vient de `FabriqueGrille::pour()` : une case n'y est traversable que si rien ne l'occupe.
 */
final class CaseLibre
{
    private const PAS_MAX = 3;

    /** @return array{x: int, y: int}|null */
    public static function proche(Grille $grille, int $x, int $y, bool $orthogonal = false): ?array
    {
        $joignables = self::joignables($grille, $x, $y);
        $libre = static fn (int $cx, int $cy): bool => isset($joignables["{$cx},{$cy}"]) && $grille->estTraversable($cx, $cy);

        $voisines = $orthogonal
            ? [[1, 0], [-1, 0], [0, 1], [0, -1]]
            : [[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [1, -1], [-1, 1], [-1, -1]];

        foreach ($voisines as [$dx, $dy]) {
            if ($libre($x + $dx, $y + $dy)) {
                return ['x' => $x + $dx, 'y' => $y + $dy];
            }
        }

        for ($rayon = 2; $rayon <= self::PAS_MAX; $rayon++) {
            for ($dx = -$rayon; $dx <= $rayon; $dx++) {
                $dy = $rayon - abs($dx);
                foreach (array_unique([$dy, -$dy]) as $signeY) {
                    if ($libre($x + $dx, $y + $signeY)) {
                        return ['x' => $x + $dx, 'y' => $y + $signeY];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Les cases qu'on joint depuis (x, y) en `PAS_MAX` pas orthogonaux au plus, sans traverser un mur, une porte close
     * (`Grille::areteBloquee()`) ni de la roche. L'occupation est ignorée : c'est un test de « même espace ».
     *
     * @return array<string, true> clé « x,y »
     */
    private static function joignables(Grille $grille, int $x, int $y): array
    {
        $pas = ["{$x},{$y}" => 0];
        $file = [[$x, $y]];

        while ($file !== []) {
            [$cx, $cy] = array_shift($file);
            $distance = $pas["{$cx},{$cy}"];

            if ($distance >= self::PAS_MAX) {
                continue;
            }

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $cx + $dx;
                $ny = $cy + $dy;

                if (isset($pas["{$nx},{$ny}"]) || $grille->estRoche($nx, $ny) || $grille->areteBloquee($cx, $cy, $nx, $ny)) {
                    continue;
                }

                $pas["{$nx},{$ny}"] = $distance + 1;
                $file[] = [$nx, $ny];
            }
        }

        return array_fill_keys(array_keys($pas), true);
    }

    /** La case elle-même si rien ne l'occupe, sinon la plus proche ({@see proche()}). */
    public static function iciOuProche(Grille $grille, int $x, int $y): ?array
    {
        return $grille->estTraversable($x, $y) ? ['x' => $x, 'y' => $y] : self::proche($grille, $x, $y);
    }
}
