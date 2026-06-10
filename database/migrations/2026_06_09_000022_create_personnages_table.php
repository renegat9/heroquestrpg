<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('joueur_id')->constrained('joueurs')->cascadeOnDelete();
            $table->foreignId('groupe_actif_id')->nullable()->constrained('groupes')->nullOnDelete();
            $table->string('nom');
            $table->enum('classe', ['barbare', 'nain', 'elfe', 'magicien']);
            $table->unsignedInteger('niveau')->default(1); // progression par jalons (P6)
            $table->unsignedInteger('attribut_body');
            $table->unsignedInteger('attribut_mind');
            $table->unsignedInteger('pv_body_max');
            $table->unsignedInteger('pv_body');
            $table->unsignedInteger('pv_mind_max');
            $table->unsignedInteger('pv_mind');
            $table->unsignedInteger('des_attaque');
            $table->unsignedInteger('des_defense');
            $table->unsignedInteger('deplacement_base'); // total/tour = base + 1d6
            $table->unsignedInteger('or')->default(0); // bourse personnelle persistante (roster)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personnages');
    }
};
