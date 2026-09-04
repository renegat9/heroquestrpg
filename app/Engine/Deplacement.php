<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\LanceurDes;

/**
 * Calcul du déplacement d'un héros pour le tour (doc 03 §3).
 *
 * Déplacement = valeur de base du héros + 1d6 (ex. Elfe 5 + 1d6 = 6 à 11 cases).
 * La base inclut déjà les bonus permanents (ex. nœud « Pas léger » de l'Elfe).
 *
 * `$malus` retranche des cases : c'est la clé `malus_deplacement` de l'armure
 * lourde — « While wearing the Plate Mail, you have a 2 square movement
 * penalty » (carte Plate Mail). On retirait auparavant le d6 tout entier, soit
 * −3,5 cases en moyenne et un déplacement DÉTERMINISTE : deux écarts à la
 * carte, pour une armure que plus personne n'achetait.
 *
 * Le total ne descend jamais sous 1 : un héros immobilisé par son équipement ne
 * pourrait plus ni fuir ni rejoindre le groupe, et rien au plateau ne cloue un
 * personnage sur place.
 *
 * Les monstres ont un déplacement FIXE (doc 09 §1) : ils n'utilisent pas
 * cette classe — leur valeur de catalogue est appliquée telle quelle.
 */
final class Deplacement
{
    public function __construct(private readonly LanceurDes $des) {}

    /**
     * `$desSupplementaires` ajoute des d6 au jet — *Bottes elfiques* : « These
     * boots grant the Elf an extra red die for movement. »
     *
     * ⚠ `$de` reste le PREMIER dé, celui que tout le monde lance, et non la
     * somme : *Évanescence* se rompt sur ce dé-là (`MenuMoteur`), et lui donner
     * une somme de deux d6 aurait fait tomber le sort presque à chaque tour
     * pour le seul héros chaussé. Les bottes ajoutent une chance de courir,
     * elles ne changent pas les autres règles qui lisent le dé.
     */
    public function calculer(int $base, int $malus = 0, int $desSupplementaires = 0): ResultatDeplacement
    {
        if ($base < 0) {
            throw new \InvalidArgumentException("Base de déplacement invalide : {$base}.");
        }

        $malus = max(0, $malus);
        $des = [];

        for ($i = 0; $i <= max(0, $desSupplementaires); $i++) {
            $des[] = $this->des->d6();
        }

        return new ResultatDeplacement(
            base: $base,
            de: $des[0],
            total: max(1, $base + array_sum($des) - $malus),
            malus: $malus,
            des: $des,
        );
    }
}
