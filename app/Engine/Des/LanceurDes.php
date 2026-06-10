<?php

declare(strict_types=1);

namespace App\Engine\Des;

/**
 * Source de hasard injectable du moteur.
 *
 * Toute mécanique de jeu DOIT passer par cette interface : le moteur fait
 * autorité, et le hasard est isolé pour pouvoir être remplacé par une
 * implémentation déterministe en test (ou rejoué depuis une graine).
 */
interface LanceurDes
{
    /**
     * Lance un dé à 6 faces classique (déplacement…).
     *
     * @return int valeur entre 1 et 6
     */
    public function d6(): int;

    /**
     * Lance un dé de combat HeroQuest (3 crânes, 2 boucliers blancs, 1 bouclier noir).
     */
    public function deCombat(): FaceDeCombat;

    /**
     * Lance plusieurs dés de combat.
     *
     * @return list<FaceDeCombat>
     */
    public function desCombat(int $nombre): array;
}
