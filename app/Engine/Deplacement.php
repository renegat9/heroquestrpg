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
 * `$deAnnule` (René, 2026-09-24) : le d6 de mouvement ne compte PAS ce tour —
 * clé `deplacement_sans_d6` de l'armure lourde. Au plateau un héros lance DEUX
 * dés de mouvement et la Plate Mail lui en retire UN (carte officielle 2021,
 * « 1 red die only for movement »). Chez nous (base de classe + UN SEUL d6,
 * écart assumé du projet), retirer un dé retire LE SEUL dé : le porteur avance
 * de sa base, point. ⚠ Le dé est quand même LANCÉ (`$de`/`$des` restent
 * renseignés) : Évanescence le lit, l'usure des Bottes elfiques le lit, et le
 * joueur doit voir ce qu'il aurait eu — seul son résultat ne compte plus dans
 * `$total`. Une VALEUR CHIFFRÉE (`malus_deplacement: 2`, retirée) venait d'une
 * conversion fan (Sjeng) que la carte officielle ne source pas.
 *
 * Le total ne descend jamais sous 1 : un héros immobilisé par son équipement ne
 * pourrait plus ni fuir ni rejoindre le groupe, et rien au plateau ne cloue un
 * personnage sur place.
 *
 * Les monstres ont un déplacement FIXE (doc 09 §1) : ils n'utilisent pas
 * cette classe — leur valeur de catalogue est appliquée telle quelle.
 *
 * `$sansMenace` (FL-Q p. 7, First Light, 2026-09-30 — *Unthreatened
 * Movement* : « without an active monster on the board, each red die for
 * movement counts as a 4 instead of being rolled ») : aucun jet, chaque dé
 * (le socle ET ceux des Bottes elfiques — toujours « un dé de mouvement ») vaut
 * directement `VALEUR_SANS_MENACE`. Le JEU DE BASE suit son propre écart
 * (base de classe + 1d6, pas deux dés sans base), déjà assumé ailleurs — ce
 * n'en ajoute pas un second, la substitution porte sur LE dé qu'on lance ici.
 */
final class Deplacement
{
    /** FL-Q p. 7 : la valeur fixe d'un dé de mouvement non menacé. */
    public const VALEUR_SANS_MENACE = 4;

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
     *
     * ⚠ `$deAnnule` ne change ni `$de` ni `$des` : les faces réellement
     * tombées restent publiées telles quelles, seul `$total` les ignore. Un
     * héros en Plate Mail ET aux Bottes elfiques (cas rare, non sourcé) perd
     * donc les DEUX dés — la carte ne distingue pas « le premier dé » d'« un
     * dé en plus », et une exception non sourcée serait une règle inventée.
     *
     * ⚠ `$sansMenace` et `$deAnnule` sont INDÉPENDANTS : un dé annulé reste
     * annulé, menace ou pas — `$sansMenace` ne fait que remplacer le JET par
     * une valeur fixe, `$deAnnule` décide seul si cette valeur (fixe ou
     * lancée) compte dans `$total`.
     */
    public function calculer(
        int $base,
        bool $deAnnule = false,
        int $desSupplementaires = 0,
        bool $sansMenace = false,
    ): ResultatDeplacement {
        if ($base < 0) {
            throw new \InvalidArgumentException("Base de déplacement invalide : {$base}.");
        }

        $des = [];

        for ($i = 0; $i <= max(0, $desSupplementaires); $i++) {
            $des[] = $sansMenace ? self::VALEUR_SANS_MENACE : $this->des->d6();
        }

        return new ResultatDeplacement(
            base: $base,
            de: $des[0],
            total: $deAnnule ? max(1, $base) : max(1, $base + array_sum($des)),
            deAnnule: $deAnnule,
            des: $des,
            sansMenace: $sansMenace,
        );
    }
}
