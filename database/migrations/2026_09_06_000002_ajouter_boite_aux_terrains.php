<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `terrains.boite` — même colonne, même sémantique, même raison que
 * `monstres.boite` (migration `2026_09_04_000002_ajouter_boite_aux_monstres`).
 *
 * Défaut critique corrigé ici (René, 2026-09-06, phase 6a) : les 7 terrains
 * sourcés (doc 18 §4, The Frozen Horror) n'avaient AUCUNE notion de boîte, si
 * bien que déclarer `structure.terrains` dans un gabarit tel quel aurait posé
 * une Rivière gelée dans une quête de jungle — le même défaut que la
 * génération de monstres avait avant que `monstres.boite` n'existe.
 *
 * ⚠ `null` n'est pas un trou : il vaut « aucune boîte, convient à TOUS les
 * thèmes » — exactement la lecture de `monstres.boite`. Aucun terrain ne porte
 * cette valeur à ce jour (les 7 lignes seedées passent à
 * `boite = 'horreur_des_glaces'`), mais la colonne le permet pour un futur
 * terrain « générique » (une flaque, une zone de gravats) qui n'appartiendrait
 * à aucune boîte en particulier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terrains', function (Blueprint $table) {
            $table->string('boite', 40)->nullable()->after('effet');
        });
    }

    public function down(): void
    {
        Schema::table('terrains', function (Blueprint $table) {
            $table->dropColumn('boite');
        });
    }
};
