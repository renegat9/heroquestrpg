<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE, lot K5a — le déclencheur de note `tour_du_mj` (« au début du tour du maître du jeu, à partir du N-ième »).
 *
 * `quetes.tours_mj` compte les tours du maître du jeu COMMENCÉS dans la quête (0 au départ). Un état durable de partie :
 * une colonne, jamais le cache (CLAUDE.md). Les quêtes existantes partent de 0 — aucune n'a de note qui le lise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->unsignedInteger('tours_mj')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('tours_mj');
        });
    }
};
