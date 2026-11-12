<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot T2 (`docs/plan-tournoi-worlds-end.md` §4) : l'ACTIVATION ALTERNÉE. Un monstre du Defender qui a joué
 * ce round porte son jeton (« a token marks those who have been activated », livret F9528 p. 12) — un état DURABLE du
 * round, donc une colonne, jamais du cache. Lue seulement en arène ; la phase des monstres de campagne joue tout le
 * monde d'une traite et ne la lit pas. Aucune ligne existante ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->boolean('active_ce_round')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn('active_ce_round');
        });
    }
};
