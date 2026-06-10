<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competences', function (Blueprint $table) {
            $table->id();
            $table->enum('classe', ['barbare', 'nain', 'elfe', 'magicien']);
            $table->string('nom');
            $table->enum('type', ['passif', 'actif', 'deblocage']);
            $table->json('effet');
            $table->foreignId('prerequis_id')->nullable()->constrained('competences')->nullOnDelete();
            $table->timestamps();

            $table->unique(['classe', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('competences');
    }
};
