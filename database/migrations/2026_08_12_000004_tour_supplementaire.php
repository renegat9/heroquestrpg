<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un TOUR entier de plus (Arrêt du temps, répertoire elfique).
 *
 * « It temporarily stops time for everyone else on the gameboard, enabling the
 * hero to take ANOTHER TURN immediately after their current turn. »
 *
 * Le drapeau se pose sur le héros CIBLE — le lanceur ou un autre — et se
 * consomme au moment où son tour s'achèverait : au lieu de marquer `a_joue`,
 * le moteur réarme ses créneaux. C'est ce qui rend la mécanique uniforme pour
 * les deux cas de la carte, sans toucher à la file d'initiative.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('tour_supplementaire')->default(false)->after('attaque_supplementaire');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('tour_supplementaire');
        });
    }
};
