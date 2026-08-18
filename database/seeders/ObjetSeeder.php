<?php

namespace Database\Seeders;

use App\Engine\RareteButin;
use App\Models\Objet;
use App\Models\Sort;
use Illuminate\Database\Seeder;

/**
 * Catalogue Market (doc 04 §4) + consommables du doc 01 §8 + un parchemin par sort (doc 02 §6).
 *
 * **Armes, armures, matériel et potions sont les 35 CARTES OFFICIELLES
 * Hasbro**, photographiées par René et transcrites carte par carte en
 * `reference/16_armurerie.md` §2.1bis — 20 d'équipement (`equipments.pdf`),
 * 15 de potions (`potions.pdf`), chacune tamponnée © 2021, 2022 ou 2023.
 *
 * Elles ont REMPLACÉ (2026-08-15) la conversion fan de Ye Olde Inn qui servait
 * jusque-là de source, et dont l'auteur écrit lui-même « I have changed some
 * item costs and functionality » : douze armes et armures qu'aucune carte
 * n'atteste ont été supprimées (migration `paquet_officiel_hasbro`), dix pièces
 * retarifées, et le Bâton est revenu de 2 dés à 1. Ce qui reste hors paquet est
 * nommément justifié : les potions du DECK DE TRÉSOR du plateau, que
 * `DeckFouille` tire par leur nom, et la trousse à outils (livret, LR p. 19).
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
            // ----- Armes : les 10 cartes officielles, par prix croissant -----
            //
            // Source : `equipments.pdf`, photos du paquet réel (© 2021/2022/2023
            // Hasbro), transcrit carte par carte en `reference/16_armurerie.md`
            // §2.1bis. C'est ce paquet-ci qui fait foi, et non plus la
            // conversion fan de §2.2, dont l'auteur écrit lui-même avoir changé
            // « some item costs and functionality ».
            //
            // Les RESTRICTIONS DE CLASSE des cartes passent par `tag_equipement`
            // × `classes_heros.tags_equipement` — jamais par une clé d'effet :
            //   sans mention          → `arme_legere`   (toutes les classes)
            //   « not the wizard »    → `arme_courante` / `arme_distance`
            //                           / `arme_deux_mains`
            //   « only the warlock »  → `arme_warlock`
            // `deux_mains` reste ORTHOGONAL au tag : il ne dit rien de la classe,
            // seulement qu'aucun bouclier ne l'accompagne.
            ['nom' => 'Dague', 'categorie' => 'arme', 'prix_base' => 25, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                // « A dagger can also be thrown at any monster you can see but
                // is lost once it is thrown. » La perte, que nous appliquions
                // sans source, est ÉCRITE sur la carte officielle.
                'effet' => ['des_attaque' => 1, 'jetable' => true]],
            // Le bâton retombe à 1 dé : c'est le chiffre de sa carte. Il gagne
            // la diagonale (« Because of its length ») et interdit le bouclier
            // (« You may not use a shield when using this weapon »), ce que
            // `deux_mains` exprime chez nous. Aucune restriction de classe.
            ['nom' => 'Bâton', 'categorie' => 'arme', 'prix_base' => 100, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_legere',
                'effet' => ['des_attaque' => 1, 'attaque_diagonale' => true, 'deux_mains' => true]],
            // Baguette du Warlock : « It may only be used by the warlock » —
            // d'où son tag propre, et non plus `armure_magicien`, qui l'ouvrait
            // au magicien contre le texte de la carte. ⚠ Pas
            // `inutilisable_adjacent` : rien ne l'interdit au contact,
            // contrairement à l'arbalète.
            ['nom' => 'Baguette', 'categorie' => 'arme', 'prix_base' => 125, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_warlock',
                'effet' => ['des_attaque' => 2, 'portee' => 'distance']],
            ['nom' => 'Épée courte', 'categorie' => 'arme', 'prix_base' => 150, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2]],
            // La Hachette EXISTE au matériel officiel (© 2023) : doc 16 §2.1 et
            // un commentaire de GroupeController soutenaient le contraire.
            ['nom' => 'Hachette', 'categorie' => 'arme', 'prix_base' => 200, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2, 'jetable' => true]],
            ['nom' => 'Rapière', 'categorie' => 'arme', 'prix_base' => 250, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 2, 'attaque_diagonale' => true]],
            // Broadsword : 3 dés, PAS de diagonale — le diagramme des armes
            // longues du livret officiel (p. 14) lui oppose justement le bâton.
            ['nom' => 'Épée large', 'categorie' => 'arme', 'prix_base' => 250, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3, 'attaque_diagonale' => false]],
            // Longsword : l'une des deux seules armes que le livret OFFICIEL
            // nomme comme frappant en diagonale (« like the staff and the
            // longsword », p. 14). Une main : elle se combine au bouclier.
            ['nom' => 'Épée longue', 'categorie' => 'arme', 'prix_base' => 350, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_courante',
                'effet' => ['des_attaque' => 3, 'attaque_diagonale' => true]],
            ['nom' => 'Arbalète', 'categorie' => 'arme', 'prix_base' => 350, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_distance',
                // « You may fire at any monster that you can see. However, you
                // cannot fire at a monster that is adjacent to you. » — les deux
                // moitiés de `portee: distance` + `inutilisable_adjacent`, cette
                // fois SOURCÉES sur le matériel officiel.
                'effet' => ['des_attaque' => 3, 'portee' => 'distance', 'inutilisable_adjacent' => true]],
            // La hache de bataille N'EST PAS une arme longue : sa carte dit
            // seulement « You may not use a shield when using this weapon ».
            ['nom' => 'Hache de bataille', 'categorie' => 'arme', 'prix_base' => 450, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_deux_mains',
                'effet' => ['des_attaque' => 4, 'deux_mains' => true]],

            // ----- Matériel : les 4 cartes qui ne sont ni arme ni armure -----
            //
            // `categorie: outil` + `emplacement: consommable`, et c'est délibéré
            // sur les trois tableaux : ils s'empilent, ils sortent de la
            // capacité de sac, et `MoteurPotions::boire()` les REFUSE déjà (sa
            // garde d'entrée teste la catégorie) — la manette ne proposera donc
            // jamais « Boire » sur une bombe fumigène.
            ['nom' => 'Chausse-trappes', 'categorie' => 'outil', 'prix_base' => 100, 'emplacement' => 'consommable',
                'effet' => ['pose_chausse_trappes' => true]],
            ['nom' => 'Bombe fumigène', 'categorie' => 'outil', 'prix_base' => 100, 'emplacement' => 'consommable',
                'effet' => ['enfume_monstre_adjacent' => true]],
            // « Counts as a Tool Kit for disarming traps and you are always
            // considered to be armed with a dagger. It can only be used by the
            // Rogue. » ⚠ Elle n'ajoute AUCUN dé — le Rogue à mains nues en
            // lance déjà un, autant que la dague : ce qu'elle donne, ce sont les
            // règles qui EXIGENT une dague (son Ambidextrie).
            ['nom' => 'Bandoulière', 'categorie' => 'outil', 'prix_base' => 300, 'emplacement' => 'sac', 'tag_equipement' => 'outil_rogue',
                'effet' => ['permet_desamorcage' => true, 'compte_comme_arme' => 'Dague']],
            // « It kills any undead creature (skeleton, zombie, or mummy). »
            // Les trois noms sont des `monstres.nom_base`, comme sur la Lame des
            // Esprits — aucun tag « mort-vivant » n'est inventé sur le bestiaire.
            ['nom' => 'Eau bénite', 'categorie' => 'outil', 'prix_base' => 400, 'emplacement' => 'consommable',
                'effet' => ['tue_creatures' => ['Squelette', 'Zombie', 'Momie']]],

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
            ['nom' => "Potion d'héroïsme", 'categorie' => 'consommable', 'prix_base' => 150, 'emplacement' => 'consommable',
                'effet' => ['attaque_supplementaire' => true]],
            // `duree` : vocabulaire App\Engine\DureeEffet (reference/19_mots_cles_effets.md).
            // Ces deux-là portaient `duree => 0`, qui n'est pas une durée mais
            // l'absence de compteur : rien ne les retirait jamais. Force et
            // Défense sont donc des BURSTS (+2 sur un jet), là où Rage, au même
            // prix, tient tout le combat pour +1 — départ playtest.
            ['nom' => 'Potion de force', 'categorie' => 'consommable', 'prix_base' => 150, 'emplacement' => 'consommable',
                'effet' => ['bonus_des_attaque' => 2, 'duree' => 'prochaine_attaque', 'condition_appliquee' => 'Renforcé']],
            ['nom' => 'Potion de défense', 'categorie' => 'consommable', 'prix_base' => 150, 'emplacement' => 'consommable',
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

            // Artefacts à CHARGES et d'ÉCONOMIE DE SORTS (2026-08-09). Les deux
            // mécaniques manquaient et bloquaient à elles seules six cartes ;
            // elles ont été écrites ensemble parce que trois de ces cartes ont
            // besoin des DEUX (l'anneau et la baguette de Galimatias dépensent
            // une charge pour agir sur les sorts).

            // « Only an Elf may use this bow. An arrow fired from this bow hits
            // and instantly kills any one monster within the Elf's line of
            // sight, unless the monster rolls a black shield on 1 combat die.
            // There are only 4 arrows with this bow. »
            ['nom' => 'Arc elfique de Vindication', 'categorie' => 'arme', 'rarete' => 'unique', 'prix_base' => 1400, 'emplacement' => 'arme_principale', 'tag_equipement' => 'arme_arc_long',
                'effet' => ['des_attaque' => 2, 'portee' => 'distance', 'inutilisable_adjacent' => true,
                    'deux_mains' => true, 'tue_sauf_bouclier_noir' => true, 'charges' => 4]],

            // « The Hero holding this magical scroll may choose to skip a turn
            // trying to read it. When read, it restores all spells that Hero
            // possessed at the beginning of the quest. »
            //
            // Consommable : c'est le seul artefact à usage unique du catalogue,
            // et il a un canal — le coffre ne rend que des pièces portables,
            // mais un parchemin s'achète… non : `unique` le tient hors de
            // l'étal. Il arrive par le coffre, qui accepte désormais un
            // consommable en REPLI quand tout le portable est déjà détenu.
            ['nom' => 'Parchemin de Sorts', 'categorie' => 'consommable', 'rarete' => 'unique', 'prix_base' => 600, 'emplacement' => 'consommable',
                'effet' => ['restaure_sorts' => true]],

            // « This ring prevents the wearer from being affected by the next
            // two Fire or Chaos Fire spells they encounter. The ring turns to
            // ash after protecting the wearer from the second spell. » — deux
            // charges, chacune annulant INTÉGRALEMENT un sort de feu (immunité,
            // pas réduction : la carte dit « not affected »).
            ['nom' => 'Anneau de Feu', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 800, 'emplacement' => 'talisman',
                'effet' => ['immunite_degat' => 'feu', 'charges' => 2]],

            // « Restores any of the owner's Body Points lost by poisoning if
            // used immediately. » — même clé que l'Antidote, aucune mécanique
            // nouvelle. Elle n'attendait qu'un CANAL : depuis que le coffre
            // accepte un consommable en repli, elle en a un.
            ['nom' => 'Plume anti-poison', 'categorie' => 'consommable', 'rarete' => 'unique', 'prix_base' => 300, 'emplacement' => 'consommable',
                'effet' => ['retire_condition' => 'Empoisonné', 'soin_pv_body' => 2]],

            // « The Wand of Recall allows you to cast two spells instead of one
            // during your turn. » — le pouvoir du nœud Réserve arcanique, mais
            // porté par un objet. Les deux partagent `bonus_sort_utilise` : ils
            // ne se cumulent donc pas.
            ['nom' => 'Baguette de Rappel', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 1300, 'emplacement' => 'talisman', 'tag_equipement' => 'armure_magicien',
                'effet' => ['second_sort_par_tour' => true]],

            // « This Ring can be used to store extra magic before an adventure.
            // It enables the Elf or Wizard who carries it to cast one spell
            // twice in the same Quest. » — une charge, dépensée sur le prochain
            // sort lancé, qui ne s'épuise alors pas.
            ['nom' => 'Anneau de Sort', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 900, 'emplacement' => 'talisman',
                'effet' => ['sort_non_epuise' => true, 'charges' => 1]],

            // « The Rod of Memory allows you to attempt to cast this spell as
            // often as you wish during the quest. You may roll one combat die
            // per turn. On a black shield, the chosen spell may be cast again.
            // May only be used by a Wizard. » — illimité, mais 1 chance sur 6.
            ['nom' => 'Sceptre de Mémoire', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 1200, 'emplacement' => 'talisman', 'tag_equipement' => 'armure_magicien',
                'effet' => ['sort_non_epuise_sur_bouclier_noir' => true]],

            // « Immediately upon acquiring this item, the adventurer will
            // recover all spells he has used so far during this quest. It also
            // grants the wielder 2 extra Mind points. » — la restauration se
            // déclenche en L'ÉQUIPANT (une charge), le +2 Mind tient tant qu'on
            // le porte.
            ['nom' => 'Baguette de Galimatias', 'categorie' => 'armure', 'rarete' => 'unique', 'prix_base' => 1300, 'emplacement' => 'talisman', 'tag_equipement' => 'armure_magicien',
                'effet' => ['bonus_pv_mind_max' => 2, 'restaure_sorts' => true, 'charges' => 1]],

            // ----- Armures (6 cartes) -----
            //
            // Elles se CUMULENT, comme au plateau : casque (slot propre depuis
            // le 2026-08-08) + corps + bouclier. Défense maximale 2 + 1 + 2 + 1
            // = 6, la valeur du livret officiel (LR p. 7).
            //
            // ⚠ Les BRASSARDS n'ont AUCUNE restriction de classe sur leur
            // carte officielle : « These hardened leather bracers give you 1
            // extra Defend die. May be combined with the helmet and/or shield. »
            // Nous en faisions une pièce réservée au magicien (`armure_magicien`,
            // repris du paquet fan) — le tag saute, tout le monde peut les
            // porter, le magicien compris. La Cape de protection, elle, n'a pas
            // de carte : elle disparaît du catalogue.
            ['nom' => 'Casque', 'categorie' => 'armure', 'prix_base' => 125, 'emplacement' => 'casque', 'tag_equipement' => 'armure_legere',
                'effet' => ['des_defense' => 1]],
            ['nom' => 'Bouclier', 'categorie' => 'armure', 'prix_base' => 150, 'emplacement' => 'arme_secondaire', 'tag_equipement' => 'bouclier',
                // « May not be used with the battle axe or the staff » : chez
                // nous c'est `incompatible_deux_mains`, et les deux armes que la
                // carte nomme sont précisément nos deux `deux_mains` de base.
                'effet' => ['des_defense' => 1, 'incompatible_deux_mains' => true]],
            ['nom' => 'Cotte de mailles', 'categorie' => 'armure', 'prix_base' => 500, 'emplacement' => 'armure', 'tag_equipement' => 'armure_legere',
                'effet' => ['des_defense' => 1]],
            // ⚠ `tag_equipement` est déclaré NULL EXPLICITEMENT, et pas
            // simplement omis : `updateOrCreate` n'écrit que les colonnes qu'on
            // lui donne, donc une base déjà semée aurait gardé l'ancien
            // `armure_magicien` — les Brassards seraient restés réservés au
            // magicien sur les bases existantes, et ouverts à tous sur les
            // neuves. Constaté au re-seed du 2026-08-15.
            ['nom' => 'Brassards', 'categorie' => 'armure', 'prix_base' => 550, 'emplacement' => 'armure', 'tag_equipement' => null,
                'effet' => ['des_defense' => 1]],
            // « While wearing the Plate Mail, you have a 2 square movement
            // penalty » : un chiffre, là où on retirait tout le d6 (−3,5 en
            // moyenne). Le malus vient de la carte, pas d'une décision de table.
            ['nom' => 'Armure de plates', 'categorie' => 'armure', 'prix_base' => 850, 'emplacement' => 'armure', 'tag_equipement' => 'armure_lourde',
                'effet' => ['des_defense' => 2, 'malus_deplacement' => 2]],

            // ----- Outils -----
            ['nom' => 'Trousse à outils', 'categorie' => 'outil', 'prix_base' => 250, 'emplacement' => 'sac',
                'effet' => ['permet_desamorcage' => true]],

            // ----- Potions du DECK DE TRÉSOR (conservées) -----
            // Ce ne sont pas des articles de boutique : ce sont les cartes que
            // `DeckFouille` tire nommément, et qui existent au plateau dans le
            // deck de trésor. Elles cohabitent donc avec les potions officielles
            // ci-dessous sans faire doublon de nom.
            ['nom' => 'Potion de soin', 'categorie' => 'consommable', 'prix_base' => 100, 'emplacement' => 'consommable',
                'effet' => ['soin_pv_body' => 4]],

            // ----- Les 15 potions officielles (`potions.pdf`, doc 16 §2.1bis) -----
            //
            // Trois sont réservées au BARBARE et deux à l'ELFE : c'est le texte
            // des cartes, et c'est la première fois qu'un consommable porte une
            // restriction de classe. Elle passe par `tag_equipement`, comme
            // toutes les autres, et `MoteurPotions::boire()` la fait respecter.
            ['nom' => 'Potion de dextérité', 'categorie' => 'consommable', 'prix_base' => 100, 'emplacement' => 'consommable',
                // « adds 5 movement squares to your next dice roll OR guarantees
                // one successful pit jump » — les deux moitiés sont posées
                // ensemble, le joueur prend celle que sa situation lui offre.
                'effet' => ['bonus_deplacement' => 5, 'saut_fosse_automatique' => true, 'une_par_tour' => true,
                    'duree' => 'ce_tour', 'condition_appliquee' => 'Renforcé']],
            ['nom' => 'Potion de bataille', 'categorie' => 'consommable', 'prix_base' => 200, 'emplacement' => 'consommable',
                'effet' => ['relance_des_attaque' => true, 'duree' => 'prochaine_attaque', 'condition_appliquee' => 'Renforcé']],
            ['nom' => 'Potion de soin mineur', 'categorie' => 'consommable', 'prix_base' => 200, 'emplacement' => 'consommable',
                'effet' => ['soin_pv_body' => 2]],
            ['nom' => 'Potion de vitesse', 'categorie' => 'consommable', 'prix_base' => 200, 'emplacement' => 'consommable',
                'effet' => ['deplacement_multiplie' => 2, 'duree' => 'ce_tour', 'condition_appliquee' => 'Renforcé']],
            ['nom' => 'Potion de force glaciale', 'categorie' => 'consommable', 'prix_base' => 200, 'emplacement' => 'consommable', 'tag_equipement' => 'potion_barbare',
                'effet' => ['multiplicateur_degats' => 2, 'duree' => 'prochaine_attaque', 'condition_appliquee' => 'Renforcé']],
            ['nom' => 'Antidote au venin', 'categorie' => 'consommable', 'prix_base' => 300, 'emplacement' => 'consommable',
                // ⚠ « caused by poison needles or poison darts only » n'est PAS
                // portable : la source d'un dégât n'est mémorisée nulle part sur
                // le héros. Même forme que la Plume anti-poison (doc 16 §10).
                'effet' => ['retire_condition' => 'Empoisonné', 'soin_pv_body' => 2]],
            ['nom' => 'Potion de peau de givre', 'categorie' => 'consommable', 'prix_base' => 300, 'emplacement' => 'consommable', 'tag_equipement' => 'potion_barbare',
                'effet' => ['bonus_des_defense' => 2, 'duree' => 'plus_de_monstre_en_vue', 'condition_appliquee' => 'Renforcé']],
            ['nom' => 'Potion de magie', 'categorie' => 'consommable', 'prix_base' => 400, 'emplacement' => 'consommable',
                'effet' => ['restaure_sorts' => 3]],
            ['nom' => 'Potion de rappel', 'categorie' => 'consommable', 'prix_base' => 400, 'emplacement' => 'consommable', 'tag_equipement' => 'potion_elfe',
                'effet' => ['restaure_sorts' => 1]],
            ['nom' => 'Potion de rage guerrière', 'categorie' => 'consommable', 'prix_base' => 400, 'emplacement' => 'consommable', 'tag_equipement' => 'potion_barbare',
                // « 2 attacks per turn as long as there are monsters in sight » :
                // le drapeau se pose ici, et `rythmerBuffsDeVue()` le RÉARME à
                // chaque début de tour tant qu'un ennemi est en vue.
                'effet' => ['attaque_supplementaire' => true, 'duree' => 'plus_de_monstre_en_vue', 'condition_appliquee' => 'Renforcé']],
            // Guérison et Régénération sont mécaniquement IDENTIQUES chez nous
            // (1d6 plafonné au maximum, même prix) : c'est le texte des deux
            // cartes, l'une disant « roll 1 red die », l'autre « up to 6 lost
            // Body Points. Roll 1 red die ». On les sème quand même toutes les
            // deux, et on le dit (doc 16 §10).
            ['nom' => 'Potion de guérison', 'categorie' => 'consommable', 'prix_base' => 500, 'emplacement' => 'consommable',
                'effet' => ['soin_pv_body_de' => 6]],
            ['nom' => 'Potion de régénération', 'categorie' => 'consommable', 'prix_base' => 500, 'emplacement' => 'consommable',
                'effet' => ['soin_pv_body_de' => 6]],
            ['nom' => 'Potion de restauration', 'categorie' => 'consommable', 'prix_base' => 500, 'emplacement' => 'consommable',
                'effet' => ['soin_pv_body' => 1, 'soin_pv_mind' => 1]],
            ['nom' => 'Potion de vision', 'categorie' => 'consommable', 'prix_base' => 500, 'emplacement' => 'consommable', 'tag_equipement' => 'potion_elfe',
                'effet' => ['revele_pieges_et_portes_en_vue' => true, 'duree' => 'premier_degat_subi', 'condition_appliquee' => 'Clairvoyance']],
            ['nom' => 'Potion de restauration supérieure', 'categorie' => 'consommable', 'prix_base' => 800, 'emplacement' => 'consommable',
                // « restores any hero's Body and Mind Points to the level they
                // were at when the hero started the Quest » = au MAXIMUM chez
                // nous. La clause « cure a hero turned into a werewolf » n'a pas
                // d'objet : aucun lycanthrope au bestiaire.
                'effet' => ['restaure_jauges_depart' => true]],
        ];

        foreach ($objets as $objet) {
            // ⚠ La RARETÉ se déduit du PRIX (`RareteButin::pourPrix()`) pour tout
            // ce qui n'en déclare pas — armes, armures, outils, consommables.
            // Elle était posée à la main et avait dérivé sans que rien ne le
            // voie : Hachette 200 po « commune » quand Baguette 125 était « peu
            // commune », Cotte de mailles 500 « rare » et Brassards 550 non.
            //
            // Gardent leur rareté DÉCLARÉE : les artefacts (`unique`, qui n'est
            // pas une bande de prix mais un statut) et les parchemins, dont la
            // rareté vient de la difficulté du sort — un meilleur signal que
            // leur prix, qui n'en est que le reflet.
            $objet['rarete'] ??= RareteButin::pourPrix((int) $objet['prix_base']);

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
