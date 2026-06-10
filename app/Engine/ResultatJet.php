<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\FaceDeCombat;

/**
 * Résultat immuable d'un jet de compétence (Body ou Mind).
 */
final readonly class ResultatJet
{
    /**
     * @param list<FaceDeCombat> $faces faces obtenues, dans l'ordre du tirage
     */
    public function __construct(
        public array $faces,
        public int $succes,
        public int $difficulte,
        public IssueJet $issue,
    ) {
    }

    public function estReussi(): bool
    {
        return $this->issue === IssueJet::Reussite;
    }

    public function estMixte(): bool
    {
        return $this->issue === IssueJet::ReussiteMixte;
    }

    public function estEchec(): bool
    {
        return $this->issue === IssueJet::Echec;
    }
}
