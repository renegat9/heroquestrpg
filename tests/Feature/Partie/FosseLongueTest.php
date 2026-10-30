<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\GabaritQuete;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\Grille;
use App\Partie\MoteurFosseLongue;
use App\Partie\MoteurPieges;
use App\Partie\MoteurSorts;
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
use Illuminate\Support\Facades\Http;

/*
 * FOSSE LONGUE — *The Mage of the Mirror* (F7539 p. 6, « Long Pit Trap »), lot G (2026-10-29). Ce fichier joue la règle PAR LES
 * VRAIES ROUTES (`GET /menu`, `POST /choix`) : le saut par le bout offert à trois cases et pas en dessous, la réussite (bouclier
 * noir, la suite du mouvement reste), la chute (2 PV, le héros reste dans le trou), la sortie sur dé rouge, le malus d'un dé de
 * combat en attaque et en défense (jamais sous un), le saut court refusé quand il retomberait dans la même fosse — et la pose
 * dans l'assembleur, sous le thème seulement.
 *
 * Scène à la main : une carte 7×7 toute en sol. Fosse longue en (3,3)–(4,3) (horizontale, `groupe_id` commun), découverte.
 * Albrecht attend en (2,3), au bout ouest ; Bertrand, d'un autre joueur, garde le round ouvert en (6,6).
 *
 * Dés : `desFiges` sert la file dans l'ordre — un d6 vaut 6 = bouclier noir, 4-5 = bouclier blanc, 1-3 = crâne (un dé de combat)
 * et, pour le dé rouge de sortie, la valeur elle-même.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

function idFosseLongue(): int
{
    return (int) Piege::where('nom', 'Fosse longue')->firstOrFail()->id;
}

/**
 * Une scène : Albrecht en `$pos`, la fosse longue en (3,3)–(4,3) dans l'état `$etatPiege`, le déplacement restant `$restant`.
 * `$dansLaFosse` : Albrecht est déjà TOMBÉ dans la fosse, sur (3,3).
 *
 * @return array{alice: JoueurAuthentifiable, groupe: App\Models\Groupe, quete: Quete, albrecht: Personnage, bertrand: Personnage}
 */
function fosseScene(array $attrs = [], int $restant = 6, string $etatPiege = 'detecte', array $pos = [2, 3], bool $dansLaFosse = false): array
{
    $alice = connecterJoueur('alice');
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, $attrs);
    $bertrand = creerHeros($bob, $groupe, 'Bertrand', 2);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — fosse longue',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $piege = idFosseLongue();
    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 7, 'hauteur' => 7,
        'grille' => [
            'largeur' => 7, 'hauteur' => 7,
            'cases' => array_fill(0, 7, array_fill(0, 7, 's')),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7,
                'theme' => 'generique', 'mediane_x' => 3, 'mediane_y' => 3]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [
                ['x' => 3, 'y' => 3, 'piege_id' => $piege, 'etat' => $etatPiege, 'groupe_id' => 'fosse-longue-1'],
                ['x' => 4, 'y' => 3, 'piege_id' => $piege, 'etat' => $etatPiege, 'groupe_id' => 'fosse-longue-1'],
            ],
            'mobilier' => [], 'terrain' => [],
            'spawn_heros' => [['x' => 2, 'y' => 3]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $albrecht->id,
        'position_x' => $dansLaFosse ? 3 : $pos[0], 'position_y' => $dansLaFosse ? 3 : $pos[1],
        'deplacement_tour' => 6, 'deplacement_restant' => $restant,
        'dans_la_fosse' => $dansLaFosse ? ['x' => 3, 'y' => 3, 'piege_id' => $piege] : null,
    ]);
    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $bertrand->id, 'position_x' => 6, 'position_y' => 6,
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'quete' => $quete->fresh(), 'albrecht' => $albrecht, 'bertrand' => $bertrand];
}

function fosseEtat(array $ctx, string $qui = 'albrecht'): EtatPersonnageQuete
{
    return EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $ctx[$qui]->id)->firstOrFail();
}

/** Le menu publié par la VRAIE route de rattrapage. */
function fosseMenu(): array
{
    return test()->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu') ?? [];
}

function fosseOption(array $menu, string $id): ?array
{
    return collect($menu['options'] ?? [])->firstWhere('id', $id);
}

function fosseChoisir(string $option)
{
    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => $option, 'parametres' => []]);
}

/** Le dé de combat, ou le d6 de sortie : une valeur figée, puis des 4 (bouclier blanc) sans effet. */
function fosseDes(int $valeur): void
{
    desFiges([$valeur, ...array_fill(0, 80, 4)]);
}

/** Cases de la carte, lues en base après la résolution (la carte est rechargée, pas la copie du test). */
function fosseCarte(array $ctx): array
{
    return Carte::where('quete_id', $ctx['quete']->id)->firstOrFail()->grille;
}

// =====================================================================
// 1. LA POSE — sous le thème seulement, en salle hors départ, sur deux cases liées
// =====================================================================

it('ne pose une fosse longue QUE sous le thème Mage du Miroir, et jamais une case de porte ni de départ', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();
    $mage = BestiaireGroupe::manuel(['mage_du_miroir']);
    $pasMage = BestiaireGroupe::manuel(['horde_ogre']);

    $poses = 0;

    foreach (range(1, 60) as $i) {
        $graine = $i * 7919;

        // Hors du thème : aucune entrée à groupe, quelle que soit la graine.
        $horsTheme = $assembleur->assembler($gabarit, $graine, AssembleurCarte::CHANCE_PASSAGE_SECRET, $pasMage);
        expect(collect($horsTheme['pieges'])->contains(fn ($p) => isset($p['groupe_id'])))->toBeFalse();

        $carte = $assembleur->assembler($gabarit, $graine, AssembleurCarte::CHANCE_PASSAGE_SECRET, $mage);
        $fosses = collect($carte['pieges'])->filter(fn ($p) => isset($p['groupe_id']))->values();

        if ($fosses->isEmpty()) {
            continue;
        }

        $poses++;
        expect($fosses)->toHaveCount(2);

        [$a, $b] = [$fosses[0], $fosses[1]];
        expect($a['groupe_id'])->toBe($b['groupe_id'])
            ->and(abs($a['x'] - $b['x']) + abs($a['y'] - $b['y']))->toBe(1);

        // Dans UNE salle, hors départ, à l'intérieur de ses murs.
        $salleDe = fn (int $x, int $y): ?int => collect($carte['salles'])->search(fn ($s) => $x > $s['x'] && $x < $s['x'] + $s['largeur'] - 1
            && $y > $s['y'] && $y < $s['y'] + $s['hauteur'] - 1);

        $salle = $salleDe((int) $a['x'], (int) $a['y']);
        expect($salle)->not->toBeNull()->and($salle)->not->toBe(0)
            ->and($salleDe((int) $b['x'], (int) $b['y']))->toBe($salle);

        // Jamais sur une case de porte (embrasure comprise).
        $cases = collect($carte['portes'])->flatMap(fn ($porte) => Grille::casesPorte($porte))
            ->map(fn ($c) => "{$c['x']},{$c['y']}");
        expect($cases->contains("{$a['x']},{$a['y']}"))->toBeFalse()
            ->and($cases->contains("{$b['x']},{$b['y']}"))->toBeFalse();
    }

    // Une graine sur 40 au plus échoue ; sur 60, la pose doit avoir lieu au moins une fois.
    expect($poses)->toBeGreaterThan(0);
});

// =====================================================================
// 2. LE SAUT PAR LE BOUT — offert à trois cases, refusé en dessous, réussi sur bouclier noir
// =====================================================================

it('offre le saut par le bout à trois cases de déplacement', function () {
    fosseScene([], 6);
    $saut = fosseOption(fosseMenu(), 'franchir_long_3_3');

    expect($saut)->not->toBeNull()
        ->and($saut['type'])->toBe('franchissement')
        ->and($saut['parametres']['saut'])->toBe('long')
        ->and($saut['parametres']['cout'])->toBe(3)
        ->and($saut['parametres']['vers'])->toBe(['x' => 5, 'y' => 3]);
});

it('ne l\'offre pas à deux cases, et le résolveur refuse une option forgée', function () {
    fosseScene([], 2);

    expect(fosseOption(fosseMenu(), 'franchir_long_3_3'))->toBeNull();
    fosseChoisir('franchir_long_3_3')->assertStatus(422);
});

it('un bouclier noir franchit la fosse par le bout : trois cases dépensées, la suite du mouvement reste', function () {
    $ctx = fosseScene([], 6);
    fosseMenu();
    fosseDes(6);

    fosseChoisir('franchir_long_3_3')->assertAccepted();

    $etat = fosseEtat($ctx);
    expect([$etat->position_x, $etat->position_y])->toBe([5, 3])
        ->and($etat->deplacement_restant)->toBe(3)
        ->and($etat->dans_la_fosse)->toBeNull();

    // La fosse, elle, n'a pas bougé : toujours découverte, les deux cases.
    $cases = collect(fosseCarte($ctx)['pieges'])->pluck('etat')->all();
    expect($cases)->toBe(['detecte', 'detecte']);
});

it('un autre résultat que le bouclier noir fait tomber dans la fosse : 2 PV, le héros y reste, le trou s\'ouvre', function () {
    $ctx = fosseScene([], 6);
    $pvAvant = (int) $ctx['albrecht']->refresh()->pv_body;
    fosseMenu();
    fosseDes(2); // crâne

    fosseChoisir('franchir_long_3_3')->assertAccepted();

    $etat = fosseEtat($ctx);
    expect((int) $ctx['albrecht']->refresh()->pv_body)->toBe($pvAvant - 2)
        ->and([$etat->position_x, $etat->position_y])->toBe([3, 3])
        ->and($etat->dans_la_fosse)->toBe(['x' => 3, 'y' => 3, 'piege_id' => idFosseLongue()])
        ->and($etat->deplacement_restant)->toBe(0)
        ->and($etat->a_deplace)->toBeTrue();

    // Les deux cases deviennent une fosse OUVERTE — jamais une seule (`MoteurPieges::changerEtat()`).
    expect(collect(fosseCarte($ctx)['pieges'])->pluck('etat')->all())->toBe(['fosse_ouverte', 'fosse_ouverte']);

    // La chute se dit au fil, avec la décision publiée (malus, seuil de sortie).
    $chute = Evenement::where('groupe_id', $ctx['groupe']->id)->get()
        ->first(fn ($e) => ($e->payload['dans_la_fosse'] ?? null) !== null);
    expect($chute)->not->toBeNull()
        ->and($chute->payload['dans_la_fosse'])->toBe(['malus_des_combat' => 1, 'sortie_sur' => [5, 6]]);
});

it('le petit côté se saute comme une fosse ordinaire, et atterrit de l\'autre côté', function () {
    // Depuis le nord de (3,3) : le saut ordinaire atterrit en (3,4), hors de la fosse. Le saut par le bout n'est pas abordé d'ici.
    $ctx = fosseScene([], 6, 'detecte', [3, 2]);
    $menu = fosseMenu();

    expect(fosseOption($menu, 'franchir_3_3'))->not->toBeNull()
        ->and(fosseOption($menu, 'franchir_long_3_3'))->toBeNull();

    // Le jet de Body compte les CRÂNES : une file de crânes fait réussir le saut ordinaire.
    desFiges(array_fill(0, 80, 1));
    fosseChoisir('franchir_3_3')->assertAccepted();
    $etat = fosseEtat($ctx);
    expect([$etat->position_x, $etat->position_y])->toBe([3, 4])
        ->and($etat->dans_la_fosse)->toBeNull();
});

it('depuis le bout ouest, le saut ORDINAIRE retomberait dans la même fosse : il n\'est pas offert', function () {
    fosseScene([], 6);

    expect(fosseOption(fosseMenu(), 'franchir_3_3'))->toBeNull()
        ->and(fosseOption(fosseMenu(), 'franchir_long_3_3'))->not->toBeNull();
});

// =====================================================================
// 3. LA SORTIE — un dé rouge, 5 ou 6 ; le déplacement du tour est pris
// =====================================================================

it('dans la fosse, le menu ne propose ni marche ni saut, seulement de se hisser vers une case libre', function () {
    fosseScene([], 6, 'fosse_ouverte', [3, 3], true);
    $menu = fosseMenu();

    expect(fosseOption($menu, 'se_deplacer'))->toBeNull()
        ->and(fosseOption($menu, 'franchir_long_3_3'))->toBeNull()
        ->and(fosseOption($menu, 'sortir_fosse_2_3'))->not->toBeNull()
        ->and(fosseOption($menu, 'sortir_fosse_3_2'))->not->toBeNull()
        ->and(fosseOption($menu, 'sortir_fosse_3_4'))->not->toBeNull()
        // La case de l'autre moitié de la fosse n'est pas une sortie : elle est piégée.
        ->and(fosseOption($menu, 'sortir_fosse_4_3'))->toBeNull();

    expect(fosseOption($menu, 'sortir_fosse_2_3')['parametres']['sortie_sur'])->toBe([5, 6]);
    expect($menu['situation'])->toContain('tombé dans la fosse longue');
});

it('un dé rouge de 5 ou 6 fait sortir le héros sur la case choisie', function () {
    $ctx = fosseScene([], 6, 'fosse_ouverte', [3, 3], true);
    fosseMenu();
    fosseDes(5);

    fosseChoisir('sortir_fosse_2_3')->assertAccepted();

    $etat = fosseEtat($ctx);
    expect([$etat->position_x, $etat->position_y])->toBe([2, 3])
        ->and($etat->dans_la_fosse)->toBeNull()
        ->and($etat->a_deplace)->toBeTrue();
});

it('un dé de 1 à 4 laisse le héros dans le trou, et la sortie prend quand même le déplacement du tour', function () {
    $ctx = fosseScene([], 6, 'fosse_ouverte', [3, 3], true);
    fosseMenu();
    fosseDes(4);

    fosseChoisir('sortir_fosse_2_3')->assertAccepted();

    $etat = fosseEtat($ctx);
    expect([$etat->position_x, $etat->position_y])->toBe([3, 3])
        ->and($etat->dans_la_fosse)->not->toBeNull()
        ->and($etat->a_deplace)->toBeTrue()
        ->and($etat->deplacement_restant)->toBe(0);

    // Déjà bougé ce tour : la sortie n'est plus offerte.
    expect(fosseOption(fosseMenu(), 'sortir_fosse_2_3'))->toBeNull();
});

// =====================================================================
// 4. LE MALUS — un dé de combat de moins, en défense, jamais sous un
// =====================================================================

it('dans la fosse, le héros défend avec un dé de combat de moins, jamais sous un', function () {
    $ctx = fosseScene(['des_defense' => 3], 6);
    $sorts = app(MoteurSorts::class);

    expect($sorts->desDefenseHeros($ctx['albrecht']->refresh()))->toBe(3);

    fosseEtat($ctx)->update(['position_x' => 3, 'position_y' => 3,
        'dans_la_fosse' => ['x' => 3, 'y' => 3, 'piege_id' => idFosseLongue()]]);

    expect($sorts->desDefenseHeros($ctx['albrecht']->refresh()))->toBe(2);

    // Un seul dé de défense : il le garde.
    $ctx['albrecht']->update(['des_defense' => 1]);
    expect($sorts->desDefenseHeros($ctx['albrecht']->refresh()))->toBe(1);
});

it('dans la fosse, l\'attaque lance un dé de combat de moins que le héros n\'en a', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['des_attaque' => 4]);
    $ctx['instance']->update(['pv_body' => 40, 'pv_body_max' => 40]);
    $etat = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $ctx['heros']->id)->firstOrFail();

    // Le héros a SON dé de base (`des_attaque`, classe + arme) : sans état, la frappe le lance tel quel.
    $base = (int) $ctx['heros']->refresh()->des_attaque;
    expect($base)->toBeGreaterThanOrEqual(2);

    $etat->update(['dans_la_fosse' => ['x' => $etat->position_x, 'y' => $etat->position_y, 'piege_id' => idFosseLongue()]]);

    // Le menu de démarrage est en cache, AVANT que le helper ne place le monstre au contact : on le régénère.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $option = collect(fosseMenu()['options'] ?? [])->first(fn (array $o) => $o['type'] === 'attaque');
    expect($option)->not->toBeNull();

    // La cible vient de l'option (liste blanche du menu) : le client la renvoie telle quelle, comme la manette.
    $cible = $option['parametres']['cibles'][0] ?? null;
    expect($cible)->not->toBeNull();
    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => $option['id'],
        'parametres' => ['cible_id' => (int) ($cible['id'] ?? $cible)],
    ])->assertAccepted();

    $payload = Evenement::query()->orderByDesc('sequence')->get()
        ->first(fn ($e) => array_key_exists('des_attaque_effectifs', (array) $e->payload));

    expect((int) $payload->payload['des_attaque_effectifs'])->toBe($base - 1);
});

it('le malus ne descend jamais sous un dé, et ne relève rien', function () {
    expect(MoteurFosseLongue::appliquerMalus(3, 1))->toBe(2)
        ->and(MoteurFosseLongue::appliquerMalus(1, 1))->toBe(1)
        ->and(MoteurFosseLongue::appliquerMalus(0, 1))->toBe(0)
        ->and(MoteurFosseLongue::appliquerMalus(3, 0))->toBe(3);
});

it('sans `saut_long` au catalogue, la fosse ne se saute pas par le bout : le lecteur est réel', function () {
    fosseScene([], 6);

    $fosse = Piege::where('nom', 'Fosse longue')->firstOrFail();
    $effet = $fosse->effet;
    unset($effet['saut_long']);
    $fosse->update(['effet' => $effet]);

    expect(fosseOption(fosseMenu(), 'franchir_long_3_3'))->toBeNull();
});

it('le résolveur refuse un saut COURT forgé dont la réception tomberait dans la même fosse', function () {
    $ctx = fosseScene([], 6);
    fosseMenu();

    // Le menu ne l'offre pas (test ci-dessus) ; le résolveur le refuse aussi, appelé directement, option forgée.
    $forgee = [
        'id' => 'franchir_3_3', 'libelle' => 'forgée', 'type' => 'franchissement',
        'jet' => ['attribut' => 'body', 'difficulte' => 2],
        'parametres' => ['piege' => ['x' => 3, 'y' => 3], 'cout' => 2],
    ];

    expect(fn () => app(App\Partie\ResolveurTour::class)->resoudre($ctx['groupe'], $ctx['albrecht']->refresh(), $forgee, []))
        ->toThrow(Illuminate\Validation\ValidationException::class, 'même fosse');
});

// =====================================================================
// 5. LA PAIRE — un changement d'état touche les deux cases d'une même fosse
// =====================================================================

it('changer l\'état d\'une case de fosse longue change aussi l\'autre, et seulement elle', function () {
    $ctx = fosseScene([], 6, 'cache');
    $carte = Carte::where('quete_id', $ctx['quete']->id)->firstOrFail();

    app(MoteurPieges::class)->changerEtat($carte, 0, 'detecte');

    expect(collect(fosseCarte($ctx)['pieges'])->pluck('etat')->all())->toBe(['detecte', 'detecte']);
});
