<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'ENTRAÎNEMENT au hub (René, 2026-11-07) : +1 à un attribut contre 500 po, une fois par attribut et par héros. La marque
 * « déjà entraîné » vit sur le HÉROS, en base — jamais en cache (règle consolidée du projet). Aucune ligne existante ne
 * change : tout héros part non entraîné.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->boolean('entraine_body')->default(false);
            $table->boolean('entraine_mind')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->dropColumn(['entraine_body', 'entraine_mind']);
        });
    }
};
