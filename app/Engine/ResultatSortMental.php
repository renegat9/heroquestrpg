<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\FaceDeCombat;

/**
 * Résultat immuable de la résistance à un sort mental (S2).
 */
final readonly class ResultatSortMental
{
    /**
     * @param list<FaceDeCombat> $faces faces du jet de résistance (vide si immunisé)
     */
    public function __construct(
        public IssueSortMental $issue,
        public array $faces,
        public int $succes,
        public int $difficulte,
    ) {
    }

    public function effetApplique(): bool
    {
        return $this->issue === IssueSortMental::SubitEffet;
    }
}
