<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\LanceurDes;

/**
 * Calcul du déplacement d'un héros pour le tour (doc 03 §3).
 *
 * Déplacement = valeur de base du héros + 1d6 (ex. Elfe 5 + 1d6 = 6 à 11 cases).
 * La base inclut déjà les bonus permanents (ex. nœud « Pas léger » de l'Elfe).
 *
 * Décision AP (armure de plates) : le porteur se déplace de sa base SEULE,
 * sans lancer le 1d6.
 *
 * Les monstres ont un déplacement FIXE (doc 09 §1) : ils n'utilisent pas
 * cette classe — leur valeur de catalogue est appliquée telle quelle.
 */
final class Deplacement
{
    public function __construct(private readonly LanceurDes $des)
    {
    }

    public function calculer(int $base, bool $armureDePlates = false): ResultatDeplacement
    {
        if ($base < 0) {
            throw new \InvalidArgumentException("Base de déplacement invalide : {$base}.");
        }

        if ($armureDePlates) {
            return new ResultatDeplacement(
                base: $base,
                de: null,
                total: $base,
                armureDePlates: true,
            );
        }

        $de = $this->des->d6();

        return new ResultatDeplacement(
            base: $base,
            de: $de,
            total: $base + $de,
            armureDePlates: false,
        );
    }
}
