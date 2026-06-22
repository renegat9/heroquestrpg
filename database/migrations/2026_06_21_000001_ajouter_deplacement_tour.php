<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Déplacement du tour (doc 03 §3 : base + 1d6) lancé UNE fois par tour et
 * MÉMORISÉ, pour que le joueur voie son allonce avant de choisir sa case
 * (menu) et que la résolution valide contre la même valeur. Réinitialisé à
 * chaque nouveau tour (phase des monstres).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->unsignedInteger('deplacement_tour')->nullable()->after('a_joue');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('deplacement_tour');
        });
    }
};
