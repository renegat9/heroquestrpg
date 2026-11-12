<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot A9a — MONSTER VS MONSTER (livret F9528 p. 16) : la variante d'un combat d'arène, son salon (puissance
 * convenue, tirage, sélection alternée) et ses membres, des JOUEURS sans personnage. État durable du salon : en base,
 * jamais en cache. Lu seulement en arène (`MonstresContreMonstres`). Aucune ligne existante ne change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->string('arene_variante', 16)->default('coop');
            $table->json('arene_mvm')->nullable();
        });

        Schema::create('arene_joueurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('groupe_id')->constrained('groupes')->cascadeOnDelete();
            $table->foreignId('joueur_id')->constrained('joueurs')->cascadeOnDelete();
            $table->string('camp', 1);
            $table->timestamps();
            $table->unique(['groupe_id', 'joueur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arene_joueurs');

        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn(['arene_variante', 'arene_mvm']);
        });
    }
};
