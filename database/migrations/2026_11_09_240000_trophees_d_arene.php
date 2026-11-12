<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot T4 (livret F9528 p. 13-15) : les TUILES TROPHÉES d'un combat — celles posées face cachée sur les cases
 * du plan, et les deux réserves d'équipe. État durable du combat : une colonne de la quête, jamais du cache. `null`
 * hors de l'arène. Aucune ligne existante ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('trophees_arene')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('trophees_arene');
        });
    }
};
