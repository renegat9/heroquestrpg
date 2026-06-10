<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Résultat immuable d'un calcul de déplacement (doc 03 §3, décision AP).
 *
 * Héros : base + 1d6 par tour. Armure de plates (AP) : base seule, sans le 1d6
 * ($de vaut alors null).
 */
final readonly class ResultatDeplacement
{
    public function __construct(
        public int $base,
        public ?int $de,
        public int $total,
        public bool $armureDePlates,
    ) {
    }
}
