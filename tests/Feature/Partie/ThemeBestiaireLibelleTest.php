<?php

declare(strict_types=1);

use App\Partie\DemarreurQuete;
use App\Partie\EtatGroupe;

/*
 * Les DEUX thèmes d'un groupe, enfin publiés (René, 2026-09-24) : le
 * narratif libre (`groupes.theme`) et la boîte de bestiaire FIGÉE
 * (`groupes.theme_bestiaire`), avec son libellé lisible déjà décidé côté
 * serveur (`DemarreurQuete::LIBELLES_BOITES`) — le client ne traduit jamais
 * un identifiant de boîte, même règle que `objectif_libelle`.
 *
 * `EtatGroupe` était auditionné à vide sur ce point : `grep theme_bestiaire
 * resources/js/` ne rendait rien, exactement le silence qui avait caché
 * `menu.situation` et l'objectif de quête avant lui.
 */

// ---------------------------------------------------------------------------
// Le registre des libellés, DANS LES DEUX SENS (CLAUDE.md « une registry est
// testée BOTH WAYS ») — aucune boîte active sans libellé, aucun libellé
// orphelin d'une boîte qui n'existe pas (ou plus).
// ---------------------------------------------------------------------------

it('donne un libellé à CHAQUE boîte de BOITES_THEMATIQUES', function () {
    foreach (DemarreurQuete::BOITES_THEMATIQUES as $boite) {
        expect(array_key_exists($boite, DemarreurQuete::LIBELLES_BOITES))
            ->toBeTrue("boîte « {$boite} » sans libellé");
    }
});

it("n'a AUCUN libellé orphelin — pas de boîte enregistrée qui n'existe plus", function () {
    foreach (array_keys(DemarreurQuete::LIBELLES_BOITES) as $boite) {
        expect(in_array($boite, DemarreurQuete::BOITES_THEMATIQUES, true))
            ->toBeTrue("libellé « {$boite} » sans boîte active");
    }
});

it('rend un libellé NON VIDE, jamais l\'identifiant brut, pour toute boîte active', function () {
    $demarreur = app(DemarreurQuete::class);

    foreach (DemarreurQuete::BOITES_THEMATIQUES as $boite) {
        $libelle = $demarreur->libelleBoiteBestiaire($boite);

        expect($libelle)->not->toBe('')
            ->and($libelle)->not->toBe($boite);
    }
});

it('repli EXPLICITE sur l\'identifiant pour une boîte non enregistrée (fail open, jamais une casse)', function () {
    expect(app(DemarreurQuete::class)->libelleBoiteBestiaire('boite_inconnue'))->toBe('boite_inconnue');
});

// ---------------------------------------------------------------------------
// EtatGroupe publie les deux thèmes
// ---------------------------------------------------------------------------

it('EtatGroupe publie le thème narratif ET la boîte de bestiaire avec son libellé', function () {
    $groupe = creerGroupe('table-themes-'.uniqid());
    $groupe->update(['theme_bestiaire' => 'horreur_des_glaces']);

    $etat = app(EtatGroupe::class)->payload($groupe->fresh());

    expect($etat['groupe']['theme'])->toBe('Cryptes maudites sous la cité')
        ->and($etat['groupe']['theme_bestiaire'])->toBe('horreur_des_glaces')
        ->and($etat['groupe']['theme_bestiaire_libelle'])->toBe('The Frozen Horror');
});

it('ne publie jamais theme_bestiaire brut à null — repli sur le calcul historique', function () {
    // Groupe neuf : la colonne n'est écrite qu'au PREMIER démarrage de quête
    // (`DemarreurQuete::demarrer()`) — ici on ne démarre rien.
    $groupe = creerGroupe('table-themes-vierge-'.uniqid());

    expect($groupe->theme_bestiaire)->toBeNull();

    $etat = app(EtatGroupe::class)->payload($groupe->fresh());
    $attendu = app(DemarreurQuete::class)->themeBestiaire((int) $groupe->id);

    expect($etat['groupe']['theme_bestiaire'])->not->toBeNull()
        ->and($etat['groupe']['theme_bestiaire'])->toBe($attendu)
        ->and($etat['groupe']['theme_bestiaire_libelle'])->toBe(DemarreurQuete::LIBELLES_BOITES[$attendu]);
});

// ⚠ `groupes.theme` est `NOT NULL` en base (saisi obligatoirement à la
// création, `Schema::create('groupes')`) : contrairement à `theme_bestiaire`,
// il n'existe pas d'état réel où il vaille `null` — `EtatGroupe` le publie
// donc tel quel, sans repli à tester ici.
