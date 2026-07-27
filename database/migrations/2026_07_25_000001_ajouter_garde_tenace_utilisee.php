<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garde tenace (nœud nain, doc 01 §6) : +1 dé de défense contre la PREMIÈRE
 * attaque subie du combat. Faute de notion de « combat » distincte en base
 * (précédent MVP : App\Partie\MoteurSorts, buffs « un_combat » portés jusqu'à
 * la fin de la quête), le nœud se consomme une fois par QUÊTE — la ligne
 * `etat_personnage_quete` est recréée à chaque quête, donc aucune remise à
 * zéro explicite n'est nécessaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('garde_tenace_utilisee')->default(false)->after('tombe');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('garde_tenace_utilisee');
        });
    }
};
