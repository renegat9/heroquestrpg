<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un monstre peut passer du côté des héros, le temps d'un tour.
 *
 * *Baguette d'Os* : « This artifact enables any hero to control all skeletons in
 * one room for one turn. » La créature ne change ni de stats ni de camp
 * durablement — elle change de PILOTE, et il faut savoir lequel : la phase des
 * sbires se joue à la fin du tour de CE héros-là, pas de n'importe lequel.
 *
 * ⚠ Deux colonnes, et non une entrée dans `habillage` : `HabillerMonstres`
 * relit et réécrit ce JSON pendant la première minute d'une quête, et cette
 * course a déjà effacé des conditions en silence une fois (2026-09-03). Une
 * donnée que le jeu écrit en cours de tour n'a rien à y faire.
 *
 * ⚠ `controle_agi` est ce qui distingue « il lui reste son action » de « il a
 * joué ce round ». Sans lui, `phaseMonstres()` le ferait rejouer du côté de
 * Zargon dans le même round : deux tours pour une créature, dont un contre ses
 * propres alliés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->foreignId('controle_par')->nullable()->after('degat_differe')
                ->constrained('personnages')->nullOnDelete();
            $table->boolean('controle_agi')->default(false)->after('controle_par');
        });
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropConstrainedForeignId('controle_par');
            $table->dropColumn('controle_agi');
        });
    }
};
