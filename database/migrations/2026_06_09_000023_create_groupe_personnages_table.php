<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupe_personnages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('groupe_id')->constrained('groupes')->cascadeOnDelete();
            $table->foreignId('personnage_id')->constrained('personnages')->cascadeOnDelete();
            $table->unsignedInteger('ordre_initiative')->default(0); // figé par quête (C1)
            $table->boolean('actif')->default(true); // présent dans la partie courante

            $table->unique(['groupe_id', 'personnage_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupe_personnages');
    }
};
