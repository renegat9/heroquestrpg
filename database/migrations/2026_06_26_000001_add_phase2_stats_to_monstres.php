<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — refonte « une seule passe » du bloc de stats monstre (doc 14 §4).
 * - portee / attaque_distance : monstres à distance (3.4).
 * - grande_taille : emprise multi-cases pour les grandes figurines, ex. ogres (3.9).
 * - archetype_lanceur : répertoire de sorts dédié des sorciers nommés (3.8).
 * `capacites` (déjà JSON) accueille en plus les règles conditionnelles (3.7) — pas de colonne neuve.
 * Valeurs par défaut neutres : aucun monstre existant ne change de comportement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->enum('portee', ['corps_a_corps', 'distance'])->default('corps_a_corps')->after('attaque');
            $table->unsignedInteger('attaque_distance')->nullable()->after('portee');
            $table->json('grande_taille')->nullable()->after('cout'); // {"l":int,"h":int} ; null = 1 case
            $table->string('archetype_lanceur')->nullable()->after('sorts_dread');
        });
    }

    public function down(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->dropColumn(['portee', 'attaque_distance', 'grande_taille', 'archetype_lanceur']);
        });
    }
};
