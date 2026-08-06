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
            ['nom' => 'Dague', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 25, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                'effet' => ['des_attaque' => 1, 'jetable' => true]],
            ['nom' => 'Bâton', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 100, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                'effet' => ['des_attaque' => 1, 'attaque_diagonale' => true]],
            ['nom' => 'Épée courte', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 150, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2]],
            // Arme de départ du NAIN au plateau : mêmes 2 dés que l'épée courte
            // — sa force est ailleurs (outils, Forge, robustesse) —, mais
            // lançable, et perdue une fois lancée comme toute arme de jet.
            ['nom' => 'Hachette', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 200, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2, 'jetable' => true]],
            // L'« attaque au second rang » a été retirée : clé sans lecteur, et
            // le mécanisme n'existe nulle part dans le jeu de plateau — pas plus
            // que l'arme « Spear » elle-même, dont seul un PIÈGE porte le nom
            // (reference/16_armurerie.md §10). La Lance garde sa diagonale, qui
            // est bien attestée pour les armes longues (livret p. 14).
            ['nom' => 'Lance', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 250, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2, 'attaque_diagonale' => true]],
            ['nom' => 'Épée large', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 350, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3, 'attaque_diagonale' => false]],
            ['nom' => 'Arbalète', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 350, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_distance',
                // Pas de clé `ligne_de_vue` : c'est `portee: distance` qui la
                // gouverne — MenuMoteur appelle Grille::ligneDeVue pour toute
                // arme à distance. La clé ne faisait que doubler, sans lecteur.
                'effet' => ['des_attaque' => 3, 'portee' => 'distance', 'inutilisable_adjacent' => true]],
            ['nom' => 'Hache de bataille', 'categorie' => 'arme', 'rarete' => 'rare', 'prix_base' => 450, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_deux_mains',
                'effet' => ['des_attaque' => 4, 'deux_mains' => true, 'attaque_diagonale' => true]],

            // Fiole trouvée en fouille : soin ALÉATOIRE (1d6), là où la potion
            // achetée au marché rend un montant fixe. Rareté `unique` pour la
            // tenir hors de l'étal — on ne l'achète pas, on la trouve.
            ['nom' => 'Fiole de soin', 'categorie' => 'consommable', 'rarete' => 'unique', 'prix_base' => 100, 'emplacement' => 'consommable',
                'effet' => ['soin_pv_body_de' => 6]],

            // Potions du deck de trésor du plateau. Elles réutilisent les clés
            // que le moteur lit déjà (`bonus_des_attaque`/`bonus_des_defense`,
            // `duree`, `condition_appliquee`) — aucune mécanique nouvelle.
            // Deux attaques dans le même tour (et non des dés en plus) :
            // l'attaque vient de l'arme chez nous, un bonus de dés n'aurait pas
            // rendu la carte du plateau.
            ['nom' => "Potion d'héroïsme", 'categorie' => 'consommable', 'rarete' => 'peu_commun', 'prix_base' => 150, 'emplacement' => 'consommable',
                'effet' => ['attaque_supplementaire' => true]],
            // `duree` : vocabulaire App\Engine\DureeEffet (reference/19_durees_effets.md).
            // Ces deux-là portaient `duree => 0`, qui n'est pas une durée mais
            // l'absence de compteur : rien ne les retirait jamais. Force et
            // Défense sont donc des BURSTS (+2 sur un jet), là où Rage, au même
            // prix, tient tout le combat pour +1 — départ playtest.
            ['nom' => 'Potion de force', 'categorie' => 'consommable', 'rarete' => 'peu_commun', 'prix_base' => 150, 'emplacement' => 'consommable',
                'effet' => ['bonus_des_attaque' => 2, 'duree' => 'prochaine_attaque', 'condition_appliquee' => 'Renforcé']],
            ['nom' => 'Potion de défense', 'categorie' => 'consommable', 'rarete' => 'peu_commun', 'prix_base' => 150, 'emplacement' => 'consommable',
                'effet' => ['bonus_des_defense' => 2, 'duree' => 'prochaine_defense', 'condition_appliquee' => 'Renforcé']],

            // ----- Artefacts : armes UNIQUES (doc 04 §4/§6) -----
            // Jamais à l'achat (PhaseMarche filtre `rarete != unique`), jamais
            // revendables, jamais forgeables (Forge les refuse). Seule source :
            // le coffre désigné d'une quête — au plus UN artefact par quête.
            //
            // UN SEUL est verrouillé (`tag_equipement: arme_deux_mains`, donc nœud
            // Maîtrise lourde) : le Fendoir des Titans. C'était impensable tant
            // qu'un objet ne pouvait pas changer de mains ; le don entre héros
            // (POST /groupes/{id}/dons) l'a rendu jouable — le magicien qui le
            // trouve le passe au barbare. DeckFouille l'écarte du tirage quand
            // aucun barbare n'est actif, sans quoi il resterait du butin mort.
            //
            // Le Bâton des Sept Sceaux est `deux_mains` mais tagué `arme_legere` :
            // le `deux_mains` interdit le bouclier, le TAG dit qui peut porter.
            //
            // Rappel de règle : `des_attaque` REMPLACE la valeur du porteur
            // (l'arme fait l'attaque, doc 03 §8) tandis que `des_defense` s'AJOUTE.
            ['nom' => "Lame d'Aube", 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 900, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 4]],
            ['nom' => 'Kriss du Fossoyeur', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 900, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3, 'des_defense' => 1]],
            ['nom' => 'Arbalète des Murmures', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 1000, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_distance',
                'effet' => ['des_attaque' => 4, 'portee' => 'distance', 'inutilisable_adjacent' => true]],
            ['nom' => 'Bâton des Sept Sceaux', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 1000, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                'effet' => ['des_attaque' => 3, 'des_defense' => 1, 'deux_mains' => true]],
            ['nom' => 'Marteau du Gardien de Pierre', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 1100, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_deux_mains',
                'effet' => ['des_attaque' => 4, 'des_defense' => 1, 'deux_mains' => true]],
            ['nom' => 'Hache du Roi sous la Montagne', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 1300, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_deux_mains',
                'effet' => ['des_attaque' => 5, 'deux_mains' => true]],
            // Sommet absolu de la courbe (6 dés), et le seul artefact VERROUILLÉ :
            // il coûte en plus un point de compétence (nœud Maîtrise lourde, arbre
            // barbare) — d'où un cran au-dessus de la Hache du Roi, qui ne coûte
            // rien à personne. Valeur à playtester comme le reste.
            ['nom' => 'Fendoir des Titans', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 1600, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_deux_mains',
                'effet' => ['des_attaque' => 6, 'deux_mains' => true]],

            // ----- Armures -----
            ['nom' => 'Casque', 'categorie' => 'armure', 'rarete' => 'commun', 'prix_base' => 125, 'emplacement' => 'armure', 'tag_equipement' => 'armure_legere',
                'effet' => ['des_defense' => 1]],
            ['nom' => 'Bouclier', 'categorie' => 'armure', 'rarete' => 'commun', 'prix_base' => 150, 'emplacement' => 'arme_secondaire', 'tag_equipement' => 'bouclier',
                'effet' => ['des_defense' => 1, 'incompatible_deux_mains' => true]],
            ['nom' => 'Cotte de mailles', 'categorie' => 'armure', 'rarete' => 'peu_commun', 'prix_base' => 500, 'emplacement' => 'armure', 'tag_equipement' => 'armure_legere',
                'effet' => ['des_defense' => 1]],
            ['nom' => 'Armure de plates', 'categorie' => 'armure', 'rarete' => 'rare', 'prix_base' => 850, 'emplacement' => 'armure', 'tag_equipement' => 'armure_lourde',
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
                'effet' => ['bonus_des_attaque' => 1, 'duree' => 'fin_du_combat', 'condition_appliquee' => 'Renforcé']],
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
