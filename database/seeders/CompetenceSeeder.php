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
                ['nom' => 'Carrure', 'type' => 'passif', 'description' => '+1 Point de Body (PV Body max).', 'effet' => ['mecanique' => 'bonus_pv_body_max', 'valeur' => 1]],
                ['nom' => 'Coup puissant', 'type' => 'actif', 'description' => "Une fois par usage, relance les dés d'attaque ratés.", 'effet' => ['mecanique' => 'relance_des_attaque_rates', 'frequence' => 'une_fois_par_usage']],
                ['nom' => 'Maîtrise lourde', 'type' => 'deblocage', 'description' => 'Débloque les armes à deux mains et les armures lourdes.', 'effet' => ['mecanique' => 'acces_equipement', 'tags' => ['arme_deux_mains', 'armure_lourde']]],
                ['nom' => 'Intimidation', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind sociaux fondés sur la peur.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'social_peur']],
                ['nom' => 'Frénésie', 'type' => 'actif', 'description' => "+1 dé d'attaque tant que tes PV de Body sont sous la moitié.", 'effet' => ['mecanique' => 'bonus_des_attaque', 'valeur' => 1, 'condition' => 'pv_body_sous_moitie']],
            ],
            'nain' => [
                ['nom' => 'Œil du mineur', 'type' => 'passif', 'description' => 'Détecte automatiquement les pièges sur les cases adjacentes.', 'effet' => ['mecanique' => 'detection_pieges_adjacents', 'automatique' => true]],
                ['nom' => 'Désamorçage', 'type' => 'actif', 'description' => 'Tente de neutraliser un piège détecté (jet de Body).', 'effet' => ['mecanique' => 'desamorcer_piege', 'jet' => 'body']],
                ['nom' => 'Garde tenace', 'type' => 'passif', 'description' => "+1 dé de défense contre la première attaque d'un combat.", 'effet' => ['mecanique' => 'bonus_des_defense', 'valeur' => 1, 'condition' => 'premiere_attaque_du_combat']],
                ['nom' => 'Forge', 'type' => 'deblocage', 'description' => 'Au hub, améliore définitivement un équipement (+1 dé ou une propriété).', 'effet' => ['mecanique' => 'forge_amelioration', 'lieu' => 'hub', 'catalogue' => 'forge_ameliorations']],
                ['nom' => 'Sang robuste', 'type' => 'passif', 'description' => 'Résistance à la condition Empoisonné.', 'effet' => ['mecanique' => 'resistance_condition', 'condition_nom' => 'Empoisonné']],
                ['nom' => 'Solides épaules', 'type' => 'passif', 'description' => '+2 emplacements de sac à dos.', 'effet' => ['mecanique' => 'bonus_capacite_sac', 'valeur' => 2]],
            ],
            'elfe' => [
                ['nom' => 'Pas léger', 'type' => 'passif', 'description' => '+1 en déplacement.', 'effet' => ['mecanique' => 'bonus_deplacement', 'valeur' => 1]],
                ['nom' => 'Première magie', 'type' => 'deblocage', 'description' => "Ouvre 1 emplacement de sort (les 3 sorts d'un élément).", 'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1]],
                ['nom' => 'Sens aiguisés', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind de perception.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'perception']],
                ['nom' => 'Tir précis', 'type' => 'actif', 'description' => 'Avantage sur une attaque à distance.', 'effet' => ['mecanique' => 'avantage_attaque', 'contexte' => 'distance']],
                ['nom' => 'Second élément', 'type' => 'deblocage', 'description' => 'Apprends un domaine de sort supplémentaire.', 'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1], 'prerequis' => 'Première magie'],
            ],
            'magicien' => [
                ['nom' => 'Réserve arcanique', 'type' => 'passif', 'description' => 'Un emplacement de sort supplémentaire.', 'effet' => ['mecanique' => 'emplacement_sort_supplementaire', 'valeur' => 1]],
                ['nom' => 'Écoles', 'type' => 'deblocage', 'description' => 'Accès à de nouveaux domaines de magie (Feu, Eau, Terre, Air).', 'effet' => ['mecanique' => 'emplacement_element', 'nb_elements' => 1, 'repetable' => true]],
                ['nom' => 'Concentration', 'type' => 'actif', 'description' => 'Une fois par quête, sacrifie ton tour pour récupérer un sort épuisé.', 'effet' => ['mecanique' => 'recuperer_sort_epuise', 'cout' => 'tour_complet', 'frequence' => 'une_fois_par_quete']],
                ['nom' => 'Contresort', 'type' => 'actif', 'description' => 'Annule un effet magique (jet de Mind).', 'effet' => ['mecanique' => 'annuler_effet_magique', 'jet' => 'mind']],
                ['nom' => 'Érudition', 'type' => 'passif', 'description' => 'Avantage aux jets de Mind de savoir et d\'érudition.', 'effet' => ['mecanique' => 'avantage_jet_mind', 'contexte' => 'savoir']],
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
                        'description' => $noeud['description'] ?? null,
                        'type' => $noeud['type'],
                        'effet' => $noeud['effet'],
                        'prerequis_id' => $prerequisId,
                    ],
                );
            }
        }
    }
}
