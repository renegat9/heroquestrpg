<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variante À DISTANCE générique (lot B, Against the Ogre Horde p. 8, Q6 —
 * René 2026-10-02 : « allons-y générique »).
 *
 * Avant ce lot, « Gobelin archer » et « Archer squelette » étaient deux blocs
 * de stats recopiés À LA MAIN depuis leur monstre de base, sans aucun lien
 * déclaré entre les deux lignes — rien ne disait qu'une mise à jour de
 * l'Orque devrait un jour se répercuter sur un « Orque archer ». Cette
 * colonne EST le lien : le nom_base du monstre standard dont la ligne est la
 * variante à distance, lu par `DemarreurQuete::variantesDistanceParBase()`
 * (achat des rencontres) et par `MonstreSeeder` (formule de dérivation).
 *
 * `null` par défaut : la quasi-totalité du bestiaire n'est pas une variante.
 * Aucune ligne existante ne change de comportement — `MonstreSeeder` remplit
 * la colonne au prochain `db:seed` pour les trois variantes concernées
 * (Gobelin archer, Archer squelette, Orque archer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->string('variante_distance_de')->nullable()->after('attaque_distance');
        });
    }

    public function down(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->dropColumn('variante_distance_de');
        });
    }
};
