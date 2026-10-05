<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chantier 3a (2026-10-04, décision de René : « un allié est TOUJOURS joué
 * par son joueur ») — remplace la phase alliée dédiée et automatique
 * (`ResolveurTour::phaseAllies()`) par un tour joué DEPUIS LA MANETTE du
 * héros qui contrôle l'allié (`recruteur_personnage_id`), juste après lui.
 *
 * Deux créneaux, comme un héros (`etat_personnage_quete.a_deplace`/`a_agi`/
 * `a_joue`) mais PORTÉS PAR L'ALLIÉ : il n'a ni porte ni potion (Ogre Horde
 * p. 9), seulement se déplacer et attaquer — jamais les talents/sorts d'un
 * héros, donc jamais besoin des colonnes qui les portent. Remis à zéro à
 * CHAQUE round par `ResolveurTour::ouvrirNouveauTour()`, exactement comme
 * les héros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->boolean('a_deplace')->default(false);
            $table->boolean('a_agi')->default(false);
            $table->boolean('a_joue')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->dropColumn(['a_deplace', 'a_agi', 'a_joue']);
        });
    }
};
