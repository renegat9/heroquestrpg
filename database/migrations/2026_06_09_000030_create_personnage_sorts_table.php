<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnage_sorts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnage_id')->constrained('personnages')->cascadeOnDelete();
            $table->foreignId('sort_id')->constrained('sorts')->restrictOnDelete();
            $table->boolean('disponible')->default(true); // épuisé/dispo (récupération par quête)

            $table->unique(['personnage_id', 'sort_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnage_sorts');
    }
};
