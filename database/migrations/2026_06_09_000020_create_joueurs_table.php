<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('joueurs', function (Blueprint $table) {
            $table->id();
            $table->string('pseudo');
            $table->string('identifiant')->unique(); // login simple (cadre interne)
            $table->string('mot_de_passe'); // hash
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('joueurs');
    }
};
