<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnage_historique', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnage_id')->constrained('personnages')->cascadeOnDelete();
            $table->string('groupe_nom');
            $table->string('theme');
            $table->text('resume');
            $table->string('issue'); // victoire, défaite, abandon…
            $table->unsignedInteger('niveau_atteint');
            $table->timestamp('termine_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnage_historique');
    }
};
