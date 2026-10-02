<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * VENT VÉLOCE et POTION DE VITESSE — `duree: prochain_deplacement` au lieu de
 * `ce_tour` (errata 2021 B4, 2026-10-01).
 *
 * Les deux cartes disent « the next time they move » / « next movement ». En
 * `ce_tour`, le bonus tombait à la fin du tour du porteur même s'il n'avait
 * pas bougé. La durée est RELUE sur la source à chaque expiration, jamais
 * recopiée sur le pivot : réécrire l'effet du catalogue suffit, y compris pour
 * un buff déjà posé en partie.
 *
 * On ne touche qu'à la clé `duree`, en relisant le JSON existant — le reste de
 * l'effet reste tel que la base le porte.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->duree('sorts', 'Vent Véloce', 'prochain_deplacement');
        $this->duree('objets', 'Potion de vitesse', 'prochain_deplacement');
    }

    public function down(): void
    {
        $this->duree('sorts', 'Vent Véloce', 'ce_tour');
        $this->duree('objets', 'Potion de vitesse', 'ce_tour');
    }

    private function duree(string $table, string $nom, string $duree): void
    {
        foreach (DB::table($table)->where('nom', $nom)->get(['id', 'effet']) as $ligne) {
            $effet = json_decode((string) $ligne->effet, true) ?: [];
            $effet['duree'] = $duree;

            DB::table($table)->where('id', $ligne->id)->update([
                'effet' => json_encode($effet, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
};
