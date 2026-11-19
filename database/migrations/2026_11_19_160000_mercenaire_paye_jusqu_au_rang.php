<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE — LES MERCENAIRES D'UNE CAMPAGNE (lot K6 ; René, 2026-11-19 : « chaque campagne choisit sa règle »).
 * `groupe_mercenaires.paye_jusqu_au_rang` : le rang de la dernière quête de campagne pour laquelle l'allié est payé — lu au
 * départ de chaque quête par `App\Partie\Classique\MercenairesCampagne::auDepart()` (congédié, ou renouvelé à moitié prix).
 * `null` pour tout allié d'un groupe RPG ou d'arène : la colonne ne leur dit rien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->unsignedInteger('paye_jusqu_au_rang')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->dropColumn('paye_jusqu_au_rang');
        });
    }
};
