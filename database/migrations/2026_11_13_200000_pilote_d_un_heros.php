<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MANETTE À PLUSIEURS HÉROS, lot M1 (`docs/plan-mode-classique.md` §5).
 *
 * Un héros garde son PROPRIÉTAIRE (`personnages.joueur_id`) ; son PILOTE est le joueur qui le joue dans CE groupe.
 * `null` = le propriétaire le pilote lui-même, donc aucune ligne existante ne change de comportement. C'est ce qui
 * couvre l'absent (son héros confié à un présent) sans rien retirer à son compte.
 *
 * État durable d'un groupe : en colonne, jamais en cache. Lu par un seul point de passage, `Groupe::piloteDe()` et
 * `Groupe::herosPilotesPar()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupe_personnages', function (Blueprint $table) {
            $table->foreignId('pilote_joueur_id')->nullable()->constrained('joueurs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groupe_personnages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pilote_joueur_id');
        });
    }
};
