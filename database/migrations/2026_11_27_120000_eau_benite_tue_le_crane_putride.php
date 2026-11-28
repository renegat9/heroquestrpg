<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LOT E2 (*Jungles of Delthrak*, F9907 p. 49) : « Skullblight […] They can be destroyed by Holy Water. » L'Eau bénite tue
 * désormais aussi le Crâne putride, PARTOUT — RPG compris (René, 2026-11-27 : une correction sourcée, exception écrite à
 * « le classique ne touche pas au RPG »). Une ligne de catalogue existante change : une migration, pas un re-seed.
 */
return new class extends Migration
{
    private const CRANE = 'Crâne putride';

    public function up(): void
    {
        foreach (DB::table('objets')->where('nom', 'Eau bénite')->get(['id', 'effet']) as $ligne) {
            $effet = json_decode((string) $ligne->effet, true) ?: [];
            $creatures = (array) ($effet['tue_creatures'] ?? []);
            if (! in_array(self::CRANE, $creatures, true)) {
                $effet['tue_creatures'] = [...$creatures, self::CRANE];
                DB::table('objets')->where('id', $ligne->id)->update(['effet' => json_encode($effet)]);
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('objets')->where('nom', 'Eau bénite')->get(['id', 'effet']) as $ligne) {
            $effet = json_decode((string) $ligne->effet, true) ?: [];
            $effet['tue_creatures'] = array_values(array_diff((array) ($effet['tue_creatures'] ?? []), [self::CRANE]));
            DB::table('objets')->where('id', $ligne->id)->update(['effet' => json_encode($effet)]);
        }
    }
};
