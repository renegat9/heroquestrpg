<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `groupes.boites_bestiaire` — le bestiaire MANUEL (René, 2026-09-28) : la
 * liste des boîtes d'extension cochées à la création, vide = HeroQuest Game
 * System seul. `null` = AUTOMATIQUE, le comportement historique
 * (`theme_bestiaire`, tiré à la première quête). Voir `App\Partie\BestiaireGroupe`.
 *
 * Ajout d'une colonne nullable : aucune ligne existante ne change de sens —
 * toutes les campagnes en cours restent automatiques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->json('boites_bestiaire')->nullable()->after('theme_bestiaire');
        });
    }

    public function down(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn('boites_bestiaire');
        });
    }
};
