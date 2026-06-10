<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instances_monstres', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quete_id')->constrained('quetes')->cascadeOnDelete();
            $table->foreignId('monstre_id')->constrained('monstres')->restrictOnDelete(); // bloc de stats
            $table->unsignedInteger('pv_body'); // courants
            $table->unsignedInteger('pv_mind');
            $table->integer('position_x')->nullable();
            $table->integer('position_y')->nullable();
            $table->enum('etat', ['actif', 'vaincu'])->default('actif');
            $table->json('habillage')->nullable(); // nom/description donnés par l'IA
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instances_monstres');
    }
};
