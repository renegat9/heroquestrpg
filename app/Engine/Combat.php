<?php

declare(strict_types=1);

namespace App\Engine;

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
    public function __construct(private readonly LanceurDes $des)
    {
    }

    /**
     * @param int          $desAttaque      dés de combat de l'attaquant (valeur d'Attaque, modificateurs inclus)
     * @param int          $desDefense      dés de combat du défenseur (Défense + armure, modificateurs inclus)
     * @param TypeFigurine $typeDefenseur   camp du DÉFENSEUR (détermine la face de bouclier qui compte)
     * @param int          $pvBodyDefenseur PV de Body courants du défenseur avant l'attaque
     */
    public function resoudreAttaque(
        int $desAttaque,
        int $desDefense,
        TypeFigurine $typeDefenseur,
        int $pvBodyDefenseur,
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

        $facesAttaque = $this->des->desCombat($desAttaque);
        $facesDefense = $this->des->desCombat($desDefense);

        $touches = count(array_filter($facesAttaque, fn ($face) => $face->estCrane()));

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
}
