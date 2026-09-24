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
            ->and($resultat->deAnnule)->toBeFalse();
    });

    it('borne le total entre base+1 et base+6', function () {
        // Nain : base 3 → 4 à 9 cases.
        $min = (new Deplacement(new LanceurDeterministe([1])))->calculer(3);
        $max = (new Deplacement(new LanceurDeterministe([6])))->calculer(3);

        expect($min->total)->toBe(4)
            ->and($max->total)->toBe(9);
    });

    it('accepte une base de 0 (cas limite : plancher à 1)', function () {
        $resultat = (new Deplacement(new LanceurDeterministe([2])))->calculer(0);

        expect($resultat->total)->toBe(2);
    });

    it('refuse une base négative', function () {
        (new Deplacement(new LanceurDeterministe([1])))->calculer(-1);
    })->throws(InvalidArgumentException::class);
});

describe('Deplacement — l\'Armure de plates FAIT PERDRE LE DÉ (René, 2026-09-24)', function () {
    it('ignore le d6 dans le total quand deAnnule est vrai, sans supprimer le dé', function () {
        // Carte officielle 2021 : « 1 red die only for movement ». Chez nous
        // (base + UN SEUL d6), retirer un dé retire LE SEUL dé — le porteur
        // avance de sa base SEULE. Le dé est quand même LANCÉ : on retirait
        // auparavant un CHIFFRE (`malus: 2`, non sourcé, conversion fan) sans
        // toucher au jet lui-même.
        $lanceur = new LanceurDeterministe([5]);
        $resultat = (new Deplacement($lanceur))->calculer(base: 4, deAnnule: true);

        expect($resultat->total)->toBe(4)          // la base SEULE, le d6 (5) ignoré
            ->and($resultat->de)->toBe(5)           // ⚠ le dé réellement tombé, publié quand même
            ->and($resultat->deAnnule)->toBeTrue()
            ->and($lanceur->valeursRestantes())->toBe(0); // le dé A été consommé
    });

    it('ne cloue jamais un héros sur place : plancher à 1 case même dé annulé', function () {
        // Base 0 (cas d'école) + dé annulé → 0 sur le papier. Rien au plateau
        // n'immobilise un personnage, et un héros à 0 case ne pourrait ni fuir
        // ni rejoindre le groupe.
        $resultat = (new Deplacement(new LanceurDeterministe([6])))->calculer(base: 0, deAnnule: true);

        expect($resultat->total)->toBe(1)
            ->and($resultat->de)->toBe(6);
    });

    it('un dé qui compte (deAnnule faux) additionne bien sa face au total', function () {
        $resultat = (new Deplacement(new LanceurDeterministe([3])))->calculer(base: 4, deAnnule: false);

        expect($resultat->total)->toBe(7)
            ->and($resultat->deAnnule)->toBeFalse();
    });
});
