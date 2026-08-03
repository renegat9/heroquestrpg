<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attaque SUPPLÉMENTAIRE offerte pour le tour (Potion d'héroïsme).
 *
 * Même patron que `bonus_sort_utilise` (Réserve arcanique du magicien) : le
 * héros dispose d'une seconde attaque au-delà de son créneau d'action, une
 * seule fois, et la colonne dit si elle est encore disponible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('attaque_supplementaire')->default(false)->after('bonus_sort_utilise');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', fn (Blueprint $table) => $table->dropColumn('attaque_supplementaire'));
    }
};
