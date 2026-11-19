<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\Quete;

/**
 * LA POSE DES ALLIÉS RECRUTÉS au départ d'une quête (3.5) — point de passage UNIQUE, extrait tel quel de
 * `DemarreurQuete::demarrer()` le 2026-11-19 pour servir aussi la quête construite du mode classique (lot K6 : les
 * mercenaires y entrent, René : « ce sont des alliés, on les gère déjà »). Aucune règle n'a changé dans l'extraction.
 *
 * Un allié est posé sur les cases de départ restantes après les héros, sinon sur la case libre atteignable la plus
 * proche de son recruteur ; rien d'atteignable : il reste en réserve, hors plateau. PV remis au plein, état de tour et
 * position remis à zéro d'abord (René, 2026-11-13 : un allié survivant perdait son premier round, et sa position est
 * une case de l'ANCIENNE carte).
 */
final class PoseDesAllies
{
    /** @param  list<array{x: int, y: int}>  $spawn  les cases de départ de la carte ; `$slot` : la première libre */
    public function poser(Groupe $groupe, Quete $quete, array $spawn, int $slot): void
    {
        GroupeMercenaire::where('groupe_id', $groupe->id)->where('etat', 'actif')
            ->update(['a_joue' => false, 'a_deplace' => false, 'a_agi' => false, 'position_x' => null, 'position_y' => null]);

        foreach (GroupeMercenaire::where('groupe_id', $groupe->id)->where('etat', 'actif')
            ->with('mercenaire')->orderBy('id')->get() as $allie) {
            $case = $spawn[$slot] ?? null;

            if ($case !== null) {
                $slot++;
            } else {
                // Plus de case de départ (8 dans la salle de l'escalier) : la case libre ATTEIGNABLE la plus proche
                // de son héros (René, 2026-11-13) — jamais sur sa case (une case, une figure), jamais à travers un
                // mur. Rien d'atteignable : l'allié reste en réserve, hors plateau.
                $case = $this->caseLibrePresDuRecruteur($quete, $allie);
            }

            $allie->update([
                'pv_body' => (int) $allie->mercenaire->pv_body,
                'position_x' => $case['x'] ?? null,
                'position_y' => $case['y'] ?? null,
                'etat' => 'actif',
            ]);
        }
    }

    /**
     * La case libre la plus proche du héros qui a recruté l'allié — au sens du DÉPLACEMENT (`Grille::casesAtteignables()`,
     * la grille réelle : figures, meubles, portes closes), donc jamais de l'autre côté d'un mur. À coût égal, la plus
     * haute puis la plus à gauche (ordre stable). Sans recruteur en jeu, depuis la première case de départ. `null` si rien
     * n'est atteignable dans un rayon de 12 points.
     *
     * @return array{x: int, y: int}|null
     */
    public function caseLibrePresDuRecruteur(Quete $quete, GroupeMercenaire $allie): ?array
    {
        $quete->unsetRelation('carte');
        $etat = $quete->etatsPersonnages()->where('personnage_id', $allie->recruteur_personnage_id)->whereNotNull('position_x')->first()
            ?? $quete->etatsPersonnages()->whereNotNull('position_x')->orderBy('id')->first();

        if ($etat === null) {
            return null;
        }

        $grille = FabriqueGrille::pour($quete);
        $meilleure = null;

        foreach ($grille->casesAtteignables((int) $etat->position_x, (int) $etat->position_y, 12) as $cle => $chemin) {
            [$x, $y] = array_map(intval(...), explode(',', (string) $cle));
            $rang = [$grille->coutChemin($chemin), $y, $x];

            if ($meilleure === null || $rang < $meilleure[0]) {
                $meilleure = [$rang, ['x' => $x, 'y' => $y]];
            }
        }

        return $meilleure[1] ?? null;
    }
}
