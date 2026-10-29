<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nelath lance chaque sort de sa liste une fois (René, 2026-10-29, option 2) : elle porte `sorts_uniques` en plus de
 * `double_sort`. Budget = son répertoire (4 sorts) au lieu du budget du boss (3) — deux tours à deux sorts.
 *
 * Une ligne de CATALOGUE seulement. Le budget d'une instance est armé à son placement
 * (`MoteurDread::reinitialiserUsagesInstance()`) : une Nelath déjà posée garderait son ancien budget jusqu'à sa
 * prochaine rencontre. Vérifié sur la vraie base le 2026-10-29 : aucune instance de Nelath.
 */
return new class extends Migration
{
    private const NOM = 'Nelath, la Reine des Esprits';

    public function up(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)
            ->update(['capacites' => json_encode(['double_sort', 'sorts_uniques'])]);
    }

    public function down(): void
    {
        DB::table('monstres')->where('nom_base', self::NOM)
            ->update(['capacites' => json_encode(['double_sort'])]);
    }
};
