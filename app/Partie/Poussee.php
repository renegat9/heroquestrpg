<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\InstanceMonstre;
use App\Models\Quete;

/**
 * REPOUSSER — la case de recul d'une figure, point de passage UNIQUE (2026-10-17).
 *
 * Le menu (`MenuMoteur::ciblesRepoussables()`) et le résolveur
 * (`ResolveurTour::resoudrePoussee()`) lisent ce prédicat. Avant cette date le
 * résolveur ne le vérifiait pas du tout : un jet réussi déplaçait la figure sur
 * une case occupée ou hors plateau, dès que l'état avait bougé entre l'affichage
 * du menu et le geste.
 */
final class Poussee
{
    /**
     * La case de recul — le prolongement de l'axe héros → monstre — est-elle libre
     * pour TOUTE l'emprise de la figure ? Une gargouille 2×2 a besoin de quatre
     * cases, et proposer une poussée que le résolveur refusera est exactement ce
     * que le projet interdit.
     */
    public static function reculLibre(Quete $quete, InstanceMonstre $instance, int $px, int $py): bool
    {
        $ix = (int) $instance->position_x;
        $iy = (int) $instance->position_y;
        $emprise = $instance->monstre->emprise();
        $grille = FabriqueGrille::pour($quete, exceptInstanceId: (int) $instance->id);

        foreach ($grille->cellulesEmprise(
            $ix + ($ix - $px),
            $iy + ($iy - $py),
            (int) $emprise['l'],
            (int) $emprise['h'],
        ) as $cellule) {
            if (! $grille->estTraversable((int) $cellule['x'], (int) $cellule['y'])) {
                return false;
            }
        }

        return true;
    }
}
