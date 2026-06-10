<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnage_competences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnage_id')->constrained('personnages')->cascadeOnDelete();
            $table->foreignId('competence_id')->constrained('competences')->restrictOnDelete();

            $table->unique(['personnage_id', 'competence_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnage_competences');
    }
};
