<?php

namespace Database\Seeders;

use App\Models\Tuile;
use Illuminate\Database\Seeder;

/**
 * Bibliothèque de tuiles de départ (doc 06 §3) — jeu minimal extensible.
 * Grille : largeur/hauteur en cases + cases (s = sol, m = mur, p = porte possible).
 * Le moteur assemble les tuiles par leurs bords 'p' (doc 06, question ouverte n°2 :
 * bibliothèque extensible par thème).
 */
class TuileSeeder extends Seeder
{
    public function run(): void
    {
        $grille = fn (array $lignes) => [
            'largeur' => strlen($lignes[0]),
            'hauteur' => count($lignes),
            'cases' => array_map(str_split(...), $lignes),
        ];

        $tuiles = [
            // Salles
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmpmm',
                'msssm',
                'psssp',
                'msssm',
                'mmpmm',
            ])],
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmmpmmm',
                'msssssm',
                'msssssm',
                'psssssp',
                'msssssm',
                'mmmpmmm',
            ])],
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmpm',
                'mssm',
                'mssp',
                'mmmm',
            ])],
            // Grande salle (boss)
            ['type' => 'salle', 'theme' => 'boss', 'grille' => $grille([
                'mmmmpmmmm',
                'msssssssm',
                'msssssssm',
                'msssssssm',
                'msssssssm',
                'mmmmmmmmm',
            ])],
            // Couloirs
            ['type' => 'couloir', 'theme' => 'generique', 'grille' => $grille([
                'mmmmmm',
                'pssssp',
                'mmmmmm',
            ])],
            ['type' => 'couloir', 'theme' => 'generique', 'grille' => $grille([
                'mpm',
                'msm',
                'msm',
                'msm',
                'mpm',
            ])],
            // Couloir en angle
            ['type' => 'couloir', 'theme' => 'generique', 'grille' => $grille([
                'mpmm',
                'mssp',
                'mmmm',
            ])],
            // Portes
            ['type' => 'porte', 'theme' => 'generique', 'grille' => $grille(['p'])],
            ['type' => 'porte', 'theme' => 'verrouillee', 'grille' => $grille(['p'])],
        ];

        foreach ($tuiles as $tuile) {
            Tuile::create($tuile);
        }
    }
}
