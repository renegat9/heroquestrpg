<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MÉMOIRE DES DÉGÂTS SUBIS (René, 2026-09-03).
 *
 * Deux colonnes sur l'état de quête d'un héros :
 *  - `degats_subis` : cumul par SOURCE depuis le début de la quête ;
 *  - `dernier_degat` : source et montant du dernier coup encaissé.
 *
 * Elles existent parce qu'une carte pose une question à laquelle rien ne savait
 * répondre : la *Plume anti-poison* dit « restores ANY of the owner's Body
 * Points lost by poisoning ». Sans mémoire, on ne peut que rendre un forfait —
 * c'est-à-dire approximer la carte, ce que le projet refuse.
 *
 * ⚠ Sur l'ÉTAT DE QUÊTE et non sur le personnage : la mémoire doit mourir avec
 * la quête, comme les jetons de Rejeton et les compteurs de capacités. Un cumul
 * qui traverserait le hub ferait rendre à la Plume des PV perdus dans un donjon
 * précédent.
 *
 * ⚠ Elles rejoignent le snapshot (`Sauvegarde`) au même titre que les autres
 * compteurs par quête : une `/reprise` qui les oublierait rendrait à la Plume un
 * cumul déjà encaissé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->json('degats_subis')->nullable()->after('jetons_rejeton');
            $table->json('dernier_degat')->nullable()->after('degats_subis');
        });
    }

    public function down(): void
    {
        Schema::table('etat_personnage_quete', function (Blueprint $table) {
            $table->dropColumn(['degats_subis', 'dernier_degat']);
        });
    }
};
