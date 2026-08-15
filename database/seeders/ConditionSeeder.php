<?php

namespace Database\Seeders;

use App\Models\Condition;
use Illuminate\Database\Seeder;

/**
 * Catalogue d'états (doc 01 §10).
 * duree_defaut en tours ; 0 = jusqu'à une condition de fin (résistance, relève, réveil…).
 * type mental → les monstres Mind 0 y sont immunisés (logique moteur).
 */
class ConditionSeeder extends Seeder
{
    public function run(): void
    {
        $conditions = [
            ['nom' => 'Empoisonné', 'type' => 'physique', 'duree_defaut' => 3,
                'effet' => ['degats_pv_body_par_tour' => 1, 'resistance_possible' => 'Sang robuste']],
            ['nom' => 'Étourdi', 'type' => 'physique', 'duree_defaut' => 1,
                'effet' => ['perd_prochain_tour' => true]],
            ['nom' => 'Apeuré', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['malus_des_attaque' => 1, 'interdit_avancer_vers_menace' => true, 'fin' => 'jet_mind_reussi']],
            ['nom' => 'Endormi', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['hors_combat' => true, 'fin' => 'reveil_ou_attaque']],
            ['nom' => 'Commandé', 'type' => 'mental', 'duree_defaut' => 1,
                'effet' => ['controle_par_ennemi' => true]],
            ['nom' => 'Ralenti', 'type' => 'physique', 'duree_defaut' => 3,
                'effet' => ['malus_deplacement' => 2]],
            ['nom' => 'Immobilisé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['deplacement_interdit' => true, 'fin' => 'liberation']],
            ['nom' => 'Caché', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['inattaquable' => true, 'fin' => 'prochain_tour']],
            // Potion de vision (Elfe) : voir les pièges et les portes secrètes
            // en ligne de vue, « until the Elf suffers at least 1 Body Point of
            // damage ». La fin est portée par la `duree` de la potion
            // (`premier_degat_subi`), pas par un compteur de tours — d'où 0.
            ['nom' => 'Clairvoyance', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['revele_pieges_et_portes_en_vue' => true, 'fin' => 'premier_degat_subi']],
            ['nom' => 'Renforcé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['bonus_des' => 'attaque_ou_defense_selon_source', 'fin' => 'un_combat_ou_duree_du_sort']],
            ['nom' => 'Tombé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['hors_combat' => true, 'occupe_sa_case' => true, 'relevable' => true, 'fin' => 'releve_ou_fin_de_combat', 'mort_si_non_releve' => true]],
            // Venin (Jungles of Delthrak, p. 48) : « dégât = paralysie, jet de
            // 1 dé rouge pour résister sur 5-6, sinon jeton venin jusqu'à la
            // fin du tour suivant ». `deplacement_interdit` est CÂBLÉ depuis le
            // 2026-08-10 — MenuMoteur retire « Se déplacer », ResolveurTour
            // refuse le mouvement —, ce qui rend du même coup `Immobilisé`
            // réellement immobilisant.
            ['nom' => 'Envenimé', 'type' => 'physique', 'duree_defaut' => 1,
                'effet' => ['deplacement_interdit' => true, 'fin' => 'prochain_tour']],

            // Flamme hypnotique (répertoire elfique, © 2023) : « paralyzed for
            // 3 turns — unable to move, attack, or defend ». Les trois d'un
            // coup, et `defense_nulle` est une SUPPRESSION, pas un malus : la
            // figure ne lance aucun dé.
            ['nom' => 'Paralysé', 'type' => 'mental', 'duree_defaut' => 3,
                'effet' => ['deplacement_interdit' => true, 'action_interdite' => true,
                    'defense_nulle' => true, 'fin' => 'duree']],

            // Évanescence (répertoire elfique) : « The hero can only move and
            // open doors. They cannot attack, search, disarm, cast spells,
            // spring traps, or be affected by attacks or spells. »
            // ⚠ Le contraire de Paralysé sur le déplacement : il marche, mais
            // ne peut RIEN faire d'autre — et rien ne peut le toucher.
            ['nom' => 'Évanescent', 'type' => 'mental', 'duree_defaut' => 0,
                'effet' => ['action_interdite' => true, 'inattaquable' => true,
                    'ignore_pieges' => true, 'fin' => 'jet_de_deplacement_eleve']],
        ];

        foreach ($conditions as $condition) {
            Condition::updateOrCreate(['nom' => $condition['nom']], $condition);
        }
    }
}
