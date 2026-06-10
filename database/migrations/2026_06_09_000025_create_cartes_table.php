<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quete_id')->unique()->constrained('quetes')->cascadeOnDelete(); // 1—1
            $table->unsignedInteger('largeur');
            $table->unsignedInteger('hauteur');
            $table->json('grille'); // tuiles assemblées, murs, portes, pièges, spawns, état révélé
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cartes');
    }
};
