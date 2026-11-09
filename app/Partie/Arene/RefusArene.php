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

    public static function etapeDeCampagne(\App\Models\Groupe $groupe, string $etape): self
    {
        $libelle = EtapesCampagne::ETAPES[$etape]['libelle'] ?? $etape;

        return new self("Le groupe « {$groupe->nom} » (#{$groupe->id}) est un combat d'arène : l'étape de campagne « {$libelle} » lui est fermée.");
    }

    public static function pasUnGroupeDArene(\App\Models\Groupe $groupe): self
    {
        return new self("Le groupe « {$groupe->nom} » (#{$groupe->id}) n'est pas un combat d'arène : ce chemin ne purge jamais une campagne.");
    }

    public static function pasUnHerosDArene(Personnage $personnage): self
    {
        return new self("« {$personnage->nom} » (#{$personnage->id}) n'est pas un héros d'arène : il ne combat pas en arène.");
    }

    public static function dejaEngage(Personnage $personnage): self
    {
        return new self("« {$personnage->nom} » combat déjà dans cette arène : un héros n'a qu'une doublure par combat.");
    }

    public static function pasUneDoublure(Personnage $personnage): self
    {
        return new self("« {$personnage->nom} » (#{$personnage->id}) n'est pas une doublure d'arène : ce chemin ne supprime jamais un vrai héros.");
    }
}
