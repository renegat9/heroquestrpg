<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalogue des alliés recrutables (Phase 2, 3.5 — doc 14) : mercenaires et
 * compagnons animaux. PNJ SCRIPTÉS (pas de personnage joué), stats simples,
 * embauchés au hub contre or, joués comme des « monstres alliés » en phase dédiée.
 * Données de référence seedées (jamais modifiées en jeu).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mercenaires', function (Blueprint $table) {
            $table->id();
            $table->string('nom')->unique();
            $table->string('type'); // archer, hallebardier, eclaireur, compagnon…
            $table->unsignedInteger('deplacement');
            $table->unsignedInteger('attaque');
            $table->enum('portee', ['corps_a_corps', 'distance'])->default('corps_a_corps');
            $table->unsignedInteger('attaque_distance')->nullable();
            $table->unsignedInteger('defense');
            $table->unsignedInteger('pv_body');
            $table->unsignedInteger('prix'); // coût en or (bourse commune)
            // Compagnon animal : une seule action (attaquer), ne porte rien, n'ouvre
            // aucune porte ; un seul par groupe (contrôlé à l'embauche).
            $table->boolean('animal')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mercenaires');
    }
};
