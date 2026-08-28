<?php

declare(strict_types=1);

use App\Models\Quete;
use App\Partie\EtatGroupe;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LES LEVIERS SONT PUBLIÉS, DONC DESSINÉS (2026-08-27).
 *
 * ⚠ `EtatGroupe` ne publiait la couche `leviers` NULLE PART : aucun levier
 * n'était donc dessiné sur aucune des deux cartes. C'était sans conséquence
 * tant qu'aucun n'était jamais posé — mais depuis que forcer un levier demande
 * un jet de Body et qu'une salle peut ne tenir qu'à cette porte, un mécanisme
 * invisible verrouille le donjon : l'option n'apparaît qu'au CONTACT, et rien
 * sur la carte ne dit où aller le chercher.
 *
 * ⚠ L'autre moitié de la règle est le BROUILLARD. Une entrée de levier ne porte
 * pas sa salle (`{x, y, levier_id, difficulte}`, format d'origine) : elle est
 * déduite des coordonnées. Publier sans filtrer poserait un marqueur par-dessus
 * le brouillard et révélerait l'emplacement d'un mécanisme que le groupe n'a
 * pas encore atteint — exactement ce que `mobilier()` et `epreuves()` évitent.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/**
 * Pose la couche `leviers` de la carte — aucun gabarit n'en déclare, le
 * placement procédural ne peut donc pas servir de mise en scène.
 *
 * @param  list<array{x: int, y: int, levier_id: string, difficulte?: int}>  $entrees
 */
function poserLeviers(Quete $quete, array $entrees): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['leviers'] = $entrees;
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

/** Case du coin haut-gauche de la salle d'index donné. */
function caseDeLaSalle(Quete $quete, int $index): array
{
    $salle = $quete->carte->grille['salles'][$index];

    return ['x' => (int) $salle['x'], 'y' => (int) $salle['y']];
}

it('publie les leviers d\'une salle DÉCOUVERTE, avec leur difficulté', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $quete = $ctx['quete'];

    // La salle 0 est la salle de départ : découverte dès le premier tour.
    $ici = caseDeLaSalle($quete, 0);
    poserLeviers($quete, [[...$ici, 'levier_id' => 'herse_nord', 'difficulte' => 3]]);

    $carte = app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['carte'];

    expect($carte['leviers'])->toHaveCount(1)
        ->and($carte['leviers'][0]['levier_id'])->toBe('herse_nord')
        ->and($carte['leviers'][0]['x'])->toBe($ici['x'])
        ->and($carte['leviers'][0]['y'])->toBe($ici['y'])
        // ⚠ La DIFFICULTÉ voyage avec le marqueur : c'est elle que l'infobulle
        // affiche, et un levier à 3 ne se tente pas comme un levier à 1.
        ->and($carte['leviers'][0]['difficulte'])->toBe(3);
});

it('TAIT les leviers d\'une salle non découverte', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $quete = $ctx['quete'];

    // Une salle que le groupe n'a pas ouverte (la 0 est la seule découverte au
    // premier tour).
    $ailleurs = caseDeLaSalle($quete, count($quete->carte->grille['salles']) - 1);
    poserLeviers($quete, [[...$ailleurs, 'levier_id' => 'herse_profonde', 'difficulte' => 2]]);

    $carte = app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['carte'];

    expect($carte['leviers'])->toBe([]);
});

it('montre un levier de COULOIR, qui n\'appartient à aucune salle', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $quete = $ctx['quete'];

    // Une case hors de toute salle : un couloir n'a pas d'index et n'est jamais
    // « découvert ». Le cacher rendrait le mécanisme introuvable, alors qu'un
    // couloir traversé est de toute façon sous les yeux du groupe.
    $salles = $quete->carte->grille['salles'];
    $dansUneSalle = function (int $x, int $y) use ($salles): bool {
        foreach ($salles as $s) {
            if ($x >= $s['x'] && $x < $s['x'] + $s['largeur']
                && $y >= $s['y'] && $y < $s['y'] + $s['hauteur']) {
                return true;
            }
        }

        return false;
    };

    $couloir = null;
    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $type) {
            if ($type === 's' && ! $dansUneSalle((int) $x, (int) $y)) {
                $couloir = ['x' => (int) $x, 'y' => (int) $y];
                break 2;
            }
        }
    }

    expect($couloir)->not->toBeNull('aucune case de couloir sur cette carte');

    poserLeviers($quete, [[...$couloir, 'levier_id' => 'herse_couloir']]);

    $carte = app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['carte'];

    expect($carte['leviers'])->toHaveCount(1)
        ->and($carte['leviers'][0]['levier_id'])->toBe('herse_couloir');
});
