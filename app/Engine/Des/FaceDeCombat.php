<?php

declare(strict_types=1);

namespace App\Engine\Des;

/**
 * Face d'un dé de combat HeroQuest (doc 03 §2).
 *
 * Répartition sur un d6 : 3 crânes, 2 boucliers blancs, 1 bouclier noir
 * (un crâne sort donc 1 fois sur 2).
 *
 * - Crâne          → touche potentielle à l'attaque / succès au jet de compétence.
 * - Bouclier blanc → défense réussie pour un HÉROS.
 * - Bouclier noir  → défense réussie pour un MONSTRE.
 */
enum FaceDeCombat: string
{
    case Crane = 'crane';
    case BouclierBlanc = 'bouclier_blanc';
    case BouclierNoir = 'bouclier_noir';

    /**
     * Convertit un résultat de d6 (1-6) en face de combat.
     * 1-3 → crâne (3 faces), 4-5 → bouclier blanc (2 faces), 6 → bouclier noir (1 face).
     */
    public static function depuisD6(int $valeur): self
    {
        return match ($valeur) {
            1, 2, 3 => self::Crane,
            4, 5 => self::BouclierBlanc,
            6 => self::BouclierNoir,
            default => throw new \InvalidArgumentException(
                "Valeur de d6 invalide : {$valeur} (attendu 1-6)."
            ),
        };
    }

    /**
     * Valeur de d6 représentative de cette face (pour le lanceur déterministe).
     */
    public function versD6(): int
    {
        return match ($this) {
            self::Crane => 1,
            self::BouclierBlanc => 4,
            self::BouclierNoir => 6,
        };
    }

    public function estCrane(): bool
    {
        return $this === self::Crane;
    }
}
