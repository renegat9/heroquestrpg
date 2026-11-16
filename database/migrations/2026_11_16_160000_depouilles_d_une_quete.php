<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE, lot H1b — les AFFAIRES d'un héros mort en Heroic (livret de règles de *First Light*, G0978, « Dead
 * Heroes » : « Armor, weapons, and treasures that belonged to you prior to death can be picked up by any other hero in
 * the room with you, no action required »).
 *
 * `quetes.depouilles` = `[{personnage_id, nom, x, y}]` : les affaires restées au sol, à la case où le héros est mort,
 * tant que personne ne les a ramassées. Les objets eux-mêmes ne bougent pas : ils restent sur la ligne du mort jusqu'au
 * ramassage. `null` = aucune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('depouilles')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('depouilles');
        });
    }
};
