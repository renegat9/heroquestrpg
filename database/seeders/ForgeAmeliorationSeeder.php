<?php

namespace Database\Seeders;

use App\Models\ForgeAmelioration;
use Illuminate\Database\Seeder;

/**
 * Améliorations de Forge du Nain (doc 04 §4) — prix fixes, une seule par objet,
 * jamais sur les objets de rareté Unique.
 */
class ForgeAmeliorationSeeder extends Seeder
{
    public function run(): void
    {
        $ameliorations = [
            ['nom' => 'Affûtée', 'cible' => 'arme', 'prix' => 150,
                'effet' => ['bonus_des_attaque' => 1]],
            ['nom' => 'Perforante', 'cible' => 'arme', 'prix' => 250,
                'effet' => ['annule_boucliers_defense' => 1]],
            ['nom' => 'Cruelle', 'cible' => 'arme', 'prix' => 120,
                'effet' => ['relance_de_attaque_rate' => 1, 'frequence' => 'une_fois_par_combat']],
            ['nom' => 'Renforcée', 'cible' => 'armure', 'prix' => 250,
                'effet' => ['bonus_des_defense' => 1]], // armure ou bouclier
            ['nom' => 'Allégée', 'cible' => 'armure', 'prix' => 200,
                'effet' => ['annule_malus_deplacement' => true]], // récupère le 1d6 (règle AP)
            ['nom' => 'Gardée', 'cible' => 'armure', 'prix' => 250,
                'effet' => ['ignore_premier_etat_du_combat' => ['Étourdi', 'Apeuré']]], // armure ou bouclier
        ];

        foreach ($ameliorations as $amelioration) {
            ForgeAmelioration::updateOrCreate(['nom' => $amelioration['nom']], $amelioration);
        }
    }
}
