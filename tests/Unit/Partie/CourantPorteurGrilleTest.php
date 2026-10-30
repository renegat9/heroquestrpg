<?php

declare(strict_types=1);

use App\Partie\Grille;

/*
 * LOT H DES RESTES — LE COURANT PORTEUR, dans le parcours lui-même (Spirit Queen's Torment, quête 11 note C, livret G0053 p. 14) :
 *
 *   « Currents in these rooms or halls make it easier for heroes to swim. A hero may move 2 extra squares when they first enter
 *     this area. »
 *
 * Un GAIN de budget, pas un coût négatif : le Dijkstra refuse les arêtes négatives, donc chaque état porte son statut du bonus
 * (« D », « P<gain> », « S »). Ces tests épinglent l'algorithme sans base de données : une grille nue, des cases de sol.
 */

/** Une rangée de `$largeur` cases de sol. */
function rangeeSol(int $largeur): array
{
    return [array_fill(0, $largeur, 's')];
}

/** Les cases atteignables, sous forme « x,y », triées par abscisse (l'ordre de découverte n'est pas un contrat). */
function atteignablesCourant(Grille $grille, int $x, int $y, int $budget): array
{
    $cases = array_keys($grille->casesAtteignables($x, $y, $budget));
    usort($cases, fn (string $a, string $b) => (int) explode(',', $a)[0] <=> (int) explode(',', $b)[0]);

    return $cases;
}

it('sans courant porteur, le parcours est celui de toujours : six cases dans un budget de cinq, à partir de la case 1', function () {
    $grille = new Grille(rangeeSol(10));

    expect(atteignablesCourant($grille, 1, 0, 5))->toBe(['0,0', '2,0', '3,0', '4,0', '5,0', '6,0'])
        ->and($grille->gainDuTrajet(1, 0, [['x' => 2, 'y' => 0]]))->toBe(0);
});

it('un courant porteur donne 2 points à la PREMIÈRE entrée : la case située deux cases plus loin devient atteignable', function () {
    $grille = new Grille(rangeeSol(10));
    $grille->definirBonusEntrees([['x' => 3, 'y' => 0, 'gain' => 2], ['x' => 4, 'y' => 0, 'gain' => 2], ['x' => 5, 'y' => 0, 'gain' => 2]]);
    $grille->activerBonusEntree();

    $atteignables = atteignablesCourant($grille, 1, 0, 5);

    // Entrée en (3,0) : payée sur le budget normal (coût 2 ≤ 5), puis le bonus : 5 + 2 = 7 points au total. (8,0) coûte 7.
    expect($atteignables)->toContain('8,0')
        ->and($atteignables)->not->toContain('9,0');
});

it('le bonus n\'est pris QU\'UNE fois par déplacement : deux courants traversés de suite ne donnent que 2 points', function () {
    $grille = new Grille(rangeeSol(12));
    $grille->definirBonusEntrees([
        ['x' => 3, 'y' => 0, 'gain' => 2], ['x' => 4, 'y' => 0, 'gain' => 2],
        ['x' => 6, 'y' => 0, 'gain' => 2], ['x' => 7, 'y' => 0, 'gain' => 2],
    ]);
    $grille->activerBonusEntree();

    $atteignables = atteignablesCourant($grille, 1, 0, 5);

    // Gain unique (2) : (8,0) à 7 points est atteignable, (9,0) à 8 points ne l'est pas. Compter deux gains donnerait (9,0).
    expect($atteignables)->toContain('8,0')
        ->and($atteignables)->not->toContain('9,0');

    // Le trajet jusqu'au bout des deux courants ne cumule rien : le gain du trajet est celui d'UNE entrée.
    $chemin = $grille->chemin(1, 0, 7, 0);
    expect($grille->gainDuTrajet(1, 0, $chemin))->toBe(2);
});

it('un héros qui PART déjà dans un courant ne le prend pas : il n\'y entre pas, rien à gagner', function () {
    $grille = new Grille(rangeeSol(10));
    $grille->definirBonusEntrees([['x' => 3, 'y' => 0, 'gain' => 2], ['x' => 4, 'y' => 0, 'gain' => 2], ['x' => 5, 'y' => 0, 'gain' => 2]]);
    $grille->activerBonusEntree();

    // Depuis (3,0), budget 1 : (4,0) coûte 1 — mais (5,0) coûterait 2, et AUCUN gain ne s'applique : hors d'atteinte.
    $atteignables = atteignablesCourant($grille, 3, 0, 1);

    expect($atteignables)->toContain('4,0')
        ->and($atteignables)->not->toContain('5,0')
        ->and($grille->gainDuTrajet(3, 0, [['x' => 4, 'y' => 0]]))->toBe(0);
});

it('un monstre (grille non activée) ne reçoit JAMAIS le gain : la même grille, le même budget, aucune case de plus', function () {
    $grille = new Grille(rangeeSol(10));
    $grille->definirBonusEntrees([['x' => 3, 'y' => 0, 'gain' => 2], ['x' => 4, 'y' => 0, 'gain' => 2], ['x' => 5, 'y' => 0, 'gain' => 2]]);

    // Sans `activerBonusEntree()` : la grille d'un monstre. Les cases restent des cases de sol, de coût 1.
    expect(atteignablesCourant($grille, 1, 0, 5))->not->toContain('8,0')
        ->and($grille->gainDuTrajet(1, 0, [['x' => 3, 'y' => 0]]))->toBe(0);
});

it('le chemin rendu à une destination atteignable grâce au courant est celui qui traverse le courant, et son coût est la somme réelle', function () {
    $grille = new Grille(rangeeSol(10));
    $grille->definirBonusEntrees([['x' => 3, 'y' => 0, 'gain' => 2], ['x' => 4, 'y' => 0, 'gain' => 2], ['x' => 5, 'y' => 0, 'gain' => 2]]);
    $grille->activerBonusEntree();

    $chemin = $grille->chemin(1, 0, 8, 0, 5);

    expect(array_map(fn (array $c) => $c['x'], $chemin))->toBe([2, 3, 4, 5, 6, 7, 8])
        ->and($grille->coutChemin($chemin))->toBe(7)
        ->and($grille->gainDuTrajet(1, 0, $chemin))->toBe(2);
});

it('une destination HORS budget même avec le courant rend le plus court chemin : le refus se dit « hors de portée », pas « inaccessible »', function () {
    $grille = new Grille(rangeeSol(10));
    $grille->definirBonusEntrees([['x' => 3, 'y' => 0, 'gain' => 2], ['x' => 4, 'y' => 0, 'gain' => 2], ['x' => 5, 'y' => 0, 'gain' => 2]]);
    $grille->activerBonusEntree();

    $chemin = $grille->chemin(1, 0, 9, 0, 5);

    expect($chemin)->not->toBeNull()
        ->and($grille->coutChemin($chemin))->toBe(8)
        ->and($grille->gainDuTrajet(1, 0, $chemin))->toBe(2);
});

it('sur une carte SANS courant porteur, un budget ne change pas le chemin rendu (rien ne filtre)', function () {
    $grille = new Grille(rangeeSol(10));

    expect($grille->chemin(1, 0, 6, 0, 5))->toBe($grille->chemin(1, 0, 6, 0))
        ->and($grille->chemin(1, 0, 9, 0, 5))->not->toBeNull('un refus hors budget reste un chemin, pas un trou');
});
