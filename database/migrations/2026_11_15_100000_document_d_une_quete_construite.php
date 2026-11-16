<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE — ce qu'une quête CONSTRUITE garde d'elle-même pendant qu'on la joue
 * (`docs/plan-mode-classique.md` §3 « Tables », lot K3).
 *
 * - `quetes.document_construit` : le document de la quête TEL QU'IL ÉTAIT AU DÉPART (notes, monstre errant,
 *   récompense…), figé par `DemarreurQueteConstruite`. Une quête en cours ne relit jamais la campagne : l'auteur peut
 *   la corriger pendant que des héros sont dans le donjon sans que leurs notes changent sous leurs pieds. `null` =
 *   quête générée, et toute quête d'avant.
 * - `quetes.drapeaux` : les drapeaux de quête posés par une note (effet `drapeau`), relus par une autre note ou par
 *   la condition de fin.
 * - `quetes.notes_jouees` : les notes « une fois » qui ont déjà tiré.
 *
 * Trois états durables d'une quête : en colonne, jamais en cache.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('document_construit')->nullable();
            $table->json('drapeaux')->nullable();
            $table->json('notes_jouees')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn(['document_construit', 'drapeaux', 'notes_jouees']);
        });
    }
};
