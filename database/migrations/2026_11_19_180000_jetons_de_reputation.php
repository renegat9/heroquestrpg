<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE — LA RÉPUTATION (lot K6, *Rise of the Dread Moon* p. 8). `groupes.jetons_reputation` : les jetons « for
 * the players to share » — un compteur du groupe, seule source (`App\Partie\Classique\Reputation`), jamais le cache, jamais
 * `groupes.or`. 0 pour tout groupe existant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->unsignedInteger('jetons_reputation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn('jetons_reputation');
        });
    }
};
