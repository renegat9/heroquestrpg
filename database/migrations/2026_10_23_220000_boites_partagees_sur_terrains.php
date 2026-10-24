<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BOÎTES PARTAGÉES SUR LES TERRAINS (chantier EXT-CARTE2, 2026-10-23) : la Trappe de
 * téléportation est imprimée dans DEUX boîtes — *The Mage of the Mirror* (F7539 p. 5, « Trap
 * Doors x2 », quête 1 note A) et *Kellar's Keep* (F4543 p. 3 et q. 8 note C, p. 22) — avec la
 * même règle : un passage au contact, un dé de combat, la même fin de tour. Une seule ligne de
 * catalogue sert les deux, comme `monstres.boites_partagees` pour l'Archer elfe.
 *
 * `boite` reste la boîte d'origine ; `boites_partagees` ajoute les autres. `Terrain::boites()`
 * la lit, `AssembleurCarte::placerTerrains()` la consomme : le même thème de campagne suffit à
 * tirer la trappe, ni plus ni moins.
 *
 * Migration ADDITIVE : une colonne nullable. Aucune ligne existante n'est modifiée — la trappe
 * elle-même n'existe pas encore en base, elle est semée par `TerrainSeeder` (`updateOrCreate`,
 * qui ne supprime rien).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terrains', function (Blueprint $table) {
            $table->json('boites_partagees')->nullable()->after('boite');
        });
    }

    public function down(): void
    {
        Schema::table('terrains', function (Blueprint $table) {
            $table->dropColumn('boites_partagees');
        });
    }
};
