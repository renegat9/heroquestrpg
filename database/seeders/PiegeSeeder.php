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
                'effet' => ['des_combat' => 3, 'bloc_permanent' => true]],
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
        ];

        foreach ($pieges as $piege) {
            Piege::updateOrCreate(['nom' => $piege['nom']], $piege);
        }
    }
}
