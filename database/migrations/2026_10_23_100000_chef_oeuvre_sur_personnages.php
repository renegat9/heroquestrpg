<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chef-d'œuvre (nain·Forge, pouvoir final, lot B 2026-10-23) : l'amélioration de forge
 * GRATUITE se joue une fois à chaque passage au hub. « Déjà utilisée à ce passage » est
 * une donnée durable — jamais un cache (CLAUDE.md : §2.16). La colonne retient l'id de la
 * quête qui a fermé le passage où l'amélioration a servi ; un passage est ouvert tant que
 * la dernière quête terminée n'est plus celle-là.
 *
 * Additive et nullable : aucun héros existant n'est touché, et `null` = jamais utilisée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->unsignedBigInteger('chef_oeuvre_quete_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->dropColumn('chef_oeuvre_quete_id');
        });
    }
};
