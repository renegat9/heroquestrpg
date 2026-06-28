<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alliés recrutés et actifs (Phase 2, 3.5). Embauchés au hub, instanciés au
 * démarrage de quête (position de spawn, PV courants). CONSOMMÉS en fin de quête
 * (décision canon) : la table est purgée à la clôture/échec de quête.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groupe_mercenaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('groupe_id')->constrained('groupes')->cascadeOnDelete();
            $table->foreignId('mercenaire_id')->constrained('mercenaires')->restrictOnDelete(); // bloc de stats
            // Héros qui a recruté l'allié (ordre/flavor) ; null si recruteur parti.
            $table->foreignId('recruteur_personnage_id')->nullable()->constrained('personnages')->nullOnDelete();
            $table->unsignedInteger('pv_body'); // courants
            $table->integer('position_x')->nullable();
            $table->integer('position_y')->nullable();
            $table->enum('etat', ['actif', 'vaincu'])->default('actif');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groupe_mercenaires');
    }
};
