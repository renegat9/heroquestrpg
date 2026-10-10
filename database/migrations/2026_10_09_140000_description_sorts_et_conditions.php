<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Description lisible des SORTS (`sorts`) et des CONDITIONS (`conditions`).
 *
 * Verdict Morcar (2026-10-09, § 5) : ni le menu ni `/moi` ne disaient ce que font
 * Voile de Brume ou Eau de Guérison, et les conditions « Vaporeux » et « Intangible »
 * n'avaient aucune description. Le texte vient des CARTES (reference/16_armurerie.md,
 * reference/02_sorts.md) et doit être porté par le catalogue, pas écrit dans une vue.
 *
 * Migration purement ADDITIVE : une colonne nullable. Les lignes existantes reçoivent
 * leur texte au re-seed (`SortSeeder`, `ConditionSeeder`, `updateOrCreate`) — exactement
 * comme `competences.description` (2026-07-19). Un re-seed ne supprime rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sorts', function (Blueprint $table) {
            $table->text('description')->nullable()->after('effet');
        });

        Schema::table('conditions', function (Blueprint $table) {
            $table->text('description')->nullable()->after('effet');
        });
    }

    public function down(): void
    {
        Schema::table('sorts', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('conditions', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
