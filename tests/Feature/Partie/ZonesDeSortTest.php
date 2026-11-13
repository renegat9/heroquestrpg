<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Partie\ZonesDeSort;
use Illuminate\Support\Collection;

/*
 * LA GÉOMÉTRIE PARTAGÉE DES SORTS DE ZONE (plan `docs/plan-parchemins-glace.md`, lot 1, 2026-11-13).
 *
 * `App\Partie\ZonesDeSort` est le SEUL calcul des cases d'une zone : `MoteurDread` (sorts du MJ) et les sorts de
 * héros (Morsure du Froid, puis Tempête de Glace) le lisent tous les deux. Ces tests figent la forme exacte des cases
 * (l'ordre compte : `cases_affectees` le publie tel quel) et la lecture des figures. Le comportement du Dread, lui,
 * est figé par ses propres tests (SortsDreadCartesTest, BrassardDeGlaceTest…).
 */

/** Une figure révélée en vue de la géométrie : position + emprise (cases). */
function figureSur(int $x, int $y, int $largeur = 1, int $hauteur = 1): InstanceMonstre
{
    // L'emprise se lit dans le CATALOGUE (`Monstre::emprise()`, colonne `grande_taille`) : c'est là qu'on la fixe.
    $instance = new InstanceMonstre(['position_x' => $x, 'position_y' => $y]);
    $instance->setRelation('monstre', new \App\Models\Monstre([
        'nom_base' => 'Test', 'grande_taille' => ['l' => $largeur, 'h' => $hauteur],
    ]));

    return $instance;
}

/** Un héros posé sur (x, y). */
function heroSur(int $x, int $y): EtatPersonnageQuete
{
    return new EtatPersonnageQuete(['personnage_id' => $x * 100 + $y, 'position_x' => $x, 'position_y' => $y]);
}

it('contact : les QUATRE cases orthogonales, jamais une diagonale', function () {
    expect(ZonesDeSort::contact(2, 3))->toBe([
        ['x' => 3, 'y' => 3], ['x' => 1, 'y' => 3], ['x' => 2, 'y' => 4], ['x' => 2, 'y' => 2],
    ]);
});

it('carré : le 2×2 dont le coin haut-gauche est donné, dans l\'ordre que publie le Dread', function () {
    expect(ZonesDeSort::carre(1, 1))->toBe([
        ['x' => 1, 'y' => 1], ['x' => 2, 'y' => 1], ['x' => 1, 'y' => 2], ['x' => 2, 'y' => 2],
    ]);
});

it('salleCommune : l\'index de la salle quand TOUTES les cases y sont, null sinon', function () {
    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 3],
        ['x' => 4, 'y' => 0, 'largeur' => 3, 'hauteur' => 3],
    ];

    // Un carré entièrement dans la salle 0 : son index.
    expect(ZonesDeSort::salleCommune(ZonesDeSort::carre(0, 0), $salles))->toBe(0)
        // Un carré à cheval sur le mur entre deux salles : pas de salle commune.
        ->and(ZonesDeSort::salleCommune(ZonesDeSort::carre(2, 0), $salles))->toBeNull()
        // Une case de couloir (hors salle) : null, même si les autres sont dans une salle.
        ->and(ZonesDeSort::salleCommune([['x' => 3, 'y' => 0], ['x' => 3, 'y' => 1]], $salles))->toBeNull()
        // Aucune case : pas de salle.
        ->and(ZonesDeSort::salleCommune([], $salles))->toBeNull();
});

it('herosSur : ne retient que les héros dont la case est dans la zone', function () {
    // Contact de (2,1) : (3,1), (1,1), (2,2), (2,0). Le héros en (1,2) est DIAGONAL : il n'y est pas.
    $heros = new Collection([heroSur(1, 1), heroSur(1, 2), heroSur(2, 2), heroSur(5, 5)]);

    $dans = ZonesDeSort::herosSur($heros, ZonesDeSort::contact(2, 1));

    expect($dans->map(fn (EtatPersonnageQuete $e) => [$e->position_x, $e->position_y])->values()->all())
        ->toBe([[1, 1], [2, 2]]);
});

it('monstresSur : une figure compte dès qu\'UNE de ses cases est dans la zone (emprise incluse)', function () {
    // Un monstre 2×2 dont l'ancre est en (4,4) : il occupe (4,4), (5,4), (4,5), (5,5).
    $grand = figureSur(4, 4, 2, 2);
    $loin = figureSur(9, 9);

    // Le contact de (5,3) touche (5,4) — une case du grand monstre, pas son ancre.
    $dans = ZonesDeSort::monstresSur(new Collection([$grand, $loin]), ZonesDeSort::contact(5, 3));

    expect($dans)->toHaveCount(1)
        ->and($dans->first()->position_x)->toBe(4);
});
