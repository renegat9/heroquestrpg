<?php

declare(strict_types=1);

use App\Partie\Grille;
use App\Partie\Rayon;

/**
 * `Rayon::cases()` (Esprit Ardent, Éclair — « continues until it meets a wall
 * or closed door ») répétait son PROPRE test « roche OU arête de porte close »
 * plutôt que d'appeler `Grille::estTraversable()` (qui, lui, traverse les
 * FIGURES qu'un rayon ne doit PAS traverser sans les toucher). Depuis qu'une
 * porte non ouverte bloque aussi sa case d'EMBRASURE (René, 2026-09-11,
 * `Grille::caseEmbrasure()`), cette réplique manquait le cas « rayon lancé
 * DEPUIS l'intérieur de la salle vers sa porte close » — l'arête protégée
 * est celle du couloir, jamais celle de l'intérieur, donc le rayon
 * continuait tout droit. `Rayon::cases()` appelle désormais aussi
 * `Grille::porteFermeeSurCase()`, la MÊME méthode que `estTraversable()` et
 * `ligneDeVue()` consultent en interne.
 */
function grilleCouloirSallePourRayon(string $etat): Grille
{
    $grille = new Grille(array_map(str_split(...), ['sssssss']));
    $grille->definirPortes(
        [['x' => 2, 'y' => 0, 'cote' => 'e', 'etat' => $etat]],
        [['x' => 3, 'y' => 0, 'largeur' => 4, 'hauteur' => 1]],
    );

    return $grille;
}

it('arrête un rayon lancé DEPUIS LE COULOIR à la case d\'embrasure d\'une porte close', function () {
    $grille = grilleCouloirSallePourRayon('fermee');

    // Depuis (0,0), vers l'est : couloir (1,0),(2,0), puis STOP — l'embrasure
    // (3,0) et l'intérieur de la salle ne doivent PAS apparaître.
    $cases = Rayon::cases($grille, 0, 0, 'e');

    expect($cases)->toBe([['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0]]);
});

it('arrête un rayon lancé DEPUIS L\'INTÉRIEUR DE LA SALLE à la case d\'embrasure — le bug corrigé', function () {
    $grille = grilleCouloirSallePourRayon('fermee');

    // Depuis (6,0) (fond de la salle), vers l'ouest : intérieur (5,0),(4,0),
    // puis STOP AVANT l'embrasure (3,0) — c'est elle qui manquait avant ce
    // correctif, l'arête (2,0)|(3,0) ne protégeant que l'autre sens.
    $cases = Rayon::cases($grille, 6, 0, 'o');

    expect($cases)->toBe([['x' => 5, 'y' => 0], ['x' => 4, 'y' => 0]]);
});

it('traverse librement une porte OUVERTE, des deux sens', function () {
    $grille = grilleCouloirSallePourRayon('ouverte');

    expect(Rayon::cases($grille, 0, 0, 'e'))->toHaveCount(6)
        ->and(Rayon::cases($grille, 6, 0, 'o'))->toHaveCount(6);
});
