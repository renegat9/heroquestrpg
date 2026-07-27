<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réserve arcanique (nœud magicien, doc 01 §6 — pouvoir de remplacement choisi
 * par René le 2026-07-26 : « lancer 2 sorts par tour » plutôt que le concept
 * jamais défini d'« emplacement de sort », voir mémoire projet
 * verdict-test-talents-2026-07) : un second sort par tour, au-delà du
 * créneau action normal. Remis à zéro à chaque nouveau tour, comme a_agi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('bonus_sort_utilise')->default(false)->after('garde_tenace_utilisee');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('bonus_sort_utilise');
        });
    }
};
