<?php

declare(strict_types=1);

use App\Partie\Grille;

/**
 * Une porte vit sur une ARÊTE {x, y, cote} entre deux cases sol — inchangé —
 * mais bloque désormais AUSSI sa CASE D'EMBRASURE (René, 2026-09-11, après
 * avoir joué : « la porte doit être centrale à sa case, bloquant l'entrée
 * dans sa case tant qu'elle n'est pas ouverte »). Non `ouverte` (verrouillée,
 * fermée, ou secrète non révélée) → l'ARÊTE reste infranchissable ET opaque,
 * ET la case d'embrasure devient elle aussi inoccupable et opaque, DES DEUX
 * CÔTÉS (voir `Grille::caseEmbrasure()`) ; ouverte → l'arête ET la case sont
 * franchissables et transparentes, comme n'importe quel sol.
 *
 * `caseEmbrasure()` tranche laquelle des deux `casesPorte()` est l'embrasure
 * via le rectangle de `salles[]` (mur compris) — sans salle fournie (grilles
 * synthétiques ci-dessous), elle retombe sur la case (x+1,y)/(x,y+1), le
 * comportement historique. `PortesGrilleEmbrasureTest` couvre le cas général,
 * avec une vraie salle des deux côtés.
 *
 * @param  list<string>  $lignes
 */
function grilleAvecPortes(array $lignes, array $portes, array $salles = []): Grille
{
    $grille = new Grille(array_map(str_split(...), $lignes));
    $grille->definirPortes($portes, $salles);

    return $grille;
}

it('rend une porte verrouillée infranchissable et opaque, arête ET case d\'embrasure', function () {
    // Rangée de sol ; une porte verrouillée sur l'ARÊTE (1,0)|(2,0). Sans
    // salle fournie, l'embrasure retombe sur (2,0) (x+1,y).
    $portes = [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'verrouillee']];

    $bloquee = grilleAvecPortes(['sssss'], $portes);
    $ouverte = grilleAvecPortes(['sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'ouverte']]);

    // La case NON embrasure (1,0) reste du sol traversable ; l'embrasure
    // (2,0), elle, devient inoccupable — comme une case de roche.
    expect($bloquee->estTraversable(1, 0))->toBeTrue()
        ->and($bloquee->estTraversable(2, 0))->toBeFalse()
        // …et l'ARÊTE continue de couper passage et vue gauche↔droite.
        ->and($bloquee->porteBloqueEntre(1, 0, 2, 0))->toBeTrue()
        ->and($bloquee->chemin(0, 0, 4, 0))->toBeNull()
        ->and($bloquee->ligneDeVue(0, 0, 4, 0))->toBeFalse();

    // La même porte ouverte laisse passer chemin ET vue, et redonne sa case.
    expect($ouverte->estTraversable(2, 0))->toBeTrue()
        ->and($ouverte->porteBloqueEntre(1, 0, 2, 0))->toBeFalse()
        ->and($ouverte->chemin(0, 0, 4, 0))->not->toBeNull()
        ->and($ouverte->ligneDeVue(0, 0, 4, 0))->toBeTrue();
});

it('rend une porte secrète non révélée infranchissable (arête et case), puis franchissable une fois ouverte', function () {
    $secrete = grilleAvecPortes(['sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'secrete']]);
    $revelee = grilleAvecPortes(['sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'ouverte']]);

    expect($secrete->estTraversable(2, 0))->toBeFalse()
        ->and($secrete->porteBloqueEntre(1, 0, 2, 0))->toBeTrue()
        ->and($secrete->chemin(0, 0, 4, 0))->toBeNull()
        ->and($secrete->ligneDeVue(0, 0, 4, 0))->toBeFalse();

    // Une fois révélée (etat ouverte), l'arête ET la case sont franchissables/transparentes.
    expect($revelee->estTraversable(2, 0))->toBeTrue()
        ->and($revelee->porteBloqueEntre(1, 0, 2, 0))->toBeFalse()
        ->and($revelee->ligneDeVue(0, 0, 4, 0))->toBeTrue()
        ->and($revelee->chemin(0, 0, 4, 0))->not->toBeNull();
});

it('n\'affecte QUE son arête et sa case d\'embrasure : les cloisons/cases voisines restent libres', function () {
    // Porte sur (1,0)|(2,0), embrasure (2,0) par repli : le pas (1,0)→(1,1) et
    // (2,0)→(2,1) restent libres, ainsi que la case (2,1) elle-même.
    $g = grilleAvecPortes(['sssss', 'sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'fermee']]);

    expect($g->porteBloqueEntre(1, 0, 2, 0))->toBeTrue()
        ->and($g->porteBloqueEntre(1, 0, 1, 1))->toBeFalse()
        ->and($g->porteBloqueEntre(2, 0, 2, 1))->toBeFalse()
        ->and($g->estTraversable(2, 1))->toBeTrue()
        // On peut contourner par la rangée du bas (aucune porte).
        ->and($g->chemin(0, 0, 4, 0))->not->toBeNull();
});

it('bloque le déplacement pondéré ET les cases atteignables sur l\'embrasure, sans changer aucun coût', function () {
    // Même patron : porte fermée sur (1,0)|(2,0), embrasure (2,0) par repli.
    $g = grilleAvecPortes(['sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'fermee']]);

    // casesAtteignables() ne doit JAMAIS proposer (2,0), (3,0) ou (4,0) —
    // inatteignables tant que la porte est close.
    $atteignables = $g->casesAtteignables(0, 0, 10);
    expect($atteignables)->not->toHaveKey('2,0')
        ->not->toHaveKey('3,0')
        ->not->toHaveKey('4,0')
        ->and($atteignables)->toHaveKey('1,0');

    // Ouverte : les cases derrière redeviennent atteignables, et chaque case
    // coûte encore exactement 1 point (sol ordinaire, aucun surcoût introduit
    // par le passage d'une embrasure).
    $ouverte = grilleAvecPortes(['sssss'], [['x' => 1, 'y' => 0, 'cote' => 'e', 'etat' => 'ouverte']]);
    $chemin = $ouverte->chemin(0, 0, 4, 0);
    expect($chemin)->toHaveCount(4)
        ->and($ouverte->coutChemin($chemin))->toBe(4);
});
