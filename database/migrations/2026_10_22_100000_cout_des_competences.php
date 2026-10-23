<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COÛT DES NŒUDS DE GRILLE — le pouvoir final (René, 2026-10-22, `docs/regles/talents-et-capacites.md`,
 * « Pouvoirs finaux »).
 *
 * Une colonne peut porter un 4e rang qui coûte 2 points au lieu d'un. Le coût devient une DONNÉE de la
 * ligne (`competences.cout`), et non une règle codée sur le rang : c'est la seule source que lisent à la
 * fois le calcul des points (`Personnage::pointsCompetence()`), l'achat (`Talents::achats()`) et le score
 * de puissance.
 *
 * Migration ADDITIVE : une colonne entière, défaut 1 — le coût de tous les rangs existants (1 à 3) et des
 * lignes déjà en base. Rien d'autre n'est modifié. Les capacités de carte (`innee`) reçoivent leur coût
 * de 0 par le seeder (`CompetenceSeeder`, `updateOrCreate`), jamais ici : une migration ne réécrit pas
 * des lignes qu'elle n'a pas besoin de connaître.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('competences', 'cout')) {
            Schema::table('competences', function (Blueprint $table): void {
                $table->unsignedTinyInteger('cout')->default(1)->after('rang');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('competences', 'cout')) {
            Schema::table('competences', function (Blueprint $table): void {
                $table->dropColumn('cout');
            });
        }
    }
};
