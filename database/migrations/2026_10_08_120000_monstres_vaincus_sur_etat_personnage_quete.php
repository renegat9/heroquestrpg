<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PEACEKEEPER (Hopekins Rest, Wizards of Morcar) — « Keep track of the number of
 * monsters you reduce to 0 Body Points for each quest. For your service, the
 * Realm rewards you with 25 gold coins per monster defeated at the end of that
 * quest. »
 *
 * Le compteur vit sur la ligne `etat_personnage_quete` du héros pour CETTE
 * quête : il repart à zéro avec chaque quête, il survit à une reprise (le
 * snapshot le porte, `Sauvegarde`), et une quête perdue ne le lit jamais.
 * Jamais un cache, jamais un total global : « for each quest » est une donnée
 * par quête.
 *
 * Additive : une colonne `default 0`, aucune ligne existante n'est touchée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $t) {
            $t->unsignedSmallInteger('monstres_vaincus')->default(0)->after('dernier_degat');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $t) {
            $t->dropColumn('monstres_vaincus');
        });
    }
};
