<?php

declare(strict_types=1);

use App\Engine\Des\FaceDeCombat as F;
use App\Engine\Des\LanceurDeterministe;
use App\Engine\OppositionMind;
use App\Engine\TypeFigurine;

/**
 * Le jet en opposition Mind contre Mind d'*Against the Ogre Horde* (F9528 p. 10-11) :
 * « both attacker and defender roll combat dice equal to their Mind Points ».
 */
function oppositionAvec(F ...$faces): OppositionMind
{
    return new OppositionMind(LanceurDeterministe::depuisFaces(...$faces));
}

describe('OppositionMind — deux volées de dés de combat', function () {
    it('lance autant de dés que de Mind de chaque côté, le lanceur d\'abord', function () {
        // Lanceur : 3 dés (crâne, crâne, noir) ; cible : 2 dés (blanc, crâne).
        $r = oppositionAvec(F::Crane, F::Crane, F::BouclierNoir, F::BouclierBlanc, F::Crane)->lancer(3, 2);

        expect($r->facesAttaquant)->toHaveCount(3)
            ->and($r->facesDefenseur)->toHaveCount(2)
            ->and($r->cranesAttaquant)->toBe(2)
            ->and($r->cranesDefenseur)->toBe(1);
    });

    it('compte les crânes NETS des boucliers BLANCS d\'un héros (Mind Lock, Dominate)', function () {
        // 3 crânes contre 2 boucliers blancs : 1 crâne net. Un bouclier NOIR ne pare pas un héros.
        $r = oppositionAvec(
            F::Crane, F::Crane, F::Crane,
            F::BouclierBlanc, F::BouclierBlanc, F::BouclierNoir,
        )->lancer(3, 3, TypeFigurine::Heros);

        expect($r->boucliersDefenseur)->toBe(2)
            ->and($r->cranesNets())->toBe(1);
    });

    it('ne descend jamais sous zéro crâne net', function () {
        $r = oppositionAvec(F::Crane, F::BouclierBlanc, F::BouclierBlanc, F::BouclierBlanc)->lancer(1, 3);

        expect($r->cranesNets())->toBe(0);
    });

    it('compte les boucliers NOIRS quand la cible est un monstre', function () {
        $r = oppositionAvec(F::Crane, F::Crane, F::BouclierNoir, F::BouclierBlanc)->lancer(2, 2, TypeFigurine::Monstre);

        expect($r->boucliersDefenseur)->toBe(1)
            ->and($r->cranesNets())->toBe(1);
    });

    it('mesure l\'écart de crânes BRUTS, signé, sans boucliers (Mind Burst — exemple p. 11)', function () {
        // « The Dread Sorcerer has 5 Mind Points and rolls five combat dice. The wizard has 6 Mind
        // Points and rolls six. Because the wizard rolled two more skulls, the Dread Sorcerer takes
        // 2 Mind Points of damage. » → lanceur 1 crâne, magicien 3 crânes : écart −2.
        $r = oppositionAvec(
            F::Crane, F::BouclierBlanc, F::BouclierBlanc, F::BouclierNoir, F::BouclierBlanc,
            F::Crane, F::Crane, F::Crane, F::BouclierBlanc, F::BouclierBlanc, F::BouclierNoir,
        )->lancer(5, 6);

        expect($r->cranesAttaquant)->toBe(1)
            ->and($r->cranesDefenseur)->toBe(3)
            ->and($r->ecart())->toBe(-2);
    });

    it('rend un écart nul sur une égalité de crânes', function () {
        $r = oppositionAvec(F::Crane, F::BouclierBlanc, F::Crane, F::BouclierNoir)->lancer(2, 2);

        expect($r->ecart())->toBe(0);
    });

    it('un camp sans Mind ne lance aucun dé (choc, Mind 0) et ne perd donc aucun crâne', function () {
        $r = oppositionAvec(F::Crane, F::Crane)->lancer(2, 0);

        expect($r->facesDefenseur)->toBe([])
            ->and($r->cranesNets())->toBe(2)
            ->and($r->ecart())->toBe(2);
    });

    it('publie la forme que le fil dessine déjà : faces, faces touchante/défensive, touches, boucliers', function () {
        $r = oppositionAvec(F::Crane, F::Crane, F::BouclierBlanc, F::Crane)->lancer(2, 2);

        expect($r->pourJournal())->toBe([
            'faces_attaque' => ['crane', 'crane'],
            'faces_defense' => ['bouclier_blanc', 'crane'],
            'face_touchante' => 'crane',
            'face_defensive' => 'bouclier_blanc',
            'touches' => 2,
            'boucliers' => 1,
        ]);

        // Mind Burst compare des crânes aux crânes : la face qui « compte » en défense est le crâne.
        expect($r->pourJournal(brut: true))->toMatchArray(['face_defensive' => 'crane', 'boucliers' => 1]);
    });

    it('refuse un nombre de dés négatif', function () {
        oppositionAvec()->lancer(-1, 2);
    })->throws(InvalidArgumentException::class);
});
