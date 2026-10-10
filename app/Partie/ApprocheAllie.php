<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * « Approcher X » — où un ALLIÉ peut-il aller au contact d'un monstre ?
 *
 * POINT DE PASSAGE UNIQUE de ce calcul, lu à la fois par le MENU
 * ({@see MenuMoteur::genererMenuAllie()}, qui n'offre une destination que si
 * elle aboutit) et par le RÉSOLVEUR ({@see ResolveurTour::resoudreDeplacementDAllie()},
 * qui refuse et exécute exactement ce trajet). Deux copies auraient dérivé :
 * l'option « Approcher » a déjà promis une case que le déplacement ne pouvait
 * pas tenir — Morcar, 2026-10-09 : la seule case libre au contact était prise,
 * le Raptor s'arrêtait sans attaquer, et l'option revenait à l'identique.
 *
 * Une destination n'existe que si elle ABOUTIT :
 *  - elle est au CONTACT orthogonal du monstre, et l'on peut S'Y ARRÊTER
 *    (`Grille::arretInterdit()` faux : ni figure, ni terrain qui l'interdit) —
 *    traverser une case occupée est permis, s'y tenir ne l'est pas ;
 *  - elle est atteignable par un chemin ;
 *  - et l'allié AVANCE réellement : le budget de déplacement et les cases où
 *    l'arrêt est interdit laissent au moins un pas. Un allié déjà au contact
 *    n'a rien à « approcher » (trajet vide, donc aucun pas).
 */
final class ApprocheAllie
{
    /**
     * Le trajet que l'allié parcourt pour approcher le monstre de l'emprise
     * `(monstreX, monstreY, l, h)`, ou `null` si aucune destination n'aboutit.
     *
     * @param  Grille  $grille  la grille de DÉPLACEMENT de l'allié (franchit les alliés, ne s'arrête pas sur eux)
     * @return array{chemin: list<array{x: int, y: int}>, arrivee: array{x: int, y: int}}|null
     */
    public function trajet(
        Grille $grille,
        int $departX,
        int $departY,
        int $budget,
        int $monstreX,
        int $monstreY,
        int $l,
        int $h,
    ): ?array {
        $meilleur = null;

        foreach ($grille->cellulesEmprise($monstreX, $monstreY, $l, $h) as $cellule) {
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $x = $cellule['x'] + $dx;
                $y = $cellule['y'] + $dy;

                // Une case qu'on ne peut pas TENIR n'est jamais une destination,
                // même si l'allié peut la traverser (cf. la Mare, le Brasier, un
                // compagnon à côté duquel on passe).
                if ($grille->arretInterdit($x, $y)) {
                    continue;
                }

                $chemin = $grille->chemin($departX, $departY, $x, $y);

                if ($chemin !== null && ($meilleur === null || count($chemin) < count($meilleur))) {
                    $meilleur = $chemin;
                }
            }
        }

        if ($meilleur === null) {
            return null;
        }

        // Traverser n'est pas s'arrêter : on recule jusqu'à la dernière case
        // LIBRE du trajet payable (une Mare ou un Brasier au bout du budget).
        $pas = $grille->pasAffordables($meilleur, $budget);

        while ($pas > 0 && $grille->arretInterdit((int) $meilleur[$pas - 1]['x'], (int) $meilleur[$pas - 1]['y'])) {
            $pas--;
        }

        if ($pas === 0) {
            return null;
        }

        return [
            'chemin' => $meilleur,
            'arrivee' => [
                'x' => (int) $meilleur[$pas - 1]['x'],
                'y' => (int) $meilleur[$pas - 1]['y'],
            ],
        ];
    }
}
