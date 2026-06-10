<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monstres', function (Blueprint $table) {
            $table->id();
            $table->string('nom_base')->unique();
            $table->unsignedInteger('deplacement'); // fixe (pas de 1d6 pour les monstres)
            $table->unsignedInteger('attaque');
            $table->unsignedInteger('defense');
            $table->unsignedInteger('pv_body');
            $table->unsignedInteger('pv_mind'); // 0 = mort-vivant, immunité mentale (logique moteur)
            $table->enum('tier', ['base', 'sous_boss', 'boss']);
            $table->unsignedInteger('cout'); // poids dans le budget de rencontres
            $table->json('capacites')->nullable();
            $table->json('sorts_dread')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monstres');
    }
};
