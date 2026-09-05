<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `quetes.objectif_majeur` — cette quête ORDINAIRE fait-elle monter d'un
 * niveau quand son objectif est accompli ? (doc 01 §5, troisième déclencheur,
 * porté le 2026-09-04.)
 *
 * ⚠ Une COLONNE, et non un calcul refait à la fin de la quête, pour la même
 * raison que `type_jalon` en est une : le fait est décidé au DÉMARRAGE, à
 * partir du plan de campagne et du nombre de quêtes. Le plan est écrit par un
 * job asynchrone ; le relire à la fin exposerait une quête commencée avant son
 * arrivée à voir sa réponse changer en cours de route. Figé au départ, il est
 * aussi inspectable et il traverse les instantanés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->boolean('objectif_majeur')->default(false)->after('type_jalon');
        });
    }

    public function down(): void
    {
        Schema::table('quetes', function (Blueprint $table) {
            $table->dropColumn('objectif_majeur');
        });
    }
};
