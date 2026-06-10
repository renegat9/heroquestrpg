<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gabarits_quete', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->enum('type_jalon', ['normale', 'sous_boss', 'boss_final']);
            $table->json('structure'); // objectifs, jalons, points de décision, budget de rencontres
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gabarits_quete');
    }
};
