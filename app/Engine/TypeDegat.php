<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * TYPE d'une source de dégâts (`effet.type_degat` d'un sort ou d'un sort Dread).
 *
 * Jusqu'au 2026-08-09 un dégât était un dégât : nos sorts retiraient des points
 * de Body, sans que rien ne dise de quelle NATURE. Trois cartes des paquets
 * sources en dépendaient — l'Anneau de Feu, le Bracelet de Glace, l'Anneau de
 * Chaleur — et le troll aussi, dont la carte dit « damage done by fire is
 * permanent and cannot be regenerated ».
 *
 * Un type n'a d'intérêt que s'il a **une source ET un lecteur**. C'est la règle
 * qui décide de ce qui est porté :
 *
 *  - **`feu`** : sources = Boule de Feu, Trait de Feu (catalogue), Tempête de
 *    feu (Dread) ; lecteurs = l'Anneau de Feu (immunité) et la régénération du
 *    troll (qu'une brûlure interrompt). Porté.
 *  - **`froid`** : ⚠ **aucune source**. Les six sorts de froid de *The Frozen
 *    Horror* — Chill, Ice Storm, Ice Wall, Mind Freeze, Skate, Soothe — sont
 *    **nommés** par le livret (reference/18 §Frozen Horror) mais leurs effets ne
 *    figurent nulle part dans ce qu'on a. Le mot est déclaré parce que la
 *    mécanique le supporte ; les deux cartes de résistance au froid restent donc
 *    non portées, faute de quoi que ce soit contre quoi résister.
 *
 * Un sort SANS `type_degat` est neutre : il ne déclenche ni immunité ni
 * interdiction de régénération. C'est le cas de tous les autres.
 */
final class TypeDegat
{
    /** Feu — la seule nature qui ait aujourd'hui une source et des lecteurs. */
    public const FEU = 'feu';

    /** Froid — déclaré, sans source (voir le docbloc). */
    public const FROID = 'froid';

    /** @var list<string> */
    public const TOUS = [self::FEU, self::FROID];

    /**
     * Natures dont AUCUNE source n'existe encore dans les catalogues. Un
     * catalogue peut les porter — le moteur les appliquerait —, mais rien ne
     * les émet : c'est une dette nommée, pas un oubli.
     *
     * @var array<string, string>
     */
    public const SANS_SOURCE = [
        self::FROID => 'Les 6 sorts de froid de The Frozen Horror sont nommés (reference/18) mais leurs effets sont introuvables.',
    ];

    public static function estConnu(?string $type): bool
    {
        return $type !== null && in_array($type, self::TOUS, true);
    }
}
