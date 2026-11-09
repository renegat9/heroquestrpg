<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot T5 — le COMBATTANT SEUL (livret F9528 p. 16) : le dernier héros debout du Challenger prend une
 * « manœuvre de base » après chaque activation du Defender. Tant qu'elle est ouverte, son menu est forcé : l'état vit en
 * colonne, jamais en cache — un téléphone rechargé retrouve la manœuvre en attente. Aucune ligne existante ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('manoeuvre_arene')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('manoeuvre_arene');
        });
    }
};
