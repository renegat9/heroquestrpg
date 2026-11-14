<?php

declare(strict_types=1);

use App\Partie\Grille;

/**
 * MURS EN ARÊTES (René, 2026-11-14 : « si on veut respecter le plateau, les portes entre deux salles ne sont pas une
 * case mais le bord commun entre deux cases » ; `docs/plan-murs-en-aretes.md`).
 *
 * Une carte peut porter, en plus de ses cases de roche, des arêtes MURÉES `{x, y, cote}` (la forme des portes). Le mur
 * et la porte close passent par le MÊME test d'arête, `Grille::areteBloquee()`. Ce que ces tests défendent :
 *
 *  1. un mur en arête bloque le pas et la vue, sans retirer aucune case au jeu ;
 *  2. une porte SUR UNE ARÊTE (`arete: true`) n'a pas de case d'embrasure : close, elle ne bloque que son arête ;
 *  3. en diagonale, la vue passe un coin de mur ouvert et s'arrête sur un coin fermé ;
 *  4. Traverser la Pierre franchit un mur en arête ;
 *  5. COMPARAISON — une carte à murs FINS voit et marche comme sa jumelle à murs ÉPAIS (une colonne de roche) ;
 *  6. une carte SANS cette couche n'est pas touchée.
 *
 * @param  list<string>  $lignes
 */
function grilleAMurs(array $lignes, array $murs = [], array $portes = []): Grille
{
    $grille = new Grille(array_map(str_split(...), $lignes));
    $grille->definirMurs($murs);
    $grille->definirPortes($portes);

    return $grille;
}

/** Deux salles 3×3 accolées : colonnes 0..2 et 3..5, séparées par un mur sur toute la hauteur (arête est de x = 2). */
function deuxSallesAccolees(array $portes = []): Grille
{
    return grilleAMurs(
        ['ssssss', 'ssssss', 'ssssss'],
        [['x' => 2, 'y' => 0, 'cote' => 'e'], ['x' => 2, 'y' => 1, 'cote' => 'e'], ['x' => 2, 'y' => 2, 'cote' => 'e']],
        $portes,
    );
}

it('bloque le pas et la vue à travers un mur en arête, sans retirer aucune case', function () {
    $grille = deuxSallesAccolees();

    expect($grille->aDesMursEnAretes())->toBeTrue()
        ->and($grille->murEntre(2, 1, 3, 1))->toBeTrue()
        ->and($grille->areteBloquee(2, 1, 3, 1))->toBeTrue()
        ->and($grille->areteBloquee(3, 1, 2, 1))->toBeTrue() // des deux côtés
        ->and($grille->areteBloquee(1, 1, 2, 1))->toBeFalse() // l'arête voisine est libre
        // Les deux cases de part et d'autre restent du sol jouable.
        ->and($grille->estTraversable(2, 1))->toBeTrue()
        ->and($grille->estTraversable(3, 1))->toBeTrue()
        // Aucun chemin d'une salle à l'autre, aucune vue.
        ->and($grille->chemin(1, 1, 4, 1))->toBeNull()
        ->and($grille->ligneDeVue(1, 1, 4, 1))->toBeFalse()
        ->and($grille->ligneDeVue(2, 0, 3, 2))->toBeFalse()
        // Dans une même salle, tout se voit et tout s'atteint.
        ->and($grille->ligneDeVue(0, 0, 2, 2))->toBeTrue()
        ->and($grille->chemin(0, 0, 2, 2))->not->toBeNull();
});

it('une porte sur une arête ne bloque AUCUNE case : close elle ferme son arête, ouverte elle laisse passer', function () {
    // Le mur laisse l'arête du milieu à la porte.
    $murs = [['x' => 2, 'y' => 0, 'cote' => 'e'], ['x' => 2, 'y' => 2, 'cote' => 'e']];
    $porte = fn (string $etat) => [['x' => 2, 'y' => 1, 'cote' => 'e', 'etat' => $etat, 'arete' => true]];

    $close = grilleAMurs(['ssssss', 'ssssss', 'ssssss'], $murs, $porte('fermee'));
    expect(Grille::porteSurArete($porte('fermee')[0]))->toBeTrue()
        ->and($close->areteBloquee(2, 1, 3, 1))->toBeTrue()
        // Ni l'une ni l'autre des deux cases de la porte n'est une embrasure : on s'y tient, on voit à travers elles.
        ->and($close->porteFermeeSurCase(2, 1))->toBeFalse()
        ->and($close->porteFermeeSurCase(3, 1))->toBeFalse()
        ->and($close->estTraversable(2, 1))->toBeTrue()
        ->and($close->estTraversable(3, 1))->toBeTrue()
        ->and($close->chemin(1, 1, 4, 1))->toBeNull()
        ->and($close->ligneDeVue(1, 1, 4, 1))->toBeFalse();

    $ouverte = grilleAMurs(['ssssss', 'ssssss', 'ssssss'], $murs, $porte('ouverte'));
    expect($ouverte->areteBloquee(2, 1, 3, 1))->toBeFalse()
        ->and($ouverte->chemin(1, 1, 4, 1))->toBe([['x' => 2, 'y' => 1], ['x' => 3, 'y' => 1], ['x' => 4, 'y' => 1]])
        ->and($ouverte->ligneDeVue(1, 1, 4, 1))->toBeTrue()
        // À travers la porte ouverte, mais pas à travers le mur d'à côté.
        ->and($ouverte->ligneDeVue(2, 0, 3, 0))->toBeFalse();

    // Une porte SANS le marqueur garde le modèle d'avant : elle occupe une case d'embrasure.
    $ancienne = grilleAMurs(['ssssss', 'ssssss', 'ssssss'], [], [['x' => 2, 'y' => 1, 'cote' => 'e', 'etat' => 'fermee']]);
    expect($ancienne->porteFermeeSurCase(3, 1))->toBeTrue();
});

it('en diagonale, la vue passe un coin de mur ouvert et s\'arrête sur un coin fermé', function () {
    // Coin OUVERT : un seul mur, à l'est de (0,0). De (0,0) à (1,1) le rayon passe par le coin ; le chemin par (0,1) est libre.
    $ouvert = grilleAMurs(['ss', 'ss'], [['x' => 0, 'y' => 0, 'cote' => 'e']]);
    expect($ouvert->ligneDeVue(0, 0, 1, 1))->toBeTrue();

    // Coin FERMÉ : murs à l'est ET au sud de (0,0) — la case est enclose dans son coin.
    $ferme = grilleAMurs(['ss', 'ss'], [['x' => 0, 'y' => 0, 'cote' => 'e'], ['x' => 0, 'y' => 0, 'cote' => 's']]);
    expect($ferme->ligneDeVue(0, 0, 1, 1))->toBeFalse()
        ->and($ferme->ligneDeVue(1, 1, 0, 0))->toBeFalse();

    // Deux murs qui se rejoignent au coin sans enclore la case de départ : un par chemin, la vue est coupée aussi.
    $croise = grilleAMurs(['ss', 'ss'], [['x' => 0, 'y' => 0, 'cote' => 'e'], ['x' => 0, 'y' => 1, 'cote' => 'e']]);
    expect($croise->ligneDeVue(0, 0, 1, 1))->toBeFalse();
});

it('Traverser la Pierre franchit un mur en arête, comme il franchit la roche et les portes closes', function () {
    $grille = deuxSallesAccolees();
    $grille->autoriserLaRoche();

    expect($grille->areteBloquee(2, 1, 3, 1))->toBeFalse()
        ->and($grille->chemin(1, 1, 4, 1))->not->toBeNull();
});

it('COMPARAISON : une carte à murs fins voit et marche comme sa jumelle à murs épais', function () {
    // ÉPAISSE : deux salles 3×4 séparées par une colonne de roche (x = 3), une ouverture en (3, 1).
    $epaisse = new Grille(array_map(str_split(...), ['sssmsss', 'sssssss', 'sssmsss', 'sssmsss']));
    // FINE : les mêmes salles accolées (la colonne de roche a disparu), un mur sur l'arête, une ouverture au même rang.
    $fine = grilleAMurs(
        ['ssssss', 'ssssss', 'ssssss', 'ssssss'],
        [['x' => 2, 'y' => 0, 'cote' => 'e'], ['x' => 2, 'y' => 2, 'cote' => 'e'], ['x' => 2, 'y' => 3, 'cote' => 'e']],
    );
    // Une case de la carte fine ↔ sa case de la carte épaisse (la salle de droite est décalée d'une colonne).
    $versEpaisse = fn (int $x): int => $x >= 3 ? $x + 1 : $x;

    $divergences = [];
    $paires = 0;
    for ($y1 = 0; $y1 < 4; $y1++) {
        for ($x1 = 0; $x1 < 6; $x1++) {
            for ($y2 = 0; $y2 < 4; $y2++) {
                for ($x2 = 0; $x2 < 6; $x2++) {
                    $paires++;
                    // Marche : atteignable dans l'une ⇔ atteignable dans l'autre.
                    $marcheFine = $fine->chemin($x1, $y1, $x2, $y2) !== null || ($x1 === $x2 && $y1 === $y2);
                    $marcheEpaisse = $epaisse->chemin($versEpaisse($x1), $y1, $versEpaisse($x2), $y2) !== null || ($x1 === $x2 && $y1 === $y2);
                    if ($marcheFine !== $marcheEpaisse) {
                        $divergences[] = "marche ($x1,$y1)→($x2,$y2)";
                    }
                    // Vue, dans une MÊME salle : identique (aucun mur n'y entre).
                    if (($x1 < 3) === ($x2 < 3)
                        && $fine->ligneDeVue($x1, $y1, $x2, $y2) !== $epaisse->ligneDeVue($versEpaisse($x1), $y1, $versEpaisse($x2), $y2)) {
                        $divergences[] = "vue interne ($x1,$y1)→($x2,$y2)";
                    }
                }
            }
        }
    }

    expect($paires)->toBe(576)->and($divergences)->toBe([]);

    // D'une salle à l'autre : on ne se voit qu'À TRAVERS l'ouverture, jamais à travers le mur — dans les deux modèles.
    expect($fine->ligneDeVue(0, 1, 5, 1))->toBeTrue()
        ->and($epaisse->ligneDeVue(0, 1, 6, 1))->toBeTrue()
        ->and($fine->ligneDeVue(2, 3, 3, 3))->toBeFalse()
        ->and($fine->ligneDeVue(0, 3, 5, 3))->toBeFalse()
        ->and($epaisse->ligneDeVue(0, 3, 6, 3))->toBeFalse();
});

it('une carte SANS murs en arêtes n\'est pas touchée', function () {
    $grille = grilleAMurs(['sss', 'sms', 'sss']);

    expect($grille->aDesMursEnAretes())->toBeFalse()
        ->and($grille->murEntre(0, 0, 1, 0))->toBeFalse()
        ->and($grille->areteBloquee(0, 0, 1, 0))->toBeFalse()
        // La case de roche bloque toujours la vue, la diagonale passe toujours un coin de roche comme avant.
        ->and($grille->ligneDeVue(0, 1, 2, 1))->toBeFalse()
        ->and($grille->ligneDeVue(0, 0, 2, 0))->toBeTrue();
});
