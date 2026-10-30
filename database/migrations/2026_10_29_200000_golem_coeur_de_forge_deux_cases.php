<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le Golem Cœur-de-Forge occupe DEUX cases (René, 2026-10-29 : « Oui passe à 2 cases »).
 *
 * « Use the Forgeheart Golem's tile » (Jungles of Delthrak, quête 6 note A, F9907 PDF 12) : la tuile posée dans la
 * salle A de la carte couvre une colonne sur deux cases, et l'image des notes est la même tuile, 2:1. L'emprise d'une
 * figure suit son jeton de livret (même règle que les ovales, `2026_10_29_100000_emprises_figures_selon_jetons`).
 *
 * ⚠ L'emprise se lit dans le CATALOGUE à chaque tour (`Monstre::emprise()`), pas sur l'instance : une figure déjà posée
 * grandirait avec cette migration. Vérifié sur la vraie base le 2026-10-29 : aucune instance de Golem Cœur-de-Forge.
 */
return new class extends Migration
{
    private const NOM = 'Golem Cœur-de-Forge';

    public function up(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)->update(['grande_taille' => json_encode(['l' => 1, 'h' => 2])]);
    }

    public function down(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)->update(['grande_taille' => null]);
    }
};
