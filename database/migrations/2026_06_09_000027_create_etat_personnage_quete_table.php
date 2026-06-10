<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('etat_personnage_quete', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnage_id')->constrained('personnages')->cascadeOnDelete();
            $table->foreignId('quete_id')->constrained('quetes')->cascadeOnDelete();
            $table->integer('position_x')->nullable();
            $table->integer('position_y')->nullable();
            $table->boolean('a_joue')->default(false);
            $table->boolean('tombe')->default(false); // 0 PV Body → tombé (P1)

            $table->unique(['personnage_id', 'quete_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etat_personnage_quete');
    }
};
