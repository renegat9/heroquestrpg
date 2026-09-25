<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Inventaire;

/**
 * Les CHARGES dépensées pendant la résolution d'une action, pour que le fil
 * dise ce qu'il reste à l'objet (René, 2026-09-25 : « est-ce que tu as corrigé
 * pour toute carte qui a des charges ? »).
 *
 * ⚠ Le défaut qu'il corrige : le fil en direct (`.combat.journal`) est bâti
 * sur le RÉSULTAT de l'action, jamais sur les événements journalisés à côté.
 * `MoteurCharges::detruire()` journalisait bien « l'objet se brise », mais
 * dans l'historique seulement — l'Orbe Céleste, l'Anneau de Feu ou le Bâton
 * Ancien se vidaient, puis disparaissaient, sans une ligne à la table.
 *
 * Même patron que `AnnoncesTalents` : SINGLETON (pas `scoped`, qui oublie
 * l'instance au début de chaque job synchrone), rempli par `MoteurCharges`,
 * point de passage unique de toute dépense, vidé par `ResolveurTour::resoudre()`
 * à l'entrée ET à la sortie. Une entrée par EXEMPLAIRE : l'Orbe qui absorbe
 * trois points d'un coup dépense trois charges, le fil n'en dit qu'une ligne.
 */
final class TamponCharges
{
    /** @var array<int, array{objet: string, personnage: string, restantes: int, max: int, detruit: bool}> */
    private array $entrees = [];

    public function depense(Inventaire $ligne, int $restantes): void
    {
        $this->entrees[(int) $ligne->id] = [
            'objet' => (string) $ligne->objet?->nom,
            'personnage' => (string) $ligne->personnage?->nom,
            'restantes' => $restantes,
            'max' => (int) ($ligne->objet?->effet['charges'] ?? 0),
            'detruit' => false,
        ];
    }

    public function detruit(Inventaire $ligne): void
    {
        if (isset($this->entrees[(int) $ligne->id])) {
            $this->entrees[(int) $ligne->id]['detruit'] = true;
        }
    }

    /** @return list<array{objet: string, personnage: string, restantes: int, max: int, detruit: bool}> */
    public function vider(): array
    {
        $lot = array_values($this->entrees);
        $this->entrees = [];

        return $lot;
    }
}
