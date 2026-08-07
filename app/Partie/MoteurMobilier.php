<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Carte;
use App\Models\Mobilier;

/**
 * Fouille du MOBILIER de salle (doc 17).
 *
 * Un coffre, un tombeau, une armoire posés sur la carte sont des objets qu'on
 * ouvre — pas du décor (décision de René, 2026-08-07). Le drapeau
 * `mobiliers.fouillable` existait depuis la création de la table sans aucun
 * lecteur : sept types sur huit le portent, et aucun ne s'ouvrait. Un joueur
 * voyait un coffre au milieu de la salle et ne pouvait rien en faire.
 *
 * Un meuble se fouille **une seule fois pour tout le groupe** — c'est un objet
 * physique, pas une table de trésor : le premier qui l'ouvre le vide. C'est la
 * différence avec la fouille de SALLE, qui est une par héros (chacun cherche
 * dans son coin). L'état vit dans la grille de la carte (`mobilier[i].fouille`),
 * comme celui des portes, donc il survit aux snapshots sans nouvelle colonne.
 *
 * Le mobilier bloquant le passage, on le fouille depuis une case ADJACENTE :
 * on ne peut pas se tenir dessus.
 */
final class MoteurMobilier
{
    /**
     * Meubles fouillables, non encore fouillés, orthogonalement adjacents à
     * (x, y) — index dans la grille + entrée + libellé du catalogue.
     *
     * @return list<array{index: int, entree: array<string, mixed>, nom: string}>
     */
    public function fouillablesAdjacents(Carte $carte, int $x, int $y): array
    {
        $entrees = (array) ($carte->grille['mobilier'] ?? []);

        if ($entrees === []) {
            return [];
        }

        $catalogue = Mobilier::query()
            ->whereIn('id', collect($entrees)->pluck('mobilier_id')->filter()->unique())
            ->get(['id', 'nom', 'fouillable'])
            ->keyBy('id');

        $trouves = [];

        foreach ($entrees as $index => $entree) {
            $type = $catalogue[$entree['mobilier_id'] ?? 0] ?? null;

            if ($type === null || ! $type->fouillable || ! empty($entree['fouille'])) {
                continue;
            }

            if ($this->adjacentAEmprise($entree, $x, $y)) {
                $trouves[] = ['index' => (int) $index, 'entree' => $entree, 'nom' => (string) $type->nom];
            }
        }

        return $trouves;
    }

    /** Marque le meuble comme fouillé — définitif, pour tout le groupe. */
    public function marquerFouille(Carte $carte, int $index): void
    {
        $grille = $carte->grille;

        if (! isset($grille['mobilier'][$index])) {
            return;
        }

        $grille['mobilier'][$index]['fouille'] = true;
        $carte->update(['grille' => $grille]);
    }

    /**
     * (x, y) touche-t-il l'EMPRISE du meuble par un côté ?
     *
     * L'emprise compte, pas l'origine : un tombeau 1×2 se fouille depuis l'une
     * ou l'autre de ses deux cases voisines, sans quoi la moitié d'un grand
     * meuble serait inatteignable.
     *
     * @param  array<string, mixed>  $entree
     */
    private function adjacentAEmprise(array $entree, int $x, int $y): bool
    {
        $ox = (int) $entree['x'];
        $oy = (int) $entree['y'];

        for ($dx = 0; $dx < (int) ($entree['l'] ?? 1); $dx++) {
            for ($dy = 0; $dy < (int) ($entree['h'] ?? 1); $dy++) {
                if (abs($ox + $dx - $x) + abs($oy + $dy - $y) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
