<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PIOCHE DES FORMES DE QWINDRAK (René, 2026-10-17 — `docs/plan-first-light.md`, « Exécution 2026-10-17 »),
 * et ORIGINE DE LA FIGURE (2026-10-22, `docs/plan-first-light.md`, « Exécution 2026-10-22 »).
 *
 * Qwindrak prend une forme Synchroforme à chaque forme vaincue, dans un ordre TIRÉ au dé et qui doit rester
 * le même jusqu'à la fin de la rencontre — à la reprise d'une partie comme dans un snapshot. La file des
 * formes restantes vit donc sur l'instance, EN COLONNE, jamais en cache ni dans l'habillage (qui s'écrit
 * par morceaux, et dont une écriture maladroite effacerait la file).
 *
 * `origine_monstre` (nom de catalogue de la créature qui a tiré la pioche : « Qwindrak the Warlock ») : la
 * figure courante est une LIGNE GÉNÉRIQUE (Gobelin, Orque…), qui ne porte ni le répertoire de sorts, ni le
 * météore. Ce qu'elle emporte de sa source se lit donc sur l'origine, jamais sur la fiche courante — en
 * colonne, pour la même raison que la file : une reprise ou un snapshot ne doit rien perdre de la règle.
 *
 * Migration ADDITIVE : deux colonnes nullables. `null` = pioche pas encore tirée (`formes_a_venir`) et figure
 * qui n'est pas une forme (`origine_monstre`) : toutes les lignes existantes (aucune créature à pioche n'a
 * jamais existé avant Qwindrak) restent exactement comme elles étaient. Rien à remplir.
 *
 * ⚠ Cette migration n'a jamais été jouée sur la vraie base : la colonne `origine_monstre` y a été AJOUTÉE à
 * celle des formes, plutôt que dans une seconde migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('instances_monstres', 'formes_a_venir')) {
            Schema::table('instances_monstres', function (Blueprint $table): void {
                $table->json('formes_a_venir')->nullable()->after('habillage');
            });
        }

        if (! Schema::hasColumn('instances_monstres', 'origine_monstre')) {
            Schema::table('instances_monstres', function (Blueprint $table): void {
                $table->string('origine_monstre')->nullable()->after('formes_a_venir');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('instances_monstres', 'origine_monstre')) {
            Schema::table('instances_monstres', function (Blueprint $table): void {
                $table->dropColumn('origine_monstre');
            });
        }

        if (Schema::hasColumn('instances_monstres', 'formes_a_venir')) {
            Schema::table('instances_monstres', function (Blueprint $table): void {
                $table->dropColumn('formes_a_venir');
            });
        }
    }
};
