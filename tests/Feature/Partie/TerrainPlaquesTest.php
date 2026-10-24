<?php

declare(strict_types=1);

use App\Engine\FormesTerrain;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Validation\ValidationException;

/*
 * TERRAINS EN PLAQUES (Jungles of Delthrak, René 2026-10-24 : « ajuste pour pouvoir mettre des terrains à plusieurs
 * cases ») — la Mare et le Brasier sont des BLOCS 2×2 ; la Jungle, la Toile et le Sable sont des MOTIFS tirés parmi une
 * liste fermée relevée sur le livret (`App\Engine\FormesTerrain`) ; la STATUE, meuble 1×1 qui bloque la vue.
 *
 * Trois étages : le catalogue et le vocabulaire (dans les deux sens), la POSE réelle (`placerTerrains()` appelé tel quel,
 * sur de vraies cartes et sur des salles de test), puis le JEU (le brasier compté par plaque, la publication, la statue).
 */

beforeEach(function () {
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

// =====================================================================
// HELPERS — préfixés `plaques` : la suite partage un seul espace de noms de fonctions.
// =====================================================================

/** Un générateur à graine fixe, de la même famille que les tirages du donjon. */
function plaquesSuivant(int $graine): \Closure
{
    $etat = $graine;

    return function () use (&$etat): int {
        $etat = ($etat * 1103515245 + 12345) & 0x7FFFFFFF;

        return $etat;
    };
}

/** Clé stable d'une FORME : ramenée à son coin haut-gauche et triée, indépendante de sa place sur la carte. */
function plaquesCle(array $cases): string
{
    $x0 = min(array_column($cases, 'x'));
    $y0 = min(array_column($cases, 'y'));
    $formes = array_map(fn (array $c) => [$c['x'] - $x0, $c['y'] - $y0], $cases);
    usort($formes, fn (array $a, array $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

    return json_encode($formes);
}

/** Les clés de toutes les formes du vocabulaire, normalisées comme les cases posées. */
function plaquesVocabulaire(): array
{
    return array_map(
        fn (array $forme) => plaquesCle(array_map(fn (array $d) => ['x' => $d[0], 'y' => $d[1]], $forme['cases'])),
        FormesTerrain::formesDe('plaque'),
    );
}

/**
 * Les cases de chaque plaque, par identifiant de groupe.
 *
 * @return array<string, list<array{x: int, y: int}>>
 */
function plaquesGroupes(array $terrains): array
{
    $groupes = [];

    foreach ($terrains as $entree) {
        if (isset($entree['groupe_id'])) {
            $groupes[$entree['groupe_id']][] = ['x' => (int) $entree['x'], 'y' => (int) $entree['y']];
        }
    }

    return $groupes;
}

/** Les cases qui touchent (orthogonalement) une porte, un levier ou une épreuve : le PARVIS, comme `AssembleurCarte::parvis()`. */
function plaquesParvis(array $carte): array
{
    $sources = [];

    foreach ($carte['portes'] ?? [] as $porte) {
        foreach (Grille::casesPorte($porte) as $case) {
            $sources[] = $case;
        }
    }

    foreach ([...($carte['leviers'] ?? []), ...($carte['epreuves'] ?? [])] as $element) {
        $sources[] = ['x' => (int) $element['x'], 'y' => (int) $element['y']];
    }

    $parvis = [];

    foreach ($sources as $source) {
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $parvis[($source['x'] + $dx).','.($source['y'] + $dy)] = true;
        }
    }

    return $parvis;
}

/** Indice de la salle qui contient la case (rectangle, murs compris), ou `null`. */
function plaquesSalleDe(array $salles, array $case): ?int
{
    foreach ($salles as $indice => $salle) {
        if ($case['x'] >= $salle['x'] && $case['x'] < $salle['x'] + $salle['largeur']
            && $case['y'] >= $salle['y'] && $case['y'] < $salle['y'] + $salle['hauteur']) {
            return $indice;
        }
    }

    return null;
}

/** Identifiant d'un terrain du catalogue, par son nom. */
function plaquesIdTerrain(string $nom): int
{
    return (int) Terrain::where('nom', $nom)->firstOrFail()->id;
}

/**
 * Une plaque de `$nom` ancrée sur (x,y) : un BLOC 2×2 (`bloc-N`), écrit comme le posent `placerTerrains()` et le payload.
 *
 * @return list<array{x: int, y: int, terrain_id: int, groupe_id: string}>
 */
function plaquesBloc(int $x, int $y, string $nom, string $groupe): array
{
    $cases = [];

    for ($dx = 0; $dx < 2; $dx++) {
        for ($dy = 0; $dy < 2; $dy++) {
            $cases[] = ['x' => $x + $dx, 'y' => $y + $dy, 'terrain_id' => plaquesIdTerrain($nom), 'groupe_id' => $groupe];
        }
    }

    return $cases;
}

/**
 * Pose les terrains d'UNE salle de test (`$larg`×`$haut` de sol, sans porte ni autre couche, aucun thème) en appelant la
 * VRAIE méthode `AssembleurCarte::placerTerrains()`. Seul moyen d'éprouver la règle du plancher sans fabriquer une carte
 * entière : on maîtrise la taille de la salle, donc le nombre de cases que le plancher laisse.
 *
 * @return list<array<string, mixed>>
 */
function plaquesPoser(int $larg, int $haut, int $graine, int $min = 1, int $max = 1): array
{
    $largeurGrille = $larg + 6;
    $hauteurGrille = max($haut + 2, 3);
    $cases = array_fill(0, $hauteurGrille, array_fill(0, $largeurGrille, 'm'));

    // Salle 0 (le départ, jamais de terrain) : une case de sol, à part.
    $cases[1][1] = 's';

    // Salle 1 : sol de `larg` × `haut`, entouré d'un mur.
    for ($y = 1; $y <= $haut; $y++) {
        for ($x = 4; $x < 4 + $larg; $x++) {
            $cases[$y][$x] = 's';
        }
    }

    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 1, 'mediane_y' => 1],
        ['x' => 3, 'y' => 0, 'largeur' => $larg + 2, 'hauteur' => $haut + 2, 'theme' => 'generique',
            'mediane_x' => 4, 'mediane_y' => 1],
    ];

    $methode = new ReflectionMethod(AssembleurCarte::class, 'placerTerrains');
    $methode->setAccessible(true);

    return $methode->invoke(
        app(AssembleurCarte::class),
        ['terrains' => ['min' => $min, 'max' => $max]],
        $cases, $salles, [], [], [], [], [], plaquesSuivant($graine), null,
    );
}

/** Une scène de jeu : une salle `$largeur`×`$hauteur`, le héros en `$herosPos`, un second héros qui garde le round ouvert. */
function plaquesScene(int $largeur, int $hauteur, array $herosPos, array $terrain = [], array $mobilier = []): array
{
    $groupe = creerGroupe('table-plaques-'.uniqid());

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Quête de test — plaques',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => $largeur, 'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => $hauteur,
            'cases' => array_fill(0, $hauteur, array_fill(0, $largeur, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur,
                'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => $mobilier, 'terrain' => $terrain,
            'spawn_heros' => [$herosPos], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);

    $heros = creerHeros(connecterJoueur('plaques-'.uniqid()), $groupe, 'Testeur', 1);
    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => $herosPos['x'], 'position_y' => $herosPos['y'], 'tombe' => false, 'a_joue' => false,
    ]);

    $second = creerHeros(connecterJoueur('plaques2-'.uniqid()), $groupe, 'Second', 2);
    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $second->id,
        'position_x' => 0, 'position_y' => $hauteur - 1, 'tombe' => false, 'a_joue' => false,
    ]);

    return ['groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'heros' => $heros, 'etatHeros' => $etatHeros];
}

function plaquesDeplacer(array $scene, int $x, int $y): array
{
    return app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros']->fresh(),
        ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'], ['x' => $x, 'y' => $y],
    );
}

/** Appelle la méthode PRIVÉE `saignerSurRiviere()` : c'est elle que le héros ET le monstre empruntent pour leurs dégâts de terrain. */
function plaquesSaigne(array $scene, array $chemin, bool $monstre = false): array
{
    $methode = new ReflectionMethod(ResolveurTour::class, 'saignerSurRiviere');
    $methode->setAccessible(true);

    return $methode->invoke(
        app(ResolveurTour::class), $scene['quete']->fresh()->load('carte'), $chemin,
        $monstre ? null : $scene['heros']->fresh(), $monstre,
    );
}

// =====================================================================
// 1. LE CATALOGUE — la forme de chaque terrain, sourcée
// =====================================================================

it('porte la forme de chaque terrain : Mare et Brasier en BLOC 2×2, Jungle, Toile et Sable en PLAQUE, le reste en une case', function () {
    foreach (['Mare', 'Brasier'] as $bloc) {
        expect(Terrain::where('nom', $bloc)->firstOrFail()->only(['largeur', 'hauteur', 'motif']))
            ->toBe(['largeur' => 2, 'hauteur' => 2, 'motif' => null], "{$bloc} : bloc fixe 2×2");
    }

    foreach (['Jungle entravante', 'Toile entravante', 'Sable entravant'] as $plaque) {
        expect(Terrain::where('nom', $plaque)->firstOrFail()->only(['largeur', 'hauteur', 'motif']))
            ->toBe(['largeur' => 1, 'hauteur' => 1, 'motif' => 'plaque'], "{$plaque} : motif de plaque");
    }

    // The Frozen Horror ne change pas : une case, sans motif.
    expect(Terrain::where('nom', 'Glace glissante')->firstOrFail()->only(['largeur', 'hauteur', 'motif']))
        ->toBe(['largeur' => 1, 'hauteur' => 1, 'motif' => null]);
});

it('tient le vocabulaire des motifs DANS LES DEUX SENS : chaque motif du catalogue est connu, et chaque motif déclaré est porté', function () {
    $utilises = Terrain::query()->whereNotNull('motif')->distinct()->pluck('motif')->all();

    expect($utilises)->not->toBe([], 'aucun motif au catalogue : le test serait vide');

    foreach ($utilises as $motif) {
        expect(FormesTerrain::connu($motif))->toBeTrue("motif « {$motif} » absent de FormesTerrain::MOTIFS");
    }

    foreach (array_keys(FormesTerrain::MOTIFS) as $motif) {
        expect(in_array($motif, $utilises, true))->toBeTrue("motif « {$motif} » déclaré mais porté par AUCUN terrain");
    }
});

it('rend chaque forme du vocabulaire VALIDE : connexe, ancrée sur (0,0), sans case en double', function () {
    foreach (FormesTerrain::formesDe('plaque') as $forme) {
        $cases = $forme['cases'];
        $cles = array_map(fn (array $d) => $d[0].','.$d[1], $cases);

        expect(count(array_unique($cles)))->toBe(count($cases), "{$forme['nom']} : case en double");
        expect(in_array('0,0', $cles, true))->toBeTrue("{$forme['nom']} : (0,0) absent, la forme n'est pas ancrée");
        expect(min(array_column($cases, 0)))->toBe(0)
            ->and(min(array_column($cases, 1)))->toBe(0);

        // Connexe : un parcours depuis (0,0) atteint toutes les cases par côté.
        $vues = ['0,0' => true];
        $pile = [[0, 0]];

        while ($pile !== []) {
            [$x, $y] = array_pop($pile);

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $voisin = ($x + $dx).','.($y + $dy);

                if (in_array($voisin, $cles, true) && ! isset($vues[$voisin])) {
                    $vues[$voisin] = true;
                    $pile[] = [$x + $dx, $y + $dy];
                }
            }
        }

        expect(count($vues))->toBe(count($cases), "{$forme['nom']} : forme non connexe");
        expect($forme['source'])->not->toBe('', "{$forme['nom']} : source vide");
    }
});

// =====================================================================
// 2. LA POSE RÉELLE — chaque forme est lue, et jamais décorative
// =====================================================================

it('CHAQUE forme du vocabulaire est tirée au moins une fois : une forme jamais posée serait une clé décorative', function () {
    Terrain::create(['nom' => 'Plaque de test', 'nom_anglais' => 'test', 'cout_deplacement' => 2,
        'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => null,
        'largeur' => 1, 'hauteur' => 1, 'motif' => 'plaque', 'effet' => ['entravant' => true]]);

    $vues = [];

    for ($graine = 1; $graine <= 300; $graine++) {
        foreach (plaquesGroupes(plaquesPoser(8, 6, $graine)) as $cases) {
            $vues[plaquesCle($cases)] = true;
        }
    }

    $vocabulaire = plaquesVocabulaire();

    expect(array_diff($vocabulaire, array_keys($vues)))->toBe([], 'formes déclarées mais jamais posées')
        ->and(array_diff(array_keys($vues), $vocabulaire))->toBe([], 'formes posées hors vocabulaire');
});

it('une plaque de JUNGLE de quatre cases ne fait JAMAIS refuser une pose pour cause de plancher (René, 2026-10-24)', function () {
    // Salle de 4×2 : huit cases de sol, plancher `CASES_JOUABLES_MINIMUM` = 6. Une plaque de quatre cases laisserait QUATRE
    // cases libres : si le plancher la comptait, aucune plaque de quatre ne serait jamais posée dans cette salle. Une
    // jungle se franchit et se tient : elle ne retire aucune case au plancher, comme le dit René.
    Terrain::create(['nom' => 'Plaque de test', 'nom_anglais' => 'test', 'cout_deplacement' => 2,
        'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => null,
        'largeur' => 1, 'hauteur' => 1, 'motif' => 'plaque', 'effet' => ['entravant' => true]]);

    $quatre = 0;

    for ($graine = 1; $graine <= 80; $graine++) {
        $groupes = plaquesGroupes(plaquesPoser(4, 2, $graine));

        expect(count($groupes))->toBe(1, "graine {$graine} : la plaque n'a pas été posée, le plancher l'a refusée à tort");

        foreach ($groupes as $cases) {
            if (count($cases) === 4) {
                $quatre++;
            }
        }
    }

    expect($quatre)->toBeGreaterThan(0, 'aucune plaque de quatre cases posée : le plancher la refuse, à tort');
});

it('la MARE, elle, retire ses quatre cases au plancher : dans une salle de 4×2 aucun bloc 2×2 ne se pose, dans une de 6×2 il se pose', function () {
    Terrain::create(['nom' => 'Mare de test', 'nom_anglais' => 'test', 'cout_deplacement' => 1,
        'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => null,
        'largeur' => 2, 'hauteur' => 2, 'motif' => null, 'effet' => ['interdit_arret' => true]]);

    for ($graine = 1; $graine <= 80; $graine++) {
        expect(plaquesPoser(4, 2, $graine))->toBe([], "graine {$graine} : une Mare a été posée dans une salle où elle tue le plancher");
    }

    $pose = 0;

    for ($graine = 1; $graine <= 80; $graine++) {
        if (plaquesPoser(6, 2, $graine) !== []) {
            $pose++;
        }
    }

    expect($pose)->toBeGreaterThan(0, 'une Mare refusée même là où le plancher la tient');
});

it('un bloc 2×2 se pose ENTIER ou pas du tout : aucune moitié de Mare ne reste dans une salle trop étroite', function () {
    Terrain::create(['nom' => 'Mare de test', 'nom_anglais' => 'test', 'cout_deplacement' => 1,
        'bloque_mouvement' => false, 'bloque_vue' => false, 'boite' => null,
        'largeur' => 2, 'hauteur' => 2, 'motif' => null, 'effet' => ['interdit_arret' => true]]);

    // Une salle d'une seule rangée ne peut pas recevoir un bloc 2×2 : aucune case ne doit donc être posée.
    for ($graine = 1; $graine <= 40; $graine++) {
        expect(plaquesPoser(8, 1, $graine))->toBe([], "graine {$graine} : une moitié de bloc posée");
    }
});

// =====================================================================
// 3. LA GÉNÉRATION RÉELLE — de vraies cartes de Delthrak
// =====================================================================

it('sur de vraies cartes de Delthrak, chaque plaque est ENTIÈRE, dans UNE salle, hors salle 0 et hors parvis', function () {
    $gabarit = GabaritQuete::query()->where('type_jalon', 'normale')->firstOrFail();
    $vocabulaire = plaquesVocabulaire();
    $nomsParId = Terrain::query()->pluck('nom', 'id')->all();
    $blocs = 0;
    $motifs = 0;

    for ($graine = 1; $graine <= 40; $graine++) {
        $carte = app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: BestiaireGroupe::auto('jungles_delthrak'));
        $parvis = plaquesParvis($carte);

        // Une MARE ou un BRASIER posé hors de son bloc (sans `bloc-`) : c'est la pose par case qu'on a quittée.
        foreach ($carte['terrain'] as $entree) {
            $nom = $nomsParId[$entree['terrain_id']] ?? '';

            if (in_array($nom, ['Mare', 'Brasier'], true)) {
                expect(str_starts_with((string) ($entree['groupe_id'] ?? ''), 'bloc-'))
                    ->toBeTrue("{$nom} posé hors de son bloc 2×2 (graine {$graine})");
            }
        }

        foreach (plaquesGroupes($carte['terrain']) as $groupe => $cases) {
            if (str_starts_with($groupe, 'bloc-')) {
                $blocs++;
                expect(count($cases))->toBe(4, "{$groupe} : un bloc 2×2 doit être entier (graine {$graine})")
                    ->and(plaquesCle($cases))->toBe(plaquesCle([['x' => 0, 'y' => 0], ['x' => 1, 'y' => 0], ['x' => 0, 'y' => 1], ['x' => 1, 'y' => 1]]));
            } else {
                $motifs++;
                expect(in_array(plaquesCle($cases), $vocabulaire, true))
                    ->toBeTrue("{$groupe} : forme absente du vocabulaire (graine {$graine})");
            }

            // Une seule salle, et jamais la salle de départ ; aucune case hors de toute salle.
            $salles = array_map(fn (array $c) => plaquesSalleDe($carte['salles'], $c), $cases);
            expect(in_array(null, $salles, true))->toBeFalse("{$groupe} hors de toute salle (graine {$graine})");
            expect(count(array_unique($salles)))->toBe(1, "{$groupe} à cheval sur deux salles (graine {$graine})");
            expect($salles[0])->not->toBe(0, "{$groupe} posé dans la salle de départ");

            foreach ($cases as $case) {
                expect(isset($parvis["{$case['x']},{$case['y']}"]))->toBeFalse("{$groupe} posé sur un parvis (graine {$graine})");
            }
        }
    }

    expect($blocs)->toBeGreaterThan(0, 'aucun bloc 2×2 sur 40 cartes de Delthrak : le test serait vide')
        ->and($motifs)->toBeGreaterThan(0, 'aucune plaque de motif sur 40 cartes de Delthrak : le test serait vide');
});

it('la pose est DÉTERMINISTE : deux donjons de même graine portent les mêmes plaques, au même endroit', function () {
    $gabarit = GabaritQuete::query()->where('type_jalon', 'normale')->firstOrFail();
    $bestiaire = BestiaireGroupe::auto('jungles_delthrak');

    $premier = app(AssembleurCarte::class)->assembler($gabarit, 7, bestiaire: $bestiaire)['terrain'];
    $second = app(AssembleurCarte::class)->assembler($gabarit, 7, bestiaire: $bestiaire)['terrain'];

    expect($second)->toBe($premier);
});

// =====================================================================
// 4. LE JEU — le brasier compté par plaque
// =====================================================================

it('un BRASIER 2×2 traversé pendant un déplacement lance UN SEUL dé : par plaque, pas par case du bloc', function () {
    // Le héros traverse (2,0) puis (3,0) : deux cases d'UN même bloc. Un seul jet, donc un seul crâne possible.
    desFiges([1, 4, 4, 4, 4, 4]);
    $scene = plaquesScene(6, 2, ['x' => 0, 'y' => 0], plaquesBloc(2, 0, 'Brasier', 'bloc-1'));
    $pv = (int) $scene['heros']->pv_body;

    $payload = plaquesDeplacer($scene, 4, 0);

    expect($payload['terrain_jets'])->toHaveCount(1, 'deux cases d\'un même brasier = un seul jet')
        ->and($payload['terrain_jets'][0]['face'])->toBe('crane')
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pv - 1);
});

it('aucune des QUATRE cases d\'un brasier 2×2 n\'accepte un ARRÊT : on la traverse, on n\'y finit jamais son mouvement', function () {
    desFiges(array_fill(0, 40, 4));
    $scene = plaquesScene(6, 2, ['x' => 0, 'y' => 0], plaquesBloc(2, 0, 'Brasier', 'bloc-1'));
    $grille = FabriqueGrille::pour($scene['quete'], exceptPersonnageId: $scene['heros']->id);

    foreach ([[2, 0], [3, 0], [2, 1], [3, 1]] as [$x, $y]) {
        expect($grille->arretInterdit($x, $y))->toBeTrue("case {$x},{$y} : un arrêt y serait accepté")
            ->and($grille->estTraversable($x, $y))->toBeTrue("case {$x},{$y} : on ne peut pas la traverser");
    }

    expect($grille->casesAtteignables(0, 0, 10))->not->toHaveKeys(['2,0', '3,0', '2,1', '3,1']);

    // Le résolveur refuse la fin de mouvement sur une case du bloc, comme il refuse une Mare.
    expect(fn () => plaquesDeplacer($scene, 3, 0))->toThrow(ValidationException::class);
});

it('un MONSTRE qui traverse le même brasier ne lance aussi qu\'UN dé', function () {
    $scene = plaquesScene(8, 2, ['x' => 0, 'y' => 0], plaquesBloc(4, 0, 'Brasier', 'bloc-1'));

    $catalogue = Monstre::where('nom_base', 'Squelette')->firstOrFail();
    $instance = InstanceMonstre::create([
        'quete_id' => $scene['quete']->id, 'monstre_id' => $catalogue->id,
        'pv_body' => 3, 'pv_body_max' => 3, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => 7, 'position_y' => 0, 'etat' => 'actif', 'revele' => true,
    ]);

    desFiges(array_fill(0, 20, 1)); // crânes partout

    $methode = new ReflectionMethod(ResolveurTour::class, 'blesserMonstreSurLeChemin');
    $methode->setAccessible(true);
    $payload = $methode->invoke(
        app(ResolveurTour::class), $scene['groupe']->fresh(), $scene['quete']->fresh()->load('carte'), $instance,
        [['x' => 6, 'y' => 0], ['x' => 5, 'y' => 0], ['x' => 4, 'y' => 0], ['x' => 3, 'y' => 0]],
        ['type' => 'monstre', 'id' => $instance->id, 'nom' => 'Squelette'],
    );

    expect($payload['jets'])->toHaveCount(1, 'deux cases d\'un même brasier : un seul jet pour le monstre')
        ->and((int) $instance->fresh()->pv_body)->toBe(2);
});

it('deux brasiers DIFFÉRENTS traversés = deux jets ; un même trajet ne rejoue jamais la même plaque', function () {
    desFiges(array_fill(0, 20, 1));
    $scene = plaquesScene(7, 2, ['x' => 0, 'y' => 0], [...plaquesBloc(1, 0, 'Brasier', 'bloc-1'), ...plaquesBloc(4, 0, 'Brasier', 'bloc-2')]);

    // Les deux cases de chaque plaque, traversées en une seule marche : deux plaques, donc deux jets.
    $deux = plaquesSaigne($scene, [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0], ['x' => 3, 'y' => 0], ['x' => 4, 'y' => 0]]);
    expect($deux['jets'])->toHaveCount(2)
        ->and(array_column($deux['jets'], 'x'))->toBe([1, 4]);

    // Un trajet qui n'entre que dans la première plaque : un seul jet.
    $un = plaquesSaigne($scene, [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0]]);
    expect($un['jets'])->toHaveCount(1);
});

it('une carte ANCIENNE — brasier sans groupe_id, case par case — lance comme avant : un jet PAR case', function () {
    desFiges(array_fill(0, 20, 1));
    $ancien = [
        ['x' => 1, 'y' => 0, 'terrain_id' => plaquesIdTerrain('Brasier')],
        ['x' => 2, 'y' => 0, 'terrain_id' => plaquesIdTerrain('Brasier')],
    ];
    $scene = plaquesScene(5, 1, ['x' => 0, 'y' => 0], $ancien);

    $payload = plaquesSaigne($scene, [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0]]);

    expect($payload['jets'])->toHaveCount(2, 'sans groupe, chaque case est sa propre pose : comportement d\'avant');
});

// =====================================================================
// 5. LA STATUE — un meuble 1×1 qui bloque la vue et le passage
// =====================================================================

it('la STATUE bloque la vue ET le passage, et n\'est ni fouillable ni destructible (aucune source ne le dit)', function () {
    $statue = Mobilier::where('nom', 'Statue')->firstOrFail();

    expect([$statue->bloque_vue, $statue->bloque_mouvement, $statue->fouillable, $statue->difficulte_destruction,
        $statue->pv_body, $statue->largeur, $statue->hauteur, $statue->boite])
        ->toBe([true, true, false, null, null, 1, 1, 'jungles_delthrak']);

    desFiges(array_fill(0, 40, 4));
    $scene = plaquesScene(6, 1, ['x' => 0, 'y' => 0], [], [
        ['mobilier_id' => $statue->id, 'nom' => 'Statue', 'x' => 3, 'y' => 0, 'l' => 1, 'h' => 1, 'salle' => 0],
    ]);
    $grille = FabriqueGrille::pour($scene['quete'], exceptPersonnageId: $scene['heros']->id);

    expect($grille->ligneDeVue(0, 0, 5, 0))->toBeFalse('la statue doit couper la ligne de vue')
        ->and($grille->estTraversable(3, 0))->toBeFalse('la statue doit barrer le passage')
        ->and($grille->casesAtteignables(0, 0, 10))->not->toHaveKeys(['3,0', '4,0']);
});

it('la STATUE ne sort que sous le thème jungles_delthrak', function () {
    $statue = (int) Mobilier::where('nom', 'Statue')->value('id');
    $gabarit = GabaritQuete::query()->where('type_jalon', 'normale')->firstOrFail();

    $sousJungle = 0;
    $sousMage = 0;

    for ($graine = 1; $graine <= 60; $graine++) {
        $sousJungle += collect(app(AssembleurCarte::class)->assembler(
            $gabarit, $graine, bestiaire: BestiaireGroupe::auto('jungles_delthrak'),
        )['mobilier'])->contains('mobilier_id', $statue) ? 1 : 0;

        $sousMage += collect(app(AssembleurCarte::class)->assembler(
            $gabarit, $graine, bestiaire: BestiaireGroupe::auto('mage_du_miroir'),
        )['mobilier'])->contains('mobilier_id', $statue) ? 1 : 0;
    }

    expect($sousJungle)->toBeGreaterThan(0, 'la statue ne sort jamais sous son thème')
        ->and($sousMage)->toBe(0, 'la statue sort hors de son thème');
});

// =====================================================================
// 6. LA PUBLICATION — groupe, ancre, brouillard
// =====================================================================

it('publie groupe_id et UNE ancre par groupe, ne publie que les cases sous un brouillard levé', function () {
    $mare = plaquesIdTerrain('Mare');

    $carte = new Carte(['grille' => ['terrain' => [
        ['x' => 1, 'y' => 1, 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => 2, 'y' => 1, 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => 1, 'y' => 2, 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => 2, 'y' => 2, 'terrain_id' => $mare, 'groupe_id' => 'bloc-1'],
        ['x' => 5, 'y' => 5, 'terrain_id' => $mare, 'groupe_id' => 'bloc-2'], // masquée : ni publiée, ni ancrée
        ['x' => 0, 'y' => 0, 'terrain_id' => $mare],                          // case isolée, sans groupe
    ]]]);

    $cases = array_fill(0, 6, array_fill(0, 6, 's'));
    $cases[5][5] = 'b';

    $methode = new ReflectionMethod(EtatGroupe::class, 'terrain');
    $methode->setAccessible(true);
    $publies = $methode->invoke(app(EtatGroupe::class), $carte, $cases);

    $bloc1 = array_values(array_filter($publies, fn (array $e) => $e['groupe_id'] === 'bloc-1'));

    expect($bloc1)->toHaveCount(4)
        ->and(array_values(array_filter($bloc1, fn (array $e) => $e['ancre'])))->toHaveCount(1, 'un bloc = UNE icône');

    expect(array_values(array_filter($publies, fn (array $e) => $e['groupe_id'] === 'bloc-2')))->toBe([], 'la plaque masquée fuit');

    $isolee = array_values(array_filter($publies, fn (array $e) => $e['groupe_id'] === null));
    expect($isolee)->toHaveCount(1)
        ->and($isolee[0]['ancre'])->toBeFalse('une case isolée ne porte pas d\'icône de plaque');
});

it('désigne l\'ancre au centre de la boîte du groupe, et la première case à égalité', function () {
    $methode = new ReflectionMethod(EtatGroupe::class, 'marquerAncresDePlaques');
    $methode->setAccessible(true);

    // Quatre cases en ligne : le centre est (3.5, 3) — (3,3) et (4,3) sont à égalité, la première de la liste gagne.
    $bande = [];

    foreach ([2, 3, 4, 5] as $x) {
        $bande[] = ['x' => $x, 'y' => 3, 'groupe_id' => 'motif-1', 'ancre' => false];
    }

    $sortie = $methode->invoke(app(EtatGroupe::class), $bande);

    expect(array_column(array_filter($sortie, fn (array $e) => $e['ancre']), 'x'))->toBe([3]);
});
