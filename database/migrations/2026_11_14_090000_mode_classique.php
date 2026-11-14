<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE, première quête jouable (`docs/plan-mode-classique.md` §3, §7).
 *
 * Tout ce qui suit est un état DURABLE de partie, donc en colonne, jamais en cache :
 * - `groupes.campagne_construite_id` et `groupes.rang_quete` : la campagne que joue un groupe classique, et où il en
 *   est. `restrictOnDelete` : une campagne en cours de jeu ne disparaît pas sous un groupe.
 * - `quetes.objectif_construit` : la condition de fin écrite par l'auteur (`{type, …}`), lue par `Quete::objectif()`.
 * - `instances_monstres.objectif` : cette figure est une CIBLE de l'objectif « vaincre ».
 *
 * Additif : aucune ligne existante ne change de sens (`null` / `1` / `false` = une campagne ordinaire).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->foreignId('campagne_construite_id')->nullable()->constrained('campagnes_construites')->restrictOnDelete();
            $table->unsignedInteger('rang_quete')->default(1);
        });

        Schema::table('quetes', function (Blueprint $table) {
            $table->json('objectif_construit')->nullable();
        });

        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->boolean('objectif')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn('objectif');
        });

        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('objectif_construit');
        });

        Schema::table('groupes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campagne_construite_id');
            $table->dropColumn('rang_quete');
        });
    }
};
