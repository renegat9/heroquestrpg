<?php

declare(strict_types=1);

namespace App\Engine;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;

/**
 * LE JET EN OPPOSITION MIND CONTRE MIND des trois sorts du Sorcier du Dread
 * d'*Against the Ogre Horde* (F9528 p. 10-11) :
 *
 *   « When [the spell] is cast, both attacker and defender roll combat dice
 *   equal to their Mind Points. »
 *
 * Aucun dé de plus, aucun jet de résistance : chaque camp lance autant de dés
 * de COMBAT (faces du jeu, jamais des d6 bruts) que de points de Mind POSSÉDÉS
 * — la jauge, pas l'attribut (errata 2021 C4, comme les jets de rupture). Ce que
 * le livret fait de ces deux volées DIFFÈRE selon le sort, et c'est ce qui sépare
 * les deux lectures offertes par ce résultat :
 *
 *  - {@see ResultatOppositionMind::cranesNets()} — *Mind Lock*, *Dominate* :
 *    les crânes de l'attaquant moins les boucliers du défenseur, « comme dans un
 *    combat normal ». ⚠ INTERPRÉTATION de René (2026-10-15) : le livret est muet
 *    sur les boucliers pour ces deux sorts. Le défenseur est un HÉROS, donc ce
 *    sont ses boucliers BLANCS qui comptent ({@see TypeFigurine::faceDefensive()}) ;
 *  - {@see ResultatOppositionMind::ecart()} — *Mind Burst* : « the combatant with
 *    the most rolled skulls […] inflicts Mind Point damage equal to the
 *    difference in skulls rolled by each combatant ». Ici on compare des CRÂNES
 *    BRUTS des deux côtés, sans boucliers — c'est la lettre du livret, et l'exemple
 *    de la p. 11 (5 dés contre 6, « the wizard rolled two more skulls ») la tient.
 *
 * Classe pure, hasard injecté (`LanceurDes`), comme `Combat` et `SortMental`.
 */
final class OppositionMind
{
    public function __construct(private readonly LanceurDes $des) {}

    /**
     * @param  int  $desAttaquant  Mind POSSÉDÉ du lanceur (jauge `pv_mind`)
     * @param  int  $desDefenseur  Mind POSSÉDÉ de la cible (jauge `pv_mind`)
     * @param  TypeFigurine  $defenseur  camp de la CIBLE — décide quelle face de bouclier compte pour les crânes nets
     */
    public function lancer(int $desAttaquant, int $desDefenseur, TypeFigurine $defenseur = TypeFigurine::Heros): ResultatOppositionMind
    {
        if ($desAttaquant < 0 || $desDefenseur < 0) {
            throw new \InvalidArgumentException("Dés de Mind invalides : {$desAttaquant} contre {$desDefenseur}.");
        }

        // Le lanceur lance d'abord : l'ordre ne change rien à la loi des dés, mais il fixe
        // la file d'un lanceur déterministe — une règle pour tous les tests.
        $facesAttaquant = $this->des->desCombat($desAttaquant);
        $facesDefenseur = $this->des->desCombat($desDefenseur);

        $compter = static fn (array $faces, FaceDeCombat $face): int => count(array_filter($faces, static fn ($f) => $f === $face));

        return new ResultatOppositionMind(
            facesAttaquant: $facesAttaquant,
            facesDefenseur: $facesDefenseur,
            cranesAttaquant: $compter($facesAttaquant, FaceDeCombat::Crane),
            cranesDefenseur: $compter($facesDefenseur, FaceDeCombat::Crane),
            boucliersDefenseur: $compter($facesDefenseur, $defenseur->faceDefensive()),
            faceDefensive: $defenseur->faceDefensive(),
        );
    }
}
