<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use App\Partie\FabriqueGrille;

/**
 * LES PASSAGES D'UN PAS — lot E2, *First Light* q. 8 D (FL-Q p. 27) : « A secret door opens in the middle of the bookcase. Heroes
 * may move from a shaded square in one room to the opposite shaded square in the other. This uses one square of their movement
 * roll. There is no line of sight from one room to the next. »
 *
 * `regles.passages: [{a: {x, y}, b: {x, y}, si_drapeau?}]` — deux cases non voisines ; le mur entre elles reste un mur (ni vue ni
 * contact). Le héros debout sur un bout, avec encore un pas de déplacement, a l'option `passage_{k}` (mouvement) : il paraît sur
 * l'autre bout s'il est libre, un pas compté (`ResolveurTour::resoudrePassageDUnPas()`, sur le modèle de l'entrée au donjon).
 * `si_drapeau` : le passage n'existe qu'une fois le drapeau posé (la note qui ouvre la bibliothèque).
 */
final class PassagesDUnPas
{
    /** @return list<array{k: int, de: array{x: int, y: int}, vers: array{x: int, y: int}}> les passages que ce héros peut prendre */
    public function offerts(Quete $quete, EtatPersonnageQuete $etat): array
    {
        if (! $quete->estConstruite() || $etat->tombe || $etat->position_x === null || $etat->a_deplace) {
            return [];
        }
        $ici = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
        $grille = null;
        $offerts = [];
        foreach ((array) data_get($quete->documentConstruit(), 'regles.passages', []) as $k => $p) {
            if (isset($p['si_drapeau']) && empty(data_get($quete->drapeaux, (string) $p['si_drapeau']))) {
                continue;
            }
            $a = ['x' => (int) $p['a']['x'], 'y' => (int) $p['a']['y']];
            $b = ['x' => (int) $p['b']['x'], 'y' => (int) $p['b']['y']];
            $vers = $ici == $a ? $b : ($ici == $b ? $a : null);
            if ($vers === null) {
                continue;
            }
            $grille ??= FabriqueGrille::pour($quete, exceptPersonnageId: (int) $etat->personnage_id);
            if ($grille->estTraversable($vers['x'], $vers['y']) && ! $grille->arretInterdit($vers['x'], $vers['y'])) {
                $offerts[] = ['k' => (int) $k, 'de' => $ici, 'vers' => $vers];
            }
        }

        return $offerts;
    }
}
