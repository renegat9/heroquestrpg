<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RACE de chaque classe de héros.
 *
 * Elle n'existait qu'en **commentaire** : René ayant rappelé le 2026-08-13 que
 * seuls le Warlock (halfling) et l'Explorateur (nain) ne sont pas humains, la
 * grille de mouvement a été refaite dessus — mais rien, dans la base ni à
 * l'écran, ne disait de quelle race était une classe. Le joueur voyait un
 * Explorateur marcher moins vite qu'un Rogue sans jamais pouvoir deviner
 * pourquoi.
 *
 * Quatre valeurs : `humain` · `nain` · `elfe` · `halfling`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes_heros', function (Blueprint $table) {
            $table->string('race', 20)->default('humain')->after('nom');
        });
    }

    public function down(): void
    {
        Schema::table('classes_heros', function (Blueprint $table) {
            $table->dropColumn('race');
        });
    }
};
