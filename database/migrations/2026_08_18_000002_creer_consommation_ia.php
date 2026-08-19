<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Télémétrie de consommation LLM (René, 2026-08-18) — jusqu'ici AUCUN
 * compteur de tokens n'existait : `StatutIA` ne retient que le DERNIER essai
 * (succès/repli/échec), rien d'agrégé, rien d'historique. Le projet réduit
 * drastiquement ses appels IA (~145/quête → 2, voir la migration jumelle
 * `2026_08_18_000001_ajouter_recits_quete.php`) — sans ce compteur,
 * impossible de VÉRIFIER le gain, seulement de l'espérer.
 *
 * Une ligne = UNE réponse HTTP réellement facturée par un fournisseur
 * (Anthropic ou Gemini), jamais un `Skill::generer()` : les retries
 * (`Skill::MAX_RETRIES` = 2, jusqu'à 3 appels facturés pour une seule
 * sortie) et le failover croisé (`ClientLLMAvecRepli`, qui rejoue l'appel
 * COMPLET chez l'AUTRE fournisseur) sont chacun une réponse HTTP distincte,
 * et donc une ligne distincte — c'est le seul moyen de les voir : à la
 * simple lecture du résultat final d'un skill, ils sont invisibles.
 *
 * `groupe_id` est volontairement SANS clé étrangère ni cascade de
 * suppression (contrairement à `evenements`/`snapshots`, qui SONT de l'état
 * de jeu et disparaissent avec la campagne) : cette table est un journal de
 * MÉTRIQUES, pas de l'état de jeu — elle doit survivre à la clôture/purge de
 * la campagne qui l'a produite, sans quoi la consommation ne pourrait
 * jamais s'accumuler au-delà d'une seule campagne encore ouverte. Nullable :
 * un appel de test (bouton « Tester » du panneau Réglages) n'a pas de
 * groupe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consommation_ia', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('groupe_id')->nullable();
            $table->index('groupe_id');
            $table->string('skill'); // App\Agent\Skills\*::nomOutil(), ou 'inconnu' hors d'un skill (ex. test de connectivité)
            $table->string('fournisseur'); // 'anthropic' | 'gemini'
            $table->string('modele');
            $table->unsignedInteger('tokens_entree');
            $table->unsignedInteger('tokens_sortie');
            $table->unsignedInteger('tokens_cache')->nullable(); // lecture de cache, seulement si le fournisseur la distingue
            $table->unsignedTinyInteger('tentative')->default(1); // 1 = premier essai ; 2+ = retry skill ou failover
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consommation_ia');
    }
};
