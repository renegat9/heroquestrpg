<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIOCHE DES FORMES DE QWINDRAK (René, 2026-10-17 — `docs/plan-first-light.md`, « Exécution 2026-10-17 »).
 *
 * Qwindrak prend une forme Synchroforme à chaque forme vaincue, dans un ordre TIRÉ au dé et qui doit rester
 * le même jusqu'à la fin de la rencontre — à la reprise d'une partie comme dans un snapshot. La file des
 * formes restantes vit donc sur l'instance, EN COLONNE, jamais en cache ni dans l'habillage (qui s'écrit
 * par morceaux, et dont une écriture maladroite effacerait la file).
 *
 * Migration ADDITIVE : une colonne JSON nullable. `null` = pioche pas encore tirée, donc toutes les lignes
 * existantes (aucune créature à pioche n'a jamais existé avant Qwindrak) restent exactement comme elles
 * étaient. Rien à remplir.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('instances_monstres', 'formes_a_venir')) {
            return;
        }

        Schema::table('instances_monstres', function (Blueprint $table): void {
            $table->json('formes_a_venir')->nullable()->after('habillage');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('instances_monstres', 'formes_a_venir')) {
            return;
        }

        Schema::table('instances_monstres', function (Blueprint $table): void {
            $table->dropColumn('formes_a_venir');
        });
    }
};
