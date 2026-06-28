<?php

declare(strict_types=1);

use App\Partie\Grille;

/**
 * Overlay des portes sur la grille tactique (doc 14 §3.1/3.3) : une porte non
 * `ouverte` (verrouillée, ou secrète non révélée) est INFRANCHISSABLE et bloque
 * la LIGNE DE VUE comme un mur ; une porte `ouverte` reste traversable et
 * transparente, même si sa case sous-jacente est restée un mur.
 *
 * @param  list<string>  $lignes
 */
function grilleAvecPortes(array $lignes, array $portes): Grille
{
    $grille = new Grille(array_map(str_split(...), $lignes));
    $grille->definirPortes($portes);

    return $grille;
}

it('rend une porte verrouillée infranchissable et opaque', function () {
    // Salle gauche, couloir, salle droite, reliées par une porte en (2,0).
    $portes = [['x' => 2, 'y' => 0, 'etat' => 'verrouillee']];

    $bloquee = grilleAvecPortes(['sspss'], $portes);
    $ouverte = grilleAvecPortes(['sspss'], [['x' => 2, 'y' => 0, 'etat' => 'ouverte']]);

    // Pathfinding : la porte verrouillée coupe le passage gauche↔droite.
    expect($bloquee->chemin(0, 0, 4, 0))->toBeNull()
        ->and($bloquee->estTraversable(2, 0))->toBeFalse()
        // Ligne de vue coupée par la porte fermée.
        ->and($bloquee->ligneDeVue(0, 0, 4, 0))->toBeFalse();

    // La même porte ouverte laisse passer chemin ET vue.
    expect($ouverte->chemin(0, 0, 4, 0))->not->toBeNull()
        ->and($ouverte->estTraversable(2, 0))->toBeTrue()
        ->and($ouverte->ligneDeVue(0, 0, 4, 0))->toBeTrue();
});

it('rend une porte secrète non révélée infranchissable même posée sur un mur', function () {
    // La case (2,0) est un MUR (porte secrète invisible) ; l'overlay décide.
    $secrete = grilleAvecPortes(['ssmss'], [['x' => 2, 'y' => 0, 'etat' => 'secrete']]);
    $revelee = grilleAvecPortes(['ssmss'], [['x' => 2, 'y' => 0, 'etat' => 'ouverte']]);

    expect($secrete->estTraversable(2, 0))->toBeFalse()
        ->and($secrete->ligneDeVue(0, 0, 4, 0))->toBeFalse();

    // Une fois révélée (etat ouverte), la porte est franchissable/transparente
    // alors même que la case reste 'm' : l'overlay prime.
    expect($revelee->estTraversable(2, 0))->toBeTrue()
        ->and($revelee->ligneDeVue(0, 0, 4, 0))->toBeTrue()
        ->and($revelee->chemin(0, 0, 4, 0))->not->toBeNull();
});
