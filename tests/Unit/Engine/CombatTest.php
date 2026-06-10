<?php

declare(strict_types=1);

use App\Engine\Combat;
use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDeterministe;
use App\Engine\TypeFigurine;

function combatAvecFaces(FaceDeCombat ...$faces): Combat
{
    return new Combat(LanceurDeterministe::depuisFaces(...$faces));
}

describe('Combat — attaque et défense (doc 03 §4-6)', function () {
    it('compte les crânes comme touches et les boucliers blancs pour un héros défenseur', function () {
        // Monstre attaque (3 dés : 2 crânes) ; héros défend (2 dés : 1 bouclier blanc).
        $combat = combatAvecFaces(
            FaceDeCombat::Crane, FaceDeCombat::Crane, FaceDeCombat::BouclierNoir,
            FaceDeCombat::BouclierBlanc, FaceDeCombat::Crane,
        );

        $resultat = $combat->resoudreAttaque(
            desAttaque: 3,
            desDefense: 2,
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: 8,
        );

        expect($resultat->touches)->toBe(2)
            ->and($resultat->boucliers)->toBe(1)
            ->and($resultat->degats)->toBe(1)
            ->and($resultat->pvBodyAvant)->toBe(8)
            ->and($resultat->pvBodyApres)->toBe(7)
            ->and($resultat->cibleTombee)->toBeFalse();
    });

    it('ignore les boucliers noirs quand le défenseur est un héros', function () {
        $combat = combatAvecFaces(
            FaceDeCombat::Crane, FaceDeCombat::Crane,
            FaceDeCombat::BouclierNoir, FaceDeCombat::BouclierNoir,
        );

        $resultat = $combat->resoudreAttaque(2, 2, TypeFigurine::Heros, 6);

        expect($resultat->boucliers)->toBe(0)
            ->and($resultat->degats)->toBe(2)
            ->and($resultat->pvBodyApres)->toBe(4);
    });

    it('compte les boucliers noirs (et seulement eux) quand le défenseur est un monstre', function () {
        $combat = combatAvecFaces(
            FaceDeCombat::Crane, FaceDeCombat::Crane, FaceDeCombat::Crane,
            FaceDeCombat::BouclierNoir, FaceDeCombat::BouclierBlanc, FaceDeCombat::BouclierBlanc,
        );

        $resultat = $combat->resoudreAttaque(3, 3, TypeFigurine::Monstre, 3);

        expect($resultat->touches)->toBe(3)
            ->and($resultat->boucliers)->toBe(1)
            ->and($resultat->degats)->toBe(2)
            ->and($resultat->pvBodyApres)->toBe(1);
    });

    it('un crâne lancé en défense ne protège jamais', function () {
        $combat = combatAvecFaces(
            FaceDeCombat::Crane,
            FaceDeCombat::Crane, FaceDeCombat::Crane,
        );

        $resultat = $combat->resoudreAttaque(1, 2, TypeFigurine::Monstre, 2);

        expect($resultat->boucliers)->toBe(0)
            ->and($resultat->degats)->toBe(1);
    });

    it('les dégâts ne sont jamais négatifs (défense excédentaire)', function () {
        $combat = combatAvecFaces(
            FaceDeCombat::Crane,
            FaceDeCombat::BouclierBlanc, FaceDeCombat::BouclierBlanc, FaceDeCombat::BouclierBlanc,
        );

        $resultat = $combat->resoudreAttaque(1, 3, TypeFigurine::Heros, 5);

        expect($resultat->degats)->toBe(0)
            ->and($resultat->pvBodyApres)->toBe(5)
            ->and($resultat->cibleTombee)->toBeFalse();
    });
});

describe('Combat — cas limites', function () {
    it('défense parfaite : tous les crânes annulés', function () {
        $combat = combatAvecFaces(
            FaceDeCombat::Crane, FaceDeCombat::Crane,
            FaceDeCombat::BouclierBlanc, FaceDeCombat::BouclierBlanc,
        );

        $resultat = $combat->resoudreAttaque(2, 2, TypeFigurine::Heros, 4);

        expect($resultat->degats)->toBe(0)
            ->and($resultat->pvBodyApres)->toBe(4);
    });

    it('0 dé d attaque : aucune touche, le défenseur lance quand même (0 dégât)', function () {
        $combat = combatAvecFaces(FaceDeCombat::BouclierNoir);

        $resultat = $combat->resoudreAttaque(0, 1, TypeFigurine::Monstre, 1);

        expect($resultat->touches)->toBe(0)
            ->and($resultat->facesAttaque)->toBe([])
            ->and($resultat->degats)->toBe(0);
    });

    it('0 dé de défense : tous les crânes passent', function () {
        $combat = combatAvecFaces(FaceDeCombat::Crane, FaceDeCombat::Crane);

        $resultat = $combat->resoudreAttaque(2, 0, TypeFigurine::Heros, 8);

        expect($resultat->facesDefense)->toBe([])
            ->and($resultat->boucliers)->toBe(0)
            ->and($resultat->degats)->toBe(2)
            ->and($resultat->pvBodyApres)->toBe(6);
    });

    it('à 0 PV de Body la figurine est « tombée » (C4), PV plancher à 0', function () {
        // Gobelin (1 PV Body, défense 1) prend 2 touches non annulées.
        $combat = combatAvecFaces(
            FaceDeCombat::Crane, FaceDeCombat::Crane, FaceDeCombat::BouclierBlanc,
            FaceDeCombat::BouclierBlanc,
        );

        $resultat = $combat->resoudreAttaque(3, 1, TypeFigurine::Monstre, 1);

        expect($resultat->degats)->toBe(2)
            ->and($resultat->pvBodyApres)->toBe(0)
            ->and($resultat->cibleTombee)->toBeTrue();
    });

    it('tombée exactement à 0 PV après des dégâts égaux aux PV restants', function () {
        $combat = combatAvecFaces(FaceDeCombat::Crane, FaceDeCombat::BouclierNoir);

        $resultat = $combat->resoudreAttaque(1, 1, TypeFigurine::Heros, 1);

        expect($resultat->degats)->toBe(1)
            ->and($resultat->pvBodyApres)->toBe(0)
            ->and($resultat->cibleTombee)->toBeTrue();
    });

    it('une cible déjà à 0 PV ne « retombe » pas', function () {
        $combat = combatAvecFaces(FaceDeCombat::Crane);

        $resultat = $combat->resoudreAttaque(1, 0, TypeFigurine::Heros, 0);

        expect($resultat->pvBodyApres)->toBe(0)
            ->and($resultat->cibleTombee)->toBeFalse();
    });

    it('refuse des dés d attaque négatifs', function () {
        combatAvecFaces()->resoudreAttaque(-1, 0, TypeFigurine::Heros, 1);
    })->throws(InvalidArgumentException::class);

    it('refuse des dés de défense négatifs', function () {
        combatAvecFaces()->resoudreAttaque(1, -1, TypeFigurine::Heros, 1);
    })->throws(InvalidArgumentException::class);

    it('refuse des PV de Body négatifs', function () {
        combatAvecFaces()->resoudreAttaque(1, 1, TypeFigurine::Heros, -1);
    })->throws(InvalidArgumentException::class);
});

describe('Combat — scénario canon', function () {
    it('Barbare (3 attaque) contre Squelette (2 défense, 1 PV Body)', function () {
        // Attaque : crâne, crâne, blanc → 2 touches. Défense squelette : noir, blanc → 1 bouclier.
        $combat = combatAvecFaces(
            FaceDeCombat::Crane, FaceDeCombat::Crane, FaceDeCombat::BouclierBlanc,
            FaceDeCombat::BouclierNoir, FaceDeCombat::BouclierBlanc,
        );

        $resultat = $combat->resoudreAttaque(3, 2, TypeFigurine::Monstre, 1);

        expect($resultat->touches)->toBe(2)
            ->and($resultat->boucliers)->toBe(1)
            ->and($resultat->degats)->toBe(1)
            ->and($resultat->cibleTombee)->toBeTrue();
    });
});
