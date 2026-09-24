<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Résultat immuable d'un calcul de déplacement (doc 03 §3).
 *
 * Héros : base + 1d6 par tour — sauf si `$deAnnule` (armure lourde, clé
 * `deplacement_sans_d6` : le porteur avance de sa base SEULE). `$de` est
 * TOUJOURS lancé, `$deAnnule` ou pas : le dé compte ou ne compte pas dans
 * `$total`, il n'est jamais supprimé — le joueur doit voir ce qu'il aurait eu.
 *
 * `$des` porte TOUS les dés lancés — un seul d'ordinaire, deux avec les *Bottes
 * elfiques*. `$de` reste le premier : c'est celui que les autres règles lisent
 * (rupture d'Évanescence), et il ne devait pas changer de sens sous leurs pieds.
 */
final readonly class ResultatDeplacement
{
    public function __construct(
        public int $base,
        public ?int $de,
        public int $total,
        public bool $deAnnule = false,
        /** @var list<int> */
        public array $des = [],
    ) {}
}
