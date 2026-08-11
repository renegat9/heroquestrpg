<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les alliés gagnent leurs Points de Mind et leurs capacités.
 *
 * Les cartes officielles (© 2023 Hasbro) portent CINQ valeurs — Movement
 * Squares, Attack, Defend, Body **et Mind** — là où notre table n'en gardait
 * que quatre : un allié n'avait aucun Mind, donc aucune prise sur les sorts
 * mentaux, ce qui en faisait sans le vouloir des figures insensibles à la peur
 * et au sommeil.
 *
 * `capacites` reprend la forme de `monstres.capacites` : une liste de mots-clés
 * que le moteur lit. Trois alliés en ont besoin — le Fauchard et deux animaux
 * frappent en diagonale, le Raptor bouge avant ET après son attaque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercenaires', function (Blueprint $table) {
            $table->unsignedTinyInteger('pv_mind')->default(2)->after('pv_body');
            $table->json('capacites')->nullable()->after('pv_mind');
        });
    }

    public function down(): void
    {
        Schema::table('mercenaires', function (Blueprint $table) {
            $table->dropColumn(['pv_mind', 'capacites']);
        });
    }
};
