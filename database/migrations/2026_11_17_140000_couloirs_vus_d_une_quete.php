<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE — LE BROUILLARD DES COULOIRS (décision de René du 2026-11-17 : « respecter le plateau »).
 *
 * `quetes.couloirs_vus` = liste de cases « x,y » de COULOIR qu'un héros a VUES (ligne de vue), cumulée. Une carte
 * construite ne révèle plus tous les couloirs à l'ouverture d'une porte : seulement ce que les héros voient
 * (`App\Partie\Classique\CouloirsVus`). État durable de partie : une colonne, jamais le cache — le perdre refermerait
 * le brouillard sur du terrain déjà parcouru (§2.16). `null` pour toute quête existante : elle garde l'ancien brouillard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('couloirs_vus')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('couloirs_vus');
        });
    }
};
