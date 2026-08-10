<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;

/**
 * Résolution d'attaque HeroQuest (doc 03 §4-6).
 *
 * 1. L'attaquant lance ses dés d'attaque : chaque CRÂNE = 1 touche.
 * 2. Le défenseur lance ses dés de défense : un HÉROS compte les boucliers
 *    BLANCS, un MONSTRE les boucliers NOIRS ; chaque bouclier annule un crâne.
 * 3. Dégâts = touches − boucliers (minimum 0) ; 1 dégât = −1 Point de Body.
 * 4. À 0 PV de Body, la figurine est « tombée » (C4) : elle occupe sa case et
 *    reste relevable ; la mort définitive (P1) et le TPK sont gérés plus haut.
 *
 * Classe pure : reçoit des valeurs (dés, camp, PV), retourne un résultat.
 * Les modificateurs (arme, armure, nœuds d'arbre, sorts) sont déjà intégrés
 * dans les nombres de dés fournis par l'appelant.
 */
final class Combat
{
    public function __construct(private readonly LanceurDes $des) {}

    /**
     * @param  int  $desAttaque  dés de combat de l'attaquant (valeur d'Attaque, modificateurs inclus)
     * @param  int  $desDefense  dés de combat du défenseur (Défense + armure, modificateurs inclus)
     * @param  TypeFigurine  $typeDefenseur  camp du DÉFENSEUR (détermine la face de bouclier qui compte)
     * @param  int  $pvBodyDefenseur  PV de Body courants du défenseur avant l'attaque
     * @param  bool  $relanceDesAttaqueRatee  Coup puissant (nœud barbare) : relance UNE FOIS chaque dé
     *                                        d'attaque raté (non-crâne), en gardant les crânes déjà obtenus
     * @param  bool  $defenseurEthere  Monstre ÉTHÉRÉ (Rise of the Dread Moon) : « une attaque de héros ne
     *                                 les touche que sur un **bouclier noir** (au lieu d'un crâne) ». Une
     *                                 face sur six au lieu de trois — c'est la seule règle du jeu qui
     *                                 change la CONDITION DE SUCCÈS d'un dé d'attaque, et elle ne vaut que
     *                                 pour une arme : le livret excepte « sort ou artefact », que
     *                                 l'appelant filtre.
     */
    public function resoudreAttaque(
        int $desAttaque,
        int $desDefense,
        TypeFigurine $typeDefenseur,
        int $pvBodyDefenseur,
        bool $relanceDesAttaqueRatee = false,
        bool $defenseurEthere = false,
    ): ResultatAttaque {
        if ($desAttaque < 0) {
            throw new \InvalidArgumentException("Dés d'attaque invalides : {$desAttaque}.");
        }
        if ($desDefense < 0) {
            throw new \InvalidArgumentException("Dés de défense invalides : {$desDefense}.");
        }
        if ($pvBodyDefenseur < 0) {
            throw new \InvalidArgumentException("PV de Body invalides : {$pvBodyDefenseur}.");
        }

        // Quelle face TOUCHE ? Un crâne d'ordinaire ; un bouclier noir contre un
        // éthéré. Cette valeur doit être connue AVANT la relance, sinon
        // « Coup puissant » relance les mauvais dés (voir ci-dessous).
        $touchante = $defenseurEthere ? FaceDeCombat::BouclierNoir : FaceDeCombat::Crane;

        $facesAttaque = $this->des->desCombat($desAttaque);

        if ($relanceDesAttaqueRatee) {
            $facesAttaque = $this->relancerRatees($facesAttaque, $touchante);
        }

        $facesDefense = $this->des->desCombat($desDefense);

        $touches = count(array_filter($facesAttaque, fn ($face) => $face === $touchante));

        $faceDefensive = $typeDefenseur->faceDefensive();
        $boucliers = count(array_filter($facesDefense, fn ($face) => $face === $faceDefensive));

        $degats = max(0, $touches - $boucliers);
        $pvBodyApres = max(0, $pvBodyDefenseur - $degats);

        return new ResultatAttaque(
            facesAttaque: $facesAttaque,
            facesDefense: $facesDefense,
            touches: $touches,
            boucliers: $boucliers,
            degats: $degats,
            pvBodyAvant: $pvBodyDefenseur,
            pvBodyApres: $pvBodyApres,
            cibleTombee: $pvBodyDefenseur > 0 && $pvBodyApres === 0,
        );
    }

    /**
     * @param  list<FaceDeCombat>  $faces
     * @return list<FaceDeCombat>
     */
    private function relancerRatees(array $faces, FaceDeCombat $touchante = FaceDeCombat::Crane): array
    {
        // ⚠ « Raté » se juge sur la face QUI TOUCHE, pas sur le crâne.
        // L'ancienne version relançait tout ce qui n'était pas un crâne : contre
        // un éthéré — où c'est le bouclier noir qui touche — elle gardait les
        // ratés et relançait les réussites, faisant tomber la chance de toucher
        // de 1/6 à 1/12. Coup puissant rendait le barbare DEUX FOIS PIRE contre
        // un spectre (audit des talents, 2026-08-10).
        $nbRatees = count(array_filter($faces, fn ($face) => $face !== $touchante));

        if ($nbRatees === 0) {
            return $faces;
        }

        $relances = $this->des->desCombat($nbRatees);
        $indexRelance = 0;

        return array_map(
            fn ($face) => $face === $touchante ? $face : $relances[$indexRelance++],
            $faces,
        );
    }
}
