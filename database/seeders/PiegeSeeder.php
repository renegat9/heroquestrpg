<?php

namespace Database\Seeders;

use App\Models\Piege;
use Illuminate\Database\Seeder;

/**
 * Les pièges de HeroQuest (doc 10 §6).
 *
 * Les TROIS pièges DE SOL (Fosse, Piège à lances, Chute de blocs) sont
 * sourcés livret de Zargon p. 14 (contrat « Les trois pièges de sol, enfin
 * tels que le livret les décrit », 2026-09-24), recoupé par
 * `reference/16_armurerie.md` §716 et `reference/17_mobilier.md` §121 — voir
 * la migration `valeurs_pieges_de_sol` pour la ligne EXISTANTE du catalogue,
 * que cette entrée `updateOrCreate` tient alignée pour une base fraîche.
 *
 * `des_combat` (lu par `MoteurPieges::declencher()`) : nombre de dés de
 * combat lancés, un crâne = 1 PV de Body, sans jet de défense — le piège
 * n'en a jamais lancé un. `bloc_permanent` (Chute de blocs) fait de la case
 * un obstacle qui bloque passage ET vue, à jamais (`FabriqueGrille::pour()`).
 */
class PiegeSeeder extends Seeder
{
    public function run(): void
    {
        $pieges = [
            ['nom' => 'Fosse', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'persistant',
                'effet' => [
                    'degats_pv_body' => 1,
                    'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
                ]],
            ['nom' => 'Piège à lances', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique',
                'effet' => ['des_combat' => 1]],
            ['nom' => 'Chute de blocs', 'detectable' => true, 'desarmable' => 'partiel', 'usage' => 'unique',
                // `franchissable` (2026-09-27) : « peut être désamorcée/sautée
                // AVANT déclenchement » (livret p. 14, doc 16 §7.3) — le même
                // saut que la fosse. Voir la migration `chute_de_blocs_franchissable`.
                'effet' => [
                    'des_combat' => 3,
                    'bloc_permanent' => true,
                    'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
                ]],
            // Deux pièges de MEUBLE (décision de René, 2026-08-17) : le tombeau
            // et l'établi de l'alchimiste peuvent mordre la main qui les fouille.
            //
            // L'AIGUILLE reprend le barème du piège de coffre — un point de Body
            // ou du poison, tiré au hasard. La FIOLE, elle, empoisonne à coup
            // sûr : un établi d'alchimiste ne cogne pas, il intoxique.
            // Le NOM compte pour le joueur : lire « Piège de coffre » en ouvrant
            // un tombeau casse la fiction.
            //
            // ⚠ Comme le piège de coffre, ils ne sont pas posés sur la carte :
            // ils sont ÉPHÉMÈRES, déclenchés par la fouille (`declencherEphemere`).
            // D'où `declencheur: ouverture_tresor`, qui les tient hors de la
            // génération de donjon.
            ['nom' => 'Aiguille empoisonnée', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique',
                'effet' => [
                    'declencheur' => 'ouverture_tresor',
                    'detection' => 'fouille_du_tresor',
                    'aleatoire' => [
                        ['degats_pv_body' => 1],
                        ['condition_appliquee' => 'Empoisonné'],
                    ],
                ]],
            // ⚠ Celui-ci n'est PAS aléatoire, et c'est voulu (René, 2026-08-17) :
            // un établi d'alchimiste empoisonne, il ne cogne pas. Sans clé
            // `aleatoire`, l'effet s'applique tel quel — c'est le seul piège du
            // catalogue à ne poser QUE une condition, sans un point de dégât.
            //
            // Ce n'est pas plus doux, au contraire : `Empoisonné` dure 3 tours à
            // 1 PV par tour, là où le coup unique du piège de coffre en retire
            // un seul. Et il reste résistible (Sang robuste), ce qu'un dégât sec
            // n'est jamais.
            ['nom' => 'Fiole de poison', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique',
                'effet' => [
                    'declencheur' => 'ouverture_tresor',
                    'detection' => 'fouille_du_tresor',
                    'condition_appliquee' => 'Empoisonné',
                ]],
            ['nom' => 'Piège de coffre', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique',
                'effet' => [
                    'declencheur' => 'ouverture_tresor',
                    'detection' => 'fouille_du_tresor',
                    'aleatoire' => [
                        ['degats_pv_body' => 1],
                        ['condition_appliquee' => 'Empoisonné'],
                    ],
                ]],

            // ===== Against the Ogre Horde (livret F9528 p. 4-5, lot B) =====
            // Boîte `horde_ogre` (migration `boite_pieges_et_mobiliers`) : ces
            // deux pièges ne sont posés que sur une carte dont le thème de
            // bestiaire du groupe inclut cette boîte (`AssembleurCarte`, même
            // lecture que `Terrain::boite`).

            // LAME BALANÇOIRE (« Swinging Blade Trap », p. 4-5) : « triggers
            // if a hero moves onto a square with the gold overlay […]. A huge
            // blade swings down […], slicing any heroes on one of the squares
            // marked with a white or red blade symbol. Zargon rolls 2 Attack
            // dice, and any affected heroes roll Defend dice as normal. » —
            // PREMIER piège à PLUSIEURS cases : `MoteurPieges::declencherZone()`
            // frappe CHAQUE héros présent sur une case de `effet.zone_lames`
            // (dés d'attaque PLEIN contre défense normale, à la différence du
            // Piège à lances/Chute de blocs qui ne lancent jamais de défense).
            //
            // ⚠ PORTAGE : le livret ne donne le gabarit exact de la zone que
            // sur le plan imprimé d'une quête précise, que nous ne reprenons
            // pas (donjons générés). `zone_lames` est une forme RELATIVE à la
            // case dorée de déclenchement — une ligne de 3 cases — posée par
            // `AssembleurCarte::placerLameBalanciere()` sur l'axe (horizontal
            // OU vertical) qui tient dans la salle au moment du tirage. C'est
            // une décision de jeu, pas une valeur sourcée : le nombre de dés
            // (2) et le fonctionnement (zone + défense normale), eux, SONT
            // sourcés p. 4-5.
            //
            // `desarmage_special` (lu par `ResolveurTour::resoudreDesamorcage()`)
            // nomme la procédure propre à CE piège : « The dwarf may
            // automatically disarm […] once it has been discovered. Any other
            // hero with a tool kit may attempt to disarm […] roll one combat
            // die. If they roll a shield, they successfully disarm the trap.
            // If they roll a skull, the trap is immediately triggered. » — un
            // DÉ DE COMBAT, jamais le jet de Body de la trousse ordinaire.
            ['nom' => 'Lame balançoire', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'persistant', 'boite' => 'horde_ogre',
                'effet' => [
                    'des_attaque_zone' => 2,
                    'zone_lames' => [[0, -1], [0, 0], [0, 1]],
                    'desarmage_special' => 'lame_balanciere',
                ]],

            // FOSSE DES TÉNÈBRES (« Pit of Darkness », p. 5) : « works in the
            // same way as a normal pit trap […] except: Pits of darkness
            // cannot be disarmed, but heroes can jump over them […]. If a
            // hero crossing […] rolls a skull, they plunge […]. Heroes
            // wearing no armor or only non-metal armor take 1 Body Point […].
            // Heroes wearing metal armor take 2 […], unless they're wearing
            // plate mail, in which case they take 3. » Variante de la Fosse :
            // même `franchissable` (jet de Body pour sauter, comme la Fosse
            // ordinaire — le livret ne change que le désamorçage et les
            // dégâts), `desarmable => 'non'` (lu par `MenuMoteur::generer()` —
            // l'option « Désamorcer » ne doit JAMAIS être proposée),
            // `degats_selon_armure` (lu par `MoteurPieges::declencher()`, qui
            // consulte `Equipement::porteArmureDePlates()`/`porteArmureMetallique()`
            // AU MOMENT de la chute) remplace `degats_pv_body`.
            ['nom' => 'Fosse des ténèbres', 'detectable' => true, 'desarmable' => 'non', 'usage' => 'persistant', 'boite' => 'horde_ogre',
                'effet' => [
                    'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
                    'degats_selon_armure' => true,
                ]],

            // ===== Wizards of Morcar (carton « Magic Reference Chart » + livret
            // G1504 p. 2/7/12, lot B, 2026-10-06) — TROIS pièges magiques,
            // communs aux trois : « cannot be found by searching » et « may
            // only be activated once » (`detectable: false`, `usage: 'unique'`,
            // reconnus par `MoteurPieges::reveler()`/`piegesCachesAdjacents()` —
            // la fouille, l'Œil du mineur, le Sens du piège et la Potion de
            // Vision les ignorent TOUS). Portés sur le patron existant :
            // `declencheur` nomme la résolution spéciale (le nom, pas
            // `'ouverture_tresor'`, les garde HORS du tirage de coffre/meuble,
            // et HORS du tirage générique de `AssembleurCarte::placerPieges()`,
            // qui les exclut nommément). Posés par
            // `AssembleurCarte::placerPiegesMorcar()`.

            // TELEPORT TRAP — « finishing movement on space A teleports the
            // character to space B elsewhere on the board, disoriented, their
            // turn ends ». Paire A/B posée par instance de carte
            // (`cartes.grille.pieges[].paire_id`), MÊME patron que les Tunnels
            // de glace (`TerrainSeeder`) — `MoteurPieges::declencherTeleportation()`.
            // « Leur tour se termine » est hérité GRATUITEMENT : tout
            // déclenchement de piège de sol force déjà `finTourPiegeSol`
            // (`ResolveurTour::resoudreDeplacement()`), aucune clé n'y est
            // donc nécessaire ici.
            ['nom' => 'Piège de téléportation', 'detectable' => false, 'desarmable' => 'non', 'usage' => 'unique', 'boite' => 'wizards_of_morcar',
                'effet' => ['teleportation' => true]],

            // HURRICANE TRAP — « repousse tous les personnages du couloir de
            // 8 cases en arrière (ou jusqu'au premier mur/piège) ». Posé en
            // COULOIR seulement (`placerPiegesMorcar()`) ; `portee_recul`
            // nombre MAXIMUM de cases, sourcé par la carte — jamais un
            // compte à inventer. Scope ASSUMÉ : les HÉROS seulement (aucun
            // piège de sol existant ne touche jamais un monstre ou un allié)
            // — voir `MoteurPieges::declencherHurricane()`.
            ['nom' => "Piège de l'ouragan", 'detectable' => false, 'desarmable' => 'non', 'usage' => 'unique', 'boite' => 'wizards_of_morcar',
                'effet' => ['declencheur' => 'hurricane', 'portee_recul' => 8]],

            // FIREBURST TRAP — « a Fireburst token remains until the
            // beginning of Zargon's turn, when it will explode, attacking
            // all heroes and monsters in the room with 3 Attack dice ».
            // DEUX nombres de dés, pour DEUX producteurs distincts du même
            // mishap : `des_attaque_zone` (3, défense NORMALE, salle entière,
            // héros ET monstres) résout l'explosion DIFFÉRÉE au tour du MJ
            // (`MoteurPieges::explosionsFireburstEnAttente()`, appelée en
            // tête de `ResolveurTour::phaseMonstres()`) ; `des_combat` (3,
            // SANS défense, le fouilleur seul) résout la carte de trésor
            // « Magical Trap » qui déclenche la MÊME mécanique (doc 18 §8 :
            // « you set off a Fireburst trap ») via le résolveur éphémère
            // générique — DIVERGENCE NOMMÉE : la carte ne frappe que le
            // tireur, jamais toute la salle, faute d'un index de grille à
            // armer pour une instance qu'aucune case ne porte. Le désamorçage
            // par *Tempest*/un sort d'Eau n'est PAS câblé : ni l'un ni
            // l'autre n'existe dans nos 9 sorts de héros transcrits
            // (Protection/Détection/Ténèbres) ni dans les 30 sorts de
            // Sorcier du Dread — ⚠ non trouvé, dette nommée plutôt qu'un
            // sort inventé.
            ['nom' => 'Piège d\'embrasement', 'detectable' => false, 'desarmable' => 'non', 'usage' => 'unique', 'boite' => 'wizards_of_morcar',
                'effet' => ['declencheur' => 'fireburst_differe', 'des_attaque_zone' => 3, 'des_combat' => 3, 'type_degat' => 'feu']],

            // POISON (carte de TRÉSOR, doc 18 §8, lot « Cartes de trésor »,
            // 2026-10-06) : « roll 1 combat die ; on a skull, lose 1 Body
            // Point, otherwise nothing ». ÉPHÉMÈRE comme Piège de coffre/
            // Aiguille empoisonnée (`declencheur: 'ouverture_tresor'`, jamais
            // posé sur la grille) — `des_combat: 1` réutilise le MÊME calcul
            // de dé que les pièges de sol, désormais partagé par
            // `MoteurPieges::resoudreDesCombat()`.
            ['nom' => 'Poison', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique', 'boite' => 'wizards_of_morcar',
                'effet' => ['declencheur' => 'ouverture_tresor', 'detection' => 'fouille_du_tresor', 'des_combat' => 1]],

            // ===== Jungles of Delthrak (livret F9907 p. 4-5, lot E) =====
            // Boîte `jungles_delthrak` : tiré par le vivier générique de
            // `AssembleurCarte::placerPieges()` sous ce thème seulement.

            // LIANES AGRIPPANTES (« Grasping Vine Trap », p. 4-5) : « If found
            // during a search, Zargon remarks that the square looks suspicious
            // and points to the square […] without marking it with a tile.
            // Once a grasping vines trap has been discovered, a hero may
            // attempt to jump or disarm the trap. » — détectable par la FOUILLE
            // ORDINAIRE (`detectable: true`, la carte publiée montre la case :
            // notre pendant du « points to the square »), se saute et se
            // désamorce comme tout piège de sol (`franchissable` = le même saut
            // de Body que la Fosse, `desarmable: oui` = la procédure ordinaire —
            // le livret n'en donne pas d'autre).
            // « If a hero steps onto the square […] the trap springs and vines
            // lash out to attack them. The hero must roll 1 combat die. On a
            // black or white shield, they successfully dodge the vines and may
            // continue their movement. If they roll a skull, they suffer 1 Body
            // Point of damage and are held in place by the vines. Their turn
            // immediately ends. They cannot move from the square until they or
            // another adjacent hero spends an action to destroy the vines. The
            // hero is then freed and the trap is removed from the board. »
            //  - `des_combat: 1` : UN dé de combat, un crâne = 1 PV de Body, sans
            //    jet de défense — la forme déjà lue pour les trois pièges de sol ;
            //  - `esquive_sur_bouclier` : un jet SANS crâne n'a aucune suite —
            //    ni dégât, ni arrêt (lu par `MoteurPieges::declencher()`) ;
            //  - `retient: Immobilisé` : sur un crâne, la condition qui interdit
            //    le déplacement (`deplacement_interdit`) et que l'action
            //    « Détruire les entraves » lève — celle d'*Étreinte des Ronces*,
            //    mêmes mots sur la carte (« they or another adjacent hero can
            //    spend an action to destroy the vines »).
            // ⚠ `usage: unique` et non `persistant` : `persistant` désigne la
            // FOSSE (`MoteurPieges::estFosse()` — Forme démoniaque, Potion de
            // dextérité), et les lianes n'en sont pas une. Qu'elles RESTENT armées
            // après une esquive est décidé par `esquive_sur_bouclier`, pas par
            // l'usage.
            ['nom' => 'Piège de lianes', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique', 'boite' => 'jungles_delthrak',
                'effet' => [
                    'des_combat' => 1,
                    'esquive_sur_bouclier' => true,
                    'retient' => 'Immobilisé',
                    'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
                ]],

            // MARE D'ACIDE (*Acid Pool Trap*, The Crypt of Perpetual Darkness, G1798 p. 5 — texte intégral) :
            // « Acid pools burn the skin of any hero who comes into contact with it. As a hero, if you move onto
            // this trap square, Zargon puts an acid pool under your hero figure, and you must roll 1 combat die.
            // On a skull, you suffer 1 Body Point of damage. The acid remains on the ground and affects any hero
            // who moves onto it. Acid pools cannot be disarmed but can be jumped. »
            // - `des_combat: 1` : un dé de combat, un crâne = 1 PV de Body, SANS jet de défense (`declencher()`).
            // - `type_degat: acide` : NATURE lue par `MoteurSorts::absorbeDegat()` (`declencher()`). Aucune pièce
            //   du catalogue ne porte encore `immunite_degat: acide` : le lecteur est en place, pas alimenté.
            // - `usage: persistant` + `mare: true` : la mare reste au sol et se déclenche à CHAQUE pas (état
            //   `mare_acide`). `mare` la sort de `estFosse()` : une mare n'est pas un trou.
            // - `desarmable: 'non'` (« cannot be disarmed ») ; `franchissable` (« can be jumped ») : le saut de la
            //   Fosse des ténèbres, son précédent le plus proche. Le livret ne chiffre pas ce saut (⚠ non trouvé).
            // - FIN DE TOUR : aucune phrase du livret ne la ferme ; elle la ferme comme tout piège de sol armé
            //   (`controlerChemin()`, précédent le plus proche). Choix signalé, pas une citation.
            // - Boîte `crypte_tenebres` : thème ALLUMÉ le 2026-10-23 (décision de René) — `AssembleurCarte::placerPieges()`
            //   ne la pose que sous la Crypte.
            ['nom' => "Mare d'acide", 'detectable' => true, 'desarmable' => 'non', 'usage' => 'persistant',
                'boite' => 'crypte_tenebres',
                'effet' => [
                    'des_combat' => 1,
                    'type_degat' => 'acide',
                    'mare' => true,
                    'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
                ]],

            // ===== Spirit Queen's Torment (G0053, boîte `spirit_queen`) =====
            // Quête 2, note C, p. 5 : « The treasure chest has a magical trap. If a hero searches for treasure before the
            // trap is disarmed, the Dread spell Ball of Flame is cast on that hero. The trap may be disarmed normally. »
            // Quête 6, note B, p. 9 : « Each of these chests is trapped. If a hero searches for treasure before the trap is
            // disarmed, the Dread spell Ball of Flame is cast on that hero. The trap may be disarmed normally. »
            // ÉPHÉMÈRE comme le Piège de coffre (`declencheur: ouverture_tresor`) : déclenché par la fouille, jamais posé.
            // `sort_dread` NOMME le sort ; ses chiffres (2 PV fixes, 2 dés rouges) sont lus au catalogue, jamais recopiés ici
            // (`MoteurPieges::declencherEphemere()` → `MoteurDread::lancerSortDePiege()`).
            // Le « may be disarmed normally » n'est pas porté : aucun geste de désamorçage n'existe pour un coffre aujourd'hui
            // (le menu ne le propose qu'à la fournaise), donc le piège part à la fouille, comme le Piège de coffre.
            // `boite: spirit_queen` : E1 (décision de René, 2026-10-29) — ce piège n'existe que sous ce thème.
            ['nom' => 'Piège de Boule de Flammes', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique',
                'boite' => 'spirit_queen',
                'effet' => [
                    'declencheur' => 'ouverture_tresor',
                    'sort_dread' => 'Boule de Flammes',
                ]],

            // ===== The Mage of the Mirror (F7539 p. 6, « Long Pit Trap », lot G, 2026-10-29) =====
            // Texte intégral, relu au livret : « If a hero wants to jump over this pit the long way, they must have at least
            // three squares of movement left after they move next to the pit. The hero then rolls one combat die. A black
            // shield means the hero has jumped across safely, using up three squares of movement. The hero can continue to
            // move if they have more movement left. Any result other than a black shield means the hero falls into the pit,
            // suffering 2 Body Points of damage. On a subsequent turn, the hero must roll a 5 or 6 on a red die to climb out
            // of the pit. While in the pit, the hero rolls one less combat die when attacking or defending (but never less
            // than one die). Note: These traps can be jumped across the short side, the same as regular pit traps. »
            //
            // MESURE (planche « Design Your Own Quest Adventures », F7539 p. 39 = PDF 20, rendu 200 dpi) : une Pit Trap fait
            // 54 × 52 px, la Long Pit Trap 106 × 52 px — 2,04 : 1, donc DEUX cases de long et UNE de large. La pose
            // (`AssembleurCarte::placerFosseLongue()`) pose donc DEUX entrées de grille, liées par `groupe_id`.
            //
            // - `degats_pv_body: 2` : « suffering 2 Body Points of damage » — lu par `MoteurPieges::declencher()`.
            // - `franchissable` : LE PETIT CÔTÉ, « the same as regular pit traps » — le saut ordinaire de la Fosse, tel quel
            //   (`ResolveurTour::resoudreFranchissement()`, réutilisé, aucun second saut).
            // - `saut_long: {face}` : LE GRAND CÔTÉ — le saut par le bout, un dé de combat, la face qui réussit (lue par
            //   `MoteurFosseLongue::sautsLongs()`). Coût : les deux cases de fosse plus la réception = 3 (le livret).
            // - `dans_la_fosse` : la chute RETIENT le héros (`sortie_sur`, un dé rouge, lu par `MoteurFosseLongue::resoudreSortie()`),
            //   et lui retire `malus_des_combat` dé de combat, en attaque comme en défense, jamais sous 1 (lu par
            //   `MoteurFosseLongue::malusDesCombat()` au point de passage de `frapper()` et de `desDefenseHerosDetail()`).
            // - `usage: persistant` : le trou reste (`fosse_ouverte`), comme la Fosse. `estFosse()` le désigne donc : Forme
            //   démoniaque et Potion de dextérité s'y appliquent, comme à toute fosse (le livret ne dit pas le contraire).
            // - `desarmable: oui` : ⚠ NON TROUVÉ dans le livret pour cette fosse précise. Elle suit la Fosse ordinaire (la note
            //   « the same as regular pit traps ») — décision signalée, pas une citation.
            // - Boîte `mage_du_miroir` : n'est posée que sous ce thème (`AssembleurCarte::placerFosseLongue()`).
            ['nom' => 'Fosse longue', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'persistant',
                'boite' => 'mage_du_miroir',
                'effet' => [
                    'degats_pv_body' => 2,
                    'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
                    'saut_long' => ['face' => 'bouclier_noir'],
                    'dans_la_fosse' => ['sortie_sur' => [5, 6], 'malus_des_combat' => 1],
                ]],
        ];

        foreach ($pieges as $piege) {
            Piege::updateOrCreate(['nom' => $piege['nom']], $piege);
        }
    }
}
