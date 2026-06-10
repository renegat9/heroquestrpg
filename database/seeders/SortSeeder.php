<?php

namespace Database\Seeders;

use App\Models\Sort;
use Illuminate\Database\Seeder;

/**
 * Les 12 sorts héros (doc 02 §7) — 4 éléments × 3 sorts.
 * difficulte_parchemin = succès de Mind requis pour un non-lanceur (S1).
 */
class SortSeeder extends Seeder
{
    public function run(): void
    {
        $sorts = [
            // Feu — offensif
            ['element' => 'feu', 'nom' => 'Boule de Feu', 'type' => 'degats', 'difficulte_parchemin' => 3,
                'effet' => ['portee' => 'distance', 'des_degats' => 2, 'defense_applicable' => true]],
            ['element' => 'feu', 'nom' => 'Courage', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'bonus_des_attaque' => 2, 'duree' => 'prochaine_attaque', 'condition_appliquee' => 'Renforcé']],
            ['element' => 'feu', 'nom' => 'Trait de Feu', 'type' => 'degats', 'difficulte_parchemin' => 1,
                'effet' => ['portee' => 'distance', 'des_degats' => 1, 'defense_applicable' => true]],

            // Eau — contrôle / soin
            ['element' => 'eau', 'nom' => 'Sommeil', 'type' => 'mental', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'monstre', 'resistance' => 'jet_mind', 'condition_appliquee' => 'Endormi', 'fin' => 'reveil_ou_attaque']],
            ['element' => 'eau', 'nom' => 'Voile de Brume', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'condition_appliquee' => 'Caché', 'duree' => 'jusqu_au_prochain_tour']],
            ['element' => 'eau', 'nom' => 'Eau de Guérison', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'soin_pv_body' => 4]],

            // Terre — défense / soin
            ['element' => 'terre', 'nom' => 'Soin du Corps', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros_ou_soi', 'soin_pv_body' => 4]],
            ['element' => 'terre', 'nom' => 'Traverser la Pierre', 'type' => 'utilitaire', 'difficulte_parchemin' => 1,
                'effet' => ['cible' => 'soi', 'franchit_mur' => true, 'cout' => 'deplacement_du_tour']],
            ['element' => 'terre', 'nom' => 'Peau de Pierre', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'bonus_des_defense' => 2, 'duree' => 'fin_du_combat', 'condition_appliquee' => 'Renforcé']],

            // Air — mobilité / puissance
            ['element' => 'air', 'nom' => 'Génie', 'type' => 'degats', 'difficulte_parchemin' => 3,
                'effet' => ['portee' => 'distance', 'des_degats' => 5, 'defense_applicable' => true, 'invocation_ephemere' => true]],
            ['element' => 'air', 'nom' => 'Vent Véloce', 'type' => 'utilitaire', 'difficulte_parchemin' => 1,
                'effet' => ['cible' => 'heros', 'deplacement_multiplie' => 2, 'duree' => 'ce_tour']],
            ['element' => 'air', 'nom' => 'Tempête', 'type' => 'mental', 'difficulte_parchemin' => 3,
                'effet' => ['cible' => 'monstres_zone', 'resistance' => 'jet_mind', 'empeche_attaque' => true, 'duree' => 'prochain_tour']],
        ];

        foreach ($sorts as $sort) {
            Sort::updateOrCreate(['nom' => $sort['nom']], $sort);
        }
    }
}
