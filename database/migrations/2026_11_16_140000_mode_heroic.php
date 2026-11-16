<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE, lot H1 — la MORT d'un héros (`docs/plan-mode-classique.md` §10, Q2 et Q7).
 *
 * - `groupes.mode_mort` : `story` (défaut, la politique de TOUS les groupes jusqu'ici — `ResolveurTour::relevementsStory()`)
 *   ou `heroic`, choisi à la création d'un groupe classique (*Jungles of Delthrak*, F9907 p. 8-9).
 * - `etat_personnage_quete.crane` : le jeton crâne d'un héros hors de combat en Heroic — pris au début de son tour
 *   suivant ; s'il commence un tour avec, il meurt.
 * - `personnages.mort_le` : la mort est DÉFINITIVE (livret de règles de *First Light*, G0978, « Dead Heroes »). La
 *   ligne du héros n'est jamais supprimée : elle reste au roster, marquée morte, non engageable.
 *
 * Ajouts de colonnes avec défaut neutre : aucune ligne existante ne change de sens (tous les groupes restent `story`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->string('mode_mort', 10)->default('story');
        });
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('crane')->default(false);
        });
        Schema::table('personnages', function (Blueprint $table) {
            $table->timestamp('mort_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('groupes', fn (Blueprint $table) => $table->dropColumn('mode_mort'));
        Schema::table('etat_personnage_quete', fn (Blueprint $table) => $table->dropColumn('crane'));
        Schema::table('personnages', fn (Blueprint $table) => $table->dropColumn('mort_le'));
    }
};
