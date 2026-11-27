<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE — LE TALISMAN DE TELOR (lot E2, *Prophecy of Telor*, G0052). Deux états DURABLES du héros, d'une quête à
 * l'autre de la campagne (jamais le cache) :
 *  - `personnages.sorts_dread_appris` : les sorts de Dread qu'une note lui a appris (« You can now use the Dread spell
 *    Lightning Bolt », q. 4 D ; « learns the Dread spell Firestorm », q. 6 C) — `Classique\TalismanDeTelor` ;
 *  - `personnages.esprit_de_livret` : les points d'Esprit gagnés ou perdus par le livret, au-delà du maximum de départ
 *    (« Gain 1 Mind Point »), que l'arène de la q. 10 annule (« their Mind Points revert to their total before starting
 *    this quest pack »). 0 et vide pour tout héros existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->json('sorts_dread_appris')->nullable();
            $table->integer('esprit_de_livret')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->dropColumn(['sorts_dread_appris', 'esprit_de_livret']);
        });
    }
};
