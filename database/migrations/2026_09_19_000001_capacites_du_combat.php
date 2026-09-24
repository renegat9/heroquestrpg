<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compteur des capacités « **une fois par COMBAT** ».
 *
 * Ni la quête (`capacites_utilisees`) ni le tour (`capacites_tour`) : un
 * troisième rythme, pour Cruelle et Gardée (Forge du Nain, doc 04 §4), qui se
 * ferme quand plus aucun monstre n'est en vue AU DÉBUT D'UN TOUR — le même
 * instant et le même prédicat que la récupération des Styles Élémentaires du
 * Moine (`MoteurSorts::rythmerBuffsDeVue()` → `MoteurSorts::monstreEnVue()`,
 * arbitrage de René, 2026-09-19). ⚠ Contrairement à `capacites_tour`, cette
 * colonne SURVIT à un changement de tour tant que le combat continue : elle
 * doit donc voyager dans le snapshot (`Sauvegarde`), comme `capacites_utilisees`
 * et `styles_epuises`.
 *
 * Une liste de noms plutôt qu'une colonne par amélioration : même raison
 * qu'à côté, un booléen par pièce forgeable serait ingérable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('capacites_combat')->nullable()->after('styles_epuises');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('capacites_combat');
        });
    }
};
