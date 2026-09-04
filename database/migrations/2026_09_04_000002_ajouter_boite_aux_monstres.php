<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `monstres.boite` — la BOÎTE d'origine d'une créature (René, 2026-09-04 :
 * « une bonne diversité selon le thème »).
 *
 * La donnée existait déjà, mais seulement en COMMENTAIRE : `MonstreSeeder`
 * groupe ses créatures par boîte d'extension depuis le portage de la doc 18,
 * et la doc 18 les source ainsi, livret par livret. Elle n'était lisible par
 * personne — la génération de quête ne connaissait que `tier` et `cout`, si
 * bien qu'une quête « glacée » et une quête « jungle » puisaient dans le même
 * sac indifférencié. Le thème ne venait que de l'habillage IA, qui RENOMME ce
 * qui est déjà là et ne choisit jamais quelle créature apparaît.
 *
 * ⚠ `null` n'est pas un trou : il vaut « **aucune boîte** », et c'est le cas de
 * nos propres blocs de stats (Troll, Champion, Seigneur, les trois sorciers
 * nommés). Une créature sans boîte convient à TOUS les thèmes — ce qui garantit
 * qu'aucun pool ne se vide, quelle que soit la boîte tirée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->string('boite', 40)->nullable()->after('tier');
        });
    }

    public function down(): void
    {
        Schema::table('monstres', function (Blueprint $table) {
            $table->dropColumn('boite');
        });
    }
};
