<?php

namespace Database\Seeders;

use App\Models\Monstre;
use Illuminate\Database\Seeder;

/**
 * Bestiaire (doc 09 §3-4) : 8 monstres de base + gabarits sous-boss/boss.
 * `cout` (budget de rencontres) : non chiffré dans le doc — barème de départ
 * croissant avec la dangerosité, à régler en playtest (doc 06 §10).
 */
class MonstreSeeder extends Seeder
{
    public function run(): void
    {
        $monstres = [
            // ----- Bestiaire de base -----
            ['nom_base' => 'Gobelin', 'deplacement' => 10, 'attaque' => 2, 'defense' => 1, 'pv_body' => 1, 'pv_mind' => 1,
                'tier' => 'base', 'cout' => 1, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Orque', 'deplacement' => 8, 'attaque' => 3, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 2,
                'tier' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Fimir', 'deplacement' => 6, 'attaque' => 3, 'defense' => 3, 'pv_body' => 2, 'pv_mind' => 3,
                'tier' => 'base', 'cout' => 3, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Squelette', 'deplacement' => 6, 'attaque' => 2, 'defense' => 2, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Zombie', 'deplacement' => 6, 'attaque' => 2, 'defense' => 3, 'pv_body' => 1, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 2, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Momie', 'deplacement' => 4, 'attaque' => 3, 'defense' => 4, 'pv_body' => 2, 'pv_mind' => 0,
                'tier' => 'base', 'cout' => 4, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Guerrier du Chaos', 'deplacement' => 7, 'attaque' => 4, 'defense' => 5, 'pv_body' => 3, 'pv_mind' => 3,
                'tier' => 'base', 'cout' => 5, 'capacites' => [], 'sorts_dread' => []],
            ['nom_base' => 'Gargouille', 'deplacement' => 6, 'attaque' => 4, 'defense' => 5, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'base', 'cout' => 6, 'capacites' => [], 'sorts_dread' => []],

            // ----- Gabarits élites (doc 09 §4 — exemples proposés, à équilibrer) -----
            // capacites = bibliothèque assignable (l'IA choisit l'habillage, le moteur résout)
            ['nom_base' => 'Champion', 'deplacement' => 7, 'attaque' => 4, 'defense' => 4, 'pv_body' => 5, 'pv_mind' => 3,
                'tier' => 'sous_boss', 'cout' => 10,
                'capacites' => ['charge'],
                'sorts_dread' => ['Trait de Chaos', 'Frayeur', 'Sommeil', 'Tempête de feu']],
            ['nom_base' => 'Seigneur', 'deplacement' => 7, 'attaque' => 5, 'defense' => 5, 'pv_body' => 10, 'pv_mind' => 5,
                'tier' => 'boss', 'cout' => 20,
                'capacites' => ['invocation', 'frappe_de_zone'],
                'sorts_dread' => ['Tempête de feu', 'Invocation de morts-vivants', 'Commandement', 'Fuite']],
        ];

        foreach ($monstres as $monstre) {
            Monstre::updateOrCreate(['nom_base' => $monstre['nom_base']], $monstre);
        }
    }
}
