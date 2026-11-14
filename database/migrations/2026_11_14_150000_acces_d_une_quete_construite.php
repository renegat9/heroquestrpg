<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE — par où l'on ENTRE dans une quête construite et par où l'on en SORT
 * (`docs/plan-mode-classique.md` §2.3, R2 et R3 ; René, 2026-11-14 : « dans certaines extensions ce sont des portes
 * qui font l'entrée et la sortie »).
 *
 * `quetes.acces` = `{entree: {type, cases, porte?}, sortie: {type, cases, porte?}}`, figé au démarrage par
 * `DemarreurQueteConstruite`. `type` vaut `escalier` ou `porte` ; `cases` sont les cases d'où un héros quitte le
 * donjon (les marches, ou la case devant la porte). État durable d'une quête : en colonne.
 *
 * `null` = toute quête d'avant, et toute quête générée : la sortie se lit alors sur l'escalier de la carte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('acces')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('acces');
        });
    }
};
