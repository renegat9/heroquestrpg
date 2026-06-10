<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forge_ameliorations', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->enum('cible', ['arme', 'armure']);
            $table->json('effet');
            $table->unsignedInteger('prix'); // prix fixe, payé sur la bourse commune
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forge_ameliorations');
    }
};
