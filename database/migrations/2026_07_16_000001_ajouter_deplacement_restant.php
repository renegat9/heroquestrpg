<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Déplacement FRACTIONNÉ (correctifs E1) : les points de déplacement restants du
 * tour, dépensés en plusieurs mouvements tant qu'il en reste et qu'aucune action
 * hors mouvement n'a été faite. `null` = déplacement pas encore entamé ce tour
 * (initialisé à `deplacement_tour` au premier pas). Réinitialisé à chaque tour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->unsignedInteger('deplacement_restant')->nullable()->after('deplacement_tour');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('deplacement_restant');
        });
    }
};
