<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\Salles;

/**
 * LA SALLE TÉNÉBREUSE — lot E2, *Wizards of Morcar* q. 4 B (G1504 p. 27) : « This room is filled with a magical darkness. Any
 * figures in the room other than skeletons, zombies, or mummies will attack and defend with 2 fewer dice than normal. No
 * ranged attacks may be used. »
 *
 * `regles.salle_tenebreuse: {salle, epargnes?: [slugs de monstres]}` — l'auteur nomme les créatures épargnées (le livret :
 * squelettes, zombies, momies). Distincte de `regles.obscurite_magique` (*Crypt*, lot K6 : les héros seuls, leurs deux
 * premières attaques). Un point de passage pour chaque lecture :
 * - le malus de 2 dés, jamais moins d'un (même plancher que la Fosse et l'obscurité magique), en attaque et en défense : les
 *   héros (`ResolveurTour::frapper()`, `MoteurSorts::desDefenseHerosDetail()`), les monstres (`InstanceMonstre::attaqueEffective()`,
 *   `defenseEffective()`) ;
 * - « No ranged attacks » : la figure qui se tient dans la salle ne tire pas (`MoteurSorts::tirInterdit()` pour un héros ;
 *   `InstanceMonstre::attaqueDistanceEffective()` rend `null`, le monstre va au contact).
 * Décision écrite : un héros qui voit dans le noir (*Crown of Shadows*, « see normally in darkness, both magical and
 * nonmagical », `Equipement::voitDansLeNoir()`) n'en souffre pas.
 */
final class SalleTenebreuse
{
    public const MALUS = 2;

    /** @return array{salle: string, epargnes: list<string>}|null */
    public static function regle(?Quete $quete): ?array
    {
        if ($quete === null || ! $quete->estConstruite()) {
            return null;
        }
        $regle = data_get($quete->documentConstruit(), 'regles.salle_tenebreuse');
        if (! is_array($regle) || ! is_string($regle['salle'] ?? null)) {
            return null;
        }

        return ['salle' => $regle['salle'], 'epargnes' => array_values((array) ($regle['epargnes'] ?? []))];
    }

    private static function dans(Quete $quete, string $salle, ?int $x, ?int $y): bool
    {
        if ($x === null || $y === null) {
            return false;
        }
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $ici = Salles::indexDe($salles, $x, $y);

        return $ici !== null && ($salles[$ici]['id'] ?? null) === $salle;
    }

    /** Le héros souffre-t-il des ténèbres, là où il se tient ? */
    public static function frappeLeHeros(Personnage $heros): bool
    {
        $etat = EtatPersonnageQuete::enQuete($heros);
        $regle = self::regle($etat?->quete);

        return $regle !== null && self::dans($etat->quete, $regle['salle'], $etat->position_x, $etat->position_y)
            && ! app(Equipement::class)->voitDansLeNoir($heros);
    }

    public static function malusHeros(Personnage $heros): int
    {
        return self::frappeLeHeros($heros) ? self::MALUS : 0;
    }

    /** Le monstre souffre-t-il des ténèbres (dans la salle, et pas d'un type épargné) ? */
    public static function frappeLeMonstre(InstanceMonstre $monstre): bool
    {
        $regle = self::regle($monstre->quete);
        if ($regle === null || $monstre->position_x === null) {
            return false;
        }
        if (in_array(CatalogueConstructeur::slug((string) $monstre->monstre?->nom_base), $regle['epargnes'], true)) {
            return false;
        }

        return self::dans($monstre->quete, $regle['salle'], (int) $monstre->position_x, (int) $monstre->position_y);
    }

    public static function malusMonstre(InstanceMonstre $monstre): int
    {
        return self::frappeLeMonstre($monstre) ? self::MALUS : 0;
    }

    /** Une figure dans la salle ne tire pas — même épargnée des dés (« No ranged attacks may be used »). */
    public static function tirInterditAuMonstre(InstanceMonstre $monstre): bool
    {
        $regle = self::regle($monstre->quete);

        return $regle !== null && $monstre->position_x !== null
            && self::dans($monstre->quete, $regle['salle'], (int) $monstre->position_x, (int) $monstre->position_y);
    }

    public static function tirInterditAuHeros(Personnage $heros): bool
    {
        $etat = EtatPersonnageQuete::enQuete($heros);
        $regle = self::regle($etat?->quete);

        return $regle !== null && self::dans($etat->quete, $regle['salle'], $etat->position_x, $etat->position_y);
    }
}
