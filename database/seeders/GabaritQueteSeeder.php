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
                    // ÉPREUVES (2026-08-24) : les ancrages à jet d'attribut. Sans
                    // elles, le moteur n'émet qu'un seul jet — la fouille de zone —
                    // et les contextes `savoir`/`social_peur` n'ont aucun producteur.
                    'epreuves' => ['min' => 1, 'max' => 2],
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
                    // ⚠ POOL de sorciers nommés, tiré au sort à chaque quête
                    // (René, 2026-09-04). Le champ existait depuis la 3.8 et
                    // n'avait JAMAIS été rempli : le repli prenait le leader de
                    // coût du palier, si bien qu'aucun lanceur nommé n'était
                    // jamais apparu en partie. Une liste plutôt qu'une valeur
                    // unique, sinon toutes les campagnes finiraient sur le même
                    // adversaire — le défaut du pool de salles, un cran plus
                    // haut.
                    //
                    // Les deux sous-boss lanceurs du bestiaire : le Chamane
                    // Gobelin (répertoire orque — commande, terrifie, endort) et
                    // le Garde-mage (Boule de Flammes, Tourmente).
                    'rencontre_finale' => [
                        'tier' => 'sous_boss',
                        'escorte_budget' => 4,
                        'archetypes' => ['chaman_orque', 'garde_magus'],
                    ],
                    'pieges' => ['min' => 2, 'max' => 3],
                    'epreuves' => ['min' => 1, 'max' => 2],
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
                    // ⚠ Les CINQ bosses lanceurs, tirés au sort (même raison
                    // qu'au jalon sous-boss). Chacun impose une lecture
                    // différente du combat final : la Liche relève les morts, le
                    // Sorcier des Tempêtes embrase et se dérobe, l'Ombre du
                    // Dread marque et appelle des spectres, l'Horreur des Glaces
                    // gèle et se soigne, l'Archimage elfe lâche ses loups.
                    //
                    // ⚠ Le **Seigneur** est dans la rotation (René, 2026-09-04)
                    // et n'en est plus le titulaire perpétuel : il fermait
                    // TOUTES les quêtes tant qu'aucun gabarit ne nommait
                    // personne. Il reste le plus cher des six (20), donc la
                    // quête qui tombe sur lui achète un peu MOINS de sbires —
                    // c'est ce que `cout` sert à dire, et c'est la seule chose
                    // qu'il pilote (budget de rencontre, `DemarreurQuete`).
                    'rencontre_finale' => [
                        'tier' => 'boss',
                        'escorte_budget' => 6,
                        'archetypes' => [
                            'seigneur_du_chaos', 'necromancien', 'maitre_tempetes',
                            'spectre_effroi', 'horreur_glacee', 'archimage_elfe',
                        ],
                    ],
                    'pieges' => ['min' => 2, 'max' => 4],
                    'epreuves' => ['min' => 1, 'max' => 2],
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
