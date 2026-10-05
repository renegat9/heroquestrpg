<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier 3b (2026-10-04) — mission « secourir » : un captif est posé sur la
 * carte à l'assemblage (`groupe_mercenaires.etat = 'captif'`, ni joué par le
 * moteur ni contrôlé par personne), puis LIBÉRÉ par un héros au contact — il
 * devient alors un allié ordinaire (`etat = 'actif'`, `recruteur_personnage_id`
 * = le héros libérateur), rejoué par 2026_10_04_040000 ci-dessus.
 *
 * `mercenaires.captif` déclare le vocabulaire fermé du profil (Gothar
 * aujourd'hui — sourcé, Frozen Horror p. 19/37 ; le Prospecteur et la
 * Princesse Millandriel de *The Mage of the Mirror* attendent encore une
 * fiche de stats sourcée, voir docs/regles/combat-et-tour.md) : un captif
 * partage `octroi_seul` avec le Squelette Hearthkin — jamais recrutable au
 * hub contre de l'or (`MercenaireController::catalogue()`), il n'existe que
 * posé par le générateur de quête puis libéré en jeu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercenaires', function (Blueprint $table) {
            $table->boolean('captif')->default(false);
        });

        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->enum('etat', ['actif', 'vaincu', 'captif'])->default('actif')->change();
        });
    }

    public function down(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->enum('etat', ['actif', 'vaincu'])->default('actif')->change();
        });

        Schema::table('mercenaires', function (Blueprint $table) {
            $table->dropColumn('captif');
        });
    }
};
