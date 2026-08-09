<?php

namespace Database\Seeders;

use App\Models\Objet;
use App\Models\Sort;
use Illuminate\Database\Seeder;

/**
 * Catalogue Market (doc 04 §4) + consommables du doc 01 §8 + un parchemin par sort (doc 02 §6).
 *
 * **Armes et armures sont la conversion carte par carte du paquet d'armurerie
 * de Ye Olde Inn** (« Sjeng's equipment », 26 cartes retenues sur 27) — prix,
 * dés, restrictions de classe et mots-clés : la table ligne à ligne, avec le
 * niveau de source de chaque valeur, est `reference/16_armurerie.md` §2.2.
 * ⚠ Ce paquet est une RÉVISION assumée du jeu de base, pas le paquet officiel
 * Avalon Hill : son auteur écrit « I have changed some item costs and
 * functionality ». Les prix et les dés viennent donc de LUI, et le §2.2 dit
 * pour chaque ligne ce que les livrets officiels corroborent par ailleurs.
 *
 * Les clés d'`effet` employées ici forment un vocabulaire fermé
 * (`App\Engine\MotsClesEquipement`, référence `reference/19_mots_cles_effets.md`
 * §9) : toute clé nouvelle doit y être déclarée, sans quoi
 * `ObjetsFonctionnelsTest` casse.
 *
 * Choix faits où les docs sont muets :
 * - prix des potions (« variable » dans le doc) : valeurs de départ à équilibrer ;
 * - parchemins : rareté/prix dérivés de la difficulté du sort (1 → commun/100, 2 → peu_commun/200, 3 → rare/350) ;
 * - la trousse à outils ne vient pas de ce paquet (il n'a que des armes et des
 *   armures) mais du livret officiel, LR p. 19.
 */
class ObjetSeeder extends Seeder
{
    public function run(): void
    {
        $objets = [
            // ----- Armes (20 cartes, par prix croissant) -----
            //
            // Les RESTRICTIONS DE CLASSE des cartes passent par `tag_equipement`
            // × `classes_heros.tags_equipement` — jamais par une clé d'effet :
            //   sans mention        → `arme_legere`   (les quatre classes)
            //   « not Wizard »      → `arme_courante` / `arme_distance`
            //   « not Wizard/Elf »  → `arme_deux_mains`
            //   « not Wizard/Dwarf »→ `arme_arc_long`
            //   « not Wizard/Barb. »→ `arme_arc_court`
            //   « not Barb./Dwarf » → `arme_erudit`
            // `deux_mains` reste ORTHOGONAL au tag : la hallebarde est à deux
            // mains et pourtant `arme_courante`, le bâton à deux mains et
            // pourtant accessible au magicien.
            //
            // Non portée : la TORCHE (2 dés, dégâts de feu, éclaire toute case
            // vue, dure une quête). Ni l'éclairage ni un type de dégât « feu »
            // n'existent chez nous ; la semer reviendrait à annoncer au joueur
            // deux règles que le moteur n'applique pas (reference/16 §10).
            ['nom' => 'Canne', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 125, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_erudit',
                'effet' => ['des_attaque' => 1, 'attaque_diagonale' => true]],
            ['nom' => 'Fronde', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 125, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                'effet' => ['des_attaque' => 1, 'portee' => 'distance', 'inutilisable_adjacent' => true]],
            ['nom' => 'Dague', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 150, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                'effet' => ['des_attaque' => 1, 'jetable' => true]],
            ['nom' => 'Fouet', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 175, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                'effet' => ['des_attaque' => 1, 'attaque_diagonale' => true]],
            // Le bâton est à DEUX MAINS sur sa carte, et passe de 1 à 2 dés :
            // l'arme du magicien cesse d'être un objet de figuration.
            ['nom' => 'Bâton', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 200, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                'effet' => ['des_attaque' => 2, 'attaque_diagonale' => true, 'deux_mains' => true]],
            ['nom' => 'Arc court', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 200, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_arc_court',
                'effet' => ['des_attaque' => 2, 'portee' => 'distance', 'inutilisable_adjacent' => true, 'deux_mains' => true]],
            ['nom' => 'Épée courte', 'categorie' => 'arme', 'rarete' => 'commun', 'prix_base' => 225, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2]],
            // Hachette et Lance sont les deux autres armes de JET du paquet, avec
            // la dague. La carte dit « thrown at an enemy in your line of sight,
            // but not adjacent » — et ne dit RIEN d'une perte : c'est nous qui
            // détruisons l'arme lancée (reference/16 §10).
            ['nom' => 'Hachette', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 250, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2, 'jetable' => true]],
            ['nom' => 'Lance', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 250, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2, 'attaque_diagonale' => true, 'jetable' => true]],
            ['nom' => 'Rapière', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 275, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2, 'attaque_diagonale' => true]],
            // Broadsword : 3 dés, PAS de diagonale — le diagramme des armes
            // longues du livret officiel (p. 14) lui oppose justement le bâton.
            ['nom' => 'Épée large', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 300, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3, 'attaque_diagonale' => false]],
            ['nom' => 'Hallebarde', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 325, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3, 'attaque_diagonale' => true, 'deux_mains' => true]],
            ['nom' => 'Masse', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 350, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3]],
            // Longsword : l'une des deux seules armes que le livret OFFICIEL
            // nomme comme frappant en diagonale (« like the staff and the
            // longsword », p. 14). Une main : elle se combine au bouclier.
            ['nom' => 'Épée longue', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 350, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3, 'attaque_diagonale' => true]],
            ['nom' => 'Arbalète', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 350, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_distance',
                // Pas de clé `ligne_de_vue` : c'est `portee: distance` qui la
                // gouverne — MenuMoteur appelle Grille::ligneDeVue pour toute
                // arme à distance. La clé ne faisait que doubler, sans lecteur.
                // `inutilisable_adjacent` traduit le mot-clé « Ranged » du
                // paquet : « you may not use it against an opponent who is
                // adjacent to you » — la règle qu'on portait sans source.
                'effet' => ['des_attaque' => 3, 'portee' => 'distance', 'inutilisable_adjacent' => true]],
            ['nom' => 'Fléau', 'categorie' => 'arme', 'rarete' => 'peu_commun', 'prix_base' => 400, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3, 'attaque_diagonale' => true]],
            // La hache de bataille N'EST PAS une arme longue : sa carte ne dit
            // que « Two-handed ». Elle portait `attaque_diagonale`, ce qui en
            // faisait la meilleure arme du jeu sur les deux axes à la fois.
            ['nom' => 'Hache de bataille', 'categorie' => 'arme', 'rarete' => 'rare', 'prix_base' => 475, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_deux_mains',
                'effet' => ['des_attaque' => 4, 'deux_mains' => true]],
            ['nom' => 'Espadon', 'categorie' => 'arme', 'rarete' => 'rare', 'prix_base' => 525, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_deux_mains',
                'effet' => ['des_attaque' => 4, 'attaque_diagonale' => true, 'deux_mains' => true]],
            ['nom' => 'Arc long', 'categorie' => 'arme', 'rarete' => 'rare', 'prix_base' => 525, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_arc_long',
                'effet' => ['des_attaque' => 4, 'portee' => 'distance', 'inutilisable_adjacent' => true, 'deux_mains' => true]],
            ['nom' => 'Épée bâtarde', 'categorie' => 'arme', 'rarete' => 'rare', 'prix_base' => 825, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_deux_mains',
                'effet' => ['des_attaque' => 5, 'attaque_diagonale' => true, 'deux_mains' => true]],

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
            // `duree` : vocabulaire App\Engine\DureeEffet (reference/19_mots_cles_effets.md).
            // Ces deux-là portaient `duree => 0`, qui n'est pas une durée mais
            // l'absence de compteur : rien ne les retirait jamais. Force et
            // Défense sont donc des BURSTS (+2 sur un jet), là où Rage, au même
            // prix, tient tout le combat pour +1 — départ playtest.
            ['nom' => 'Potion de force', 'categorie' => 'consommable', 'rarete' => 'peu_commun', 'prix_base' => 150, 'emplacement' => 'consommable',
                'effet' => ['bonus_des_attaque' => 2, 'duree' => 'prochaine_attaque', 'condition_appliquee' => 'Renforcé']],
            ['nom' => 'Potion de défense', 'categorie' => 'consommable', 'rarete' => 'peu_commun', 'prix_base' => 150, 'emplacement' => 'consommable',
                'effet' => ['bonus_des_defense' => 2, 'duree' => 'prochaine_defense', 'condition_appliquee' => 'Renforcé']],

            // ----- Artefacts UNIQUES (doc 04 §4/§6, reference/16 §9) -----
            //
            // Conversion du paquet `sjeng-artefacts.pdf` (Ye Olde Inn), qui
            // rassemble les cartes artefact des cinq sources officielles —
            // boîte de base, Kellar's Keep / Return of the Witch Lord, Frozen
            // Horror, Mage of the Mirror, White Dwarf. On ne porte que celles
            // dont le moteur sait appliquer l'effet : les 25 autres sont
            // recensées, avec ce qui leur manque, en `reference/16` §9.
            //
            // Elles REMPLACENT 7 artefacts inventés (Lame d'Aube, Kriss du
            // Fossoyeur…) qui n'étaient que des armes à dés croissants — 4, 5,
            // puis 6 dés — là où un vrai artefact a un POUVOIR : frapper deux
            // fois un orque, blesser à coup sûr, porter des PV en plus. La
            // montée en dés seule finissait par rendre l'armurerie inutile.
            //
            // Jamais à l'achat (PhaseMarche filtre `rarete != unique`), jamais
            // revendables, jamais forgeables (Forge les refuse). Seule source :
            // le coffre désigné d'une quête — au plus UN artefact par quête.
            //
            // Rappel de règle : `des_attaque` REMPLACE la valeur du porteur
            // (l'arme fait l'attaque, doc 03 §8) tandis que `des_defense` s'AJOUTE.

            // « The sword Orcs Bane allows you to roll two combat dice in
            // attack. You may attack TWICE if you are fighting Orcs. »
            ['nom' => 'Fléau des Orques', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 900, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2, 'attaque_double_contre' => ['Orque']]],
            // « Spirit Blade allows you to roll three combat dice in attack OR
            // four dice in attack against undead creatures such as Skeletons,
            // Zombies and Mummies. » — les trois noms sont ceux de la carte,
            // pas une famille inventée.
            ['nom' => 'Lame des Esprits', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 1100, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3, 'des_attaque_contre' => ['noms' => ['Squelette', 'Zombie', 'Momie'], 'des' => 4]]],
            // « This weapon always inflicts one Body Point of damage. It may be
            // thrown at any monster or player visible to the owner. The dagger
            // is lost once thrown. It cannot be used on an adjacent target. »
            // La carte SOURCE enfin la destruction de l'arme lancée, que notre
            // `jetable` appliquait sans que rien ne l'atteste (reference/16 §10).
            ['nom' => 'Dague de jet magique', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 700, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                'effet' => ['degats_fixes' => 1, 'jetable' => true, 'portee' => 'distance', 'inutilisable_adjacent' => true]],

            // « Borin's Armour allows you to roll 2 extra combat dice in
            // defence. May be combined with a helmet and a shield. » — le cumul
            // est acquis depuis que le casque a son slot ; ce qui reste propre à
            // l'artefact, c'est l'ABSENCE de malus de déplacement, « unlike
            // normal plate mail » (LR p. 7).
            ['nom' => 'Armure de Borin', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 1200, 'emplacement' => 'armure', 'tag_equipement' => 'armure_legere',
                'effet' => ['des_defense' => 2]],

            // Talismans : ni dés d'attaque ni dés de défense, mais des PV
            // MAXIMUM en plus — d'où leur propre emplacement, sans quoi ils
            // auraient concurrencé une vraie armure pour un bénéfice d'une autre
            // nature. Les quatre bijoux de classe sont la même carte déclinée
            // (« adds 2 Body points and 1 Mind point to the … totals »), une par
            // héros : Amulet of the North, Elven Bracers, Magister's Hood,
            // Dwarven Runestones.
            ['nom' => 'Talisman du Savoir', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 800, 'emplacement' => 'talisman',
                'effet' => ['bonus_pv_mind_max' => 2]],
            ['nom' => 'Amulette du Nord', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 1000, 'emplacement' => 'talisman', 'tag_equipement' => 'talisman_barbare',
                'effet' => ['bonus_pv_body_max' => 2, 'bonus_pv_mind_max' => 1]],
            ['nom' => 'Brassards elfiques', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 1000, 'emplacement' => 'talisman', 'tag_equipement' => 'talisman_elfe',
                'effet' => ['bonus_pv_body_max' => 2, 'bonus_pv_mind_max' => 1]],
            ['nom' => 'Capuche du Magister', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 1000, 'emplacement' => 'talisman', 'tag_equipement' => 'talisman_magicien',
                'effet' => ['bonus_pv_body_max' => 2, 'bonus_pv_mind_max' => 1]],
            ['nom' => 'Runes naines', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 1000, 'emplacement' => 'talisman', 'tag_equipement' => 'talisman_nain',
                'effet' => ['bonus_pv_body_max' => 2, 'bonus_pv_mind_max' => 1]],

            // ----- Armures (6 cartes) -----
            //
            // Elles se CUMULENT, comme au plateau : casque (slot propre depuis
            // le 2026-08-08) + corps + bouclier. Défense maximale 2 + 1 + 2 + 1
            // = 6, la valeur du livret officiel (LR p. 7).
            //
            // Brassards et Cape sont « May ONLY be used by a Wizard » : le seul
            // équipement défensif du magicien, qu'aucune autre classe ne peut
            // porter — d'où un tag à eux (`armure_magicien`).
            ['nom' => 'Casque', 'categorie' => 'armure', 'rarete' => 'commun', 'prix_base' => 125, 'emplacement' => 'casque', 'tag_equipement' => 'armure_legere',
                'effet' => ['des_defense' => 1]],
            ['nom' => 'Bouclier', 'categorie' => 'armure', 'rarete' => 'commun', 'prix_base' => 125, 'emplacement' => 'arme_secondaire', 'tag_equipement' => 'bouclier',
                'effet' => ['des_defense' => 1, 'incompatible_deux_mains' => true]],
            ['nom' => 'Brassards', 'categorie' => 'armure', 'rarete' => 'commun', 'prix_base' => 200, 'emplacement' => 'armure', 'tag_equipement' => 'armure_magicien',
                'effet' => ['des_defense' => 1]],
            ['nom' => 'Cape de protection', 'categorie' => 'armure', 'rarete' => 'peu_commun', 'prix_base' => 350, 'emplacement' => 'armure', 'tag_equipement' => 'armure_magicien',
                'effet' => ['des_defense' => 1]],
            ['nom' => 'Cotte de mailles', 'categorie' => 'armure', 'rarete' => 'rare', 'prix_base' => 450, 'emplacement' => 'armure', 'tag_equipement' => 'armure_legere',
                'effet' => ['des_defense' => 1]],
            // « While wearing the Plate Mail, you have a 2 square movement
            // penalty » : un chiffre, là où on retirait tout le d6 (−3,5 en
            // moyenne). Le malus vient de la carte, pas d'une décision de table.
            ['nom' => 'Armure de plates', 'categorie' => 'armure', 'rarete' => 'rare', 'prix_base' => 850, 'emplacement' => 'armure', 'tag_equipement' => 'armure_lourde',
                'effet' => ['des_defense' => 2, 'malus_deplacement' => 2]],

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
