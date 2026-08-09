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
     * @param  list<FaceDeCombat>  $facesAttaque  faces lancées par l'attaquant
     * @param  list<FaceDeCombat>  $facesDefense  faces lancées par le défenseur
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
    ) {}

    /**
     * Attaque qui NE PASSE PAS par les dés : un montant de dégâts garanti, sans
     * jet d'attaque ni jet de défense.
     *
     * Une seule arme en produit — la Dague de jet magique, « This weapon always
     * inflicts one Body Point of damage » (paquet d'artefacts, reference/16 §9).
     * Les deux listes de faces sont vides, ce qui est la vérité : aucun dé n'a
     * été lancé, et la manette affichera donc zéro dé plutôt que d'en inventer.
     */
    public static function sansJet(int $degats, int $pvBodyDefenseur): self
    {
        $degats = max(0, $degats);
        $apres = max(0, $pvBodyDefenseur - $degats);

        return new self(
            facesAttaque: [],
            facesDefense: [],
            touches: $degats,
            boucliers: 0,
            degats: $degats,
            pvBodyAvant: $pvBodyDefenseur,
            pvBodyApres: $apres,
            cibleTombee: $apres === 0,
        );
    }
}
