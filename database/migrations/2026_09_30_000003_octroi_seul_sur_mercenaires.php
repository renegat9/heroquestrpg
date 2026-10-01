<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le Squelette Hearthkin (First Light, FL-Q p. 6) partage le catalogue
 * `mercenaires` — même bloc de stats, même table `groupe_mercenaires`, même
 * purge de fin de quête — mais il ne s'achète JAMAIS au hub : il n'existe que
 * par l'action du Cor des Hearthkin, en quête. `octroi_seul` est ce qui
 * retire une ligne du catalogue RECRUTABLE (`MercenaireController::catalogue()`)
 * sans en faire un second type de ligne ni une seconde table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mercenaires', function (Blueprint $table) {
            $table->boolean('octroi_seul')->default(false)->after('animal');
        });
    }

    public function down(): void
    {
        Schema::table('mercenaires', function (Blueprint $table) {
            $table->dropColumn('octroi_seul');
        });
    }
};
