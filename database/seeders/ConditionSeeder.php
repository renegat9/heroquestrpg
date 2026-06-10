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
                'effet' => ['inattaquable' => true, 'fin' => 'son_prochain_tour']],
            ['nom' => 'Renforcé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['bonus_des' => 'attaque_ou_defense_selon_source', 'fin' => 'un_combat_ou_duree_du_sort']],
            ['nom' => 'Tombé', 'type' => 'physique', 'duree_defaut' => 0,
                'effet' => ['hors_combat' => true, 'occupe_sa_case' => true, 'relevable' => true, 'fin' => 'releve_ou_fin_de_combat', 'mort_si_non_releve' => true]],
        ];

        foreach ($conditions as $condition) {
            Condition::updateOrCreate(['nom' => $condition['nom']], $condition);
        }
    }
}
