<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effets GLOBAUX de la quête (Jungles of Delthrak q. 8, note A : « All Goblins in
 * this quest are elite warriors dedicated to Gruulob and roll 1 additional Attack
 * die ») — la liste que la quête porte, figée au démarrage par
 * `App\Partie\EffetsGlobauxQuete::etablir()`, comme `type_jalon`.
 *
 * En BASE, jamais en cache (règle consolidée du projet). NULL = quête ouverte avant
 * cette colonne : sa liste se lit alors sur sa roster, sans écriture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('effets_globaux')->nullable()->after('captif_mercenaire_id');
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('effets_globaux');
        });
    }
};
