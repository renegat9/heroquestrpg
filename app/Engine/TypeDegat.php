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
 *  - **`froid`** : sources = Morsure de Froid, Tempête de Glace (Dread,
 *    `SortDreadSeeder`, depuis le 2026-09-04), et depuis le 2026-09-10 les
 *    deux terrains de glace qui blessent (Chambre forte de glace, Rivière
 *    gelée — `TerrainSeeder`) ; lecteur = `MoteurSorts::absorbeDegat()`, le
 *    même point de passage que le feu, consulté par `ResolveurTour` aux TROIS
 *    chemins qui blessent un héros (tir ami, sort de Dread, terrain). Porté à
 *    son tour — c'est ce qui a rendu l'Anneau de Chaleur portable
 *    (`config/cartes.php`).
 *
 * Un sort SANS `type_degat` est neutre : il ne déclenche ni immunité ni
 * interdiction de régénération. C'est le cas de tous les autres.
 */
final class TypeDegat
{
    /** Feu — sources et lecteurs depuis le 2026-08-09. */
    public const FEU = 'feu';

    /** Froid — sources et lecteur depuis le 2026-09-04 (Morsure de Froid). */
    public const FROID = 'froid';

    /** @var list<string> */
    public const TOUS = [self::FEU, self::FROID];

    /**
     * Natures dont AUCUNE source n'existe encore dans les catalogues. Un
     * catalogue peut les porter — le moteur les appliquerait —, mais rien ne
     * les émet : c'est une dette nommée, pas un oubli.
     *
     * ⚠ VIDE depuis le 2026-09-06 : `froid` en est sorti quand `Morsure de
     * Froid` lui a donné sa première source (2026-09-04) sans que cette
     * constante suive — exactement le genre de dérive silencieuse que le
     * mécanisme existe pour empêcher. `TypeDegatSansSourceTest` (dans
     * `RegainEtDegatsTest.php`, sur le modèle de `RegainEffet::SANS_UTILISATEUR`)
     * confronte désormais cette liste au catalogue dans les deux sens, pour
     * qu'une prochaine nature ne puisse pas dériver de la même façon sans
     * qu'un test rouge le dise.
     *
     * @var array<string, string>
     */
    public const SANS_SOURCE = [];

    public static function estConnu(?string $type): bool
    {
        return $type !== null && in_array($type, self::TOUS, true);
    }
}
