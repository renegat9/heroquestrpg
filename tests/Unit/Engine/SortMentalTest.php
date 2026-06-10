<?php

declare(strict_types=1);

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDeterministe;
use App\Engine\IssueSortMental;
use App\Engine\SortMental;

function sortAvecFaces(FaceDeCombat ...$faces): SortMental
{
    return new SortMental(LanceurDeterministe::depuisFaces(...$faces));
}

describe('SortMental — résolution binaire (décision S2, doc 02 §5)', function () {
    it('la cible résiste avec au moins un crâne (difficulté par défaut 1)', function () {
        // Orque, PV Mind 2 → 2 dés de résistance.
        $resultat = sortAvecFaces(FaceDeCombat::Crane, FaceDeCombat::BouclierBlanc)
            ->resoudre(mindCible: 2);

        expect($resultat->issue)->toBe(IssueSortMental::Resiste)
            ->and($resultat->succes)->toBe(1)
            ->and($resultat->effetApplique())->toBeFalse();
    });

    it('la cible subit l effet sans aucun crâne', function () {
        $resultat = sortAvecFaces(FaceDeCombat::BouclierBlanc, FaceDeCombat::BouclierNoir)
            ->resoudre(mindCible: 2);

        expect($resultat->issue)->toBe(IssueSortMental::SubitEffet)
            ->and($resultat->effetApplique())->toBeTrue();
    });

    it('est binaire : aucune notion de réussite mixte sur la résistance', function () {
        // 1 succès sous une difficulté de 2 → subit l'effet, pas de mixte (S2 vs P4).
        $resultat = sortAvecFaces(
            FaceDeCombat::Crane,
            FaceDeCombat::BouclierBlanc,
            FaceDeCombat::BouclierNoir,
        )->resoudre(mindCible: 3, difficulte: 2);

        expect($resultat->issue)->toBe(IssueSortMental::SubitEffet);
    });

    it('respecte une difficulté de résistance paramétrable (sort puissant)', function () {
        $resultat = sortAvecFaces(
            FaceDeCombat::Crane,
            FaceDeCombat::Crane,
            FaceDeCombat::BouclierBlanc,
        )->resoudre(mindCible: 3, difficulte: 2);

        expect($resultat->issue)->toBe(IssueSortMental::Resiste)
            ->and($resultat->succes)->toBe(2);
    });

    it('protège aussi les héros contre les sorts de Dread (doc 09 §4)', function () {
        // Barbare, attribut Mind 1 : un seul dé pour résister — très exposé.
        $resiste = sortAvecFaces(FaceDeCombat::Crane)->resoudre(mindCible: 1);
        $subit = sortAvecFaces(FaceDeCombat::BouclierNoir)->resoudre(mindCible: 1);

        expect($resiste->issue)->toBe(IssueSortMental::Resiste)
            ->and($subit->issue)->toBe(IssueSortMental::SubitEffet);
    });
});

describe('SortMental — immunité Mind 0 (doc 09 §2)', function () {
    it('une cible à Mind 0 (mort-vivant) est immunisée, aucun dé lancé', function () {
        $lanceur = new LanceurDeterministe(); // file vide : tout tirage exploserait
        $resultat = (new SortMental($lanceur))->resoudre(mindCible: 0);

        expect($resultat->issue)->toBe(IssueSortMental::Immunise)
            ->and($resultat->faces)->toBe([])
            ->and($resultat->succes)->toBe(0)
            ->and($resultat->effetApplique())->toBeFalse();
    });

    it('l immunité vaut quelle que soit la difficulté du sort', function () {
        $resultat = (new SortMental(new LanceurDeterministe()))
            ->resoudre(mindCible: 0, difficulte: 3);

        expect($resultat->issue)->toBe(IssueSortMental::Immunise);
    });
});

describe('SortMental — validation', function () {
    it('refuse un Mind négatif', function () {
        sortAvecFaces()->resoudre(mindCible: -1);
    })->throws(InvalidArgumentException::class);

    it('refuse une difficulté inférieure à 1', function () {
        sortAvecFaces()->resoudre(mindCible: 2, difficulte: 0);
    })->throws(InvalidArgumentException::class);
});
