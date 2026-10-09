<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SORTS DE SORCIER DÉJÀ LANCÉS — Wizards of Morcar (livret G1504 p. 10) :
 * « Each spell may only be used once per quest. At the beginning of a new
 * quest, each Sorcerer in that quest starts with a full set of six spells. »
 *
 * `usages_dread` est un budget GLOBAL par rencontre (1/2/3 selon le palier) :
 * il ne dit pas QUEL sort est dépensé, et un sorcier à six sorts uniques ne se
 * résume pas à un compteur. Cette colonne est la liste des NOMS déjà lancés,
 * lue par `MoteurDread::sortsDisponibles()` et écrite par `consommerUsage()`.
 *
 * Colonne et non cache (règle consolidée : tout état durable vit en base) ; elle
 * voyage dans le snapshot (`Sauvegarde`), sans quoi une reprise rendrait au
 * sorcier les sorts qu'il avait déjà dépensés. Réarmée au placement par
 * `reinitialiserUsagesInstance()`, au même instant que `usages_dread`.
 *
 * ADDITIVE : nullable, aucune donnée existante touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->json('sorts_dread_lances')->nullable()->after('capacites_reactives_utilisees');
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn('sorts_dread_lances');
        });
    }
};
