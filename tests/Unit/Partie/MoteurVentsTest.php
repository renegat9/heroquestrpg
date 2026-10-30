<?php

declare(strict_types=1);

use App\Partie\Grille;
use App\Partie\MoteurVents;

/*
 * LOT H DES RESTES — LA GÉOMÉTRIE DES VENTS DE COULOIR (*Spirit Queen's Torment* q. 12, livret G0053 p. 15), sans base de données :
 * une grille nue, des cases de couloir. Les règles TRANCHÉES sont celles de l'en-tête de `App\Partie\MoteurVents`.
 */

/** Une rangée de `$largeur` cases de couloir. */
function couloirVents(int $largeur): Grille
{
    return new Grille([array_fill(0, $largeur, 's')]);
}

/** Une grille de `$largeur` × `$hauteur` cases de sol. */
function salleVents(int $largeur, int $hauteur): Grille
{
    return new Grille(array_fill(0, $hauteur, array_fill(0, $largeur, 's')));
}

it('pousse de DEUX cases dans une direction libre, et finit sur la seconde', function () {
    $poussee = (new MoteurVents())->poussee(couloirVents(9), 3, 0, 'e', 2);

    expect($poussee['fin'])->toBe(['x' => 5, 'y' => 0])
        ->and(array_map(fn (array $c) => $c['x'], $poussee['pas']))->toBe([4, 5]);
});

it('s\'arrête AVANT un mur : une poussée partielle reste légale, la case du mur n\'est jamais traversée', function () {
    // Mur (`m`) en (5,0) : la poussée vers l'est n'avance que d'une case.
    $grille = new Grille([['s', 's', 's', 's', 's', 'm', 's', 's']]);
    $poussee = (new MoteurVents())->poussee($grille, 3, 0, 'e', 2);

    expect($poussee['fin'])->toBe(['x' => 4, 'y' => 0])
        ->and(count($poussee['pas']))->toBe(1);
});

it('n\'est PAS légale quand la première case est un mur : la direction n\'est pas offerte', function () {
    $grille = new Grille([['s', 's', 's', 'm', 's']]);

    expect((new MoteurVents())->poussee($grille, 2, 0, 'e', 2))->toBeNull()
        ->and((new MoteurVents())->directions($grille, 2, 0, 2))->toBe(['o']);
});

it('une FIGURE bloque la poussée : collée au héros, elle retire la direction ; plus loin, la poussée est partielle', function () {
    $grille = couloirVents(9);
    $grille->occuper([['x' => 4, 'y' => 0]]);

    // La case d'à côté est occupée : l'est n'est pas légal. L'ouest l'est.
    expect((new MoteurVents())->directions($grille, 3, 0, 2))->toBe(['o']);

    // Figure en (5,0) : l'est avance d'une case seulement, et reste légal (la poussée s'arrête AVANT la figure).
    $loin = couloirVents(9);
    $loin->occuper([['x' => 5, 'y' => 0]]);
    expect((new MoteurVents())->poussee($loin, 3, 0, 'e', 2)['fin'])->toBe(['x' => 4, 'y' => 0]);
});

it('une MARE se traverse sans y finir : la poussée va jusqu\'à la case d\'arrêt suivante', function () {
    $grille = couloirVents(9);
    $grille->interdireArret([['x' => 4, 'y' => 0]]);

    $poussee = (new MoteurVents())->poussee($grille, 3, 0, 'e', 2);

    expect($poussee['fin'])->toBe(['x' => 5, 'y' => 0])
        ->and(count($poussee['pas']))->toBe(2, 'la Mare est traversée : le héros passe dessus');
});

it('une Mare seule en bout de poussée ramène la fin à la case d\'avant : on ne finit jamais dans une Mare', function () {
    $grille = couloirVents(9);
    $grille->interdireArret([['x' => 5, 'y' => 0]]);

    $poussee = (new MoteurVents())->poussee($grille, 3, 0, 'e', 2);

    expect($poussee['fin'])->toBe(['x' => 4, 'y' => 0]);
});

it('les directions légales sont données dans l\'ordre FIXE E, O, S, N', function () {
    $legales = (new MoteurVents())->directions(salleVents(5, 5), 2, 2, 1);

    expect($legales)->toBe(['e', 'o', 's', 'n']);
});

it('Zargon pousse vers le monstre VIVANT le plus proche de la case d\'arrivée', function () {
    $grille = couloirVents(9);

    // Monstre à l'ouest (en 0,0) : la poussée vers l'ouest finit plus près de lui.
    expect((new MoteurVents())->directionDeZargon($grille, 4, 0, 2, [['x' => 0, 'y' => 0]]))->toBe('o')
        // Monstre à l'est (en 8,0) : l'est.
        ->and((new MoteurVents())->directionDeZargon($grille, 4, 0, 2, [['x' => 8, 'y' => 0]]))->toBe('e');
});

it('Zargon, à égalité de distance, départage dans l\'ordre fixe E, O, S, N', function () {
    $grille = salleVents(5, 5);

    // Monstre en (2,2) : les quatre fins possibles (4,2), (0,2), (2,4), (2,0) sont toutes à distance 2 de lui. E gagne.
    expect((new MoteurVents())->directionDeZargon($grille, 2, 2, 2, [['x' => 2, 'y' => 2]]))->toBe('e');
});

it('Zargon sans aucun monstre vivant pousse dans la PREMIÈRE direction légale de l\'ordre fixe', function () {
    expect((new MoteurVents())->directionDeZargon(couloirVents(9), 4, 0, 2, []))->toBe('e');
});

it('Zargon ne choisit jamais une direction qui n\'est pas légale : pas de direction, pas de poussée', function () {
    $grille = new Grille([['m', 's', 'm']]);

    expect((new MoteurVents())->directionDeZargon($grille, 1, 0, 2, [['x' => 1, 'y' => 0]]))->toBeNull();
});

it('un ALLIÉ (compagnon du camp des héros) arrête aussi la poussée : traversable pour la marche, jamais un point d\'arrêt ni un passage', function () {
    $grille = couloirVents(9);
    $grille->occuperAllie([['x' => 4, 'y' => 0]]);

    expect((new MoteurVents())->directions($grille, 3, 0, 2))->toBe(['o'], 'la poussée ne traverse pas un compagnon')
        ->and((new MoteurVents())->poussee($grille, 3, 0, 'e', 2))->toBeNull();
});
