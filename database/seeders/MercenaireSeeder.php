<?php

namespace Database\Seeders;

use App\Models\Mercenaire;
use Illuminate\Database\Seeder;

/**
 * Alliés recrutables (doc 14 §3.5) — valeurs de départ à régler en playtest.
 * Mercenaires (humains, embauchés contre or) + compagnons animaux (un par groupe,
 * une seule action : attaquer). `prix` en or de la bourse commune.
 */
class MercenaireSeeder extends Seeder
{
    public function run(): void
    {
        $mercenaires = [
            ['nom' => 'Archer mercenaire', 'type' => 'archer',
                'deplacement' => 8, 'attaque' => 2, 'portee' => 'distance', 'attaque_distance' => 3,
                'defense' => 2, 'pv_body' => 1, 'prix' => 150, 'animal' => false,
                'description' => 'Tireur à gages : harcèle l\'ennemi à distance avec ligne de vue.'],
            ['nom' => 'Hallebardier', 'type' => 'hallebardier',
                'deplacement' => 6, 'attaque' => 3, 'defense' => 3, 'pv_body' => 2, 'prix' => 220, 'animal' => false,
                'description' => 'Fantassin en armure : tient la ligne au corps-à-corps.'],
            ['nom' => 'Loup fidèle', 'type' => 'compagnon',
                'deplacement' => 10, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'prix' => 120, 'animal' => true,
                'description' => 'Compagnon animal : rapide, mord l\'ennemi le plus proche ; n\'agit qu\'en attaquant.'],
        ];

        foreach ($mercenaires as $m) {
            Mercenaire::updateOrCreate(['nom' => $m['nom']], $m);
        }
    }
}
