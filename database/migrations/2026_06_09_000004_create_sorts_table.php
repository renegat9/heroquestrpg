<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sorts', function (Blueprint $table) {
            $table->id();
            $table->enum('element', ['feu', 'eau', 'terre', 'air']);
            $table->string('nom')->unique();
            $table->enum('type', ['degats', 'mental', 'utilitaire']);
            $table->unsignedTinyInteger('difficulte_parchemin'); // 1-3 succès (jet de Mind, non-lanceur)
            $table->json('effet');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sorts');
    }
};
