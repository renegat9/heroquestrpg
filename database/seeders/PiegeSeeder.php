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
