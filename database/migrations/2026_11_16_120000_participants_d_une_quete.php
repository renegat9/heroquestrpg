<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MODE CLASSIQUE, lot S1 — QUI JOUE une quête (`docs/plan-mode-classique.md` §6 : la quête SOLO, *The Frozen Horror*,
 * *The Mage of the Mirror* : « seul le héros désigné entre »).
 *
 * `quetes.participants` = la liste des `personnages.id` engagés dans la quête, figée au démarrage par
 * `DemarreurQueteConstruite`. Un héros actif du groupe absent de la liste est SPECTATEUR : pas de ligne d'état, pas de
 * menu, rien à risquer ni à gagner. Distinct d'un héros SORTI, qui y figure mais n'a plus de ligne d'état.
 *
 * `null` = tout le groupe (toute quête d'avant, toute quête générée, toute quête construite sans règle solo). Ajout
 * d'une colonne nullable : aucune ligne existante n'est réécrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->json('participants')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('participants');
        });
    }
};
