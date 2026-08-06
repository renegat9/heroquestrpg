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
                'effet' => ['cible' => 'heros', 'condition_appliquee' => 'Caché', 'duree' => 'prochain_tour']],
            ['element' => 'eau', 'nom' => 'Eau de Guérison', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros', 'soin_pv_body' => 4]],

            // Terre — défense / soin
            ['element' => 'terre', 'nom' => 'Soin du Corps', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                'effet' => ['cible' => 'heros_ou_soi', 'soin_pv_body' => 4]],
            ['element' => 'terre', 'nom' => 'Traverser la Pierre', 'type' => 'utilitaire', 'difficulte_parchemin' => 1,
                'effet' => ['cible' => 'soi', 'franchit_mur' => true, 'cout' => 'deplacement_du_tour']],
            ['element' => 'terre', 'nom' => 'Peau de Pierre', 'type' => 'utilitaire', 'difficulte_parchemin' => 2,
                // Texte officiel : « 1 dé de défense supplémentaire jusqu'au
                // PREMIER DÉGÂT SUBI » (reference/18_extensions.md §3). On
                // donnait 2 dés pour tout le combat — deux écarts d'un coup.
                'effet' => ['cible' => 'heros', 'bonus_des_defense' => 1, 'duree' => 'premier_degat_subi', 'condition_appliquee' => 'Renforcé']],

            // Air — mobilité / puissance
            // `invocation_ephemere` RETIRÉ : clé sans lecteur, et surtout sans
            // source. Le texte officiel ne parle d'aucune invocation — « ouvre
            // une porte au choix OU attaque avec 5 dés de combat » (Kellar's
            // Keep p. 15, reference/18_extensions.md §3). Les 5 dés sont donc
            // exacts ; c'est le second mode, l'ouverture de porte, qui manque
            // encore (à trancher).
            ['element' => 'air', 'nom' => 'Génie', 'type' => 'degats', 'difficulte_parchemin' => 3,
                'effet' => ['portee' => 'distance', 'des_degats' => 5, 'defense_applicable' => true, 'ouvre_porte' => true]],
            ['element' => 'air', 'nom' => 'Vent Véloce', 'type' => 'utilitaire', 'difficulte_parchemin' => 1,
                'effet' => ['cible' => 'heros', 'deplacement_multiplie' => 2, 'duree' => 'ce_tour']],
            ['element' => 'air', 'nom' => 'Tempête', 'type' => 'mental', 'difficulte_parchemin' => 3,
                // « Un monstre choisi passe son prochain tour » (Kellar's Keep
                // p. 15, reference/18_extensions.md §3) : MONO-cible — il n'a
                // jamais été un sort de zone —, et le tour saute ENTIÈREMENT.
                // On lisait auparavant `monstres_zone` (ciblage inexistant) et
                // `empeche_attaque` (le monstre avançait quand même).
                'effet' => ['cible' => 'monstre', 'resistance' => 'jet_mind', 'saute_tour' => true, 'duree' => 'prochain_tour']],
        ];

        foreach ($sorts as $sort) {
            Sort::updateOrCreate(['nom' => $sort['nom']], $sort);
        }
    }
}
