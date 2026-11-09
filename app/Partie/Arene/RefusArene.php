<?php

declare(strict_types=1);

namespace App\Partie\Arene;

use App\Models\Personnage;

/**
 * MODE ARÈNE : une erreur NOMMÉE, jamais un passage en silence (`docs/plan-mode-arene.md` §3, §6). Levée quand un
 * chemin de l'arène atteint ce qu'il ne doit jamais toucher : un vrai héros, une copie de copie.
 */
final class RefusArene extends \DomainException
{
    public static function doublureDeDoublure(Personnage $personnage): self
    {
        return new self("« {$personnage->nom} » (#{$personnage->id}) est déjà une doublure d'arène : on ne double pas une doublure.");
    }

    public static function herosDeCampagne(Personnage $personnage): self
    {
        return new self("« {$personnage->nom} » (#{$personnage->id}) est un héros de campagne : seul un héros d'arène combat en arène.");
    }

    public static function pasUneDoublure(Personnage $personnage): self
    {
        return new self("« {$personnage->nom} » (#{$personnage->id}) n'est pas une doublure d'arène : ce chemin ne supprime jamais un vrai héros.");
    }
}
