<?php

namespace Database\Seeders;

use App\Models\Competence;
use Illuminate\Database\Seeder;

/**
 * Arbres de compétences — brouillon de départ du doc 01 §6 (~5-6 nœuds par héros).
 * Les clés `effet` sont des identifiants mécaniques destinés au moteur.
 */
class CompetenceSeeder extends Seeder
{
    public function run(): void
    {
        $arbres = [
            'barbare' => [
                ['nom' => 'Carrure', 'type' => 'passif', 'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Coup puissant', 'type' => 'actif', 'effet' => ['mecanique' => 'relance_des_attaque_rates', 'frequence' => 'une_fois_par_usage']],
                ['nom' => 'Maîtrise lourde', 'type' => 'deblocage', 'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['arme_deux_mains', 'armure_lourde']]],
                ['nom' => 'Intimidation', 'type' => 'passif', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'social_peur']],
                ['nom' => 'Frénésie', 'type' => 'actif', 'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
            ],
            'nain' => [
                ['nom' => 'Œil du mineur', 'type' => 'passif', 'effet' => ['mecanique' => 'detection_pieges_adjacents', 'automatique' => true]],
                ['nom' => 'Désamorçage', 'type' => 'actif', 'effet' => ['mecanique' => 'desamorcer_piege', 'jet' => 'body']],
                ['nom' => 'Garde tenace', 'type' => 'passif', 'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Forge', 'type' => 'deblocage', 'effet' => ['mecanique' => 'forge_amelioration', 'lieu' => 'hub', 'catalogue' => 'forge_ameliorations']],
                ['nom' => 'Sang robuste', 'type' => 'passif', 'effet' => ['mecanique' => 'resistance_condition', 'condition_nom' => 'Empoisonné']],
                ['nom' => 'Solides épaules', 'type' => 'passif', 'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
            ],
            'elfe' => [
                ['nom' => 'Pas léger', 'type' => 'passif', 'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Première magie', 'type' => 'deblocage', 'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1]],
                ['nom' => 'Sens aiguisés', 'type' => 'passif', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'perception']],
                ['nom' => 'Tir précis', 'type' => 'actif', 'effet' => ['mecanique' => 'avantage_attaque', 'contexte' => 'distance']],
                ['nom' => 'Second élément', 'type' => 'deblocage', 'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1], 'prerequis' => 'Première magie'],
            ],
            'magicien' => [
                ['nom' => 'Réserve arcanique', 'type' => 'passif', 'effet' => ['mecanique' => 'emplacement_sort_supplementaire', 'valeur' => 1]],
                ['nom' => 'Écoles', 'type' => 'deblocage', 'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1, 'repetable' => true]],
                ['nom' => 'Concentration', 'type' => 'actif', 'effet' => ['mecanique' => 'recuperer_sort_epuise', 'cout' => 'tour_complet', 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Contresort', 'type' => 'actif', 'effet' => ['mecanique' => 'annuler_effet_magique', 'jet' => 'mind']],
                ['nom' => 'Érudition', 'type' => 'passif', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'savoir']],
            ],
        ];

        foreach ($arbres as $classe => $noeuds) {
            foreach ($noeuds as $noeud) {
                $prerequisId = null;

                if (isset($noeud['prerequis'])) {
                    $prerequisId = Competence::where('classe', $classe)
                        ->where('nom', $noeud['prerequis'])
                        ->value('id');
                }

                Competence::updateOrCreate(
                    ['classe' => $classe, 'nom' => $noeud['nom']],
                    [
                        'type' => $noeud['type'],
                        'effet' => $noeud['effet'],
                        'prerequis_id' => $prerequisId,
                    ],
                );
            }
        }
    }
}
