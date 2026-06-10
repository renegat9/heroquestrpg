<?php

declare(strict_types=1);

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDeterministe;
use App\Engine\IssueJet;
use App\Engine\JetCompetence;

function jetAvecFaces(FaceDeCombat ...$faces): JetCompetence
{
    return new JetCompetence(LanceurDeterministe::depuisFaces(...$faces));
}

describe('JetCompetence — règles de base (doc 01 §3)', function () {
    it('compte chaque crâne comme un succès', function () {
        $jet = jetAvecFaces(
            FaceDeCombat::Crane,
            FaceDeCombat::BouclierBlanc,
            FaceDeCombat::Crane,
            FaceDeCombat::BouclierNoir,
        );

        $resultat = $jet->resoudre(nbDes: 4, difficulte: 2);

        expect($resultat->succes)->toBe(2)
            ->and($resultat->faces)->toHaveCount(4)
            ->and($resultat->difficulte)->toBe(2);
    });

    it('réussit quand les succès atteignent exactement la difficulté', function () {
        $resultat = jetAvecFaces(FaceDeCombat::Crane, FaceDeCombat::Crane)
            ->resoudre(nbDes: 2, difficulte: 2);

        expect($resultat->issue)->toBe(IssueJet::Reussite)
            ->and($resultat->estReussi())->toBeTrue();
    });

    it('réussit quand les succès dépassent la difficulté', function () {
        $resultat = jetAvecFaces(FaceDeCombat::Crane, FaceDeCombat::Crane, FaceDeCombat::Crane)
            ->resoudre(nbDes: 3, difficulte: 1);

        expect($resultat->issue)->toBe(IssueJet::Reussite);
    });

    it('échoue nettement quand il manque plus d un succès', function () {
        $resultat = jetAvecFaces(
            FaceDeCombat::Crane,
            FaceDeCombat::BouclierBlanc,
            FaceDeCombat::BouclierNoir,
        )->resoudre(nbDes: 3, difficulte: 3);

        expect($resultat->issue)->toBe(IssueJet::Echec)
            ->and($resultat->estEchec())->toBeTrue();
    });
});

describe('JetCompetence — réussite mixte (décision P4)', function () {
    it('détecte le quasi-échec : un succès sous la difficulté', function () {
        $resultat = jetAvecFaces(
            FaceDeCombat::Crane,
            FaceDeCombat::Crane,
            FaceDeCombat::BouclierBlanc,
            FaceDeCombat::BouclierNoir,
        )->resoudre(nbDes: 4, difficulte: 3);

        expect($resultat->issue)->toBe(IssueJet::ReussiteMixte)
            ->and($resultat->estMixte())->toBeTrue()
            ->and($resultat->succes)->toBe(2);
    });

    it('exige au moins 1 succès pour un mixte : 0 crâne en difficulté 1 est un échec sec', function () {
        $resultat = jetAvecFaces(
            FaceDeCombat::BouclierBlanc,
            FaceDeCombat::BouclierNoir,
            FaceDeCombat::BouclierBlanc,
        )->resoudre(nbDes: 3, difficulte: 1);

        expect($resultat->issue)->toBe(IssueJet::Echec);
    });

    it('donne un mixte à difficulté 2 avec exactement 1 crâne', function () {
        $resultat = jetAvecFaces(FaceDeCombat::Crane, FaceDeCombat::BouclierBlanc)
            ->resoudre(nbDes: 2, difficulte: 2);

        expect($resultat->issue)->toBe(IssueJet::ReussiteMixte);
    });
});

describe('JetCompetence — cas limites', function () {
    it('0 dé (attribut 0) donne 0 succès et un échec automatique, sans tirage', function () {
        $lanceur = new LanceurDeterministe(); // file vide : tout tirage exploserait
        $resultat = (new JetCompetence($lanceur))->resoudre(nbDes: 0, difficulte: 1);

        expect($resultat->succes)->toBe(0)
            ->and($resultat->faces)->toBe([])
            ->and($resultat->issue)->toBe(IssueJet::Echec);
    });

    it('gère les difficultés très difficiles (4+)', function () {
        $resultat = jetAvecFaces(
            FaceDeCombat::Crane,
            FaceDeCombat::Crane,
            FaceDeCombat::Crane,
            FaceDeCombat::Crane,
            FaceDeCombat::Crane,
        )->resoudre(nbDes: 5, difficulte: 4);

        expect($resultat->issue)->toBe(IssueJet::Reussite)
            ->and($resultat->succes)->toBe(5);
    });

    it('couvre les jets de parchemin des non-lanceurs (S1 : Mind, difficulté 1 à 3)', function () {
        // Barbare (Mind 1) tente un parchemin standard (difficulté 2) : au mieux 1 crâne → jamais une réussite franche.
        $resultat = jetAvecFaces(FaceDeCombat::Crane)->resoudre(nbDes: 1, difficulte: 2);

        expect($resultat->issue)->toBe(IssueJet::ReussiteMixte);
    });

    it('refuse un nombre de dés négatif', function () {
        jetAvecFaces()->resoudre(nbDes: -1, difficulte: 1);
    })->throws(InvalidArgumentException::class);

    it('refuse une difficulté inférieure à 1', function () {
        jetAvecFaces()->resoudre(nbDes: 2, difficulte: 0);
    })->throws(InvalidArgumentException::class);
});
