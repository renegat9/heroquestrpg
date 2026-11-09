<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE ARÈNE, lot A2 (`docs/plan-mode-arene.md` §3) : un combat d'arène est un `groupes` comme les autres — pour
 * réutiliser la table, la manette, Reverb, les menus et le tour — distingué par `mode`, lu par un seul prédicat,
 * `Groupe::estArene()`. Vocabulaire fermé : `Groupe::MODES`. Aucune ligne existante ne change : tout groupe existant
 * est une campagne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->string('mode', 16)->default('campagne');
        });
    }

    public function down(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
