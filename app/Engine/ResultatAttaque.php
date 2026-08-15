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
     * @param  FaceDeCombat  $faceTouchante  face qui TOUCHE pour cette attaque
     * @param  FaceDeCombat  $faceDefensive  face qui PARE pour ce défenseur
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
        public FaceDeCombat $faceTouchante = FaceDeCombat::Crane,
        public FaceDeCombat $faceDefensive = FaceDeCombat::BouclierBlanc,
    ) {}

    /**
     * Les quatre clés que TOUT payload d'attaque publie sur le journal.
     *
     * Les deux listes de faces ne suffisent pas à afficher un jet : un dé n'est
     * un succès que *relativement* à qui le lance. Un bouclier blanc pare pour
     * un héros et ne pare RIEN pour un monstre ; un crâne touche, sauf contre un
     * éthéré où c'est le bouclier noir. La manette dessinait un bouclier pour
     * les deux couleurs indistinctement — un héros frappant un gobelin voyait
     * donc « parer » des boucliers blancs qui n'avaient rien paré, et lisait
     * 3 parades là où le moteur en comptait 1.
     *
     * La règle est publiée par le MOTEUR, jamais redéduite côté client : c'est
     * lui qui sait, et une seconde implémentation de la même règle dériverait.
     *
     * @return array<string, mixed>
     */
    public function pourJournal(): array
    {
        return [
            'faces_attaque' => array_map(fn (FaceDeCombat $f) => $f->value, $this->facesAttaque),
            'faces_defense' => array_map(fn (FaceDeCombat $f) => $f->value, $this->facesDefense),
            'face_touchante' => $this->faceTouchante->value,
            'face_defensive' => $this->faceDefensive->value,
        ];
    }

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

    /**
     * Le MÊME jet, dont les dégâts sont multipliés — Potion de force glaciale :
     * « their next attack causes twice as many Body Points of damage as are
     * rolled » (carte © 2022).
     *
     * Les faces lancées sont conservées telles quelles : le joueur doit voir
     * son jet réel, et c'est le total infligé qui double, pas le nombre de
     * crânes. `touches` et `boucliers` restent donc la vérité des dés.
     *
     * ⚠ Cette fabrique existe pour qu'il n'y ait qu'UN endroit où `degats`,
     * `pvBodyApres` et `cibleTombee` se recalculent ensemble : multiplier à la
     * main dans l'appelant laisserait les trois se contredire, et `frapper()`
     * relit `pvBodyApres` cinq fois.
     */
    public function avecDegatsMultiplies(int $facteur): self
    {
        if ($facteur <= 1) {
            return $this;
        }

        $degats = $this->degats * $facteur;
        $apres = max(0, $this->pvBodyAvant - $degats);

        return new self(
            facesAttaque: $this->facesAttaque,
            facesDefense: $this->facesDefense,
            touches: $this->touches,
            boucliers: $this->boucliers,
            degats: $degats,
            pvBodyAvant: $this->pvBodyAvant,
            pvBodyApres: $apres,
            cibleTombee: $apres === 0,
            faceTouchante: $this->faceTouchante,
            faceDefensive: $this->faceDefensive,
        );
    }
}
