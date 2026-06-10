<?php

namespace Database\Seeders;

use App\Models\Objet;
use App\Models\Sort;
use Illuminate\Database\Seeder;

/**
 * Catalogue Market (doc 04 §4) + consommables du doc 01 §8 + un parchemin par sort (doc 02 §6).
 *
 * Choix faits où les docs sont muets :
 * - prix des potions (« variable » dans le doc) : valeurs de départ à équilibrer ;
 * - parchemins : rareté/prix dérivés de la difficulté du sort (1 → commun/100, 2 → peu_commun/200, 3 → rare/350) ;
 * - casque/cotte/plates partagent l'emplacement « armure » (un seul slot d'armure au MVP).
 */
class ObjetSeeder extends Seeder
{
    public function run(): void
    {
        $objets = [
            // ----- Armes -----
            ['nom' => 'Dague', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 25, 'emplacement' => 'arme_principale',
                'effet' => ['des_attaque' => 1, 'jetable' => true, 'jetable_frequence' => 'une_fois_par_combat']],
            ['nom' => 'Bâton', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 100, 'emplacement' => 'arme_principale',
                'effet' => ['des_attaque' => 1, 'attaque_diagonale' => true]],
            ['nom' => 'Épée courte', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 150, 'emplacement' => 'arme_principale',
                'effet' => ['des_attaque' => 2]],
            ['nom' => 'Lance', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 250, 'emplacement' => 'arme_principale',
                'effet' => ['des_attaque' => 2, 'attaque_diagonale' => true, 'attaque_second_rang' => true]],
            ['nom' => 'Épée large', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 350, 'emplacement' => 'arme_principale',
                'effet' => ['des_attaque' => 3, 'attaque_diagonale' => false]],
            ['nom' => 'Arbalète', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 350, 'emplacement' => 'arme_principale',
                'effet' => ['des_attaque' => 3, 'portee' => 'distance', 'ligne_de_vue' => true, 'inutilisable_adjacent' => true]],
            ['nom' => 'Hache de bataille', 'categorie' => 'arme', 'rarete' => 'rare', 'prix_base' => 450, 'emplacement' => 'arme_principale',
                'effet' => ['des_attaque' => 4, 'deux_mains' => true, 'attaque_diagonale' => true]],

            // ----- Armures -----
            ['nom' => 'Casque', 'categorie' => 'armure', 'rarete' => 'commun', 'prix_base' => 125, 'emplacement' => 'armure',
                'effet' => ['des_defense' => 1]],
            ['nom' => 'Bouclier', 'categorie' => 'armure', 'rarete' => 'commun', 'prix_base' => 150, 'emplacement' => 'arme_secondaire',
                'effet' => ['des_defense' => 1, 'incompatible_deux_mains' => true]],
            ['nom' => 'Cotte de mailles', 'categorie' => 'armure', 'rarete' => 'peu_commun', 'prix_base' => 500, 'emplacement' => 'armure',
                'effet' => ['des_defense' => 1]],
            ['nom' => 'Armure de plates', 'categorie' => 'armure', 'rarete' => 'rare', 'prix_base' => 850, 'emplacement' => 'armure',
                'effet' => ['des_defense' => 2, 'deplacement_sans_d6' => true]], // décision AP : dépl. = base seule

            // ----- Outils -----
            ['nom' => 'Trousse à outils', 'categorie' => 'outil', 'rarete' => 'peu_commun', 'prix_base' => 250, 'emplacement' => 'sac',
                'effet' => ['permet_desamorcage' => true]],

            // ----- Consommables (doc 01 §8 ; prix = propositions) -----
            ['nom' => 'Potion de soin', 'categorie' => 'consommable', 'rarete' => 'commun', 'prix_base' => 100, 'emplacement' => 'consommable',
                'effet' => ['soin_pv_body' => 4]],
            ['nom' => "Potion d'esprit clair", 'categorie' => 'consommable', 'rarete' => 'commun', 'prix_base' => 100, 'emplacement' => 'consommable',
                'effet' => ['soin_pv_mind' => 4]],
            ['nom' => 'Potion de rage', 'categorie' => 'consommable', 'rarete' => 'peu_commun', 'prix_base' => 150, 'emplacement' => 'consommable',
                'effet' => ['bonus_des_attaque' => 1, 'duree' => 'un_combat', 'condition_appliquee' => 'Renforcé']],
            ['nom' => 'Antidote', 'categorie' => 'consommable', 'rarete' => 'commun', 'prix_base' => 50, 'emplacement' => 'consommable',
                'effet' => ['retire_condition' => 'Empoisonné']],
        ];

        foreach ($objets as $objet) {
            Objet::updateOrCreate(['nom' => $objet['nom']], $objet);
        }

        // ----- Parchemins : un par sort, rareté/prix selon la difficulté (doc 02 §6-7) -----
        $bareme = [
            1 => ['rarete' => 'commun', 'prix_base' => 100],
            2 => ['rarete' => 'peu_commun', 'prix_base' => 200],
            3 => ['rarete' => 'rare', 'prix_base' => 350],
        ];

        foreach (Sort::all() as $sort) {
            $palier = $bareme[$sort->difficulte_parchemin];

            Objet::updateOrCreate(
                ['nom' => "Parchemin : {$sort->nom}"],
                [
                    'categorie' => 'parchemin',
                    'rarete' => $palier['rarete'],
                    'prix_base' => $palier['prix_base'],
                    'emplacement' => 'consommable',
                    'effet' => [
                        'sort_id' => $sort->id,
                        'sort_nom' => $sort->nom,
                        'difficulte_non_lanceur' => $sort->difficulte_parchemin,
                    ],
                ],
            );
        }
    }
}
