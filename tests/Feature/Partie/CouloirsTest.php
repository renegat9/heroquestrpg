<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Mobilier;
use App\Models\Quete;
use App\Models\Tuile;
use App\Partie\AssembleurCarte;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use App\Partie\MoteurPortes;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
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
    $this->seed([TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
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
 * @return array{0: Groupe, 1: Quete}
 */
function groupeAvecCarte(int $graine): array
{
    $groupe = creerGroupe();
    $carteAssemblee = app(AssembleurCarte::class)->assembler(gabaritNormal(), $graine);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => gabaritNormal()->id,
        'titre' => 'Quête de test',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    Carte::create([
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
 * Quête + carte construite À LA MAIN (pas de génération procédurale) — pour
 * placer un meuble précis à une position CONNUE et exercer FabriqueGrille /
 * Grille::ligneDeVue de façon déterministe (bloque_mouvement / bloque_vue,
 * doc 17). `$cases` est déjà au format m/s de `Grille` ; `$mobilier` au
 * format `carte.grille.mobilier` ({mobilier_id, x, y, l, h, salle}).
 *
 * @param  list<list<string>>  $cases
 * @param  list<array{mobilier_id: int, x: int, y: int, l: int, h: int, salle: int}>  $mobilier
 * @return array{0: Groupe, 1: Quete}
 */
function groupeAvecCarteMobilier(array $cases, array $mobilier): array
{
    $groupe = creerGroupe();

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => gabaritNormal()->id,
        'titre' => 'Quête de test',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    $largeur = count($cases[0] ?? []);
    $hauteur = count($cases);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [],
            'leviers' => [],
            'pieges' => [],
            'mobilier' => $mobilier,
            'spawn_heros' => [['x' => 0, 'y' => 0]],
            'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    return [$groupe, $quete->fresh()];
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

it('ne pose qu\'UNE porte par salle : un seuil fait UNE case, comme au plateau', function () {
    $carte = app(AssembleurCarte::class)->assembler(gabaritNormal(), 7717);

    $parJonction = collect($carte['portes'])->groupBy('jonction');

    expect($parJonction)->not->toBeEmpty();

    foreach ($parJonction as $jonction => $portes) {
        // 2 portes par jonction : une par salle, aux deux bouts du couloir.
        // Ce test en exigeait 4 (seuils larges de 2 cases, pour qu'un tank ne
        // bouche pas la vue au tireur). Le jeu officiel n'a que des portes
        // d'UNE case et règle ce cas par l'attaque en DIAGONALE — « deux héros
        // à la fois peuvent attaquer un monstre qui bloque un seuil de porte »
        // (LR p. 14, reference/16_armurerie.md §6.2). Décision de René,
        // 2026-08-08.
        expect($portes)->toHaveCount(2, "jonction {$jonction} : seuil dédoublé");

        // …et jamais deux portes accolées sur un même bord.
        $parBord = $portes->groupBy(fn ($p) => $p['cote'] === 'e' ? $p['x'] : $p['y']);
        foreach ($parBord as $bord => $surCeBord) {
            expect($surCeBord)->toHaveCount(1, "bord {$bord} : deux portes côte à côte");
        }
    }
});

it('n\'ouvre que le seuil poussé, et laisse fermé celui d\'en face', function () {
    [$groupe, $quete] = groupeAvecCarte(7717);

    $portes = $quete->carte->grille['portes'];
    $index = collect($portes)->search(fn ($p) => ($p['etat'] ?? '') === 'fermee');
    $poussee = $portes[$index];
    $jonction = $poussee['jonction'];

    app(MoteurPortes::class)->ouvrir($groupe, $quete->carte, (int) $index, 'test');

    $apres = collect($quete->carte->fresh()->grille['portes'])
        ->filter(fn ($p) => ($p['jonction'] ?? null) === $jonction);

    // Un couloir a DEUX seuils, un par salle, désormais d'une seule case
    // chacun. Pousser l'un ne doit pas ouvrir l'autre : sinon le groupe arrive
    // au bout du corridor devant une porte déjà ouverte, sur une salle non
    // révélée (test de jeu 2026-08-07).
    expect($apres)->toHaveCount(2)
        ->and($apres->where('etat', 'ouverte'))->toHaveCount(1);

    $ouverte = $apres->firstWhere('etat', 'ouverte');
    expect($ouverte['x'])->toBe($poussee['x'])
        ->and($ouverte['y'])->toBe($poussee['y']);
});

it('offre un vivier de salles VARIÉ (le catalogue ne doit pas retomber à 3 formes)', function () {
    $formes = Tuile::where('type', 'salle')->where('theme', 'generique')->get()
        ->map(fn ($t) => $t->grille['largeur'].'×'.$t->grille['hauteur'])
        ->unique();

    // Le vivier n'en comptait que 3 (et chacune en double, TuileSeeder n'étant
    // pas idempotent) : toutes les salles d'un donjon se ressemblaient.
    expect($formes->count())->toBeGreaterThanOrEqual(6);

    // …et le seeder est re-semable sans dupliquer.
    $avant = Tuile::count();
    (new TuileSeeder)->run();
    expect(Tuile::count())->toBe($avant);
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
            foreach (Grille::casesPorte($porte) as $case) {
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
                foreach (Grille::casesPorte($porte) as $case) {
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

it('garantit AU MOINS une porte secrète par quête', function () {
    // Exigence de René. Elle repose sur deux propriétés du placement :
    //  · les salles sont posées de façon COMPACTE, donc l'arbre se replie et
    //    laisse des paires de salles voisines mais non reliées ;
    //  · le gabarit impose au moins 5 salles — en dessous, aucune boucle n'est
    //    géométriquement possible (0 % à 3 salles, 59 % à 4).
    // Casser l'une ou l'autre fait retomber la probabilité, sans rien casser
    // d'autre : d'où ce test.
    foreach ([42, 7717, 31337, 97, 555, 104729, 2024, 31, 777, 12345, 8, 999999] as $graine) {
        $carte = app(AssembleurCarte::class)->assembler(gabaritNormal(), $graine);

        $secretes = collect($carte['portes'])->where('etat', 'secrete')
            ->pluck('jonction')->unique();

        expect($secretes->count())->toBeGreaterThanOrEqual(1, "graine {$graine} : aucune porte secrète");
    }
});

// ---------------------------------------------------------------------------
// Mobilier (doc 17) — même famille d'invariants anti-blocage que les portes
// secrètes ci-dessus : un meuble mal placé fige le groupe exactement comme
// une salle tributaire d'une porte secrète (§2.16).
// ---------------------------------------------------------------------------

it('pose du mobilier UNIQUEMENT dans les salles (jamais en couloir, jamais en salle 0)', function () {
    $graines = [42, 7717, 31337, 97, 555, 104729, 2024, 31, 777, 12345, 8, 999999];
    $totalMeubles = 0;

    foreach ($graines as $graine) {
        $carte = app(AssembleurCarte::class)->assembler(gabaritAvecBoss(), $graine);

        foreach ($carte['mobilier'] as $meuble) {
            $totalMeubles++;

            expect($meuble['salle'])->not->toBe(0, "graine {$graine} : meuble en salle de départ");

            $salle = $carte['salles'][$meuble['salle']];

            // L'EMPRISE ENTIÈRE (l×h) doit tenir dans le rectangle de la salle
            // déclarée — jamais un pas dans le couloir ni dans une salle voisine
            // mitoyenne (les salles peuvent partager un bord, cf. accolerSallesMitoyennes).
            for ($dy = 0; $dy < $meuble['h']; $dy++) {
                for ($dx = 0; $dx < $meuble['l']; $dx++) {
                    $x = $meuble['x'] + $dx;
                    $y = $meuble['y'] + $dy;

                    expect($x)->toBeGreaterThanOrEqual($salle['x'])
                        ->and($x)->toBeLessThan($salle['x'] + $salle['largeur'])
                        ->and($y)->toBeGreaterThanOrEqual($salle['y'])
                        ->and($y)->toBeLessThan($salle['y'] + $salle['hauteur']);
                    expect($carte['cases'][$y][$x] ?? 'm')
                        ->toBe('s', "graine {$graine} : meuble hors sol en ({$x},{$y})");
                }
            }
        }
    }

    // Le catalogue est bien lu (sans quoi ce test ne vaudrait rien) : au moins
    // un meuble posé sur la douzaine de graines.
    expect($totalMeubles)->toBeGreaterThan(0);
});

it('ne pose jamais un meuble sur le seuil d\'une porte', function () {
    foreach ([42, 7717, 31337, 97, 555, 104729, 2024, 31, 777, 12345, 8, 999999] as $graine) {
        $carte = app(AssembleurCarte::class)->assembler(gabaritAvecBoss(), $graine);

        $casesPorte = [];
        foreach ($carte['portes'] as $porte) {
            foreach (Grille::casesPorte($porte) as $case) {
                $casesPorte["{$case['x']},{$case['y']}"] = true;
            }
        }

        foreach ($carte['mobilier'] as $meuble) {
            for ($dy = 0; $dy < $meuble['h']; $dy++) {
                for ($dx = 0; $dx < $meuble['l']; $dx++) {
                    $cle = ($meuble['x'] + $dx).','.($meuble['y'] + $dy);
                    expect($casesPorte)->not->toHaveKey($cle, "graine {$graine} : meuble sur un seuil ({$cle})");
                }
            }
        }
    }
});

it('ne superpose jamais un meuble et un piège (invisible + indéclenchable sinon)', function () {
    foreach ([42, 7717, 31337, 97, 555, 104729, 2024, 31, 777, 12345, 8, 999999] as $graine) {
        $carte = app(AssembleurCarte::class)->assembler(gabaritAvecBoss(), $graine);

        $casesPiege = collect($carte['pieges'])->map(fn ($p) => "{$p['x']},{$p['y']}")->flip();

        foreach ($carte['mobilier'] as $meuble) {
            for ($dy = 0; $dy < $meuble['h']; $dy++) {
                for ($dx = 0; $dx < $meuble['l']; $dx++) {
                    $cle = ($meuble['x'] + $dx).','.($meuble['y'] + $dy);
                    expect($casesPiege->has($cle))->toBeFalse("graine {$graine} : meuble sur un piège ({$cle})");
                }
            }
        }
    }
});

it('ne laisse JAMAIS le mobilier isoler une case par ailleurs atteignable', function () {
    // Même garantie que « aucune salle tributaire d'une porte secrète » plus
    // haut, à l'échelle de la case cette fois : depuis le spawn héros, portes
    // ouvertes, TOUTE case de sol non couverte par du mobilier doit rester
    // joignable. Un meuble qui romprait cette propriété a normalement déjà été
    // refusé au placement (AssembleurCarte::salleResteConnexe) — ce test
    // rejoue l'invariant côté carte assemblée, pour le verrouiller ici aussi.
    foreach ([42, 7717, 31337, 97, 555, 104729, 2024, 31, 777, 12345, 8, 999999, 1, 2, 3] as $graine) {
        $carte = app(AssembleurCarte::class)->assembler(gabaritAvecBoss(), $graine);

        $occupees = [];
        foreach ($carte['mobilier'] as $meuble) {
            for ($dy = 0; $dy < $meuble['h']; $dy++) {
                for ($dx = 0; $dx < $meuble['l']; $dx++) {
                    $occupees[($meuble['x'] + $dx).','.($meuble['y'] + $dy)] = true;
                }
            }
        }

        $grille = new Grille($carte['cases']);
        $grille->definirPortes(array_map(fn (array $p) => [...$p, 'etat' => 'ouverte'], $carte['portes']));
        $grille->occuper(array_map(
            fn (string $cle) => ['x' => (int) explode(',', $cle)[0], 'y' => (int) explode(',', $cle)[1]],
            array_keys($occupees),
        ));

        $depart = $carte['spawn_heros'][0];

        foreach ($carte['salles'] as $s) {
            for ($y = $s['y']; $y < $s['y'] + $s['hauteur']; $y++) {
                for ($x = $s['x']; $x < $s['x'] + $s['largeur']; $x++) {
                    if (($carte['cases'][$y][$x] ?? 'm') !== 's' || isset($occupees["{$x},{$y}"])) {
                        continue;
                    }
                    if ($x === $depart['x'] && $y === $depart['y']) {
                        continue;
                    }

                    expect($grille->chemin($depart['x'], $depart['y'], $x, $y))
                        ->not->toBeNull("graine {$graine} : case ({$x},{$y}) isolée par le mobilier");
                }
            }
        }
    }
});

it('rend une case de mobilier BLOQUANT infranchissable via FabriqueGrille, source unique d\'occupation', function () {
    // FabriqueGrille est le chemin réellement emprunté en jeu (déplacement,
    // ciblage, ligne de vue) — contrairement au test ci-dessus qui rejoue la
    // géométrie brute, celui-ci exerce le VRAI point d'entrée du moteur.
    [, $quete] = groupeAvecCarte(7717);

    $carte = $quete->carte->grille;
    expect($carte['mobilier'])->not->toBeEmpty();

    $grille = FabriqueGrille::pour($quete);

    $idsBloquants = Mobilier::query()->where('bloque_mouvement', true)->pluck('id')->all();

    foreach ($carte['mobilier'] as $meuble) {
        if (! in_array($meuble['mobilier_id'], $idsBloquants, true)) {
            continue;
        }

        for ($dy = 0; $dy < $meuble['h']; $dy++) {
            for ($dx = 0; $dx < $meuble['l']; $dx++) {
                $x = $meuble['x'] + $dx;
                $y = $meuble['y'] + $dy;
                expect($grille->estTraversable($x, $y))
                    ->toBeFalse("case de mobilier bloquant ({$x},{$y}) traversable");
            }
        }
    }

    // Contrôle négatif : une case de sol ordinaire, hors emprise de tout
    // meuble et hors figure, reste traversable — la grille ne bloque pas tout.
    $occupeesMeubles = [];
    foreach ($carte['mobilier'] as $meuble) {
        for ($dy = 0; $dy < $meuble['h']; $dy++) {
            for ($dx = 0; $dx < $meuble['l']; $dx++) {
                $occupeesMeubles[($meuble['x'] + $dx).','.($meuble['y'] + $dy)] = true;
            }
        }
    }
    $libre = collect($carte['salles'])->flatMap(function (array $s) use ($carte, $occupeesMeubles) {
        $libres = [];
        for ($y = $s['y']; $y < $s['y'] + $s['hauteur']; $y++) {
            for ($x = $s['x']; $x < $s['x'] + $s['largeur']; $x++) {
                if (($carte['cases'][$y][$x] ?? 'm') === 's' && ! isset($occupeesMeubles["{$x},{$y}"])) {
                    $libres[] = ['x' => $x, 'y' => $y];
                }
            }
        }

        return $libres;
    })->first();

    expect($libre)->not->toBeNull()
        ->and($grille->estTraversable($libre['x'], $libre['y']))->toBeTrue();
});

// ---------------------------------------------------------------------------
// bloque_mouvement / bloque_vue (doc 17, portage) — les DEUX propriétés sont
// INDÉPENDANTES : un meuble bloquant n'était jusqu'ici ajouté qu'aux cases
// OCCUPÉES de Grille, ce qui coupait aussi la ligne de vue dès qu'un appelant
// passait figuresBloquent: true (le cas de toute arme à distance, MenuMoteur)
// — conséquence, une simple table arrêtait les flèches. Une table bloque le
// passage mais on voit par-dessus ; une bibliothèque bloque les deux.
// ---------------------------------------------------------------------------

it('coupe la ligne de vue par une case de Bibliothèque, MÊME avec figuresBloquent: false — un meuble haut est du décor, pas une figure', function () {
    $bibliotheque = Mobilier::query()->where('nom', 'Bibliothèque')->firstOrFail();
    expect($bibliotheque->bloque_vue)->toBeTrue(); // hypothèse du test, pas un hasard de seed

    [, $quete] = groupeAvecCarteMobilier(
        [['s', 's', 's', 's', 's']],
        [['mobilier_id' => $bibliotheque->id, 'x' => 2, 'y' => 0, 'l' => 1, 'h' => 1, 'salle' => 0]],
    );

    $grille = FabriqueGrille::pour($quete);

    // Case (2,0) entre les deux extrémités (0,0) et (4,0) : la vue est coupée
    // qu'on regarde simplement (figuresBloquent: false, déplacement) OU qu'on
    // tire une flèche (figuresBloquent: true) — un meuble opaque n'est PAS
    // une figure interposée, il coupe la vue INCONDITIONNELLEMENT, comme un mur.
    expect($grille->ligneDeVue(0, 0, 4, 0))->toBeFalse()
        ->and($grille->ligneDeVue(0, 0, 4, 0, figuresBloquent: true))->toBeFalse();
});

it('ne coupe PAS la ligne de vue par une case de Table, MÊME avec figuresBloquent: true — une table est basse, on voit par-dessus', function () {
    $table = Mobilier::query()->where('nom', 'Table')->firstOrFail();
    expect($table->bloque_vue)->toBeFalse(); // hypothèse du test, pas un hasard de seed

    [, $quete] = groupeAvecCarteMobilier(
        [['s', 's', 's', 's', 's']],
        [['mobilier_id' => $table->id, 'x' => 2, 'y' => 0, 'l' => 1, 'h' => 1, 'salle' => 0]],
    );

    $grille = FabriqueGrille::pour($quete);

    // C'était LE bug : une table (bloque_mouvement, pas bloque_vue) occupait
    // la même case que les figures, et arrêtait donc aussi les tirs. Elle ne
    // doit désormais JAMAIS couper la vue, même en tir/sort.
    expect($grille->ligneDeVue(0, 0, 4, 0))->toBeTrue()
        ->and($grille->ligneDeVue(0, 0, 4, 0, figuresBloquent: true))->toBeTrue();
});

it('laisse Bibliothèque ET Table toutes deux INTRAVERSABLES : bloque_vue ne change rien à bloque_mouvement', function () {
    $bibliotheque = Mobilier::query()->where('nom', 'Bibliothèque')->firstOrFail();
    $table = Mobilier::query()->where('nom', 'Table')->firstOrFail();

    [, $quete] = groupeAvecCarteMobilier(
        [['s', 's', 's', 's', 's']],
        [
            ['mobilier_id' => $bibliotheque->id, 'x' => 1, 'y' => 0, 'l' => 1, 'h' => 1, 'salle' => 0],
            ['mobilier_id' => $table->id, 'x' => 3, 'y' => 0, 'l' => 1, 'h' => 1, 'salle' => 0],
        ],
    );

    $grille = FabriqueGrille::pour($quete);

    expect($grille->estTraversable(1, 0))->toBeFalse()
        ->and($grille->estTraversable(3, 0))->toBeFalse();
});
