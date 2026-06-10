<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tuiles', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['salle', 'couloir', 'porte']);
            $table->string('theme');
            $table->json('grille');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tuiles');
    }
};
