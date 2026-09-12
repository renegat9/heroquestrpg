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
            // La plus petite salle du vivier : intérieur 2x3 (René, 2026-09-12,
            // « remplace la 2x2 par 2x3 »). Elle valait 2x2 = 4 cases de sol,
            // et représentait 16 % des salles ORDINAIRES — pas un cas
            // marginal : quatre héros et ce qu'ils venaient combattre n'y
            // tenaient pas, et la moitié du groupe restait sur le seuil sans
            // pouvoir agir (§2.12 ter de `docs/regles/carte-donjon.md`).
            // Les quatre 'p' sont là pour la lisibilité du patron seulement :
            // l'assembleur perce sur `intdiv($w, 2)` / `intdiv($h, 2)`, soit
            // la colonne 2 et la ligne 2 — toutes deux dans l'intérieur.
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmpm',
                'mssm',
                'pssp',
                'mssm',
                'mmpm',
            ])],
            // Sa TRANSPOSÉE, intérieur 3x2 (René, 2026-09-12). ⚠ Il faut bien
            // deux patrons : l'assembleur ne fait AUCUNE rotation, il lit
            // `largeur`/`hauteur` tels que semés. Le vivier le dit déjà de
            // lui-même — `5x7` et `7x5` y figurent séparément alors que l'une
            // est la transposée de l'autre.
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmpmm',
                'msssm',
                'psssp',
                'mmpmm',
            ])],
            // Grande salle (boss)
            // Variété de salles (test de jeu 2026-07-31) : le vivier ne comptait
            // que 3 formes, si bien que toutes les salles d'un donjon se
            // ressemblaient — les joueurs ne savaient plus où ils étaient.
            // Intérieurs PLEINS uniquement : l'assembleur perce ses portes sur la
            // médiane du slot et compte dessus pour toujours ouvrir sur du sol.
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmmpmmm',
                'msssssm',
                'psssssp',
                'msssssm',
                'mmmpmmm',
            ])],
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmmmpmmmm',
                'msssssssm',
                'psssssssp',
                'msssssssm',
                'mmmmpmmmm',
            ])],
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmpmm',
                'msssm',
                'msssm',
                'psssp',
                'msssm',
                'msssm',
                'mmpmm',
            ])],
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmmpmmm',
                'msssssm',
                'msssssm',
                'psssssp',
                'msssssm',
                'msssssm',
                'mmmpmmm',
            ])],
            ['type' => 'salle', 'theme' => 'generique', 'grille' => $grille([
                'mmmmpmmmm',
                'msssssssm',
                'msssssssm',
                'psssssssp',
                'msssssssm',
                'msssssssm',
                'mmmmpmmmm',
            ])],
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

        // Purge puis recréation : les tuiles sont des données de RÉFÉRENCE
        // re-semables. Le `create()` seul dupliquait tout le catalogue à chaque
        // passage (18 lignes pour 9 tuiles en base de développement), ce qui
        // rétrécissait la variété réelle : le vivier de salles ne comptait que
        // 3 formes, chacune en double. Aucune clé étrangère ne pointe vers
        // `tuiles` — `cartes.grille` est un instantané de la carte assemblée.
        Tuile::query()->delete();

        foreach ($tuiles as $tuile) {
            Tuile::create($tuile);
        }
    }
}
