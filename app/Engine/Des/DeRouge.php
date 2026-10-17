<?php

declare(strict_types=1);

namespace App\Engine\Des;

/**
 * Le DÉ ROUGE de résistance (sorts de feu, René 2026-09-02) : un d6 BRUT dont
 * chaque 5 ou 6 annule 1 point de dégât.
 *
 * ⚠ Le d6 brut, jamais une face de combat : nos faces regroupent 4-5 en
 * bouclier blanc, ce qui avalerait la moitié de la règle.
 *
 * Point de passage unique (2026-09-25) : le seuil vivait en TROIS copies —
 * `MoteurDread::SEUIL_DE_ROUGE`, un `>= 5` en dur dans
 * `ResolveurTour::reduireParDesRouges()`, et l'ensemble `[5, 6]` que
 * `JournalCombat` publie pour dessiner les dés. Le jour où le seuil bouge, le
 * dessin aurait entouré des dés que le moteur n'a pas comptés.
 */
final class DeRouge
{
    public const SEUIL = 5;

    public static function reussit(int $face): bool
    {
        return $face >= self::SEUIL;
    }

    /**
     * LA FLAMME DU DREAD (Qwindrak, à sa forme de Dragon — `reference/18` §6.1 : « chaque héros dans
     * la même salle que lui lance un dé rouge ; sur 3 ou moins, il perd 1 Body Point ») lit le MÊME dé
     * à l'envers : ici, c'est le 1-3 qui blesse, le 4-6 qui épargne. Le seuil reste ici, avec le dé.
     */
    public const SEUIL_FLAMME = 3;

    public static function brule(int $face): bool
    {
        return $face <= self::SEUIL_FLAMME;
    }

    /**
     * L'ensemble des faces gagnantes, tel que `JetDes.vue` le compare.
     *
     * @return list<int>
     */
    public static function facesGagnantes(): array
    {
        return range(self::SEUIL, 6);
    }
}
