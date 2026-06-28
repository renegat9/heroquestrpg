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

            // ----- Sorciers nommés à répertoire dédié (3.8 — config/archetypes_lanceurs.php) -----
            // Le répertoire vient de l'archétype ; `sorts_dread` reste vide (l'archétype prime).
            // `cout` sous les leaders de tier (Champion 10 / Seigneur 20) pour ne pas changer
            // la rencontre finale auto-sélectionnée des quêtes.
            ['nom_base' => 'Chamane Gobelin', 'deplacement' => 8, 'attaque' => 2, 'defense' => 2, 'pv_body' => 3, 'pv_mind' => 4,
                'tier' => 'sous_boss', 'cout' => 9,
                'capacites' => [], 'sorts_dread' => [], 'archetype_lanceur' => 'chaman_orque'],
            ['nom_base' => 'Liche', 'deplacement' => 6, 'attaque' => 3, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 6,
                'tier' => 'boss', 'cout' => 18,
                'capacites' => ['invocation'], 'sorts_dread' => [], 'archetype_lanceur' => 'necromancien'],
            ['nom_base' => 'Sorcier des Tempêtes', 'deplacement' => 7, 'attaque' => 3, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 5,
                'tier' => 'boss', 'cout' => 17,
                'capacites' => [], 'sorts_dread' => [], 'archetype_lanceur' => 'maitre_tempetes'],

            // ----- Monstre à choix tactique (3.7) -----
            // `choix_attaque` : cible robuste (PV > seuil) → coup massif unique
            // (dés +massive_des_bonus) ; cible affaiblie → double_nombre attaques.
            // Décision 100 % moteur (ResolveurTour). `cout` sous le leader sous_boss.
            ['nom_base' => 'Ours polaire de guerre', 'deplacement' => 6, 'attaque' => 3, 'defense' => 4, 'pv_body' => 6, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'cout' => 9,
                'capacites' => ['choix_attaque' => ['seuil' => 2, 'massive_des_bonus' => 2, 'double_nombre' => 2]],
                'sorts_dread' => []],

            // ----- Monstre à distance (3.4) -----
            // `portee` distance + `attaque_distance` (dés en tir) ; au contact il
            // perd un dé (attaque corps-à-corps moindre). Exige la ligne de vue.
            ['nom_base' => 'Gobelin archer', 'deplacement' => 8, 'attaque' => 1, 'defense' => 1, 'pv_body' => 1, 'pv_mind' => 1,
                'tier' => 'base', 'cout' => 2, 'portee' => 'distance', 'attaque_distance' => 3,
                'capacites' => [], 'sorts_dread' => []],

            // ----- Grande figurine multi-cases (3.9) -----
            // `grande_taille` : emprise 1×2 (deux cases). Adjacence/ligne de vue/
            // déplacement raisonnent sur l'emprise (moteur Grille).
            ['nom_base' => 'Ogre', 'deplacement' => 6, 'attaque' => 4, 'defense' => 3, 'pv_body' => 5, 'pv_mind' => 2,
                'tier' => 'sous_boss', 'cout' => 9, 'grande_taille' => ['l' => 1, 'h' => 2],
                'capacites' => [], 'sorts_dread' => []],
        ];

        foreach ($monstres as $monstre) {
            Monstre::updateOrCreate(['nom_base' => $monstre['nom_base']], $monstre);
        }
    }
}
