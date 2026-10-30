<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * POSE DES TERRAINS DES TOURS (Spirit Queen's Torment, lot H des restes, 2026-10-29).
 *
 * Trois terrains se posent sur une SALLE ENTIÈRE (courants contraires, sol meuble, courant porteur : « these rooms »),
 * le quatrième sur chaque COULOIR de la carte (vents de couloir : « strong air currents move through the hallways »).
 * La colonne `pose` dit laquelle des deux poses, lue par `AssembleurCarte::placerTerrains()` :
 *
 *   - `salle`    : toutes les cases LIBRES d'une salle tirée (jamais la salle de départ), une plaque `salle-N` ;
 *   - `couloirs` : chaque case de couloir de la carte, SANS groupe (une case isolée, comme avant les plaques).
 *
 * `null` = la pose d'avant (une case, un bloc fixe, un motif) — toutes les lignes existantes restent dans ce cas, aucune
 * carte déjà assemblée n'en change. Migration ADDITIVE, une colonne nullable : `TerrainSeeder` pose la valeur des
 * nouvelles lignes (`updateOrCreate`), jamais ne réécrit les autres.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terrains', function (Blueprint $table) {
            $table->string('pose', 20)->nullable()->after('motif');
        });
    }

    public function down(): void
    {
        Schema::table('terrains', function (Blueprint $table) {
            $table->dropColumn('pose');
        });
    }
};
