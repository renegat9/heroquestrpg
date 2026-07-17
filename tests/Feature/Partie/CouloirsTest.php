<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Partie\AssembleurCarte;
use App\Partie\Grille;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;

/*
 * Couloirs à 2 cases de large (correctifs F) : deux figurines passent de front,
 * chaque rangée a sa porte, et l'enchaînement des salles reste RÉELLEMENT
 * connecté (le vrai risque d'un couloir élargi : percer une porte sur un mur).
 */

beforeEach(function () {
    $this->seed([TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** @return array{carte: array<string, mixed>, rangees: list<int>} */
function carteAssemblee(): array
{
    $carte = app(AssembleurCarte::class)->assembler(GabaritQuete::query()->firstOrFail());
    $ligneMediane = intdiv($carte['hauteur'], 2);

    $rangees = [];
    for ($k = AssembleurCarte::LARGEUR_COULOIR - 1; $k >= 0; $k--) {
        $rangees[] = $ligneMediane - $k;
    }

    return ['carte' => $carte, 'rangees' => $rangees];
}

it('creuse chaque couloir sur 2 rangées de sol (F)', function () {
    ['carte' => $carte, 'rangees' => $rangees] = carteAssemblee();

    expect($rangees)->toHaveCount(2);

    // Couloir entre la 1re et la 2e salle : toute sa longueur, sur les 2 rangées.
    $salle = $carte['salles'][0];
    $debut = $salle['x'] + $salle['largeur'];

    for ($cx = $debut; $cx < $debut + AssembleurCarte::LONGUEUR_COULOIR; $cx++) {
        foreach ($rangees as $ry) {
            expect($carte['cases'][$ry][$cx])->toBe('s');
        }
    }
});

it('perce une porte par rangée de chaque côté d\'une jonction (F)', function () {
    ['carte' => $carte, 'rangees' => $rangees] = carteAssemblee();

    $salle = $carte['salles'][0];
    $xPorteEst = $salle['x'] + $salle['largeur'] - 1;

    $ys = array_column(
        array_filter($carte['portes'], fn ($p) => (int) $p['x'] === $xPorteEst),
        'y',
    );
    sort($ys);

    expect($ys)->toBe($rangees);
});

it('garde les salles réellement reliées : un chemin existe du spawn héros au spawn monstre (F)', function () {
    ['carte' => $carte] = carteAssemblee();

    // Connectivité de la GÉOMÉTRIE : on ouvre les portes (leur état est une
    // règle de jeu à part — cf. portes fermées par défaut) pour éprouver le
    // tracé lui-même, y compris les portes percées sur les rangées élargies.
    $grille = new Grille($carte['cases']);
    $grille->definirPortes(array_map(
        fn (array $p) => [...$p, 'etat' => 'ouverte'],
        $carte['portes'],
    ));

    $depart = $carte['spawn_heros'][0];          // 1re salle
    $arrivee = $carte['spawn_monstres'][0];      // dernière salle (rencontre finale)

    expect($grille->chemin($depart['x'], $depart['y'], $arrivee['x'], $arrivee['y']))->not->toBeNull();
});
