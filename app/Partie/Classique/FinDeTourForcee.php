<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;

/**
 * « THEIR TURN IMMEDIATELY ENDS », demandé hors du résolveur (lot E2). Une règle de livret qui joue PENDANT une action — une
 * note de quête (`Rejetons`), un pas sur le pont de corde (`PontDeCorde`) — demande la fin du tour du héros par un marqueur
 * de `capacites_tour` ; `ResolveurTour::resoudre()` le lit avec ses propres fins de tour forcées (glace, piège de sol,
 * sables, porte forcée) : le créneau devient `tour`, toute la cérémonie de fin de tour se joue. Un marqueur en base, pas un
 * drapeau d'instance : la demande vient d'un autre service que le résolveur. La remise à zéro du round l'efface.
 */
final class FinDeTourForcee
{
    public const MARQUEUR = 'fin_de_tour_forcee';

    public static function demander(EtatPersonnageQuete $etat): void
    {
        $etat->update(['capacites_tour' => array_values(array_unique([...(array) ($etat->capacites_tour ?? []), self::MARQUEUR]))]);
    }

    public static function demandee(?EtatPersonnageQuete $etat): bool
    {
        return $etat !== null && in_array(self::MARQUEUR, (array) ($etat->capacites_tour ?? []), true);
    }
}
