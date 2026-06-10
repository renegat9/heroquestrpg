<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\FaceDeCombat;

/**
 * Camp d'une figurine — détermine quelle face de bouclier compte en défense
 * (doc 03 §5) : les héros comptent les boucliers blancs, les monstres les noirs.
 */
enum TypeFigurine: string
{
    case Heros = 'heros';
    case Monstre = 'monstre';

    public function faceDefensive(): FaceDeCombat
    {
        return match ($this) {
            self::Heros => FaceDeCombat::BouclierBlanc,
            self::Monstre => FaceDeCombat::BouclierNoir,
        };
    }
}
