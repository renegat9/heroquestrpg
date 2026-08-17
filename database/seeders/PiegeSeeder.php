<?php

namespace Database\Seeders;

use App\Models\Piege;
use Illuminate\Database\Seeder;

/**
 * Les 4 pièges de base HeroQuest (doc 10 §6).
 * Dégâts : 1 PV de Body partout (question ouverte n°1 — valeur de départ).
 */
class PiegeSeeder extends Seeder
{
    public function run(): void
    {
        $pieges = [
            ['nom' => 'Fosse', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'persistant',
                'effet' => [
                    'degats_pv_body' => 1,
                    'condition_appliquee' => 'Immobilisé', // perd son déplacement
                    'franchissable' => ['jet' => 'body', 'difficulte' => 2, 'si' => 'detectee'],
                ]],
            ['nom' => 'Piège à lances', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique',
                'effet' => ['degats_pv_body' => 1]],
            ['nom' => 'Chute de blocs', 'detectable' => true, 'desarmable' => 'partiel', 'usage' => 'unique',
                'effet' => ['degats_pv_body' => 1, 'bloque_passage' => true]],
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
