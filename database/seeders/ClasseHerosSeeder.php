<?php

namespace Database\Seeders;

use App\Models\ClasseHeros;
use Illuminate\Database\Seeder;

/**
 * Les 4 héros — valeurs de départ du doc 01 §4 (PV canon HeroQuest, attributs de jet proposés).
 */
class ClasseHerosSeeder extends Seeder
{
    public function run(): void
    {
        $classes = [
            // nom, pv_body, pv_mind, attr_body, attr_mind, attaque, defense, dépl., bonus_sac
            ['nom' => 'barbare',  'pv_body' => 8, 'pv_mind' => 2, 'attr_body' => 4, 'attr_mind' => 1, 'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4, 'bonus_sac' => 0],
            ['nom' => 'nain',     'pv_body' => 7, 'pv_mind' => 3, 'attr_body' => 3, 'attr_mind' => 2, 'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 3, 'bonus_sac' => 1],
            ['nom' => 'elfe',     'pv_body' => 6, 'pv_mind' => 4, 'attr_body' => 2, 'attr_mind' => 3, 'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 5, 'bonus_sac' => 0],
            ['nom' => 'magicien', 'pv_body' => 4, 'pv_mind' => 6, 'attr_body' => 1, 'attr_mind' => 4, 'des_attaque' => 1, 'des_defense' => 2, 'deplacement_base' => 4, 'bonus_sac' => 0],
        ];

        foreach ($classes as $classe) {
            ClasseHeros::updateOrCreate(['nom' => $classe['nom']], $classe);
        }
    }
}
