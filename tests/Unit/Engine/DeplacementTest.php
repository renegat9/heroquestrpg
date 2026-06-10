<?php

declare(strict_types=1);

use App\Engine\Deplacement;
use App\Engine\Des\LanceurDeterministe;

describe('Deplacement — base + 1d6 (doc 03 §3)', function () {
    it('additionne la base du héros et un d6', function () {
        // Elfe : base 5, d6 = 4 → 9 cases.
        $deplacement = new Deplacement(new LanceurDeterministe([4]));

        $resultat = $deplacement->calculer(base: 5);

        expect($resultat->base)->toBe(5)
            ->and($resultat->de)->toBe(4)
            ->and($resultat->total)->toBe(9)
            ->and($resultat->armureDePlates)->toBeFalse();
    });

    it('borne le total entre base+1 et base+6', function () {
        // Nain : base 3 → 4 à 9 cases.
        $min = (new Deplacement(new LanceurDeterministe([1])))->calculer(3);
        $max = (new Deplacement(new LanceurDeterministe([6])))->calculer(3);

        expect($min->total)->toBe(4)
            ->and($max->total)->toBe(9);
    });

    it('accepte une base de 0 (cas limite : malus extrême)', function () {
        $resultat = (new Deplacement(new LanceurDeterministe([2])))->calculer(0);

        expect($resultat->total)->toBe(2);
    });

    it('refuse une base négative', function () {
        (new Deplacement(new LanceurDeterministe([1])))->calculer(-1);
    })->throws(InvalidArgumentException::class);
});

describe('Deplacement — armure de plates (décision AP)', function () {
    it('en plates : base seule, aucun d6 lancé', function () {
        $lanceur = new LanceurDeterministe(); // file vide : un d6 exploserait
        $resultat = (new Deplacement($lanceur))->calculer(base: 4, armureDePlates: true);

        expect($resultat->total)->toBe(4)
            ->and($resultat->de)->toBeNull()
            ->and($resultat->armureDePlates)->toBeTrue();
    });

    it('en plates le total ne dépend d aucun hasard', function () {
        $lanceur = new LanceurDeterministe([6, 6, 6]);
        $deplacement = new Deplacement($lanceur);

        $resultat = $deplacement->calculer(base: 4, armureDePlates: true);

        expect($resultat->total)->toBe(4)
            ->and($lanceur->valeursRestantes())->toBe(3); // rien consommé
    });
});
