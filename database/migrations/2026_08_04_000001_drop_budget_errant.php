<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retire `quetes.budget_errant`.
 *
 * Les monstres errants n'ont plus de plafond : leur carte revient sous le paquet
 * comme toutes les autres et doit mordre à chaque fois. Avec 6 cartes errant sur
 * 24, un budget qui s'épuisait transformait la carte la plus fréquente du deck
 * en carte blanche dès le milieu de quête.
 *
 * Simple `unsignedSmallInteger NULL` : aucune clé étrangère, aucun index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', fn (Blueprint $table) => $table->dropColumn('budget_errant'));
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->unsignedSmallInteger('budget_errant')->nullable()->after('artefact_objet_id');
        });
    }
};
