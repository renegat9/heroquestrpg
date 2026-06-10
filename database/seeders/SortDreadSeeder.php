<?php

namespace Database\Seeders;

use App\Models\SortDread;
use Illuminate\Database\Seeder;

/**
 * Magie du Chaos (doc 09 §4) — résolution identique aux sorts héros (doc 02 §5).
 * « Tempête de feu » est notée sous-boss/boss dans le doc : palier = sous_boss (le plus précoce).
 */
class SortDreadSeeder extends Seeder
{
    public function run(): void
    {
        $sorts = [
            ['nom' => 'Trait de Chaos', 'palier' => 'sous_boss', 'type' => 'degats',
                'effet' => ['portee' => 'distance', 'des_degats' => 2, 'defense_applicable' => true, 'cible' => 'heros']],
            ['nom' => 'Frayeur', 'palier' => 'sous_boss', 'type' => 'controle',
                'effet' => ['cible' => 'heros', 'resistance' => 'jet_mind', 'condition_appliquee' => 'Apeuré']],
            ['nom' => 'Sommeil', 'palier' => 'sous_boss', 'type' => 'controle',
                'effet' => ['cible' => 'heros', 'resistance' => 'jet_mind', 'condition_appliquee' => 'Endormi', 'fin' => 'reveil_ou_attaque']],
            ['nom' => 'Tempête de feu', 'palier' => 'sous_boss', 'type' => 'degats',
                'effet' => ['cible' => 'heros_zone', 'des_degats' => 2, 'defense_applicable' => true]],
            ['nom' => 'Invocation de morts-vivants', 'palier' => 'boss', 'type' => 'invocation',
                'effet' => ['invoque' => ['Squelette', 'Zombie'], 'nombre' => 2]],
            ['nom' => 'Commandement', 'palier' => 'boss', 'type' => 'controle',
                'effet' => ['cible' => 'heros', 'resistance' => 'jet_mind', 'condition_appliquee' => 'Commandé', 'duree_tours' => 1]],
            ['nom' => 'Fuite', 'palier' => 'boss', 'type' => 'fuite',
                'effet' => ['teleportation' => 'phase_suivante']],
        ];

        foreach ($sorts as $sort) {
            SortDread::updateOrCreate(['nom' => $sort['nom']], $sort);
        }
    }
}
