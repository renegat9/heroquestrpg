<?php

declare(strict_types=1);

use App\Partie\ApprocheAllie;
use App\Partie\Grille;

/*
 * « Approcher X » (Morcar, 2026-10-09) : une destination n'est offerte que si elle
 * ABOUTIT — une case au contact du monstre où l'allié peut s'arrêter, atteignable, et
 * un pas réellement fait. Le menu et le résolveur lisent ce même calcul
 * (`ApprocheAllie::trajet()`), donc ce test vaut pour les deux.
 *
 * Grille 5×5 de sol. Monstre en (2,2) ; ses quatre cases de contact : (1,2), (3,2),
 * (2,1), (2,3). Allié en (0,2), budget 4.
 */

/** @return Grille */
function grilleDeSol(int $taille = 5): Grille
{
    $cases = [];

    for ($y = 0; $y < $taille; $y++) {
        for ($x = 0; $x < $taille; $x++) {
            $cases[$y][$x] = 's';
        }
    }

    return new Grille($cases);
}

it('n\'offre AUCUNE approche quand toutes les cases de contact sont prises (le cas Morcar)', function () {
    $grille = grilleDeSol();
    // Les quatre cases de contact sont occupées par des HÉROS (figures alliées de l'allié :
    // traversables, jamais un endroit où s\'arrêter).
    $grille->occuperAllie([['x' => 1, 'y' => 2], ['x' => 3, 'y' => 2], ['x' => 2, 'y' => 1], ['x' => 2, 'y' => 3]]);
    $grille->occuper([['x' => 2, 'y' => 2]]); // le monstre lui-même

    expect(app(ApprocheAllie::class)->trajet($grille, 0, 2, 4, 2, 2, 1, 1))->toBeNull();
});

it('approche la SEULE case de contact libre, et y arrive dans le budget', function () {
    $grille = grilleDeSol();
    $grille->occuperAllie([['x' => 1, 'y' => 2], ['x' => 3, 'y' => 2], ['x' => 2, 'y' => 1]]);
    $grille->occuper([['x' => 2, 'y' => 2]]);

    $trajet = app(ApprocheAllie::class)->trajet($grille, 0, 2, 4, 2, 2, 1, 1);

    expect($trajet)->not->toBeNull()
        ->and($trajet['arrivee'])->toBe(['x' => 2, 'y' => 3])
        ->and($trajet['chemin'])->toHaveCount(3);
});

it('avance réellement quand le budget ne suffit pas : l\'arrivée est un pas, pas le départ', function () {
    // Allié en (0,0), contact le plus proche (1,2) à 3 pas ; budget 1 : un seul pas.
    $grille = grilleDeSol();
    $grille->occuper([['x' => 2, 'y' => 2]]);

    $trajet = app(ApprocheAllie::class)->trajet($grille, 0, 0, 1, 2, 2, 1, 1);

    expect($trajet)->not->toBeNull();
    $arrivee = $trajet['arrivee'];
    expect(abs($arrivee['x']) + abs($arrivee['y']))->toBe(1);
});

it('n\'approche pas vers une case où l\'arrêt est interdit par le terrain (Mare, Brasier)', function () {
    $grille = grilleDeSol();
    $grille->occuperAllie([['x' => 1, 'y' => 2], ['x' => 3, 'y' => 2], ['x' => 2, 'y' => 1]]);
    $grille->occuper([['x' => 2, 'y' => 2]]);
    $grille->interdireArret([['x' => 2, 'y' => 3]]); // la seule case libre : on la traverse, on ne s'y arrête pas

    expect(app(ApprocheAllie::class)->trajet($grille, 0, 2, 4, 2, 2, 1, 1))->toBeNull();
});

it('n\'approche pas quand l\'allié est DÉJÀ au contact : rien à rejoindre', function () {
    $grille = grilleDeSol();
    $grille->occuper([['x' => 2, 'y' => 2]]);

    // L'allié est en (1,2), case de contact : le trajet vers une autre case de contact est nul.
    expect(app(ApprocheAllie::class)->trajet($grille, 1, 2, 4, 2, 2, 1, 1))->toBeNull();
});

it('une case de contact prise par un MONSTRE n\'est jamais une destination', function () {
    $grille = grilleDeSol();
    $grille->occuper([['x' => 2, 'y' => 2], ['x' => 1, 'y' => 2], ['x' => 3, 'y' => 2], ['x' => 2, 'y' => 1]]);

    $trajet = app(ApprocheAllie::class)->trajet($grille, 0, 2, 4, 2, 2, 1, 1);

    expect($trajet)->not->toBeNull()
        ->and($trajet['arrivee'])->toBe(['x' => 2, 'y' => 3]);
});
