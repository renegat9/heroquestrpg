<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Récits PRÉ-GÉNÉRÉS de la quête (décision de René, 2026-08-18).
 *
 * L'IA fabrique la quête, elle ne la joue plus : descriptions de salles et
 * variantes de temps forts sont produites UNE FOIS au démarrage, puis lues par
 * le moteur sans le moindre appel LLM. Une campagne passait ~145 appels par
 * quête (dont ~110 menus dont 3 sur 4 étaient jetés) ; il en reste 2.
 *
 * En BASE, jamais en cache — règle consolidée du projet : un pack perdu
 * re-muettirait toute la narration d'une quête en cours, exactement comme la
 * perte des salles explorées figeait le groupe (§2.16).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('recits')->nullable()->after('tresors_fouilles');
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('recits');
        });
    }
};
