<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use Illuminate\Support\Collection;

/**
 * LA GÉOMÉTRIE DES SORTS DE ZONE — UN seul calcul des cases, lu par les sorts du MJ (`MoteurDread`) ET par les sorts de
 * HÉROS (`MoteurSorts::figuresDeZone()` : Morsure du Froid, puis Tempête de Glace). Deux copies de « les quatre cases
 * orthogonales » ou de « le carré 2×2 » auraient divergé : le Dread les avait déjà écrites une fois, en privé.
 *
 * Ce fichier ne sait RIEN de qui frappe ni de qui est debout : il rend des cases, et filtre des figures déjà choisies
 * par l'appelant. Les listes d'entrée (monstres actifs et révélés, héros debout) restent celles de chaque appelant :
 * le Dread ne demande pas `revele`, et l'exiger ici changerait une règle qu'il a figée.
 *
 * Plan `docs/plan-parchemins-glace.md`, lot 1 (2026-11-13).
 */
final class ZonesDeSort
{
    /**
     * Les quatre cases ORTHOGONALES de (x, y) — jamais les diagonales (« adjacent to the spellcaster (though NOT
     * DIAGONALLY adjacent) », *Chill*). L'ordre (+x, −x, +y, −y) est celui que le Dread publie en `cases_affectees` :
     * il ne bouge pas.
     *
     * @return list<array{x: int, y: int}>
     */
    public static function contact(int $x, int $y): array
    {
        return [
            ['x' => $x + 1, 'y' => $y], ['x' => $x - 1, 'y' => $y],
            ['x' => $x, 'y' => $y + 1], ['x' => $x, 'y' => $y - 1],
        ];
    }

    /**
     * Le carré 2×2 dont (x, y) est le coin haut-gauche (*Ice Storm* : « an area 2 squares wide by 2 squares long »).
     *
     * @return list<array{x: int, y: int}>
     */
    public static function carre(int $x, int $y): array
    {
        return [
            ['x' => $x, 'y' => $y], ['x' => $x + 1, 'y' => $y],
            ['x' => $x, 'y' => $y + 1], ['x' => $x + 1, 'y' => $y + 1],
        ];
    }

    /**
     * L'index de la SALLE qui contient TOUTES ces cases, ou `null` : un couloir, une case hors de toute salle, ou des cases
     * réparties sur deux salles (un carré à cheval sur un mur). C'est « tient entièrement dans la salle du lanceur » — la
     * règle de la Tempête de Glace côté héros. Aucune case ⇒ `null`.
     *
     * @param  list<array{x: int, y: int}>  $cases
     * @param  array<int|string, array{x: int|string, y: int|string, largeur: int|string, hauteur: int|string}>  $salles
     */
    public static function salleCommune(array $cases, array $salles): ?int
    {
        $salle = null;

        foreach ($cases as $case) {
            $index = Salles::indexDe($salles, (int) $case['x'], (int) $case['y']);

            if ($index === null || ($salle !== null && $index !== $salle)) {
                return null;
            }

            $salle = $index;
        }

        return $salle;
    }

    /**
     * Les héros de `$heros` dont la case est dans `$cases`. Un héros sans position n'est jamais dans une zone.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $heros
     * @param  list<array{x: int, y: int}>  $cases
     * @return Collection<int, EtatPersonnageQuete>
     */
    public static function herosSur(Collection $heros, array $cases): Collection
    {
        $index = self::index($cases);

        return $heros
            ->filter(fn (EtatPersonnageQuete $h) => $h->position_x !== null
                && isset($index[(int) $h->position_x.':'.(int) $h->position_y]))
            ->values();
    }

    /**
     * Les figures dont UNE case est dans `$cases`. L'emprise compte entière (`InstanceMonstre::cases()`) : une figure de
     * deux cases, ou de seize, est dans la zone dès que l'une d'elles y est — l'ancre seule laissait hors de la zone un
     * Bloomrot Scion dont seule la tête y était (2026-11-05).
     *
     * @param  Collection<int, InstanceMonstre>  $monstres
     * @param  list<array{x: int, y: int}>  $cases
     * @return Collection<int, InstanceMonstre>
     */
    public static function monstresSur(Collection $monstres, array $cases): Collection
    {
        $index = self::index($cases);

        return $monstres
            ->filter(fn (InstanceMonstre $m) => $m->position_x !== null
                && collect($m->cases())->contains(fn (array $c) => isset($index[$c['x'].':'.$c['y']])))
            ->values();
    }

    /**
     * @param  list<array{x: int, y: int}>  $cases
     * @return array<string, true>
     */
    private static function index(array $cases): array
    {
        $index = [];

        foreach ($cases as $case) {
            $index[$case['x'].':'.$case['y']] = true;
        }

        return $index;
    }
}
