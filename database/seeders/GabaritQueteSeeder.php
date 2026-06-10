<?php

namespace Database\Seeders;

use App\Models\GabaritQuete;
use Illuminate\Database\Seeder;

/**
 * Gabarits de quête de départ (doc 06 §2) : la structure est garantie par le
 * gabarit, le MJ IA remplit (narration, PNJ, habillage), le moteur assemble
 * le contenu mécanique au budget de rencontres (budget en points de `cout`
 * du bestiaire, multiplié par le score de puissance du groupe — doc 06 §2).
 */
class GabaritQueteSeeder extends Seeder
{
    public function run(): void
    {
        $gabarits = [
            [
                'nom' => 'Exploration simple',
                'type_jalon' => 'normale',
                'structure' => [
                    'objectif' => 'atteindre_et_recuperer', // objet/PNJ au fond du donjon
                    'salles' => ['min' => 3, 'max' => 5],
                    'jalons' => ['entree', 'obstacle_median', 'salle_objectif'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2], // ex. passage discret vs frontal
                    ],
                    'budget_rencontres' => ['base' => 6, 'par_salle' => true],
                    'pieges' => ['min' => 1, 'max' => 2],
                    'butin' => ['or_base' => 50, 'objets_rares_max' => 1],
                ],
            ],
            [
                'nom' => 'Antre du sous-boss',
                'type_jalon' => 'sous_boss',
                'structure' => [
                    'objectif' => 'vaincre_sous_boss',
                    'salles' => ['min' => 4, 'max' => 6],
                    'jalons' => ['entree', 'point_de_non_retour', 'antre'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2],
                        ['apres' => 'point_de_non_retour', 'branches' => 2], // affaiblir le boss vs y aller
                    ],
                    'budget_rencontres' => ['base' => 8, 'par_salle' => true],
                    'rencontre_finale' => ['tier' => 'sous_boss', 'escorte_budget' => 4],
                    'pieges' => ['min' => 2, 'max' => 3],
                    'butin' => ['or_base' => 120, 'objets_rares_max' => 2],
                ],
            ],
            [
                'nom' => 'Confrontation finale',
                'type_jalon' => 'boss_final',
                'structure' => [
                    'objectif' => 'vaincre_boss_final',
                    'salles' => ['min' => 5, 'max' => 7],
                    'jalons' => ['entree', 'epreuve', 'antichambre', 'salle_du_trone'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2],
                        ['apres' => 'epreuve', 'branches' => 2],
                    ],
                    'budget_rencontres' => ['base' => 10, 'par_salle' => true],
                    'rencontre_finale' => ['tier' => 'boss', 'escorte_budget' => 6],
                    'pieges' => ['min' => 2, 'max' => 4],
                    'butin' => ['or_base' => 300, 'objets_rares_max' => 3],
                ],
            ],
        ];

        foreach ($gabarits as $gabarit) {
            GabaritQuete::create($gabarit);
        }
    }
}
