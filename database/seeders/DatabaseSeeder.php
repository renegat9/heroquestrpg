<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Catalogues de référence (doc 12 §5) — données seedées, jamais modifiées
     * en jeu : l'IA habille (renomme/redécrit) sans toucher aux effets (Q6).
     */
    public function run(): void
    {
        $this->call([
            ClasseHerosSeeder::class,
            CompetenceSeeder::class,
            ConditionSeeder::class,
            ObjetSeeder::class,
            ForgeAmeliorationSeeder::class,
            SortSeeder::class,
            SortDreadSeeder::class,
            MonstreSeeder::class,
            PiegeSeeder::class,
            TuileSeeder::class,
            GabaritQueteSeeder::class,
        ]);
    }
}
