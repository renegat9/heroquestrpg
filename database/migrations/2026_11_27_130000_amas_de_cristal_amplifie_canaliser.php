<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LOT E2 (*Jungles of Delthrak*, F9907 p. 3) : « If a monster casts Channel Dread while adjacent to the crystal cluster, they
 * add 1 to their roll result. » L'Amas de cristal porte désormais `amplifie_canaliser_effroi` (lecteur
 * `MoteurDread::bonusCristauxAdjacents()`, quêtes construites seulement). Une ligne de catalogue existante change : une
 * migration, pas un re-seed. Les autres clés de l'effet sont gardées.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('mobiliers')->where('nom', 'Amas de cristal')->get(['id', 'effet']) as $ligne) {
            $effet = json_decode((string) $ligne->effet, true) ?: [];
            $effet['amplifie_canaliser_effroi'] = 1;
            DB::table('mobiliers')->where('id', $ligne->id)->update(['effet' => json_encode($effet)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('mobiliers')->where('nom', 'Amas de cristal')->get(['id', 'effet']) as $ligne) {
            $effet = json_decode((string) $ligne->effet, true) ?: [];
            unset($effet['amplifie_canaliser_effroi']);
            DB::table('mobiliers')->where('id', $ligne->id)->update(['effet' => $effet === [] ? null : json_encode($effet)]);
        }
    }
};
