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
     * @param  list<FaceDeCombat>  $faces  faces obtenues, dans l'ordre du tirage
     */
    public function __construct(
        public array $faces,
        public int $succes,
        public int $difficulte,
        public IssueJet $issue,
    ) {}

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

    /**
     * Le MÊME jet, déclaré réussi — *Dragon Bondissant* du Moine,
     * « automatically succeed when jumping over a trap ».
     *
     * ⚠ On garde les faces telles qu'elles sont tombées : le joueur doit voir
     * les dés que sa technique vient de démentir, sinon le « saut automatique »
     * ressemble à un jet chanceux et la technique disparaît de la table.
     */
    public function force(): self
    {
        return new self($this->faces, max($this->succes, $this->difficulte), $this->difficulte, IssueJet::Reussite);
    }
}
