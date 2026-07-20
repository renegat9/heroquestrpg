<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Partie\AssembleurCarte;
use App\Partie\Grille;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;

/*
 * Carte 2D BRANCHUE (fini la chaîne gauche-droite, playtest F) : les salles
 * sont posées sur une grille en arbre (jusqu'à 4 embranchements par salle),
 * reliées par des couloirs à 2 voies, UNE SEULE porte par bord de salle (la
 * voie parallèle est un cul-de-sac sans porte — fini les deux portes
 * adjacentes à chaque jonction), et les monstres sont répartis (round-robin)
 * sur toutes les salles au lieu de s'entasser dans la dernière.
 */

beforeEach(function () {
    $this->seed([TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Gabarit « normal » (pas de rencontre finale) — assemblage générique. */
function gabaritNormal(): GabaritQuete
{
    return GabaritQuete::query()->where('type_jalon', 'normale')->firstOrFail();
}

/** Gabarit avec rencontre finale (sous-boss/boss) — pour les checks de salle boss. */
function gabaritAvecBoss(): GabaritQuete
{
    return GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail();
}

/**
 * Toutes les cases 'p' (porte) de la grille, en liste de coordonnées.
 *
 * @param  list<list<string>>  $cases
 * @return list<array{x: int, y: int}>
 */
function portesDeLaGrille(array $cases): array
{
    $positions = [];

    foreach ($cases as $y => $ligne) {
        foreach ($ligne as $x => $case) {
            if ($case === 'p') {
                $positions[] = ['x' => $x, 'y' => $y];
            }
        }
    }

    return $positions;
}

/** Index de la salle contenant (x, y), ou null. */
function salleContenant(array $salles, int $x, int $y): ?int
{
    foreach ($salles as $i => $s) {
        if ($x >= $s['x'] && $x < $s['x'] + $s['largeur'] && $y >= $s['y'] && $y < $s['y'] + $s['hauteur']) {
            return $i;
        }
    }

    return null;
}

it('varie la carte selon la graine (fini « toujours la même carte ») et reste reproductible', function () {
    $gabarit = gabaritNormal();
    $assembleur = app(AssembleurCarte::class);

    // Reproductible : même graine → carte identique (indispensable pour une
    // même quête / la reprise).
    expect($assembleur->assembler($gabarit, 42)['cases'])
        ->toBe($assembleur->assembler($gabarit, 42)['cases']);

    // Variété : sur une douzaine de graines, plusieurs cartes DISTINCTES
    // (dimensions et/ou disposition des salles).
    $signatures = collect(range(1, 12))
        ->map(fn ($g) => md5(json_encode($assembleur->assembler($gabarit, $g * 7919)['cases'])))
        ->unique();

    expect($signatures->count())->toBeGreaterThan(1);
});

it('pose des portes-ARÊTES valides et distinctes (une porte ne prend pas de case)', function () {
    $assembleur = app(AssembleurCarte::class);

    foreach (range(1, 15) as $graine) {
        $carte = $assembleur->assembler(gabaritNormal(), $graine * 101);

        // Aucune case n'est une « porte » : les portes vivent sur les cloisons.
        expect(collect($carte['cases'])->flatten()->unique()->all())->not->toContain('p');
        expect($carte['portes'])->not->toBeEmpty();

        $aretes = [];
        foreach ($carte['portes'] as $p) {
            expect($p['cote'] ?? null)->toBeIn(['e', 's']);

            // Les DEUX cases que sépare l'arête sont du SOL franchissable.
            [$a, $b] = Grille::casesPorte($p);
            expect($carte['cases'][$a['y']][$a['x']] ?? 'm')->toBe('s')
                ->and($carte['cases'][$b['y']][$b['x']] ?? 'm')->toBe('s');

            // Une seule porte par cloison (jamais deux portes sur la même arête).
            $cle = Grille::cleArete($a['x'], $a['y'], $b['x'], $b['y']);
            expect($aretes)->not->toContain($cle, "Arête de porte en double (graine {$graine}) : {$cle}");
            $aretes[] = $cle;
        }
    }
});

it('creuse chaque couloir sur 2 voies traversables (F)', function () {
    $carte = app(AssembleurCarte::class)->assembler(gabaritNormal(), 42);

    expect($carte['aretes'])->not->toBeEmpty();

    foreach ($carte['aretes'] as $arete) {
        $ax = $arete['porte_a']['x'];
        $ay = $arete['porte_a']['y'];
        $bx = $arete['porte_b']['x'];
        $by = $arete['porte_b']['y'];

        if ($ay === $by) {
            // Arête horizontale : la voie principale (ligne ay) et la voie
            // parallèle (ay-1) sont toutes deux traversables entre les portes.
            [$xMin, $xMax] = $ax < $bx ? [$ax, $bx] : [$bx, $ax];
            for ($x = $xMin; $x <= $xMax; $x++) {
                expect(in_array($carte['cases'][$ay][$x], ['s', 'p'], true))->toBeTrue();
            }
            $voieParallele = array_slice($carte['cases'][$ay - 1], $xMin + 1, $xMax - $xMin - 1);
            expect($voieParallele)->not->toBeEmpty()
                ->and(array_unique($voieParallele))->toBe(['s']);
        } else {
            // Arête verticale : symétrique sur les colonnes.
            [$yMin, $yMax] = $ay < $by ? [$ay, $by] : [$by, $ay];
            for ($y = $yMin; $y <= $yMax; $y++) {
                expect(in_array($carte['cases'][$y][$ax], ['s', 'p'], true))->toBeTrue();
            }
            $voieParallele = [];
            for ($y = $yMin + 1; $y < $yMax; $y++) {
                $voieParallele[] = $carte['cases'][$y][$ax - 1];
            }
            expect($voieParallele)->not->toBeEmpty()
                ->and(array_unique($voieParallele))->toBe(['s']);
        }
    }
});

it('pose les portes inter-salles FERMÉES par défaut : elles barrent le passage (E2)', function () {
    $carte = app(AssembleurCarte::class)->assembler(gabaritNormal(), 42);

    $etats = array_unique(array_column($carte['portes'], 'etat'));
    expect($etats)->toBe(['fermee']);

    // Et elles barrent RÉELLEMENT : sans les ouvrir, une salle non voisine du
    // départ est inatteignable.
    $grille = new Grille($carte['cases']);
    $grille->definirPortes($carte['portes']);

    $depart = $carte['spawn_heros'][0];
    $arrivee = $carte['spawn_monstres'][0];

    expect($grille->chemin($depart['x'], $depart['y'], $arrivee['x'], $arrivee['y']))->toBeNull();
});

it('garde toutes les salles réellement reliées : un chemin existe du spawn héros vers CHAQUE salle (portes ouvertes)', function () {
    $carte = app(AssembleurCarte::class)->assembler(gabaritAvecBoss(), 7);

    // Connectivité de la GÉOMÉTRIE : on ouvre les portes (leur état est une
    // règle de jeu à part — cf. portes fermées par défaut) pour éprouver le
    // tracé lui-même.
    $grille = new Grille($carte['cases']);
    $grille->definirPortes(array_map(
        fn (array $p) => [...$p, 'etat' => 'ouverte'],
        $carte['portes'],
    ));

    $depart = $carte['spawn_heros'][0];

    foreach ($carte['salles'] as $i => $salle) {
        $centre = ['x' => $salle['x'] + intdiv($salle['largeur'], 2), 'y' => $salle['y'] + intdiv($salle['hauteur'], 2)];
        expect($grille->chemin($depart['x'], $depart['y'], $centre['x'], $centre['y']))
            ->not->toBeNull("Salle {$i} inatteignable depuis le spawn héros.");
    }

    // Et spécifiquement la salle boss (dernière posée).
    $bossSalle = $carte['salles'][count($carte['salles']) - 1];
    $centreBoss = ['x' => $bossSalle['x'] + intdiv($bossSalle['largeur'], 2), 'y' => $bossSalle['y'] + intdiv($bossSalle['hauteur'], 2)];
    expect($bossSalle['theme'])->toBe('boss')
        ->and($grille->chemin($depart['x'], $depart['y'], $centreBoss['x'], $centreBoss['y']))->not->toBeNull();
});

it('produit un arbre RÉELLEMENT branchu sur plusieurs graines (pas une simple chaîne)', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = gabaritAvecBoss(); // salles.max = 7 : plus de marge pour brancher

    $dejaBranchu = false;

    foreach (range(1, 20) as $graine) {
        $carte = $assembleur->assembler($gabarit, $graine * 53);

        // Nb d'arêtes par salle (parent OU enfant).
        $degres = array_fill(0, count($carte['salles']), 0);
        foreach ($carte['aretes'] as $arete) {
            $degres[$arete['a']]++;
            $degres[$arete['b']]++;
        }

        // Une salle avec ≥ 3 arêtes a nécessairement ≥ 2 ENFANTS (elle n'a
        // qu'au plus 1 arête « parent ») → vraie branche.
        if (max($degres) >= 3) {
            $dejaBranchu = true;
            break;
        }
    }

    expect($dejaBranchu)->toBeTrue();
});

it('répartit les monstres sur AU MOINS 2 salles distinctes, boss en position 0', function () {
    $carte = app(AssembleurCarte::class)->assembler(gabaritAvecBoss(), 42);

    expect($carte['spawn_monstres'])->not->toBeEmpty();

    $salles = collect($carte['spawn_monstres'])
        ->map(fn ($p) => salleContenant($carte['salles'], $p['x'], $p['y']))
        ->unique();

    expect($salles->count())->toBeGreaterThanOrEqual(2)
        ->and($salles->contains(null))->toBeFalse(); // toujours dans une salle, jamais un couloir

    // La rencontre finale (spawn_monstres[0], = le boss côté DemarreurQuete)
    // est dans la salle boss (dernière posée, thème « boss »).
    $bossIndex = count($carte['salles']) - 1;
    expect($carte['salles'][$bossIndex]['theme'])->toBe('boss')
        ->and(salleContenant($carte['salles'], $carte['spawn_monstres'][0]['x'], $carte['spawn_monstres'][0]['y']))
        ->toBe($bossIndex);
});
