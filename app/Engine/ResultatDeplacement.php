<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Résultat immuable d'un calcul de déplacement (doc 03 §3).
 *
 * Héros : base + 1d6 par tour, moins `$malus` (encombrement de l'armure
 * lourde — clé `malus_deplacement`). `$de` est toujours lancé : le malus
 * retranche des cases, il ne supprime pas le dé.
 */
final readonly class ResultatDeplacement
{
    public function __construct(
        public int $base,
        public ?int $de,
        public int $total,
        public int $malus = 0,
    ) {}
}
