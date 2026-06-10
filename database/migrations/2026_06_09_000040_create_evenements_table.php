<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evenements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('groupe_id')->constrained('groupes')->cascadeOnDelete();
            $table->foreignId('quete_id')->nullable()->constrained('quetes')->cascadeOnDelete();
            $table->unsignedInteger('sequence'); // ordre dans le journal du groupe
            $table->enum('type', ['action', 'jet', 'choix', 'combat', 'narration', 'systeme']);
            $table->json('acteur')->nullable(); // personnage ou monstre
            $table->json('payload'); // données de l'événement (jet, résultat, choix)
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['groupe_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evenements');
    }
};
