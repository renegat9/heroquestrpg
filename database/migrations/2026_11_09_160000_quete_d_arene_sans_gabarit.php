<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot T1 (`docs/plan-tournoi-worlds-end.md` §4) : un combat d'arène est une `quetes` SANS gabarit. Le
 * gabarit décrit un donjon de campagne (salles, deck de fouille, objectif, butin) ; le hall du tournoi n'en a aucun, et
 * un gabarit « arène » dans le catalogue pourrait être tiré par `DemarreurQuete::choisirGabarit()` pour une campagne.
 * Le moteur lit déjà le gabarit d'une quête en `?->` partout où il le lit en cours de partie. Aucune ligne existante ne
 * change : toutes les quêtes existantes ont un gabarit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->unsignedBigInteger('gabarit_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->unsignedBigInteger('gabarit_id')->nullable(false)->change();
        });
    }
};
