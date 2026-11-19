<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE — LE DÉGUISEMENT (lot K6, *Rise of the Dread Moon* p. 9). `etat_personnage_quete.deguise` : le héros
 * porte son jeton de déguisement dans CETTE quête (`App\Partie\Classique\Deguisement`). État durable de partie : une
 * colonne, jamais le cache. Faux pour toute ligne existante et toute quête qui ne le permet pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('deguise')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('deguise');
        });
    }
};
