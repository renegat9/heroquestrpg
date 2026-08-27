<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COMPTEUR DE PITIÉ du passage secret (René, 2026-08-27).
 *
 * Une carte sur deux cache une salle derrière une porte secrète. Un tirage à
 * 50 % pur peut cependant laisser une campagne entière sans le moindre passage
 * — et c'est justement le genre de série que personne ne lit comme du hasard :
 * le groupe conclut que la fonctionnalité n'existe pas et cesse de fouiller.
 *
 * D'où la chance qui MONTE de 10 points à chaque carte sans passage, et
 * RETOMBE à 50 dès qu'on en pose un. Au pire, cinq cartes sans rien, puis la
 * certitude.
 *
 * ⚠ Colonne sur `groupes` et non en cache : c'est un état de campagne durable,
 * et « tout état de jeu durable vit en base » (règle consolidée du projet). Le
 * perdre remettrait la campagne à 50 % en silence, ce qui est précisément ce
 * que le compteur existe pour éviter.
 *
 * ⚠ Défaut 50 : une campagne déjà en cours reprend au taux de base, sans dette
 * ni crédit. On ne peut pas reconstituer son historique — la carte ne garde pas
 * trace de « il n'y avait pas de passage » — et inventer un compteur de reprise
 * serait pire qu'un redémarrage propre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->unsignedTinyInteger('chance_passage_secret')->default(50)->after('or');
        });
    }

    public function down(): void
    {
        Schema::table('groupes', function (Blueprint $table) {
            $table->dropColumn('chance_passage_secret');
        });
    }
};
