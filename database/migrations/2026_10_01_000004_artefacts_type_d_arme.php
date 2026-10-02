<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TYPE D'ARME DES ARTEFACTS — `est_une` (errata 2021 B2, 2026-10-01).
 *
 * Avalon Hill a confirmé que le *Fléau des Orques* (Orc's Bane) est une épée
 * courte et la *Lame Fantôme* (Phantom Blade) une dague ; la carte de la
 * *Serre du Corbeau* (Raven's Talon) dit elle-même « this dagger ». Les règles
 * qui nomment une arme — l'Ambidextrie du Rogue, la liste du Moine — les
 * reconnaissent désormais par `Equipement::estArmeDeType()`.
 *
 * Ajoute la clé au JSON existant sans toucher au reste ; aucune ligne
 * d'inventaire n'est concernée (l'effet est relu sur le catalogue).
 */
return new class extends Migration
{
    private const TYPES = [
        'Fléau des Orques' => 'Épée courte',
        'Lame Fantôme' => 'Dague',
        'Serre du Corbeau' => 'Dague',
    ];

    public function up(): void
    {
        foreach (self::TYPES as $nom => $type) {
            $this->modifier($nom, fn (array $effet) => [...$effet, 'est_une' => $type]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TYPES) as $nom) {
            $this->modifier($nom, function (array $effet) {
                unset($effet['est_une']);

                return $effet;
            });
        }
    }

    private function modifier(string $nom, callable $transformer): void
    {
        foreach (DB::table('objets')->where('nom', $nom)->get(['id', 'effet']) as $ligne) {
            DB::table('objets')->where('id', $ligne->id)->update([
                'effet' => json_encode($transformer(json_decode((string) $ligne->effet, true) ?: []), JSON_UNESCAPED_UNICODE),
            ]);
        }
    }
};
