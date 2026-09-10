<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\GabaritQuete;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\DemarreurQuete;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;

/*
 * Couche TERRAIN (doc 18 §4, The Frozen Horror, phase 4a du plan
 * docs/plan-glace-et-degats-mind.md) — FONDATIONS seules : catalogue seedé,
 * placement (`AssembleurCarte::placerTerrains()`), lecture de grille
 * (`FabriqueGrille::pour()`, `Grille::definirCoutsDeplacement()`), publication
 * (`EtatGroupe`). Aucune règle de jeu câblée ici — un autre agent branchera
 * les lecteurs sur `effet`.
 */

beforeEach(function () {
    $this->seed([
        TuileSeeder::class,
        GabaritQueteSeeder::class,
        PiegeSeeder::class,
        MobilierSeeder::class,
        EpreuveSeeder::class,
        TerrainSeeder::class,
    ]);
});

function gabaritTerrainNormal(): GabaritQuete
{
    return GabaritQuete::query()->where('type_jalon', 'normale')->firstOrFail();
}

function gabaritTerrainAvecBoss(): GabaritQuete
{
    return GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail();
}

/** Copie NON PERSISTÉE d'un gabarit, structure enrichie — assembler() ne lit que `structure`. */
function gabaritAvecStructureTerrain(GabaritQuete $base, array $ajout): GabaritQuete
{
    $copie = $base->replicate();
    $copie->structure = [...$base->structure, ...$ajout];

    return $copie;
}

/**
 * Quête + carte assemblée PROCÉDURALEMENT à cette graine, via le vrai
 * AssembleurCarte::assembler() — pour les tests de PLACEMENT (salle 0, paires
 * de tunnels). `$theme` (phase 6a) filtre la couche terrain par
 * `terrains.boite` — les 7 terrains sourcés valent tous `horreur_des_glaces`,
 * donc un appel SANS thème (`null`, le défaut) ne pose jamais aucun d'eux :
 * les tests de PLACEMENT pur (salle 0, format, paires) doivent passer
 * `'horreur_des_glaces'` explicitement pour observer autre chose qu'une
 * couche vide.
 */
function queteAvecCarteTerrainAssemblee(GabaritQuete $gabarit, int $graine, ?string $theme = null): array
{
    $groupe = creerGroupe('table-terrain-'.$graine.'-'.uniqid());
    $carteAssemblee = app(AssembleurCarte::class)->assembler($gabarit, $graine, chancePassageSecret: AssembleurCarte::CHANCE_PASSAGE_SECRET, themeBestiaire: $theme);

    return [$groupe, $carteAssemblee];
}

/**
 * Quête + carte construite À LA MAIN (pas de génération procédurale), avec une
 * couche `terrain` explicite — pour FabriqueGrille/Grille (indépendance des
 * deux drapeaux, coût de déplacement). Même patron que
 * `groupeAvecCarteMobilier()` (CouloirsTest.php), adapté au terrain.
 *
 * @param  list<list<string>>  $cases
 * @param  list<array{x: int, y: int, terrain_id: int}>  $terrain
 */
function queteAvecCarteEtTerrain(array $cases, array $terrain): Quete
{
    $groupe = creerGroupe('table-terrain-fg-'.uniqid());
    $gabarit = gabaritTerrainNormal();

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — terrain/FabriqueGrille',
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
            'mobilier' => [],
            'epreuves' => [],
            'terrain' => $terrain,
            'spawn_heros' => [['x' => 0, 'y' => 0]],
            'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    return $quete->fresh();
}

// ---------------------------------------------------------------------
// 1. Catalogue
// ---------------------------------------------------------------------

it('seed les 7 terrains sourcés de The Frozen Horror (doc 18 §4), et seulement eux', function () {
    $noms = Terrain::query()->orderBy('id')->pluck('nom')->all();

    expect($noms)->toBe([
        'Glace glissante',
        'Glissière de glace',
        'Rivière gelée',
        'Tunnel de glace',
        'Chambre forte de glace',
        'Glace magique',
        'Rebord de crevasse',
    ]);
});

it('exclut le Bottomless Chasm, la Living Fog Room et le Sceptre — dettes nommées, pas des oublis', function () {
    $noms = Terrain::query()->pluck('nom')->map(fn ($n) => mb_strtolower((string) $n))->all();

    foreach (['gouffre', 'chasm', 'brouillard', 'sceptre', 'scepter', 'crypte', 'cage', 'trône', 'clé', 'antre'] as $motInterdit) {
        foreach ($noms as $nom) {
            expect($nom)->not->toContain($motInterdit, "« {$motInterdit} » ne devrait apparaître dans aucun nom de terrain");
        }
    }
});

it('a un catalogue cohérent : cout_deplacement >= 1, effet non vide (vocabulaire), drapeaux booléens', function () {
    $terrains = Terrain::query()->get();

    expect($terrains)->toHaveCount(7);

    foreach ($terrains as $t) {
        expect($t->cout_deplacement)->toBeGreaterThanOrEqual(1, "cout_deplacement de {$t->nom}")
            ->and($t->effet)->toBeArray("effet de {$t->nom} doit être un tableau")
            ->and($t->effet)->not->toBeEmpty("effet de {$t->nom} — vocabulaire manquant, terrain décoratif")
            ->and($t->bloque_mouvement)->toBeBool()
            ->and($t->bloque_vue)->toBeBool();
    }
});

it('donne à la Rivière gelée un coût de déplacement de 2, et 1 à tous les autres', function () {
    foreach (Terrain::query()->get() as $t) {
        $attendu = $t->nom === 'Rivière gelée' ? 2 : 1;
        expect($t->cout_deplacement)->toBe($attendu, "coût de déplacement de {$t->nom}");
    }
});

it('ne bloque le mouvement ni la vue pour aucun des 7 terrains sourcés — ce sont des dangers de sol, pas des murs', function () {
    foreach (Terrain::query()->get() as $t) {
        expect($t->bloque_mouvement)->toBeFalse("bloque_mouvement de {$t->nom}")
            ->and($t->bloque_vue)->toBeFalse("bloque_vue de {$t->nom}");
    }
});

it('donne au Tunnel de glace un effet de téléportation identifiable pour la pose par paires', function () {
    $tunnel = Terrain::where('nom', 'Tunnel de glace')->firstOrFail();

    expect($tunnel->effet['teleportation'] ?? null)->toBeTrue();
});

it('reste seedé SANS purge (clé sur nom) : re-semer garde les mêmes identifiants', function () {
    $idAvant = Terrain::where('nom', 'Glace glissante')->value('id');

    $this->seed(TerrainSeeder::class);

    $idApres = Terrain::where('nom', 'Glace glissante')->value('id');
    expect($idApres)->toBe($idAvant)
        ->and(Terrain::query()->count())->toBe(7);
});

// ---------------------------------------------------------------------
// 2. Placement — jamais en salle 0, comptage gouverné par le gabarit
// ---------------------------------------------------------------------

/** Copie NON PERSISTÉE d'un gabarit dont `structure.terrains` a été RETIRÉ. */
function gabaritSansTerrains(GabaritQuete $base): GabaritQuete
{
    $copie = $base->replicate();
    $structure = $base->structure;
    unset($structure['terrains']);
    $copie->structure = $structure;

    return $copie;
}

it('ne pose AUCUN terrain quand le gabarit ne déclare pas structure.terrains — comme les leviers avant leur premier gabarit', function () {
    // ⚠ Depuis la phase 6a, `GabaritQueteSeeder` déclare `structure.terrains`
    // sur les trois gabarits réels : ce test retire volontairement la clé
    // pour vérifier l'absence de déclaration EN ELLE-MÊME, indépendamment du
    // filtre par thème — d'où le thème passé explicitement (« glace »), pour
    // que la couche vide s'explique par le gabarit et non par autre chose.
    $gabarit = gabaritSansTerrains(gabaritTerrainAvecBoss());
    [, $carte] = queteAvecCarteTerrainAssemblee($gabarit, 42, 'horreur_des_glaces');

    expect($carte['terrain'])->toBe([]);
});

it('ne pose jamais de terrain dans la salle de départ (salle 0)', function () {
    $gabarit = gabaritAvecStructureTerrain(gabaritTerrainAvecBoss(), ['terrains' => ['min' => 8, 'max' => 8]]);

    foreach ([1, 2, 3, 42, 777, 12345] as $graine) {
        // Thème « glace » explicite : les 7 terrains sourcés sont tous
        // `boite = horreur_des_glaces`, un autre thème (ou aucun) viderait la
        // couche et ce test ne prouverait plus rien sur la salle 0.
        [, $carte] = queteAvecCarteTerrainAssemblee($gabarit, $graine, 'horreur_des_glaces');
        $depart = $carte['salles'][0];

        expect($carte['terrain'])->not->toBeEmpty("graine {$graine} : couche vide, la salle 0 n'est pas réellement testée");

        foreach ($carte['terrain'] as $entree) {
            $dansDepart = $entree['x'] >= $depart['x'] && $entree['x'] < $depart['x'] + $depart['largeur']
                && $entree['y'] >= $depart['y'] && $entree['y'] < $depart['y'] + $depart['hauteur'];

            expect($dansDepart)->toBeFalse("graine {$graine} : terrain en salle 0 ({$entree['x']},{$entree['y']})");
        }
    }
});

it('respecte le format {x, y, terrain_id} déclaré au contrat — aucun index de salle', function () {
    $gabarit = gabaritAvecStructureTerrain(gabaritTerrainAvecBoss(), ['terrains' => ['min' => 5, 'max' => 5]]);
    [, $carte] = queteAvecCarteTerrainAssemblee($gabarit, 777, 'horreur_des_glaces');

    expect($carte['terrain'])->not->toBeEmpty();

    foreach ($carte['terrain'] as $entree) {
        expect($entree)->toHaveKeys(['x', 'y', 'terrain_id'])
            ->and($entree)->not->toHaveKey('salle');
    }
});

// ---------------------------------------------------------------------
// 3. Placement — les tunnels vont par paires
// ---------------------------------------------------------------------

it('pose les Tunnels de glace PAR PAIRES seulement — les deux extrémités ou aucune', function () {
    $gabarit = gabaritAvecStructureTerrain(gabaritTerrainAvecBoss(), ['terrains' => ['tunnels' => ['min' => 3, 'max' => 3]]]);
    $tunnelId = Terrain::where('nom', 'Tunnel de glace')->value('id');

    $auMoinsUnePaire = false;

    foreach ([1, 2, 3, 42, 777, 12345, 99999] as $graine) {
        [, $carte] = queteAvecCarteTerrainAssemblee($gabarit, $graine, 'horreur_des_glaces');

        $entrees = collect($carte['terrain'])->where('terrain_id', $tunnelId);
        expect($entrees->count() % 2)->toBe(0, "graine {$graine} : nombre IMPAIR de cases de tunnel");

        foreach ($entrees->groupBy('paire_id') as $paireId => $groupe) {
            expect($groupe)->toHaveCount(2, "graine {$graine} : paire « {$paireId} » incomplète");
            $auMoinsUnePaire = true;
        }
    }

    expect($auMoinsUnePaire)->toBeTrue('aucune paire de tunnel posée sur 7 graines — le test ne prouve rien');
});

it('ne pose aucun Tunnel de glace quand structure.terrains.tunnels n\'est pas déclaré', function () {
    $gabarit = gabaritAvecStructureTerrain(gabaritTerrainAvecBoss(), ['terrains' => ['min' => 4, 'max' => 4]]);
    $tunnelId = Terrain::where('nom', 'Tunnel de glace')->value('id');

    [, $carte] = queteAvecCarteTerrainAssemblee($gabarit, 42, 'horreur_des_glaces');

    expect($carte['terrain'])->not->toBeEmpty(); // du terrain ORDINAIRE est bien posé…
    expect(collect($carte['terrain'])->where('terrain_id', $tunnelId))->toHaveCount(0); // …jamais de tunnel
});

// ---------------------------------------------------------------------
// 4. Placement — INVARIANT DUR : la connectivité (abandon, jamais forcé)
// ---------------------------------------------------------------------

it('ABANDONNE une pose de terrain BLOQUANT qui isolerait une case, plutôt que de la forcer', function () {
    // Salle « poche » construite à la main : seuil A=(2,0), puis un couloir
    // linéaire B=(3,0) - C=(4,0) - D=(5,0). Bloquer B (ou C) isole tout ce qui
    // est derrière — c'est exactement l'invariant à vérifier. La salle 0 (le
    // départ, en (0,0)) n'a rien à voir avec le test : elle existe seulement
    // pour que l'index de salle « 1 » soit bien la salle poche.
    $cases = [
        ['m', 's', 's', 's', 's', 's', 'm'],
    ];
    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 1, 'hauteur' => 1], // salle 0 : jamais candidate
        ['x' => 2, 'y' => 0, 'largeur' => 4, 'hauteur' => 1], // salle 1 : A,B,C,D
    ];
    $portes = [['x' => 1, 'y' => 0, 'cote' => 'e']]; // arête (1,0)|(2,0) → seuil de la salle 1 = A=(2,0)

    // Un SEUL type au catalogue, qui BLOQUE le mouvement — aucun des 7
    // terrains sourcés ne le fait, donc on en pose un synthétique pour
    // exercer le mécanisme (le mécanisme doit tenir même si rien ne l'utilise
    // encore en pratique, cf. AssembleurCarte::placerTerrains()).
    Terrain::query()->delete();
    $bloquant = Terrain::create([
        'nom' => 'Test — bloc de glace (bouchon)',
        'nom_anglais' => 'Test blocker',
        'cout_deplacement' => 1,
        'bloque_mouvement' => true,
        'bloque_vue' => false,
    ]);

    $structure = ['terrains' => ['min' => 3, 'max' => 3]]; // les 3 candidates B, C, D à la fois

    $assembleur = app(AssembleurCarte::class);
    $methode = new ReflectionMethod(AssembleurCarte::class, 'placerTerrains');
    $methode->setAccessible(true);

    $auMoinsUnRefus = false;

    for ($graine = 0; $graine < 80; $graine++) {
        $resultat = $methode->invoke(
            $assembleur, $structure, $cases, $salles, $portes, [], [], [], [], fn () => $graine,
        );

        // INVARIANT, quelle que soit la graine : aucune case de sol non
        // occupée par le terrain posé ne doit devenir inatteignable depuis le
        // seuil A=(2,0).
        $occupeesCles = collect($resultat)->map(fn ($t) => "{$t['x']},{$t['y']}")->all();

        $grilleTest = new Grille($cases);
        $grilleTest->obstruer(array_map(
            fn (string $cle) => ['x' => (int) explode(',', $cle)[0], 'y' => (int) explode(',', $cle)[1]],
            $occupeesCles,
        ));

        foreach ([[2, 0], [3, 0], [4, 0], [5, 0]] as [$x, $y]) {
            if (in_array("{$x},{$y}", $occupeesCles, true)) {
                continue; // occupée par le terrain lui-même : pas une case à atteindre
            }

            expect($grilleTest->chemin(2, 0, $x, $y))
                ->not->toBeNull("graine {$graine} : case ({$x},{$y}) isolée par la pose de terrain — invariant de connectivité rompu");
        }

        if (count($resultat) < 3) {
            $auMoinsUnRefus = true;
        }
    }

    // Sur au moins un ordre de tirage, poser du terrain BLOQUANT sur les 3
    // candidates à la fois DOIT avoir été refusé au moins une fois (bloquer le
    // seuil B avant D/C isole le reste de la poche) — sinon le mécanisme
    // d'abandon n'a jamais été exercé et ce test ne prouve rien.
    expect($auMoinsUnRefus)->toBeTrue('aucune des 80 graines testées n\'a produit de refus — le mécanisme d\'abandon semble ne jamais s\'activer');

    // Le type synthétique reste inutilisé nulle part ailleurs : pas de fuite
    // vers les autres tests (RefreshDatabase les isole de toute façon).
    expect($bloquant->bloque_mouvement)->toBeTrue();
});

// ---------------------------------------------------------------------
// 5. Lecture de grille — FabriqueGrille : bloque_mouvement / bloque_vue
//    sont INDÉPENDANTS (le bug de la table qui arrêtait les flèches, un
//    cran plus loin) ; le coût de déplacement est exposé, pas encore
//    consommé par la BFS.
// ---------------------------------------------------------------------

it('bloque le MOUVEMENT sans bloquer la VUE — un terrain bloquant n\'arrête PAS une flèche', function () {
    Terrain::query()->delete();
    $mur = Terrain::create([
        'nom' => 'Test — bloque mouvement seul', 'nom_anglais' => 'Test move-blocker',
        'cout_deplacement' => 1, 'bloque_mouvement' => true, 'bloque_vue' => false,
    ]);

    $cases = [array_fill(0, 5, 's')];
    $quete = queteAvecCarteEtTerrain($cases, [['x' => 2, 'y' => 0, 'terrain_id' => $mur->id]]);

    $grille = FabriqueGrille::pour($quete);

    expect($grille->estTraversable(2, 0))->toBeFalse('un terrain bloque_mouvement=true doit être infranchissable')
        ->and($grille->ligneDeVue(0, 0, 4, 0))->toBeTrue()
        ->and($grille->ligneDeVue(0, 0, 4, 0, figuresBloquent: true))->toBeTrue(
            'un terrain qui bloque le mouvement ne doit PAS arrêter une flèche — le bug de la table (mobilier), un cran plus loin',
        );
});

it('bloque la VUE sans bloquer le MOUVEMENT — indépendance dans l\'autre sens', function () {
    Terrain::query()->delete();
    $brume = Terrain::create([
        'nom' => 'Test — bloque vue seul', 'nom_anglais' => 'Test sight-blocker',
        'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => true,
    ]);

    $cases = [array_fill(0, 5, 's')];
    $quete = queteAvecCarteEtTerrain($cases, [['x' => 2, 'y' => 0, 'terrain_id' => $brume->id]]);

    $grille = FabriqueGrille::pour($quete);

    expect($grille->estTraversable(2, 0))->toBeTrue('un terrain qui ne bloque QUE la vue reste franchissable')
        ->and($grille->ligneDeVue(0, 0, 4, 0))->toBeFalse('bloque_vue coupe la vue inconditionnellement, comme un mur')
        ->and($grille->ligneDeVue(0, 0, 4, 0, figuresBloquent: true))->toBeFalse();
});

it('ne bloque ni mouvement ni vue pour les 7 terrains sourcés, via FabriqueGrille (sanity)', function () {
    $glace = Terrain::where('nom', 'Glace glissante')->firstOrFail();
    $cases = [array_fill(0, 5, 's')];
    $quete = queteAvecCarteEtTerrain($cases, [['x' => 2, 'y' => 0, 'terrain_id' => $glace->id]]);

    $grille = FabriqueGrille::pour($quete);

    expect($grille->estTraversable(2, 0))->toBeTrue()
        ->and($grille->ligneDeVue(0, 0, 4, 0, figuresBloquent: true))->toBeTrue();
});

it('expose le coût de déplacement de la Rivière gelée sur la Grille, et le fait peser sur le parcours PONDÉRÉ sans toucher à distance()', function () {
    $riviere = Terrain::where('nom', 'Rivière gelée')->firstOrFail();
    $cases = [array_fill(0, 5, 's')];
    $quete = queteAvecCarteEtTerrain($cases, [['x' => 2, 'y' => 0, 'terrain_id' => $riviere->id]]);

    $grille = FabriqueGrille::pour($quete);

    // Couloir d'une seule rangée : AUCUN détour possible, `chemin()` traverse
    // donc forcément la case de rivière — sa LONGUEUR (4 cases) ne change pas,
    // mais son COÛT le fait : `RiviereGeleeTest` prouve le cas où un détour
    // existe (Dijkstra le préfère), celui-ci prouve juste que le coût est
    // désormais bien consommé sur un trajet qui n'a pas le choix.
    expect($grille->coutDeplacement(2, 0))->toBe(2)
        ->and($grille->coutDeplacement(0, 0))->toBe(1)
        // distance() reste GÉOMÉTRIQUE (portée, adjacence) : 4 pas, jamais 5.
        ->and($grille->distance(0, 0, 4, 0))->toBe(4)
        ->and($grille->chemin(0, 0, 4, 0))->toHaveCount(4)
        // …mais coutChemin() le confronte au budget réel : 3 cases de sol (1
        // chacune) + 1 case de rivière (2) = 5, jamais 4.
        ->and($grille->coutChemin($grille->chemin(0, 0, 4, 0)))->toBe(5);
});

// ---------------------------------------------------------------------
// 6. Publication — EtatGroupe, filtrée par le brouillard
// ---------------------------------------------------------------------

it('ne publie un terrain QUE si sa case n\'est pas masquée par le brouillard', function () {
    $glace = Terrain::where('nom', 'Glace glissante')->firstOrFail();

    $carte = new Carte(['grille' => [
        'terrain' => [
            ['x' => 1, 'y' => 1, 'terrain_id' => $glace->id], // case VUE
            ['x' => 5, 'y' => 5, 'terrain_id' => $glace->id], // case MASQUÉE
        ],
    ]]);

    // Grille de brouillard DÉJÀ appliquée (comme le fait `carte()` avant
    // d'appeler `terrain()`) : 's' = vue, 'b' = masquée.
    $cases = array_fill(0, 6, array_fill(0, 6, 's'));
    $cases[5][5] = 'b';

    $methode = new ReflectionMethod(EtatGroupe::class, 'terrain');
    $methode->setAccessible(true);
    $publies = $methode->invoke(app(EtatGroupe::class), $carte, $cases);

    expect($publies)->toHaveCount(1);
    expect($publies[0]['x'])->toBe(1)
        ->and($publies[0]['y'])->toBe(1)
        ->and($publies[0]['nom'])->toBe('Glace glissante')
        ->and($publies[0]['cout_deplacement'])->toBe(1)
        ->and($publies[0])->toHaveKeys(['terrain_id', 'bloque_mouvement', 'bloque_vue', 'paire_id', 'image_url']);
});

it('publie le paire_id d\'un tunnel visible, et null pour un terrain ordinaire', function () {
    $tunnel = Terrain::where('nom', 'Tunnel de glace')->firstOrFail();
    $glace = Terrain::where('nom', 'Glace glissante')->firstOrFail();

    $carte = new Carte(['grille' => [
        'terrain' => [
            ['x' => 0, 'y' => 0, 'terrain_id' => $tunnel->id, 'paire_id' => 'tunnel-1'],
            ['x' => 1, 'y' => 0, 'terrain_id' => $glace->id],
        ],
    ]]);
    $cases = array_fill(0, 1, array_fill(0, 2, 's'));

    $methode = new ReflectionMethod(EtatGroupe::class, 'terrain');
    $methode->setAccessible(true);
    $publies = collect($methode->invoke(app(EtatGroupe::class), $carte, $cases))->keyBy('x');

    expect($publies[0]['paire_id'])->toBe('tunnel-1')
        ->and($publies[1]['paire_id'])->toBeNull();
});

it('publie un terrain de COULOIR comme un terrain de SALLE — le brouillard est le seul critère, aucun index de salle à dériver', function () {
    // Même leçon que les leviers (cf. EtatGroupe::leviers()) : une entrée de
    // terrain ne porte pas d'index de salle, donc rien ne doit privilégier ou
    // pénaliser un couloir face à une salle — seule la case compte.
    $glace = Terrain::where('nom', 'Glace glissante')->firstOrFail();

    $carte = new Carte(['grille' => [
        'terrain' => [
            ['x' => 3, 'y' => 3, 'terrain_id' => $glace->id], // en plein « couloir » fictif
        ],
    ]]);
    $cases = array_fill(0, 5, array_fill(0, 5, 's'));

    $methode = new ReflectionMethod(EtatGroupe::class, 'terrain');
    $methode->setAccessible(true);
    $publies = $methode->invoke(app(EtatGroupe::class), $carte, $cases);

    expect($publies)->toHaveCount(1)
        ->and($publies[0])->not->toHaveKey('salle');
});

// ---------------------------------------------------------------------
// 7. Thème du bestiaire (phase 6a) — la boîte du terrain, le gel du thème
// ---------------------------------------------------------------------

it('ne pose JAMAIS un terrain de glace sous un thème non-glace', function () {
    $gabarit = gabaritAvecStructureTerrain(gabaritTerrainAvecBoss(), ['terrains' => ['min' => 6, 'max' => 6]]);

    // ⚠ La liste se DÉRIVE des thèmes actifs, et `horreur_des_glaces` en fait
    // partie depuis le 2026-09-06 : c'est le seul thème sous lequel la glace
    // DOIT apparaître, donc le seul à exclure ici. Le test suivant en est la
    // preuve positive — les deux se lisent ensemble, et retirer l'un des deux
    // laisserait le filtre à moitié vérifié.
    $nonGlace = array_values(array_diff(DemarreurQuete::BOITES_THEMATIQUES, ['horreur_des_glaces']));
    expect($nonGlace)->not->toBeEmpty();

    foreach ($nonGlace as $theme) {
        [, $carte] = queteAvecCarteTerrainAssemblee($gabarit, 42, $theme);

        expect($carte['terrain'])->toBe([], "thème « {$theme} » : un terrain de glace est apparu");
    }
});

it('pose bien du terrain sous le thème horreur_des_glaces — preuve positive du filtre', function () {
    $gabarit = gabaritAvecStructureTerrain(gabaritTerrainAvecBoss(), ['terrains' => ['min' => 6, 'max' => 6]]);

    [, $carte] = queteAvecCarteTerrainAssemblee($gabarit, 42, 'horreur_des_glaces');

    expect($carte['terrain'])->not->toBeEmpty();
});

it('pose un terrain boite=null sous N\'IMPORTE QUEL thème — convient à tout, comme monstres.boite', function () {
    // Aucun des 7 terrains sourcés n'est `boite = null` : on en seede un
    // synthétique pour exercer la moitié « universelle » du filtre, que le
    // catalogue réel ne couvre pas aujourd'hui.
    Terrain::create([
        'nom' => 'Test — terrain universel', 'nom_anglais' => 'Test universal terrain',
        'cout_deplacement' => 1, 'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => null,
    ]);

    $gabarit = gabaritAvecStructureTerrain(gabaritTerrainAvecBoss(), ['terrains' => ['min' => 8, 'max' => 8]]);

    $idUniversel = Terrain::where('nom', 'Test — terrain universel')->value('id');

    foreach ([...DemarreurQuete::BOITES_THEMATIQUES, 'horreur_des_glaces', null] as $theme) {
        [, $carte] = queteAvecCarteTerrainAssemblee($gabarit, 42, $theme);

        expect($carte['terrain'])->not->toBeEmpty('thème « '.($theme ?? 'null').' » : le terrain universel n\'apparaît pas');
        expect(collect($carte['terrain'])->pluck('terrain_id'))->toContain($idUniversel);

        // ⚠ Sous le thème glace, les 7 terrains sourcés restent AUSSI
        // éligibles (boite = horreur_des_glaces) : seule cette combinaison-là
        // peut légitimement mélanger l'universel et le thématique. Pour tout
        // AUTRE thème (ou aucun), rien d'autre que l'universel ne doit passer
        // le filtre.
        if ($theme !== 'horreur_des_glaces') {
            foreach ($carte['terrain'] as $entree) {
                expect($entree['terrain_id'])->toBe($idUniversel, 'thème « '.($theme ?? 'null').' »');
            }
        }
    }
});

it('un groupe SANS theme_bestiaire rempli retombe sur le calcul historique, jamais null', function () {
    $demarreur = app(DemarreurQuete::class);
    $groupe = creerGroupe('table-theme-vierge-'.uniqid());

    expect($groupe->theme_bestiaire)->toBeNull()
        ->and($demarreur->themeBestiaireDuGroupe($groupe))->toBe($demarreur->themeBestiaire((int) $groupe->id))
        ->and($demarreur->themeBestiaireDuGroupe($groupe))->not->toBeNull();
});

it('le thème d\'un groupe déjà FIGÉ ne bouge plus — simule l\'allongement de BOITES_THEMATIQUES', function () {
    // On ne peut pas littéralement ajouter un 5e thème à la constante en plein
    // test ; on simule sa CONSÉQUENCE — le calcul brut change de réponse — en
    // figeant une valeur DIFFÉRENTE de ce que le calcul brut donne pour cet
    // id. Si le résolveur préfère la colonne au calcul, il est par
    // construction immunisé contre n'importe quel changement futur du modulo.
    $demarreur = app(DemarreurQuete::class);
    $groupe = creerGroupe('table-theme-fige-'.uniqid());

    $calculBrut = $demarreur->themeBestiaire((int) $groupe->id);
    $autre = collect(DemarreurQuete::BOITES_THEMATIQUES)->first(fn ($t) => $t !== $calculBrut);

    expect($autre)->not->toBeNull(); // au moins 2 thèmes déclarés, sinon le test ne prouve rien

    $groupe->update(['theme_bestiaire' => $autre]);

    expect($demarreur->themeBestiaireDuGroupe($groupe->fresh()))->toBe($autre)
        ->and($demarreur->themeBestiaireDuGroupe($groupe->fresh()))->not->toBe($calculBrut);
});

it('DemarreurQuete::demarrer() FIGE réellement le thème au premier démarrage de quête', function () {
    // Flux HTTP complet (même patron que DemarrageQueteTest) : la colonne
    // doit être écrite par le VRAI chemin de démarrage, pas seulement par le
    // résolveur testé en isolation ci-dessus.
    Illuminate\Support\Facades\Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed(\Database\Seeders\MonstreSeeder::class);

    $joueur = connecterJoueur('alice-theme');
    $groupe = creerGroupe('table-theme-demarre-'.uniqid());
    creerHeros($joueur, $groupe, 'Albrecht', 1);

    expect($groupe->fresh()->theme_bestiaire)->toBeNull();

    $this->postJson('/api/groupes/'.$groupe->identifiant.'/quetes')->assertCreated();

    $themeFige = $groupe->fresh()->theme_bestiaire;
    expect($themeFige)->not->toBeNull()
        ->and(DemarreurQuete::BOITES_THEMATIQUES)->toContain($themeFige);
});
