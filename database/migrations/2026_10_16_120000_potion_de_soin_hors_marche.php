<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * POTION DE SOIN HORS DE L'ÉTAL (René, 2026-10-16 : « la Potion de soin, 100 po, prix non
 * sourcé, ne se vend plus ; la Potion de guérison sourcée, 500 po, reste en vente »).
 *
 * Même drapeau que les trois cartes de trésor sans prix (`effet.hors_marche`, migration
 * `2026_10_16_110000`), lu au même point de passage `PhaseMarche::estHorsMarche()` : ni à
 * l'étal, ni dans l'inventaire vendable, ni revendable. Une ligne du catalogue, désignée par
 * son nom. Aucune ligne d'inventaire n'est touchée : une Potion de soin déjà dans un sac reste
 * buvable (`MoteurPotions::boire()` ne lit pas `hors_marche`), elle ne se revend plus.
 *
 * La Potion de soin reste trouvable en coffre : `DeckFouille::carteCoffre()` tire ses potions
 * de la clé `potions` du gabarit, qui n'est pas l'étal. Le retrait ne concerne que la vente.
 *
 * Migration et pas seulement une re-semence : `ObjetSeeder` pose la même clé, mais changer une
 * ligne existante se fait par migration dans ce projet (CLAUDE.md) — un `migrate` la joue sur la
 * base de production sans rejouer le catalogue.
 */
return new class extends Migration
{
    private const NOM = 'Potion de soin';

    public function up(): void
    {
        $this->poser(true);
    }

    public function down(): void
    {
        $this->poser(false);
    }

    private function poser(bool $horsMarche): void
    {
        foreach (DB::table('objets')->where('nom', self::NOM)->get(['id', 'effet']) as $ligne) {
            $effet = json_decode((string) $ligne->effet, true) ?: [];

            if ($horsMarche) {
                $effet['hors_marche'] = true;
            } else {
                unset($effet['hors_marche']);
            }

            DB::table('objets')->where('id', $ligne->id)->update([
                'effet' => json_encode($effet, JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
};
