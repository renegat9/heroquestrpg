<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La synthèse vocale Gemini passe à COUPÉE par défaut (René, 2026-08-22).
 *
 * Le modèle preview `gemini-2.5-flash-tts` plafonne à 100 requêtes/jour MÊME
 * facturé, et une quête en consomme ~10 (mesuré sur la pile réelle le
 * 2026-08-22 : 10 fichiers pour 10 salles). Dix quêtes par jour et le quota
 * est vide — partagé, qui plus est, avec la génération d'images.
 *
 * ⚠ Ce n'est PAS une dégradation : jouer en Web Speech est un mode supporté
 * (CLAUDE.md), la table lit tout, et `BibliothequeNarration::salle()` sert
 * alors la phrase d'entrée nominative en plus du texte figé — l'absence de
 * bande-son est précisément ce qui l'autorise. Le pack de récits, lui, est
 * écrit quoi qu'il arrive : c'est la VOIX qu'on coupe, pas le texte.
 *
 * ⚠ Et c'est le SEUL interrupteur qui économise vraiment le quota. La bascule
 * « narration par la voix du navigateur » est un réglage d'APPAREIL
 * (`localStorage`, `useVoix.js`) : elle fait jeter l'`url` au lecteur après
 * que le fichier a été demandé, généré et facturé. Couper côté appareil ne
 * coûtait rien de moins ; couper ici sort `GenererVoixQuete` avant le premier
 * appel réseau, et rend au passage ~2 min 45 sur la préparation d'une quête.
 *
 * La ligne singleton EXISTANTE est basculée avec le défaut : sans ça,
 * l'instance déjà installée (la seule qui compte, le projet est auto-hébergé)
 * garderait l'ancien réglage et le « défaut » ne changerait rien pour
 * personne. Le panneau Réglages permet de la rallumer quand on la veut —
 * elle prend effet à la quête SUIVANTE, la synthèse étant faite à la
 * construction et jamais à la volée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->boolean('voix_dynamique_active')->default(false)->change();
        });

        DB::table('parametres')->update(['voix_dynamique_active' => false]);
    }

    public function down(): void
    {
        Schema::table('parametres', function (Blueprint $table) {
            $table->boolean('voix_dynamique_active')->default(true)->change();
        });

        // La LIGNE n'est pas remise à `true` : on ne sait pas ce que l'exploitant
        // avait choisi avant, et rallumer une génération facturée à son insu
        // serait le pire des deux torts.
    }
};
