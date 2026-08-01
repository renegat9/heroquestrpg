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

/**
 * Groupe minimal + quête + carte assemblée à cette graine — pour exercer
 * MoteurPortes sur une vraie carte sans démarrer une quête complète.
 *
 * @return array{0: \App\Models\Groupe, 1: \App\Models\Quete}
 */
function groupeAvecCarte(int $graine): array
{
    $groupe = creerGroupe();
    $carteAssemblee = app(AssembleurCarte::class)->assembler(gabaritNormal(), $graine);

    $quete = \App\Models\Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => gabaritNormal()->id,
        'titre' => 'Quête de test',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    \App\Models\Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $carteAssemblee['largeur'],
        'hauteur' => $carteAssemblee['hauteur'],
        'grille' => $carteAssemblee,
    ]);

    return [$groupe, $quete->fresh()];
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

        // Jonction MITOYENNE (salles mur contre mur) : pas de couloir du tout,
        // juste un seuil — les deux portes sont à une case l'une de l'autre.
        // Rien à vérifier ici, c'est le cas voulu.
        if (abs($ax - $bx) + abs($ay - $by) <= 1) {
            continue;
        }

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

    // Les portes de l'ARBRE sont fermées ; les liaisons SUPPLÉMENTAIRES (boucles)
    // peuvent être secrètes — mais rien d'autre.
    $etats = array_unique(array_column($carte['portes'], 'etat'));
    sort($etats);
    expect(array_diff($etats, ['fermee', 'secrete']))->toBe([])
        ->and(in_array('fermee', $etats, true))->toBeTrue();

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

// ---------------------------------------------------------------------------
// Jonctions larges de 2 cases (test de jeu 2026-07-31)
// ---------------------------------------------------------------------------

it('ouvre chaque jonction sur 2 CASES DE FRONT, portes comprises', function () {
    $carte = app(AssembleurCarte::class)->assembler(gabaritNormal(), 7717);

    $parJonction = collect($carte['portes'])->groupBy('jonction');

    expect($parJonction)->not->toBeEmpty();

    foreach ($parJonction as $jonction => $portes) {
        // 4 portes = 2 par bord de salle (voie médiane + voie parallèle) : le
        // passage fait deux cases de large de bout en bout. Sans ça, le tank
        // bouchait le seuil et le tireur derrière n'avait aucune ligne de vue
        // (constat du test de jeu à 2 : magicien muet pendant 8 tours).
        expect($portes)->toHaveCount(4, "jonction {$jonction} : passage rétréci");

        // Les deux portes d'un même bord sont VOISINES (vraie ouverture double,
        // pas deux passages distincts).
        $paires = $portes->groupBy(fn ($p) => $p['cote'] === 'e' ? $p['x'] : $p['y']);
        foreach ($paires as $bord) {
            expect($bord)->toHaveCount(2);
            $a = $bord[0];
            $b = $bord[1];
            expect(abs($a['x'] - $b['x']) + abs($a['y'] - $b['y']))->toBe(1);
        }
    }
});

it('ouvre TOUTE la jonction quand on ouvre une seule de ses portes', function () {
    [$groupe, $quete] = groupeAvecCarte(7717);

    $portes = $quete->carte->grille['portes'];
    $index = collect($portes)->search(fn ($p) => ($p['etat'] ?? '') === 'fermee');
    $jonction = $portes[$index]['jonction'];

    app(App\Partie\MoteurPortes::class)->ouvrir($groupe, $quete->carte, (int) $index, 'test');

    $apres = collect($quete->carte->fresh()->grille['portes'])
        ->filter(fn ($p) => ($p['jonction'] ?? null) === $jonction);

    // Une jonction s'ouvre d'un bloc : à moitié ouverte, elle redeviendrait un
    // goulot d'une case — exactement ce que l'élargissement supprime.
    expect($apres)->toHaveCount(4)
        ->and($apres->every(fn ($p) => $p['etat'] === 'ouverte'))->toBeTrue();
});

it('offre un vivier de salles VARIÉ (le catalogue ne doit pas retomber à 3 formes)', function () {
    $formes = App\Models\Tuile::where('type', 'salle')->where('theme', 'generique')->get()
        ->map(fn ($t) => $t->grille['largeur'].'×'.$t->grille['hauteur'])
        ->unique();

    // Le vivier n'en comptait que 3 (et chacune en double, TuileSeeder n'étant
    // pas idempotent) : toutes les salles d'un donjon se ressemblaient.
    expect($formes->count())->toBeGreaterThanOrEqual(6);

    // …et le seeder est re-semable sans dupliquer.
    $avant = App\Models\Tuile::count();
    (new Database\Seeders\TuileSeeder())->run();
    expect(App\Models\Tuile::count())->toBe($avant);
});


it('ne rend JAMAIS une salle tributaire d\'une porte secrète', function () {
    // Les liaisons supplémentaires ouvrent des BOUCLES : ce sont des raccourcis,
    // jamais l'unique accès. Une salle qu'on ne pourrait atteindre qu'en trouvant
    // une porte secrète bloquerait un groupe qui rate son jet de fouille — la
    // classe de bug qui figeait déjà le groupe au §2.16.
    foreach ([42, 7717, 31337, 104729, 555] as $graine) {
        $carte = app(AssembleurCarte::class)->assembler(gabaritNormal(), $graine);

        // Grille où seules les portes NON secrètes sont ouvertes.
        $portes = array_map(function (array $p) {
            $p['etat'] = ($p['etat'] ?? '') === 'secrete' ? 'secrete' : 'ouverte';

            return $p;
        }, $carte['portes']);

        $grille = new Grille($carte['cases']);
        $grille->definirPortes($portes);

        $depart = $carte['spawn_heros'][0];

        foreach ($carte['salles'] as $i => $salle) {
            $cible = ['x' => $salle['mediane_x'], 'y' => $salle['mediane_y']];
            expect($grille->chemin($depart['x'], $depart['y'], $cible['x'], $cible['y']))
                ->not->toBeNull("graine {$graine} : salle {$i} inatteignable sans porte secrète");
        }
    }
});

it('pose des pièges DANS les salles, jamais dans celle du départ', function () {
    $enSalle = 0;
    $enCouloir = 0;

    foreach ([42, 7717, 31337, 97, 555, 104729, 2024, 31, 777, 12345] as $graine) {
        $carte = app(AssembleurCarte::class)->assembler(gabaritNormal(), $graine);
        $depart = $carte['salles'][0];

        foreach ($carte['pieges'] as $piege) {
            $salle = null;
            foreach ($carte['salles'] as $i => $s) {
                if ($piege['x'] >= $s['x'] && $piege['x'] < $s['x'] + $s['largeur']
                    && $piege['y'] >= $s['y'] && $piege['y'] < $s['y'] + $s['hauteur']) {
                    $salle = $i;
                    break;
                }
            }

            $salle === null ? $enCouloir++ : $enSalle++;

            // Un piège sous les pieds du groupe au tour 1 serait subi, pas joué.
            expect($salle)->not->toBe(0, "graine {$graine} : piège dans la salle de départ");
        }
    }

    // Les deux emplacements existent : s'en tenir aux couloirs rendait les
    // pièges prévisibles et les salles parfaitement sûres.
    expect($enSalle)->toBeGreaterThan(0)
        ->and($enSalle + $enCouloir)->toBeGreaterThan(0);
});

it('ne met jamais une porte secrète et une porte normale sur le même seuil', function () {
    // Deux états différents sur le même mur, c'est illisible pour le joueur —
    // et c'était un piège moteur : la recherche de « porte close adjacente »
    // rendait la première trouvée, si bien qu'une secrète pouvait masquer une
    // porte ouvrable et priver le héros de son option « Ouvrir la porte ».
    foreach ([42, 7717, 31337, 97, 555, 104729, 2024, 31, 777, 12345] as $graine) {
        $carte = app(AssembleurCarte::class)->assembler(gabaritNormal(), $graine);

        $parCase = [];
        foreach ($carte['portes'] as $porte) {
            foreach (App\Partie\Grille::casesPorte($porte) as $case) {
                $parCase[$case['x'].','.$case['y']][] = $porte['etat'];
            }
        }

        foreach ($parCase as $cle => $etats) {
            $secrete = in_array('secrete', $etats, true);
            $normale = (bool) array_filter($etats, fn ($e) => $e !== 'secrete');

            expect($secrete && $normale)->toBeFalse("graine {$graine}, case {$cle} : ".implode('+', $etats));
        }
    }
});

it('ne pose qu\'UNE seule jonction par côté de salle, secrète comprise', function () {
    // Règle de René : un mur de salle (ou de couloir) ne porte qu'un passage,
    // qu'il soit normal ou secret. Un seuil large de 2 cases compte pour UN :
    // ses arêtes partagent la même `jonction` et s'ouvrent ensemble.
    foreach ([42, 7717, 31337, 97, 555, 104729, 2024, 31, 777, 12345] as $graine) {
        $carte = app(AssembleurCarte::class)->assembler(gabaritNormal(), $graine);

        foreach ($carte['salles'] as $i => $s) {
            $parCote = [];

            foreach ($carte['portes'] as $porte) {
                foreach (App\Partie\Grille::casesPorte($porte) as $case) {
                    if ($case['x'] < $s['x'] || $case['x'] >= $s['x'] + $s['largeur']
                        || $case['y'] < $s['y'] || $case['y'] >= $s['y'] + $s['hauteur']) {
                        continue;
                    }

                    $cote = match (true) {
                        $case['y'] === $s['y'] => 'N',
                        $case['y'] === $s['y'] + $s['hauteur'] - 1 => 'S',
                        $case['x'] === $s['x'] => 'W',
                        $case['x'] === $s['x'] + $s['largeur'] - 1 => 'E',
                        default => null,
                    };

                    if ($cote !== null) {
                        $parCote[$cote][$porte['jonction'] ?? -1] = true;
                    }
                }
            }

            foreach ($parCote as $cote => $jonctions) {
                expect(count($jonctions))->toBe(1, "graine {$graine}, salle {$i}, côté {$cote}");
            }
        }
    }
});
