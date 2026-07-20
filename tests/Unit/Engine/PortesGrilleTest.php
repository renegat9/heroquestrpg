<?php

declare(strict_types=1);

use App\Partie\Grille;

/**
 * Portes = ARÊTES (doc 14 §3.1/3.3) : une porte ne prend pas de case, elle vit
 * sur la cloison entre deux cases sol {x, y, cote}. Non `ouverte` (verrouillée,
 * ou secrète non révélée) → l'ARÊTE est infranchissable ET opaque ; ouverte →
 * franchissable et transparente. Les cases, elles, restent du sol des deux côtés.
 *
 * @param  list<string>  $lignes
 */
function grilleAvecPortes(array $lignes, array $portes): Grille
{
    $grille = new Grille(array_map(str_split(...), $lignes));
    $grille->definirPortes($portes);

    return $grille;
}

it('rend une porte verrouillée infranchissable et opaque sur son arête', function () {
    // Rangée de sol ; une porte verrouillée sur l'ARÊTE (1,0)|(2,0).
    $portes = [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'verrouillee']];

    $bloquee = grilleAvecPortes(['sssss'], $portes);
    $ouverte = grilleAvecPortes(['sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'ouverte']]);

    // Les DEUX cases restent du sol traversable (la porte ne prend pas de case).
    expect($bloquee->estTraversable(1, 0))->toBeTrue()
        ->and($bloquee->estTraversable(2, 0))->toBeTrue()
        // …mais l'ARÊTE coupe passage et vue gauche↔droite.
        ->and($bloquee->porteBloqueEntre(1, 0, 2, 0))->toBeTrue()
        ->and($bloquee->chemin(0, 0, 4, 0))->toBeNull()
        ->and($bloquee->ligneDeVue(0, 0, 4, 0))->toBeFalse();

    // La même porte ouverte laisse passer chemin ET vue.
    expect($ouverte->porteBloqueEntre(1, 0, 2, 0))->toBeFalse()
        ->and($ouverte->chemin(0, 0, 4, 0))->not->toBeNull()
        ->and($ouverte->ligneDeVue(0, 0, 4, 0))->toBeTrue();
});

it('rend une porte secrète non révélée infranchissable, puis franchissable une fois ouverte', function () {
    $secrete = grilleAvecPortes(['sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'secrete']]);
    $revelee = grilleAvecPortes(['sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'ouverte']]);

    expect($secrete->porteBloqueEntre(1, 0, 2, 0))->toBeTrue()
        ->and($secrete->chemin(0, 0, 4, 0))->toBeNull()
        ->and($secrete->ligneDeVue(0, 0, 4, 0))->toBeFalse();

    // Une fois révélée (etat ouverte), l'arête est franchissable/transparente.
    expect($revelee->porteBloqueEntre(1, 0, 2, 0))->toBeFalse()
        ->and($revelee->ligneDeVue(0, 0, 4, 0))->toBeTrue()
        ->and($revelee->chemin(0, 0, 4, 0))->not->toBeNull();
});

it('n\'affecte QUE son arête : les cloisons voisines restent libres', function () {
    // Porte sur (1,0)|(2,0) : le pas (1,0)→(1,1) et (2,0)→(2,1) restent libres.
    $g = grilleAvecPortes(['sssss', 'sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'fermee']]);

    expect($g->porteBloqueEntre(1, 0, 2, 0))->toBeTrue()
        ->and($g->porteBloqueEntre(1, 0, 1, 1))->toBeFalse()
        ->and($g->porteBloqueEntre(2, 0, 2, 1))->toBeFalse()
        // On peut contourner par la rangée du bas (aucune porte).
        ->and($g->chemin(0, 0, 4, 0))->not->toBeNull();
});
