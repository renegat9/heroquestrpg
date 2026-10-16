<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMPAGNON ANIMAL OFFERT (*Against the Ogre Horde*, livret F9528 p. 8-9 : « recruited at no
 * cost […] so long as there are fewer than four hero players »), décision de René
 * 2026-10-02 (Q2) et interprétation confirmée le 2026-10-15 : proposé UNE fois, gratuitement,
 * au lancement de la PREMIÈRE quête d'un groupe de moins de 4 héros, et il accompagne le
 * groupe toute la campagne.
 *
 * DEUX colonnes, toutes deux ADDITIVES et sans effet sur les lignes existantes :
 *
 *  - `groupes.compagnon_offert` : l'ISSUE durable de la proposition — `null` (pas encore
 *    tranchée), `'pris'` ou `'refuse'`. Jamais en cache (règle dure : un état de jeu durable
 *    vit en base). Les groupes déjà en campagne n'ont aucune offre à recevoir : la
 *    proposition n'existe que tant que le groupe n'a JAMAIS lancé de quête
 *    (`App\Partie\CompagnonOffert::ouvert()`), ce qui les met hors de portée sans y toucher.
 *
 *  - `groupe_mercenaires.offert` : cet allié est le compagnon OFFERT. Lu à un seul endroit
 *    qui change le jeu, `FaveursHopekins::reglerEntretien()` — « at no cost » vaut pour tout
 *    son séjour : l'entretien de 10 po par quête est celui d'un suivant ENGAGÉ, et celui-ci ne
 *    l'a jamais été. Sans la colonne, un groupe à sec perdrait son animal à la première quête
 *    et « toute la campagne » ne voudrait rien dire. Défaut `false` : tout allié existant
 *    reste un suivant ordinaire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->string('compagnon_offert', 16)->nullable()->after('phase');
        });

        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->boolean('offert')->default(false)->after('invoque_par_objet_id');
        });
    }

    public function down(): void
    {
        Schema::table('groupe_mercenaires', function (Blueprint $table) {
            $table->dropColumn('offert');
        });

        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn('compagnon_offert');
        });
    }
};
