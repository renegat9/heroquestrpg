<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QUI a choisi les boîtes du bestiaire (René, 2026-11-05 : le MJ IA choisit les extensions selon le thème, sinon un
 * tirage au hasard selon la longueur). `groupes.boites_bestiaire` reste LA liste qui filtre ; cette colonne dit d'où
 * elle vient : `mj` (le MJ IA, selon le thème), `tirage` (au hasard), `null` (cochées par les joueurs, le manuel).
 *
 * Ajout pur : aucune ligne existante ne change. Les campagnes d'avant (`boites_bestiaire` `null`) gardent la rotation
 * historique, et un bestiaire coché avant ce jour reste « cochées par les joueurs ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->string('origine_boites', 16)->nullable()->after('boites_bestiaire');
        });
    }

    public function down(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn('origine_boites');
        });
    }
};
