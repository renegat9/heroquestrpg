<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SABLES MOUVANTS (*The Mage of the Mirror* F7539 p. 29, quête 8, note C) : un
 * saut raté « lands in the quicksand and starts to sink » ; le héros abandonne
 * deux objets, son tour s'achève, et « on the hero's next turn, the hero climbs
 * out of the quicksand onto the square across from the square they left ».
 *
 * Cet état DOIT survivre à un téléphone rechargé, à un round entier et à une
 * reprise de snapshot : une COLONNE, jamais une clé de cache (règle consolidée,
 * CLAUDE.md « chaque état durable vit en base »). Même patron que
 * `piege_a_ecarter` (2026-09-24) : un JSON posé par le moteur, lu par
 * `MenuMoteur::generer()` pour FORCER le menu, effacé par la résolution qui le
 * soldera.
 *
 * Forme : {phase: 'defausse'|'sables', a_defausser: int, sable: {x, y},
 * vers: {x, y}, terrain: string} — voir `App\Partie\MoteurSables`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('enlise')->nullable()->after('piege_a_ecarter');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('enlise');
        });
    }
};
