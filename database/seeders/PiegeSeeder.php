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
        ];

        foreach ($pieges as $piege) {
            Piege::updateOrCreate(['nom' => $piege['nom']], $piege);
        }
    }
}
