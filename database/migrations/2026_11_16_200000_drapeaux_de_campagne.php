<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE, lot K5h — les DRAPEAUX DE CAMPAGNE (plan §2.5 C4, prévu au §3 « Tables »).
 *
 * `groupes.drapeaux_campagne` = `{nom: true}`, comme `quetes.drapeaux`, mais sur le GROUPE : posés par l'effet de note
 * `drapeau` avec `campagne: true`, ils survivent à la quête et se lisent dans les suivantes (`Quete::aDrapeau()`). Un
 * état durable de partie : une colonne, jamais le cache. `null` pour tout groupe existant — aucun n'en a encore posé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->json('drapeaux_campagne')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn('drapeaux_campagne');
        });
    }
};
