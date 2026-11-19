<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LOT K6 (mode classique, 2026-11-19) : la *Crown of Shadows* « allows you to see normally in darkness, both magical and
 * nonmagical » — la clé `vision_nocturne` a désormais son lecteur (`Classique\ObscuriteMagique`). Une ligne de catalogue
 * existante change : une migration, pas un re-seed (CLAUDE.md). Les autres clés de l'effet sont gardées telles quelles.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('objets')->where('nom', 'Couronne des Ténèbres')->get(['id', 'effet']) as $ligne) {
            $effet = json_decode((string) $ligne->effet, true) ?: [];
            $effet['vision_nocturne'] = true;
            DB::table('objets')->where('id', $ligne->id)->update(['effet' => json_encode($effet)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('objets')->where('nom', 'Couronne des Ténèbres')->get(['id', 'effet']) as $ligne) {
            $effet = json_decode((string) $ligne->effet, true) ?: [];
            unset($effet['vision_nocturne']);
            DB::table('objets')->where('id', $ligne->id)->update(['effet' => json_encode($effet)]);
        }
    }
};
