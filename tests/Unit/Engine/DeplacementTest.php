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
            ->and($resultat->malus)->toBe(0);
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

describe('Deplacement — encombrement de l\'armure lourde', function () {
    it('retranche le malus en CASES, sans supprimer le d6', function () {
        // « While wearing the Plate Mail, you have a 2 square movement
        // penalty » (carte Plate Mail) : base 4 + d6 5 − 2 = 7. Le dé est bien
        // lancé — on retirait auparavant le d6 tout entier, ce qui rendait le
        // déplacement DÉTERMINISTE en plus de coûter 3,5 cases en moyenne.
        $lanceur = new LanceurDeterministe([5]);
        $resultat = (new Deplacement($lanceur))->calculer(base: 4, malus: 2);

        expect($resultat->total)->toBe(7)
            ->and($resultat->de)->toBe(5)
            ->and($resultat->malus)->toBe(2)
            ->and($lanceur->valeursRestantes())->toBe(0); // le dé A été consommé
    });

    it('ne cloue jamais un héros sur place : plancher à 1 case', function () {
        // Base 1, d6 = 1, malus 2 → −0 sur le papier. Rien au plateau
        // n'immobilise un personnage, et un héros à 0 case ne pourrait ni
        // fuir ni rejoindre le groupe.
        $resultat = (new Deplacement(new LanceurDeterministe([1])))->calculer(base: 1, malus: 2);

        expect($resultat->total)->toBe(1);
    });

    it('ignore un malus négatif (il ne devient jamais un bonus)', function () {
        $resultat = (new Deplacement(new LanceurDeterministe([3])))->calculer(base: 4, malus: -5);

        expect($resultat->total)->toBe(7)
            ->and($resultat->malus)->toBe(0);
    });
});
