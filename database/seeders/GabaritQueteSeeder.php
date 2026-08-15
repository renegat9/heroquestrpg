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
                    // Plancher à 5 salles : en dessous, l'arbre ne peut pas se
                    // replier assez pour offrir une paire de salles voisines mais
                    // non reliées — donc AUCUNE boucle, donc aucune porte secrète
                    // (0 % à 3 salles, 59 % à 4, 100 % à partir de 5).
                    'salles' => ['min' => 5, 'max' => 8],
                    'jalons' => ['entree', 'obstacle_median', 'salle_objectif'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2], // ex. passage discret vs frontal
                    ],
                    'budget_rencontres' => ['base' => 6, 'par_salle' => true],
                    // Deck de cartes de fouille (doc 14 §3.2) — remplace l'ancien
                    // tirage pondéré, qui remappait un d6 sur des poids et biaisait
                    // la distribution dès que leur total n'était pas 6. Ici la
                    // composition est GARANTIE : on pioche sans remise.
                    //
                    // Règle de dimensionnement : somme(cartes) > salles.max, sinon
                    // la dernière salle devient déductible (« il ne reste qu'une
                    // carte, c'est forcément le piège »). DeckFouille complète en
                    // « rien » si le compte est trop juste.
                    //
                    // `or_coffre` : ce que verse le coffre désigné quand aucune arme
                    // unique n'est disponible pour la quête.
                    'deck_fouille' => [
                        // Deck de trésor du JEU DE PLATEAU, à l'identique : 24 cartes
                        // piochées SANS REMISE. Les montants d'or sont ceux des cartes.
                        'cartes' => [
                            'gemme' => 2,          // 35 po
                            'or_25' => 2,
                            'or_15' => 2,
                            'bijoux' => 2,         // 50 po
                            'piege_trou' => 2,
                            'piege_fleches' => 2,
                            'potion_soin' => 3,
                            'potion_heroisme' => 1,
                            'potion_force' => 1,
                            'potion_defense' => 1,
                            'errant' => 6,
                        ],
                        'or' => 25,                // repli si un montant manque
                        'or_coffre' => 90,
                        'potions' => ['Potion de soin', 'Potion de soin mineur'],
                    ],
                    'pieges' => ['min' => 1, 'max' => 2],
                    'butin' => ['or_base' => 50],
                ],
            ],
            [
                'nom' => 'Antre du sous-boss',
                'type_jalon' => 'sous_boss',
                'structure' => [
                    'objectif' => 'vaincre_sous_boss',
                    'salles' => ['min' => 6, 'max' => 9],
                    'jalons' => ['entree', 'point_de_non_retour', 'antre'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2],
                        ['apres' => 'point_de_non_retour', 'branches' => 2], // affaiblir le boss vs y aller
                    ],
                    'budget_rencontres' => ['base' => 8, 'par_salle' => true],
                    'deck_fouille' => [
                        // Deck de trésor du JEU DE PLATEAU, à l'identique : 24 cartes
                        // piochées SANS REMISE. Les montants d'or sont ceux des cartes.
                        'cartes' => [
                            'gemme' => 2,          // 35 po
                            'or_25' => 2,
                            'or_15' => 2,
                            'bijoux' => 2,         // 50 po
                            'piege_trou' => 2,
                            'piege_fleches' => 2,
                            'potion_soin' => 3,
                            'potion_heroisme' => 1,
                            'potion_force' => 1,
                            'potion_defense' => 1,
                            'errant' => 6,
                        ],
                        'or' => 25,                // repli si un montant manque
                        'or_coffre' => 180,
                        'potions' => ['Potion de soin', 'Potion de restauration', 'Potion de bataille'],
                    ],
                    'rencontre_finale' => ['tier' => 'sous_boss', 'escorte_budget' => 4],
                    'pieges' => ['min' => 2, 'max' => 3],
                    'butin' => ['or_base' => 120],
                ],
            ],
            [
                'nom' => 'Confrontation finale',
                'type_jalon' => 'boss_final',
                'structure' => [
                    'objectif' => 'vaincre_boss_final',
                    'salles' => ['min' => 7, 'max' => 10],
                    'jalons' => ['entree', 'epreuve', 'antichambre', 'salle_du_trone'],
                    'points_decision' => [
                        ['apres' => 'entree', 'branches' => 2],
                        ['apres' => 'epreuve', 'branches' => 2],
                    ],
                    'budget_rencontres' => ['base' => 10, 'par_salle' => true],
                    'deck_fouille' => [
                        // Deck de trésor du JEU DE PLATEAU, à l'identique : 24 cartes
                        // piochées SANS REMISE. Les montants d'or sont ceux des cartes.
                        'cartes' => [
                            'gemme' => 2,          // 35 po
                            'or_25' => 2,
                            'or_15' => 2,
                            'bijoux' => 2,         // 50 po
                            'piege_trou' => 2,
                            'piege_fleches' => 2,
                            'potion_soin' => 3,
                            'potion_heroisme' => 1,
                            'potion_force' => 1,
                            'potion_defense' => 1,
                            'errant' => 6,
                        ],
                        'or' => 25,                // repli si un montant manque
                        'or_coffre' => 300,
                        'potions' => ['Potion de soin', 'Potion de bataille', 'Antidote au venin'],
                    ],
                    'rencontre_finale' => ['tier' => 'boss', 'escorte_budget' => 6],
                    'pieges' => ['min' => 2, 'max' => 4],
                    'butin' => ['or_base' => 300],
                ],
            ],
        ];

        // updateOrCreate (et non create) : les gabarits sont des données de
        // RÉFÉRENCE, re-semables sur une base vivante quand leur structure
        // évolue — comme ObjetSeeder. Le `create` d'origine échouait sur la
        // contrainte d'unicité du nom, laissant l'ancienne structure en place.
        foreach ($gabarits as $gabarit) {
            GabaritQuete::updateOrCreate(['nom' => $gabarit['nom']], $gabarit);
        }
    }
}
