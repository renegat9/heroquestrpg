<?php

namespace App\Models;

use App\Partie\Salles;
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

    /**
     * Index de la SALLE DE DÉPART — celle qui contient l'escalier d'entrée —
     * ou `null` sur une carte sans escalier (campagne assemblée avant le
     * chantier : le repli « pas d'exigence de position » s'applique alors).
     *
     * Décision de René (2026-10-10, verdict Jungle) : on ne sort pas « sur
     * l'escalier » mais « tous réunis dans la salle de départ ». La salle est
     * trouvée par `Salles::indexDe()` — point de passage unique de « quelle
     * salle contient cette case ? » —, jamais supposée être l'index 0.
     */
    public function salleDepart(): ?int
    {
        $escalier = $this->casesEscalier();

        if ($escalier === []) {
            return null;
        }

        return Salles::indexDe((array) ($this->grille['salles'] ?? []), $escalier[0]['x'], $escalier[0]['y']);
    }

    /**
     * Cette case est-elle dans la salle de départ ? Faux hors salle (couloir,
     * position inconnue) et faux sur une carte sans salle de départ — c'est à
     * l'appelant de tester `salleDepart() !== null` avant d'exiger quoi que ce
     * soit (voir `Quete::rassemblementDepart()`).
     */
    public function dansSalleDepart(?int $x, ?int $y): bool
    {
        $depart = $this->salleDepart();

        if ($depart === null || $x === null || $y === null) {
            return false;
        }

        return Salles::indexDe((array) ($this->grille['salles'] ?? []), $x, $y) === $depart;
    }
}
