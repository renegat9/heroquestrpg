<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('objets', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->enum('categorie', ['arme', 'armure', 'outil', 'consommable', 'parchemin']);
            $table->enum('rarete', ['commun', 'peu_commun', 'rare', 'unique']);
            $table->unsignedInteger('prix_base');
            $table->enum('emplacement', ['arme_principale', 'arme_secondaire', 'armure', 'sac', 'consommable']);
            $table->json('effet');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('objets');
    }
};
