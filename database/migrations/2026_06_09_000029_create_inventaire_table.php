<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventaire', function (Blueprint $table) {
            $table->id();
            $table->foreignId('personnage_id')->constrained('personnages')->cascadeOnDelete();
            $table->foreignId('objet_id')->constrained('objets')->restrictOnDelete();
            $table->enum('emplacement', ['arme_principale', 'arme_secondaire', 'armure', 'sac', 'consommable']);
            $table->unsignedInteger('quantite')->default(1); // consommables
            $table->json('ameliorations')->nullable(); // bonus de Forge attaché à cet exemplaire (réfère forge_ameliorations)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventaire');
    }
};
