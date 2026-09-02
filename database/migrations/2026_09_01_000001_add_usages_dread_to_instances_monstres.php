<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les usages de sorts de Dread vivaient en cache (`dread:usages:{instance}:{quete}`,
 * `Cache::forever`) — exactement le motif que la règle consolidée du projet
 * interdit : tout état de jeu durable est en base, jamais en cache. La perte
 * d'une clé ne cassait rien de visible, elle rendait simplement le boss MUET
 * pour le reste de la quête (`usagesRestants()` retombe à 0), et personne
 * n'aurait pu le diagnostiquer en jeu.
 *
 * Trois colonnes, une par compteur : le budget par rencontre, et les deux
 * verrous 1×/rencontre de l'Invocation et de la Fuite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            // 0 = plus d'usage. Réarmée par MoteurDread::reinitialiserUsagesInstance()
            // au démarrage de quête ; reste à 0 pour un monstre de base.
            $table->unsignedTinyInteger('usages_dread')->default(0)->after('brule');
            $table->boolean('invocation_dread_utilisee')->default(false)->after('usages_dread');
            $table->boolean('fuite_dread_utilisee')->default(false)->after('invocation_dread_utilisee');
        });

        // Rattrapage des quêtes EN VOL : leur budget vivait en cache, la colonne
        // naît à 0 — sans cela, tout boss d'une partie en cours resterait muet
        // jusqu'à la quête suivante. On rend le budget plein plutôt qu'un reste
        // qu'on ne peut plus connaître : mieux vaut un sort de trop qu'un boss
        // silencieux, et la clé de cache d'origine n'est plus lisible ici.
        foreach (['sous_boss' => 2, 'boss' => 3] as $tier => $usages) {
            DB::table('instances_monstres')
                ->where('etat', 'actif')
                ->whereIn('monstre_id', DB::table('monstres')->where('tier', $tier)->pluck('id'))
                ->update(['usages_dread' => $usages]);
        }
    }

    public function down(): void
    {
        Schema::table('instances_monstres', function (Blueprint $table) {
            $table->dropColumn(['usages_dread', 'invocation_dread_utilisee', 'fuite_dread_utilisee']);
        });
    }
};
