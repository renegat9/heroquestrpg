<?php

declare(strict_types=1);

namespace App\Engine\Des;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Implémentation aléatoire du lanceur de dés.
 *
 * Seedable : avec une graine, la séquence de tirages est entièrement
 * reproductible (rejeu d'une partie, débogage). Sans graine, le moteur
 * Mt19937 est initialisé aléatoirement.
 */
final class LanceurAleatoire implements LanceurDes
{
    private readonly Randomizer $randomizer;

    public function __construct(?int $graine = null)
    {
        $this->randomizer = new Randomizer(
            $graine === null ? new Mt19937() : new Mt19937($graine)
        );
    }

    public function d6(): int
    {
        return $this->randomizer->getInt(1, 6);
    }

    public function deCombat(): FaceDeCombat
    {
        return FaceDeCombat::depuisD6($this->d6());
    }

    public function desCombat(int $nombre): array
    {
        if ($nombre < 0) {
            throw new \InvalidArgumentException("Nombre de dés invalide : {$nombre}.");
        }

        $faces = [];
        for ($i = 0; $i < $nombre; $i++) {
            $faces[] = $this->deCombat();
        }

        return $faces;
    }
}
