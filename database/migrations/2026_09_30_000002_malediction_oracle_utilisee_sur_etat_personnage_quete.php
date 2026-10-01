<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ORACLE — le jeton Mark of Zargon est utilisable UNE FOIS PAR QUÊTE (FL-Q
 * p. 6 : « the token returns to the hero's sheet at the start of each
 * quest »). Colonne sur `etat_personnage_quete`, jamais sur `personnages` ni
 * en cache : une ligne neuve est créée à chaque démarrage de quête
 * (`DemarreurQuete`), donc le compteur revient à `false` tout seul — même
 * patron que `capacites_utilisees` pour les capacités « once per quest ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->boolean('malediction_oracle_utilisee')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn('malediction_oracle_utilisee');
        });
    }
};
