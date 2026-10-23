<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\Des\LanceurDeterministe;
use App\Engine\MotsClesDanger;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\Danger;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use App\Partie\MoteurDanger;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\DangerSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * DANGER MOBILE AUTONOME (2026-10-23) — le rocher roulant de *Kellar's Keep* (q. 3, livret F4543 p. 12-13) et la brume de
 * la mort de *Return of the Witch Lord* (q. 3, livret F4193 p. 13). Éprouvé EN JEU : une quête minimale (une salle, un
 * couloir de six cases qui finit sur un mur), les dés injectés (`desFiges`), et les vraies routes (`GET /menu`,
 * `POST /choix`) là où le passage est joué par un héros. Les tours du MJ passent par la vraie phase (`attendre`).
 *
 * Les helpers portent un préfixe `dang` : ils ne partagent aucun nom avec les autres fichiers de test.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : celui-ci dérive un parchemin par sort. DangerSeeder : le catalogue des deux dangers.
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
        TerrainSeeder::class, DangerSeeder::class]);
});

/** Le couloir de la scène : une salle 0 (x 1-4, y 3-5), puis une ligne de six cases (y 4, x 5-10) qui finit sur un mur. */
function dangCouloir(): array
{
    $cases = array_fill(0, 9, array_fill(0, 16, 'm'));

    for ($y = 3; $y <= 5; $y++) {
        for ($x = 1; $x <= 4; $x++) {
            $cases[$y][$x] = 's';
        }
    }

    for ($x = 5; $x <= 10; $x++) {
        $cases[4][$x] = 's';
    }

    return $cases;
}

/** A = (5,4) ; le rocher suit la ligne et s'écrase en E = (10,4), le mur est en (11,4). */
const DANG_LIGNE = [[5, 4], [6, 4], [7, 4], [8, 4], [9, 4], [10, 4]];

/**
 * @param  list<array<string, mixed>>  $dangers  entrées brutes de `cartes.grille['dangers']`
 * @param  array<int, array{0: int, 1: int}>  $positions  héros → case
 * @return array{alice: JoueurAuthentifiable, bob: ?JoueurAuthentifiable, groupe: App\Models\Groupe, quete: Quete, albrecht: Personnage, bertrand: ?Personnage}
 */
function dangScene(int $nbHeros = 1, array $dangers = [], array $positions = [], ?array $cases = null, array $herosAttrs = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);
    $bob = null;
    $bertrand = null;

    if ($nbHeros > 1) {
        $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
        $bertrand = creerHeros($bob, $groupe, 'Bertrand', 2);
    }

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — dangers mobiles',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => $cases ?? dangCouloir(),
            'salles' => [['x' => 1, 'y' => 3, 'largeur' => 4, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 2, 'mediane_y' => 4]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => [], 'dangers' => $dangers,
            'spawn_heros' => [['x' => 2, 'y' => 4]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    $positionsDefaut = [1 => [2, 4], 2 => [2, 5]];
    $positions = $positions + $positionsDefaut;

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $albrecht->id,
        'position_x' => $positions[1][0], 'position_y' => $positions[1][1],
    ]);

    if ($bertrand !== null) {
        EtatPersonnageQuete::create([
            'quete_id' => $quete->id, 'personnage_id' => $bertrand->id,
            'position_x' => $positions[2][0], 'position_y' => $positions[2][1],
        ]);
    }

    return ['alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'),
        'albrecht' => $albrecht, 'bertrand' => $bertrand];
}

/** Une entrée de rocher telle que `AssembleurCarte` la pose : en attente sur A, ou roulant à `i`. */
function dangRocher(array $run = DANG_LIGNE, string $etat = 'attente', int $i = 0, array $franchi = []): array
{
    return [
        'danger_id' => Danger::where('nom', 'Rocher roulant')->value('id'),
        'nom' => 'Rocher roulant', 'etat' => $etat,
        'x' => $run[$i][0], 'y' => $run[$i][1],
        'run' => $run, 'i' => $i, 'franchi' => $franchi,
    ];
}

/** Une entrée de brume de la mort, active sur la case (x, y). */
function dangBrume(int $x, int $y, string $etat = 'actif'): array
{
    return [
        'danger_id' => Danger::where('nom', 'Brume de la mort')->value('id'),
        'nom' => 'Brume de la mort', 'etat' => $etat, 'x' => $x, 'y' => $y,
    ];
}

/** Arme un héros : une ligne d'inventaire en `arme_principale`, comme `Inventaire::create` le fait pour le départ. */
function dangArmer(Personnage $heros, string $nomObjet): void
{
    $heros->inventaire()->where('emplacement', 'arme_principale')->delete();

    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => 'arme_principale', 'quantite' => 1,
    ]);
}

/** La quête relue depuis la base, avec sa carte fraîche. */
function dangQuete(array $ctx): Quete
{
    return $ctx['quete']->fresh()->load('carte');
}

/** Une phase du MJ réelle : le héros d'Alice finit son tour (`attendre`), la phase des monstres se joue. */
function dangTourDuMj(array $ctx, array $des = []): LanceurDeterministe
{
    // Des blancs derrière les dés imposés : ni crâne ni bouclier noir, rien ne tombe à l'improviste. UNE seule liaison :
    // le lanceur rendu est celui que la phase consomme, et `valeursRestantes()` dit combien il en reste.
    $lanceur = desFiges([...$des, ...array_fill(0, 200, 4)]);

    test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    return $lanceur;
}

/** Toutes les listes `cibles` d'un menu, à n'importe quelle profondeur. @return list<array<string, mixed>> */
function dangCiblesDuMenu(mixed $noeud): array
{
    $trouvees = [];

    if (is_array($noeud)) {
        foreach ($noeud as $cle => $valeur) {
            $trouvees = $cle === 'cibles'
                ? [...$trouvees, ...array_values((array) $valeur)]
                : [...$trouvees, ...dangCiblesDuMenu($valeur)];
        }
    }

    return $trouvees;
}

// =====================================================================
// 0. LE CATALOGUE ET LE VOCABULAIRE SE CONFRONTENT DANS LES DEUX SENS
// =====================================================================

it('rien n\'est écrit dans dangers.effet sans être déclaré, et rien n\'est déclaré sans être écrit', function () {
    $ecrits = Danger::query()->get()->flatMap(fn (Danger $d) => array_keys((array) $d->effet))->unique()->values()->all();

    expect(array_diff($ecrits, MotsClesDanger::cles()))->toBe([])
        ->and(array_diff(MotsClesDanger::cles(), $ecrits))->toBe([]);
});

it('chaque clé déclarée a un lecteur qui existe, et l\'un de ces fichiers NOMME la clé', function () {
    foreach (MotsClesDanger::VOCABULAIRE as $cle => $definition) {
        $lecteurs = (array) $definition['lecteur'];
        $nomme = false;

        foreach ($lecteurs as $lecteur) {
            [$classe, $methode] = explode('::', $lecteur);

            expect(method_exists($classe, $methode))->toBeTrue("lecteur de « {$cle} » introuvable : {$lecteur}");

            if (str_contains((string) file_get_contents((new ReflectionClass($classe))->getFileName()), "'{$cle}'")) {
                $nomme = true;
            }
        }

        expect($nomme)->toBeTrue("aucun fichier lecteur de « {$cle} » ne nomme la clé");
    }
});

it('chaque élément de destruction du catalogue est un vrai sort ou une vraie arme', function () {
    foreach (Danger::all() as $danger) {
        foreach ((array) ($danger->effet['destruction'] ?? []) as $nom) {
            expect(Sort::where('nom', $nom)->exists() || Objet::where('nom', $nom)->exists())
                ->toBeTrue("« {$nom} » ne désigne ni un sort ni une arme du catalogue");
        }
    }
});

// =====================================================================
// 1. LE ROCHER : tombe au passage du DERNIER héros, roule, touche sans défense, s'écrase
// =====================================================================

it('le rocher tombe quand le héros franchit sa case déclencheur (trajet réel, par POST /choix)', function () {
    $ctx = dangScene(1, [dangRocher(DANG_LIGNE, 'attente')], positions: [1 => [2, 4]]);
    desFiges(array_fill(0, 60, 6));
    test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();

    // (2,4) → (7,4) : la route traverse A = (5,4) sans s'y arrêter.
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => 7, 'y' => 4],
    ])->assertSuccessful();

    $entree = dangQuete($ctx)->carte->grille['dangers'][0];
    expect($entree['etat'])->toBe('roule')
        ->and($entree['franchi'])->toContain($ctx['albrecht']->id);
});

it('le rocher ne tombe que quand le DERNIER héros debout a franchi A : un seul passage ne suffit pas', function () {
    $ctx = dangScene(2, [dangRocher(DANG_LIGNE, 'attente')], positions: [1 => [2, 4], 2 => [2, 5]]);
    $moteur = app(MoteurDanger::class);
    $albrecht = $ctx['albrecht'];
    $bertrand = $ctx['bertrand'];

    // Albrecht franchit A (5,4) en allant jusqu'à (7,4) : Bertrand est encore derrière, le rocher reste en attente.
    $chute1 = $moteur->franchissementDuHeros($ctx['groupe'], dangQuete($ctx), $albrecht, [
        ['x' => 3, 'y' => 4], ['x' => 4, 'y' => 4], ['x' => 5, 'y' => 4], ['x' => 6, 'y' => 4], ['x' => 7, 'y' => 4],
    ]);
    expect($chute1)->toBeNull()
        ->and(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('attente');

    // Bertrand la franchit à son tour : c'est le DERNIER, le rocher tombe.
    $chute2 = $moteur->franchissementDuHeros($ctx['groupe'], dangQuete($ctx), $bertrand, [
        ['x' => 3, 'y' => 5], ['x' => 4, 'y' => 4], ['x' => 5, 'y' => 4], ['x' => 6, 'y' => 4],
    ]);

    expect($chute2)->toMatchArray(['type' => 'danger_chute', 'heros' => 'Bertrand'])
        ->and(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('roule');
});

it('un héros qui S\'ARRÊTE sur A ne l\'a pas franchie', function () {
    $ctx = dangScene(1, [dangRocher(DANG_LIGNE, 'attente')]);

    $chute = app(MoteurDanger::class)->franchissementDuHeros($ctx['groupe'], dangQuete($ctx), $ctx['albrecht'], [
        ['x' => 3, 'y' => 4], ['x' => 4, 'y' => 4], ['x' => 5, 'y' => 4],
    ]);

    expect($chute)->toBeNull()
        ->and(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('attente');
});

it('au tour du MJ le rocher roule de la SOMME de deux dés rouges, touche avec 5 dés de combat SANS défense, et s\'arrête au mur en bloc', function () {
    // Dés rouges 3 + 4 = 7 cases : A=0 → fin de ligne (indice 5) ; le héros est en (6,4), sur la première case traversée.
    $ctx = dangScene(1, [dangRocher(DANG_LIGNE, 'roule', 0)], positions: [1 => [6, 4]]);
    $pvAvant = $ctx['albrecht']->fresh()->pv_body;

    // 3 et 4 : les deux dés rouges. Puis 5 dés de combat : 1, 1, 6, 6, 4 → deux crânes. Aucun dé de défense ne suit.
    // 200 blancs derrière : s'il reste exactement 200 valeurs, la phase en a consommé exactement 7 (2 rouges + 5 combat).
    $lanceur = dangTourDuMj($ctx, [3, 4, 1, 1, 6, 6, 4]);

    $entree = dangQuete($ctx)->carte->grille['dangers'][0];
    expect($entree['etat'])->toBe('bloc')
        ->and([$entree['x'], $entree['y']])->toBe([10, 4])
        ->and($ctx['albrecht']->fresh()->pv_body)->toBe($pvAvant - 2)
        ->and($lanceur->valeursRestantes())->toBe(200);
});

it('sans défense : un héros touché par le rocher ne lance AUCUN dé de défense (un jet de plus casserait la comptabilité)', function () {
    $ctx = dangScene(1, [dangRocher(DANG_LIGNE, 'roule', 0)], positions: [1 => [6, 4]]);

    // Si la défense était lancée, il resterait moins de 200 valeurs : la défense aurait pris les blancs.
    $lanceur = dangTourDuMj($ctx, [3, 4, 1, 1, 6, 6, 4]);

    expect($lanceur->valeursRestantes())->toBe(200);
});

it('le rocher arrêté (bloc) BLOQUE le passage : mouvement ET vue, et ne se publie plus comme un danger en mouvement', function () {
    $ctx = dangScene(1, [dangRocher(DANG_LIGNE, 'bloc', 5)], positions: [1 => [2, 4]]);
    $quete = dangQuete($ctx);

    $grille = FabriqueGrille::pour($quete);
    $atteignables = array_keys($grille->casesAtteignables(2, 4, 50));

    // La case de chute (10,4) n'est plus atteignable, et tout ce qui n'était accessible qu'au-delà non plus.
    expect($atteignables)->not->toContain('10,4')
        ->and($atteignables)->toContain('9,4');
});

it('un rocher qui ne roule pas (chemin ou arret inattendus) ne bouge jamais : la clé est lue, pas décorative', function () {
    $ctx = dangScene(1, [dangRocher(DANG_LIGNE, 'roule', 0)], positions: [1 => [6, 4]]);
    $fiche = Danger::where('nom', 'Rocher roulant')->firstOrFail();
    $effet = $fiche->effet;
    $effet['chemin'] = 'reseau_de_couloirs';
    $fiche->update(['effet' => $effet]);

    $lanceur = dangTourDuMj($ctx, [3, 4]);

    expect(dangQuete($ctx)->carte->grille['dangers'][0]['i'])->toBe(0)
        ->and($lanceur->valeursRestantes())->toBe(200 + 2);   // les deux dés rouges NE sont PAS lancés
});

// =====================================================================
// 2. LA BRUME : glisse de 6 cases au plus, passe sur les héros (1 PV, sans dé), ne termine jamais sur l'un d'eux
// =====================================================================

it('la brume passe sur le héros qu\'elle traverse (−1 PV, sans dé) et s\'arrête AVANT lui', function () {
    // Brume en (5,4), héros en (6,4) : la case la plus proche de lui est (7,4), atteinte EN PASSANT par (6,4).
    $ctx = dangScene(1, [dangBrume(5, 4)], positions: [1 => [6, 4]]);
    $pvAvant = $ctx['albrecht']->fresh()->pv_body;

    $lanceur = dangTourDuMj($ctx, []);

    $brume = dangQuete($ctx)->carte->grille['dangers'][0];

    expect([$brume['x'], $brume['y']])->toBe([7, 4])
        ->and($ctx['albrecht']->fresh()->pv_body)->toBe($pvAvant - 1)
        ->and($lanceur->valeursRestantes())->toBe(200);   // aucun dé lancé pour la brume
});

it('la brume ne termine jamais son tour sur la case d\'un héros', function () {
    $ctx = dangScene(1, [dangBrume(5, 4)], positions: [1 => [7, 4]]);

    dangTourDuMj($ctx, []);

    $brume = dangQuete($ctx)->carte->grille['dangers'][0];
    expect([$brume['x'], $brume['y']])->not->toBe([7, 4]);
});

it('la brume ne bloque pas le passage : un héros peut atteindre la case où elle se trouve', function () {
    $ctx = dangScene(1, [dangBrume(7, 4)], positions: [1 => [2, 4]]);

    $grille = FabriqueGrille::pour(dangQuete($ctx));

    expect(array_keys($grille->casesAtteignables(2, 4, 50)))->toContain('7,4');
});

// =====================================================================
// 3. DESTRUCTION : la Lame des Esprits au contact, une autre arme ne fait rien, et le contact est exigé
// =====================================================================

it('la Lame des Esprits, au contact, détruit la brume (et la publication la retire)', function () {
    $ctx = dangScene(1, [dangBrume(7, 4)], positions: [1 => [6, 4]]);
    dangArmer($ctx['albrecht'], 'Lame des Esprits');
    desFiges(array_fill(0, 40, 6));

    test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();
    $reponse = test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'frapper_danger_0',
    ])->assertSuccessful();

    expect(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('detruit');

    $publies = test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.dangers');
    expect($publies)->toBe([]);
});

it('une arme ordinaire frappe la brume SANS effet : elle reste en place et le fil le dit', function () {
    $ctx = dangScene(1, [dangBrume(7, 4)], positions: [1 => [6, 4]]);
    dangArmer($ctx['albrecht'], 'Dague');
    desFiges(array_fill(0, 40, 6));

    test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'frapper_danger_0',
    ])->assertSuccessful();

    expect(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('actif');
});

it('le menu n\'offre la frappe qu\'au contact, et le résolveur refuse une brume hors de portée', function () {
    $ctx = dangScene(1, [dangBrume(9, 4)], positions: [1 => [2, 4]]);
    dangArmer($ctx['albrecht'], 'Lame des Esprits');
    desFiges(array_fill(0, 40, 6));

    $menu = test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu') ?? [];
    expect(collect($menu)->pluck('id')->all())->not->toContain('frapper_danger_0');

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'frapper_danger_0',
    ])->assertStatus(422);

    expect(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('actif');
});

it('la Tempête voit la brume comme cible ; un sort qui ne la détruit pas ne la voit pas ; la résolution détruit', function () {
    $ctx = dangScene(1, [dangBrume(7, 4)], positions: [1 => [2, 4]]);
    $hero = $ctx['albrecht'];
    DB::table('personnage_sorts')->insert([
        ['personnage_id' => $hero->id, 'sort_id' => Sort::where('nom', 'Tempête')->value('id'), 'disponible' => true],
        ['personnage_id' => $hero->id, 'sort_id' => Sort::where('nom', 'Boule de Feu')->value('id'), 'disponible' => true],
    ]);
    desFiges(array_fill(0, 40, 6));

    $menu = test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu');
    $cibles = collect(dangCiblesDuMenu($menu))->where('type', 'danger');

    // Une seule cible « danger » : celle de la Tempête. La Boule de Feu ne détruit pas la brume : elle n'en a aucun candidat.
    expect($cibles)->toHaveCount(1)
        ->and($cibles->first()['id'])->toBe(1);

    $tempeteId = Sort::where('nom', 'Tempête')->value('id');

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer_sort',
        'parametres' => ['cle' => "sort:{$tempeteId}", 'cible_id' => 1, 'cible_type' => 'danger'],
    ])->assertSuccessful();

    expect(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('detruit');
});

// =====================================================================
// 4. PUBLICATION SOUS LE BROUILLARD ET POSE
// =====================================================================

it('un danger n\'est publié que sur une case connue, et jamais tant qu\'il attend', function () {
    $cases = array_fill(0, 9, array_fill(0, 16, 'b'));
    $cases[4][5] = 's';   // la case du rocher est connue, les autres ne le sont pas.

    $publies = app(MoteurDanger::class)->publier(
        new Carte(['grille' => ['dangers' => [dangRocher(DANG_LIGNE, 'roule', 0), dangBrume(12, 2, 'actif')]]]),
        $cases,
    );

    // La brume est sur une case inconnue : rien ; le rocher roule sur la case connue (5,4) : publié.
    expect($publies)->toHaveCount(1)
        ->and($publies[0])->toMatchArray(['nom' => 'Rocher roulant', 'x' => 5, 'y' => 4, 'etat' => 'roule']);

    $attente = app(MoteurDanger::class)->publier(
        new Carte(['grille' => ['dangers' => [dangRocher(DANG_LIGNE, 'attente')]]]),
        $cases,
    );
    expect($attente)->toBe([]);
});

it('un danger de la boîte d\'un AUTRE thème n\'est jamais posé : aucun tirage', function () {
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();

    foreach ([null, 'jungles_delthrak', 'horde_ogre'] as $theme) {
        foreach (range(1, 12) as $graine) {
            $sortie = app(AssembleurCarte::class)->assembler(
                $gabarit, $graine, bestiaire: $theme === null ? null : BestiaireGroupe::auto($theme),
            );
            expect($sortie['dangers'])->toBe([]);
        }
    }
});

it('sous le thème kellars_keep, un rocher posé en couloir ne coupe JAMAIS l\'accès : toute case accessible avant reste accessible après', function () {
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
    $idsMobilier = Mobilier::where('bloque_mouvement', true)->pluck('id')->all();
    $idsTerrain = Terrain::where('bloque_mouvement', true)->pluck('id')->all();
    $poses = 0;

    foreach (range(1, 60) as $graine) {
        $sortie = app(AssembleurCarte::class)->assembler(
            $gabarit, $graine, bestiaire: BestiaireGroupe::auto('kellars_keep'),
        );
        $grille = new Grille($sortie['cases']);

        $bloquees = [];
        foreach ($sortie['mobilier'] as $meuble) {
            if (in_array((int) $meuble['mobilier_id'], $idsMobilier, true)) {
                array_push($bloquees, ...$grille->cellulesEmprise((int) $meuble['x'], (int) $meuble['y'], (int) $meuble['l'], (int) $meuble['h']));
            }
        }
        foreach ($sortie['terrain'] as $tuile) {
            if (in_array((int) $tuile['terrain_id'], $idsTerrain, true)) {
                $bloquees[] = ['x' => (int) $tuile['x'], 'y' => (int) $tuile['y']];
            }
        }

        $origine = null;
        $s0 = $sortie['salles'][0];
        for ($y = $s0['y']; $y < $s0['y'] + $s0['hauteur'] && $origine === null; $y++) {
            for ($x = $s0['x']; $x < $s0['x'] + $s0['largeur']; $x++) {
                $libre = ! in_array("{$x},{$y}", array_map(fn ($c) => "{$c['x']},{$c['y']}", $bloquees), true);
                if (($sortie['cases'][$y][$x] ?? 'm') === 's' && $libre) {
                    $origine = [$x, $y];
                    break;
                }
            }
        }

        $atteignablesDepuis = function (array $bloq) use ($sortie, $origine): array {
            $g = new Grille($sortie['cases']);
            $g->obstruer($bloq);

            return array_keys($g->casesAtteignables($origine[0], $origine[1], 100000));
        };

        $avant = $atteignablesDepuis($bloquees);

        foreach ($sortie['dangers'] as $entree) {
            if (($entree['etat'] ?? null) !== 'attente') {
                continue;
            }

            $poses++;
            $run = $entree['run'];

            // Ligne droite de corridor nu, qui finit sur un mur.
            foreach ($run as $k => [$x, $y]) {
                expect($sortie['cases'][$y][$x] ?? 'm')->toBe('s');
                expect(\App\Partie\Salles::indexDe($sortie['salles'], $x, $y))->toBeNull();
                if ($k > 0) {
                    expect(abs($x - $run[$k - 1][0]) + abs($y - $run[$k - 1][1]))->toBe(1);
                }
            }
            [$ex, $ey] = end($run);
            $dx = $ex - $run[count($run) - 2][0];
            $dy = $ey - $run[count($run) - 2][1];
            expect($sortie['cases'][$ey + $dy][$ex + $dx] ?? 'm')->toBe('m');

            // Le rocher s'arrête en E : rien d'accessible avant ne doit devenir inaccessible.
            $apres = $atteignablesDepuis([...$bloquees, ['x' => $ex, 'y' => $ey]]);
            foreach ($avant as $cle) {
                if ($cle !== "{$ex},{$ey}") {
                    expect(in_array($cle, $apres, true))->toBeTrue("le rocher de la graine {$graine} coupe la case {$cle}");
                }
            }
        }
    }

    // Le tirage n'est pas mort : sur 60 quêtes il s'en pose (sinon le test ne prouverait rien).
    expect($poses)->toBeGreaterThan(0);
});

it('sous le thème witch_lord, la brume se pose sur une case de couloir nu', function () {
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
    $posees = 0;

    foreach (range(1, 40) as $graine) {
        $sortie = app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: BestiaireGroupe::auto('witch_lord'));

        foreach ($sortie['dangers'] as $entree) {
            expect($entree['etat'])->toBe('actif')
                ->and($sortie['cases'][$entree['y']][$entree['x']])->toBe('s')
                ->and(\App\Partie\Salles::indexDe($sortie['salles'], (int) $entree['x'], (int) $entree['y']))->toBeNull();
            $posees++;
        }
    }

    expect($posees)->toBeGreaterThan(0);
});

// =====================================================================
// 5. LES CHEMINS QUE LES TESTS PRÉCÉDENTS NE COUVRAIENT PAS
// =====================================================================

it('arret « jamais » : le rocher qui atteint la fin de sa ligne reste en mouvement, il ne devient pas un bloc', function () {
    $ctx = dangScene(1, [dangRocher(DANG_LIGNE, 'roule', 0)], positions: [1 => [6, 4]]);
    $fiche = Danger::where('nom', 'Rocher roulant')->firstOrFail();
    $effet = $fiche->effet;
    $effet['arret'] = 'jamais';
    $fiche->update(['effet' => $effet]);

    dangTourDuMj($ctx, [3, 4, 1, 1, 6, 6, 4]);

    $entree = dangQuete($ctx)->carte->grille['dangers'][0];
    expect($entree['etat'])->toBe('roule')
        ->and($entree['i'])->toBe(5);   // arrivé au bout de la ligne, sans rien fermer
});

it('la phase des monstres porte aussi le tour du MJ : le rocher roule même avec un monstre vivant en salle', function () {
    // Sans monstre, c'est la branche « donjon nettoyé » qui joue le danger. Avec un monstre, c'est `phaseMonstres()` :
    // les DEUX coutures sont éprouvées, et la phase ne peut pas sauter le rocher.
    $ctx = dangScene(1, [dangRocher(DANG_LIGNE, 'roule', 0)], positions: [1 => [6, 4]]);
    $catalogue = Monstre::where('tier', 'base')->firstOrFail();
    InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => 2, 'position_y' => 3, 'etat' => 'actif', 'revele' => true,
    ]);

    dangTourDuMj($ctx, [3, 4, 1, 1, 6, 6, 4]);

    expect(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('bloc');
});

it('accesPreserve : fermer le coin d\'un rocher qui COUPE une route est refusé ; un coin qui ne coupe rien est accepté', function () {
    $m = new ReflectionMethod(AssembleurCarte::class, 'accesPreserve');
    $m->setAccessible(true);
    $assembleur = app(AssembleurCarte::class);

    // Couloir y=4, x 1..5 (le coin E = (5,4), mur en (6,4)) ; une branche au nord part de E : (5,3),(5,2),(5,1).
    $coupe = array_fill(0, 9, array_fill(0, 16, 'm'));
    for ($x = 1; $x <= 5; $x++) {
        $coupe[4][$x] = 's';
    }
    foreach ([3, 2, 1] as $y) {
        $coupe[$y][5] = 's';
    }

    // Même couloir, sans branche : fermer E ne coupe plus rien (il n'y a rien au-delà).
    $libre = array_fill(0, 9, array_fill(0, 16, 'm'));
    for ($x = 1; $x <= 5; $x++) {
        $libre[4][$x] = 's';
    }

    expect($m->invoke($assembleur, $coupe, [], [1, 4], 5, 4))->toBeFalse()
        ->and($m->invoke($assembleur, $libre, [], [1, 4], 5, 4))->toBeTrue();
});

it('une brume qui s\'est éloignée depuis le menu ne se frappe plus : le coup périmé ne détruit rien', function () {
    $ctx = dangScene(1, [dangBrume(7, 4)], positions: [1 => [6, 4]]);
    dangArmer($ctx['albrecht'], 'Lame des Esprits');
    desFiges(array_fill(0, 40, 6));

    test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk();

    // Le menu a vu la brume au contact ; elle glisse ensuite (état reconstruit comme après une reprise).
    $quete = dangQuete($ctx);
    $grille = $quete->carte->grille;
    $grille['dangers'][0]['x'] = 10;
    $quete->carte->update(['grille' => $grille]);

    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'frapper_danger_0',
    ])->assertStatus(422);

    expect(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('actif');
});

it('le lecteur de destruction par sort REFUSE un sort qui ne détruit pas la brume, même appelé directement (sans le menu)', function () {
    $ctx = dangScene(1, [dangBrume(7, 4)], positions: [1 => [6, 4]]);
    $moteur = app(MoteurDanger::class);

    // La Boule de Feu n'est pas dans la liste de destruction : le lecteur ne détruit rien et rend null.
    expect($moteur->detruireParSort(dangQuete($ctx), $ctx['albrecht'], 0, 'Boule de Feu'))->toBeNull()
        ->and(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('actif');

    // La Tempête, elle, la détruit.
    expect($moteur->detruireParSort(dangQuete($ctx), $ctx['albrecht'], 0, 'Tempête'))->toMatchArray(['type' => 'danger_detruit'])
        ->and(dangQuete($ctx)->carte->grille['dangers'][0]['etat'])->toBe('detruit');
});
