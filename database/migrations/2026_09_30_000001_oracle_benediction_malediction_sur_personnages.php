<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ORACLE — Bénédiction et Malédiction (First Light, FL-Q p. 6, lot C).
 *
 * Les deux états sont DURABLES — ils suivent le héros d'une quête à l'autre
 * jusqu'à usage ou levée (règle consolidée de CLAUDE.md : « every durable
 * game state lives in the DB, never in cache »). Colonnes sur `personnages`,
 * jamais sur l'état de quête : une bénédiction gagnée en quête 3 doit encore
 * pouvoir être dépensée en quête 7.
 *
 * - `benediction_oracle` : « Oracle's Blessing » — au choix, UNE fois : (a)
 *   révéler une salle derrière une porte fermée adjacente sans l'ouvrir
 *   (`ResolveurTour::resoudreOracleSalle()`), ou (b) après un jet d'Attaque
 *   ou de Défense, relancer tous les dés en gardant le second résultat
 *   (`MoteurReactions::relancerBenedictionOracle()`, réaction hors tour).
 *   Disparaît après usage (mis à `false`).
 * - `malediction_oracle` : « Oracle's Curse » (jeton Mark of Zargon). Posée
 *   sur la fiche à l'échec de l'épreuve de l'Oracle, elle reste tant qu'elle
 *   n'est pas levée par un don de 800 po entre deux quêtes
 *   (`PhaseMarche::leverMalediction()`). La CADENCE « une fois par quête »
 *   vit à part, sur `etat_personnage_quete.malediction_oracle_utilisee`
 *   (migration jumelle) : elle revient d'elle-même à chaque quête, puisque
 *   cette ligne est recréée au démarrage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->boolean('benediction_oracle')->default(false)->after('or');
            $table->boolean('malediction_oracle')->default(false)->after('benediction_oracle');
        });
    }

    public function down(): void
    {
        Schema::table('personnages', function (Blueprint $table) {
            $table->dropColumn(['benediction_oracle', 'malediction_oracle']);
        });
    }
};
