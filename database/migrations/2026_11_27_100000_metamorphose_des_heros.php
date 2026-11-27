<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE — LA MÉTAMORPHOSE (lot E2, *Prophecy of Telor* q. 5 : « all other heroes … have been turned into Orcs! »).
 * `personnages.metamorphose` : `{en: nom du monstre, pv_body_max: le maximum d'avant}` tant que le héros est changé, `null`
 * sinon — `Classique\Metamorphose`, durable (jamais le cache), levé par la note qui rend la forme ou au départ de la quête
 * suivante. `null` pour tout héros existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->json('metamorphose')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->dropColumn('metamorphose');
        });
    }
};
