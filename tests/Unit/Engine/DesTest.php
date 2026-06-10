<?php

declare(strict_types=1);

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurAleatoire;
use App\Engine\Des\LanceurDeterministe;

describe('FaceDeCombat — répartition exacte du dé de combat (doc 03 §2)', function () {
    it('mappe le d6 sur 3 crânes, 2 boucliers blancs, 1 bouclier noir', function () {
        expect(FaceDeCombat::depuisD6(1))->toBe(FaceDeCombat::Crane)
            ->and(FaceDeCombat::depuisD6(2))->toBe(FaceDeCombat::Crane)
            ->and(FaceDeCombat::depuisD6(3))->toBe(FaceDeCombat::Crane)
            ->and(FaceDeCombat::depuisD6(4))->toBe(FaceDeCombat::BouclierBlanc)
            ->and(FaceDeCombat::depuisD6(5))->toBe(FaceDeCombat::BouclierBlanc)
            ->and(FaceDeCombat::depuisD6(6))->toBe(FaceDeCombat::BouclierNoir);
    });

    it('donne exactement 3/2/1 faces sur les 6 valeurs (un crâne sort 1 fois sur 2)', function () {
        $comptes = [FaceDeCombat::Crane->value => 0, FaceDeCombat::BouclierBlanc->value => 0, FaceDeCombat::BouclierNoir->value => 0];
        foreach (range(1, 6) as $valeur) {
            $comptes[FaceDeCombat::depuisD6($valeur)->value]++;
        }

        expect($comptes[FaceDeCombat::Crane->value])->toBe(3)
            ->and($comptes[FaceDeCombat::BouclierBlanc->value])->toBe(2)
            ->and($comptes[FaceDeCombat::BouclierNoir->value])->toBe(1);
    });

    it('rejette les valeurs hors 1-6', function (int $valeur) {
        FaceDeCombat::depuisD6($valeur);
    })->with([0, 7, -1])->throws(InvalidArgumentException::class);

    it('versD6 est l inverse de depuisD6', function () {
        foreach (FaceDeCombat::cases() as $face) {
            expect(FaceDeCombat::depuisD6($face->versD6()))->toBe($face);
        }
    });
});

describe('LanceurDeterministe', function () {
    it('rejoue la séquence de d6 fournie dans l ordre', function () {
        $lanceur = new LanceurDeterministe([3, 1, 6]);

        expect($lanceur->d6())->toBe(3)
            ->and($lanceur->d6())->toBe(1)
            ->and($lanceur->d6())->toBe(6)
            ->and($lanceur->valeursRestantes())->toBe(0);
    });

    it('dérive les dés de combat de la même file', function () {
        $lanceur = new LanceurDeterministe([2, 5, 6, 4]);

        expect($lanceur->desCombat(4))->toBe([
            FaceDeCombat::Crane,
            FaceDeCombat::BouclierBlanc,
            FaceDeCombat::BouclierNoir,
            FaceDeCombat::BouclierBlanc,
        ]);
    });

    it('se construit depuis des faces de combat lisibles', function () {
        $lanceur = LanceurDeterministe::depuisFaces(
            FaceDeCombat::Crane,
            FaceDeCombat::BouclierNoir,
        );

        expect($lanceur->deCombat())->toBe(FaceDeCombat::Crane)
            ->and($lanceur->deCombat())->toBe(FaceDeCombat::BouclierNoir);
    });

    it('lève une exception quand la file est épuisée', function () {
        $lanceur = new LanceurDeterministe([4]);
        $lanceur->d6();
        $lanceur->d6();
    })->throws(RuntimeException::class);

    it('refuse les valeurs hors 1-6', function () {
        new LanceurDeterministe([3, 7]);
    })->throws(InvalidArgumentException::class);

    it('refuse un nombre de dés négatif', function () {
        (new LanceurDeterministe([1]))->desCombat(-1);
    })->throws(InvalidArgumentException::class);

    it('lancer 0 dé de combat retourne un tableau vide sans consommer la file', function () {
        $lanceur = new LanceurDeterministe([1]);

        expect($lanceur->desCombat(0))->toBe([])
            ->and($lanceur->valeursRestantes())->toBe(1);
    });
});

describe('LanceurAleatoire', function () {
    it('produit toujours des d6 entre 1 et 6', function () {
        $lanceur = new LanceurAleatoire(graine: 42);
        foreach (range(1, 1000) as $_) {
            expect($lanceur->d6())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(6);
        }
    });

    it('est reproductible avec la même graine', function () {
        $a = new LanceurAleatoire(graine: 1234);
        $b = new LanceurAleatoire(graine: 1234);

        $sequenceA = array_map(fn () => $a->d6(), range(1, 50));
        $sequenceB = array_map(fn () => $b->d6(), range(1, 50));

        expect($sequenceA)->toBe($sequenceB);
    });

    it('produit des séquences différentes avec des graines différentes', function () {
        $a = new LanceurAleatoire(graine: 1);
        $b = new LanceurAleatoire(graine: 2);

        $sequenceA = array_map(fn () => $a->d6(), range(1, 50));
        $sequenceB = array_map(fn () => $b->d6(), range(1, 50));

        expect($sequenceA)->not->toBe($sequenceB);
    });

    it('sort approximativement un crâne une fois sur deux', function () {
        $lanceur = new LanceurAleatoire(graine: 7);
        $cranes = count(array_filter(
            $lanceur->desCombat(6000),
            fn (FaceDeCombat $face) => $face->estCrane(),
        ));

        // Espérance 3000 (1/2) ; marge large pour rester stable.
        expect($cranes)->toBeGreaterThan(2700)->toBeLessThan(3300);
    });

    it('refuse un nombre de dés négatif', function () {
        (new LanceurAleatoire(graine: 1))->desCombat(-3);
    })->throws(InvalidArgumentException::class);
});
