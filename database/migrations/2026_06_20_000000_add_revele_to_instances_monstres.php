<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Révélation des monstres par salle : un monstre reste DORMANT (non affiché,
 * n'agit pas) tant que sa salle n'a pas été découverte par les héros. Défaut
 * `true` (rétro-compatible) ; DemarreurQuete pose `false` pour les monstres
 * des salles autres que celle de départ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->boolean('revele')->default(true)->after('etat');
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn('revele');
        });
    }
};
