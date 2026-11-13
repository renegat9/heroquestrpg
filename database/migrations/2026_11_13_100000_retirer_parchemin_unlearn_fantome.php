<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Le PARCHEMIN FANTÔME « Parchemin : Unlearn » (relevé le 2026-11-13, retrait décidé par René).
 *
 * `ObjetSeeder` dérive un parchemin par sort, nommé d'après le sort. Le sort *Unlearn* a été renommé
 * « Désapprentissage » le 2026-10-08 (migration 2026_10_08_110000) APRÈS qu'un premier seed eut créé
 * « Parchemin : Unlearn » : le seeder suivant a créé « Parchemin : Désapprentissage » à côté, et l'ancienne
 * ligne est restée — même `effet.sort_id`, donc DEUX chances de tirer ce sort au butin.
 *
 * Ce n'est pas une donnée de partie : une ligne de CATALOGUE. On la retire, mais sans jamais rien perdre :
 * toute référence (sac d'un héros, artefact d'une quête, allié invoqué) est d'abord reportée sur le parchemin
 * « Désapprentissage ». Idempotente : sans ligne fantôme, ne fait rien. `down()` ne la recrée pas (le seeder ne
 * la recréerait pas non plus : le sort ne porte plus ce nom).
 */
return new class extends Migration
{
    private const FANTOME = 'Parchemin : Unlearn';

    private const BON = 'Parchemin : Désapprentissage';

    public function up(): void
    {
        if (! Schema::hasTable('objets')) {
            return;
        }

        $fantome = DB::table('objets')->where('nom', self::FANTOME)->value('id');

        if ($fantome === null) {
            return;
        }

        $bon = DB::table('objets')->where('nom', self::BON)->value('id');

        $references = [
            ['inventaire', 'objet_id'],
            ['quetes', 'artefact_objet_id'],
            ['groupe_mercenaires', 'invoque_par_objet_id'],
        ];

        foreach ($references as [$table, $colonne]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $colonne)) {
                continue;
            }

            $nombre = DB::table($table)->where($colonne, $fantome)->count();

            if ($nombre > 0 && $bon === null) {
                throw new RuntimeException(
                    "« ".self::FANTOME." » est référencé ({$table}.{$colonne}) et « ".self::BON." » n'existe pas : "
                    .'lancer le seeder des objets avant cette migration.'
                );
            }

            DB::table($table)->where($colonne, $fantome)->update([$colonne => $bon]);
        }

        DB::table('objets')->where('id', $fantome)->delete();
    }

    public function down(): void
    {
        // Rien : la ligne était un doublon. La recréer rendrait le défaut.
    }
};
