<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CARTES DE TRÉSOR SANS PRIX — `effet.hors_marche` (René, 2026-10-16 :
 * « On ne peut pas vendre les potions qu'on trouve dans les trésors »).
 *
 * Trois potions du deck de fouille n'ont aucun prix imprimé sur leur carte
 * (*Heroic Brew*, *Potion of Strength*, *Potion of Defense* : reference/16 §7.4),
 * et l'étal les vendait pourtant à 150 po inventées. La clé `hors_marche` les
 * retire de l'étal et de la revente (`PhaseMarche::estHorsMarche()`).
 *
 * Une migration et pas seulement une re-semence : `ObjetSeeder` pose la même
 * clé, mais changer une ligne existante se fait par migration dans ce projet,
 * et un `migrate` la joue sur la base de production sans rejouer le catalogue.
 * On ne touche qu'au JSON `effet` de trois lignes du catalogue, désignées par
 * leur nom : aucune ligne d'inventaire n'est modifiée — une potion déjà dans un
 * sac reste buvable, elle ne se revend plus.
 */
return new class extends Migration
{
    private const POTIONS = [
        "Potion d'héroïsme",
        'Potion de force',
        'Potion de défense',
    ];

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
        foreach (self::POTIONS as $nom) {
            foreach (DB::table('objets')->where('nom', $nom)->get(['id', 'effet']) as $ligne) {
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
    }
};
