<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE, lot K5k (R5/F4) — la CHARGE à porter et à ramener.
 *
 * `quetes.charge` = `{nom, x, y, porteur_id, sortie}` : où est la charge (au sol, ou sur son porteur), et si elle a quitté
 * le donjon. État durable de partie : une colonne, jamais le cache (`App\Partie\Classique\Charge`). `null` pour toute
 * quête existante — aucune n'a de charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('charge')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('charge');
        });
    }
};
