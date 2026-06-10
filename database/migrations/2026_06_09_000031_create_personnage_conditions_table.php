<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnage_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnage_id')->constrained('personnages')->cascadeOnDelete();
            $table->foreignId('condition_id')->constrained('conditions')->restrictOnDelete();
            $table->integer('duree'); // tours restants ; 0 = jusqu'à une condition de fin
            $table->string('source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnage_conditions');
    }
};
