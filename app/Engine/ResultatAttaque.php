<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\FaceDeCombat;

/**
 * Résultat immuable d'une résolution d'attaque (doc 03 §4-6).
 *
 * Dégâts = crânes − boucliers pertinents (minimum 0) ; chaque point retire
 * 1 Point de Body. À 0 PV de Body la figurine est « tombée » (C4) : elle
 * occupe toujours sa case et reste relevable (P1 pour la mort définitive).
 */
final readonly class ResultatAttaque
{
    /**
     * @param list<FaceDeCombat> $facesAttaque faces lancées par l'attaquant
     * @param list<FaceDeCombat> $facesDefense faces lancées par le défenseur
     */
    public function __construct(
        public array $facesAttaque,
        public array $facesDefense,
        public int $touches,
        public int $boucliers,
        public int $degats,
        public int $pvBodyAvant,
        public int $pvBodyApres,
        public bool $cibleTombee,
    ) {
    }
}
