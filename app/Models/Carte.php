<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Carte extends Model
{
    protected $table = 'cartes';

    protected $fillable = [
        'quete_id',
        'largeur',
        'hauteur',
        'grille',
    ];

    protected function casts(): array
    {
        return [
            'grille' => 'array',
        ];
    }

    public function quete(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_id');
    }

    /**
     * Cases de l'escalier d'entrée (chantier escalier-entrée, 2026-10-05) :
     * déplie le bloc `grille['escalier']` (`{x, y, l, h}`) posé par
     * `AssembleurCarte::placerEscalier()` en liste de cases individuelles.
     *
     * ⚠ Absente/vide sur une carte assemblée AVANT ce chantier (campagne EN
     * COURS dans la vraie base) — tout lecteur doit traiter `[]` comme « pas
     * d'escalier sur cette carte », jamais comme une anomalie, et retomber
     * sur le comportement d'avant (voir `MenuMoteur`, `Quete::captifLibereEtVivant()`).
     *
     * @return list<array{x: int, y: int}>
     */
    public function casesEscalier(): array
    {
        $bloc = $this->grille['escalier'] ?? null;

        if (! is_array($bloc) || ! isset($bloc['x'], $bloc['y'])) {
            return [];
        }

        $l = (int) ($bloc['l'] ?? 1);
        $h = (int) ($bloc['h'] ?? 1);
        $cases = [];

        for ($dy = 0; $dy < $h; $dy++) {
            for ($dx = 0; $dx < $l; $dx++) {
                $cases[] = ['x' => (int) $bloc['x'] + $dx, 'y' => (int) $bloc['y'] + $dy];
            }
        }

        return $cases;
    }

    /**
     * Cette case fait-elle partie de l'escalier d'entrée ? Point de passage
     * UNIQUE (`MenuMoteur` pour `quitter_donjon`, `Quete::captifLibereEtVivant()`
     * pour la mission « secourir ») — deux calculs de la même question
     * dériveraient sans qu'on le remarque, le défaut le plus répété de ce
     * projet (cf. `Grille::caseEmbrasure()`).
     */
    public function surEscalier(?int $x, ?int $y): bool
    {
        if ($x === null || $y === null) {
            return false;
        }

        foreach ($this->casesEscalier() as $case) {
            if ($case['x'] === $x && $case['y'] === $y) {
                return true;
            }
        }

        return false;
    }
}
