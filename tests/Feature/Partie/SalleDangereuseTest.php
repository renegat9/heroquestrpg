<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\MotsClesMobilier;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Mobilier;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\MoteurMobilier;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * SALLE DANGEREUSE (2026-10-23) — la forge des nains (Kellar's Keep q. 4 note D, F4543 p. 15 : frappe qui FINIT son
 * déplacement dans la salle, sauf le Nain, ne se désamorce pas) et la fournaise (Spirit Queen's Torment q. 13 note A,
 * G0053 p. 31 : frappe qui FINIT son tour dans la salle, se désamorce « comme un piège », les monstres n'en souffrent pas).
 *
 * Éprouvé EN JEU : une petite carte à la main (salle 0, couloir, salle chauffée, couloir est), des dés injectés
 * (`desFiges`), et les vraies routes (`GET /menu`, `POST /choix`). Les helpers portent le préfixe `sal`.
 *
 * Dés : un d6 vaut 1-3 = crâne, 4-5 = bouclier blanc, 6 = bouclier noir. Les déplacements reçoivent `deplacement_base`
 * 12 : leur d6 ne change jamais l'issue, et une file de MÊMES valeurs rend chaque test indépendant de l'ordre des tirages.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
        TerrainSeeder::class]);
});

/** Le donjon de la scène : salle 0 (x 1-4, y 3-5, départ), couloir (5,4), salle chauffée (x 6-9, y 3-5), couloir est (x 10-14, y 4). */
function salCases(): array
{
    $cases = array_fill(0, 9, array_fill(0, 16, 'm'));

    for ($y = 3; $y <= 5; $y++) {
        for ($x = 1; $x <= 4; $x++) {
            $cases[$y][$x] = 's';
        }

        for ($x = 6; $x <= 9; $x++) {
            $cases[$y][$x] = 's';
        }
    }

    $cases[4][5] = 's';

    for ($x = 10; $x <= 14; $x++) {
        $cases[4][$x] = 's';
    }

    return $cases;
}

/** Un meuble de la scène, posé sur la case (x, y) de la salle chauffée (index 1). */
function salMeuble(string $nom, int $x, int $y): array
{
    return [
        'mobilier_id' => Mobilier::where('nom', $nom)->firstOrFail()->id,
        'x' => $x, 'y' => $y, 'l' => 1, 'h' => 1, 'salle' => 1,
    ];
}

/**
 * @param  list<array<string, mixed>>  $mobiliers  entrées brutes de `cartes.grille['mobilier']`
 * @param  array<string, mixed>  $attributs  attributs du héros (classe, PV…)
 * @param  array{0: int, 1: int}  $position  case de départ du héros
 * @return array{alice: JoueurAuthentifiable, groupe: Groupe, quete: Quete, heros: Personnage}
 */
function salScene(array $mobiliers = [], array $attributs = [], array $position = [2, 4]): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, $attributs + ['deplacement_base' => 12]);

    // Les salles 0 (départ) et 1 (la salle chauffée) sont DÉCOUVERTES : une case d'une salle inconnue n'est pas une destination.
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — salle dangereuse',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
        'salles_decouvertes' => [0, 1],
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => salCases(),
            'salles' => [
                ['x' => 1, 'y' => 3, 'largeur' => 4, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 2, 'mediane_y' => 4],
                ['x' => 6, 'y' => 3, 'largeur' => 4, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 7, 'mediane_y' => 4],
            ],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => $mobiliers, 'terrain' => [], 'dangers' => [],
            'spawn_heros' => [['x' => 2, 'y' => 4]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => $position[0], 'position_y' => $position[1],
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'heros' => $heros];
}

/** Les options du menu courant du héros d'Alice (ids). */
function salOptions(array $ctx): array
{
    $options = test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options') ?? [];

    return array_column($options, 'id');
}

/** Un déplacement réel : le menu est ouvert, puis `se_deplacer` vers (x, y) par POST /choix. */
function salDeplacer(array $ctx, int $x, int $y): void
{
    salOptions($ctx);
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => $x, 'y' => $y],
    ])->assertSuccessful();
}

/** Le héros d'Alice termine son tour (`attendre`). Pas de monstre dans ces scènes : seule la fin de tour du héros compte. */
function salTerminerTour(array $ctx): void
{
    salOptions($ctx);
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);
}

/** Un désamorçage réel, par l'option de menu `desamorcer_x_y` (le menu doit l'offrir d'abord). */
function salDesamorcer(array $ctx, int $x, int $y)
{
    salOptions($ctx);

    return test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => "desamorcer_{$x}_{$y}",
    ]);
}

/** Les événements de la salle dangereuse journalisés pour ce groupe (un par jet de dé de la salle). */
function salEvenements(array $ctx): array
{
    return Evenement::query()
        ->where('groupe_id', $ctx['groupe']->id)
        ->get()
        ->filter(fn (Evenement $e) => in_array(($e->payload['type'] ?? null), ['salle_dangereuse', 'desamorcage_mobilier'], true))
        ->values()
        ->all();
}

/** La case d'un meuble, relue dans la carte de la BASE (état durable, pas une copie en mémoire). */
function salMeubleEnBase(array $ctx, string $nom): array
{
    $grille = Carte::query()->where('quete_id', $ctx['quete']->id)->firstOrFail()->grille;
    $id = Mobilier::where('nom', $nom)->value('id');

    return collect($grille['mobilier'] ?? [])->firstWhere('mobilier_id', $id) ?? [];
}

// =====================================================================
// REGISTRE — le vocabulaire `salle_dangereuse` testé dans les deux sens (`MotsClesMobilier`)
// =====================================================================

it('REGISTRE (sens 1) : toute clé d\'effet portée par un meuble du catalogue est déclarée dans MotsClesMobilier', function () {
    foreach (Mobilier::query()->get(['nom', 'effet']) as $meuble) {
        foreach (array_keys((array) $meuble->effet) as $cle) {
            expect(MotsClesMobilier::connue((string) $cle))->toBeTrue("« {$meuble->nom} » porte la clé « {$cle} » non déclarée.");
        }
    }
});

it('REGISTRE (sens 2) : toute clé déclarée est portée par au moins un meuble du catalogue', function () {
    foreach (array_keys(MotsClesMobilier::VOCABULAIRE) as $cle) {
        $porteurs = Mobilier::query()->get(['nom', 'effet'])
            ->filter(fn (Mobilier $m) => array_key_exists($cle, (array) $m->effet));

        expect($porteurs)->not->toBeEmpty("La clé déclarée « {$cle} » n'est portée par aucun meuble : un registre sans porteur.");
    }
});

it('les deux meubles à salle dangereuse portent le moment, la classe épargnée et le désamorçage SOURCÉS', function () {
    $forge = Mobilier::where('nom', 'Forge des nains')->firstOrFail();
    $fournaise = Mobilier::where('nom', 'Fournaise')->firstOrFail();

    expect($forge->boite)->toBe('kellars_keep')
        ->and(MotsClesMobilier::salleDangereuse((array) $forge->effet))->toBe([
            'moment' => 'fin_deplacement', 'exempte' => 'nain', 'desamorcable' => false,
        ]);

    expect($fournaise->boite)->toBe('spirit_queen')
        ->and(MotsClesMobilier::salleDangereuse((array) $fournaise->effet))->toBe([
            'moment' => 'fin_tour', 'exempte' => null, 'desamorcable' => true,
        ]);
});

it('un moment hors vocabulaire LÈVE une exception : un meuble mal déclaré ne brûle jamais en silence', function () {
    MotsClesMobilier::salleDangereuse(['salle_dangereuse' => ['moment' => 'fin_de_partie']]);
})->throws(InvalidArgumentException::class);

it('un meuble sans salle dangereuse n\'en a pas (le normaliseur rend null, il ne devine rien)', function () {
    expect(MotsClesMobilier::salleDangereuse(['fouille' => []]))->toBeNull()
        ->and(MotsClesMobilier::salleDangereuse(null))->toBeNull();
});

// =====================================================================
// LA FORGE — fin de DÉPLACEMENT, sauf le Nain
// =====================================================================

it('la forge : un héros qui FINIT son déplacement dans la salle roule un dé — un crâne lui retire 1 PV de Body', function () {
    $ctx = salScene([salMeuble('Forge des nains', 7, 3)]);
    desFiges(array_fill(0, 200, 1));

    salDeplacer($ctx, 7, 4);

    expect($ctx['heros']->fresh()->pv_body)->toBe(7)
        ->and(collect(salEvenements($ctx))->pluck('payload.type')->all())->toContain('salle_dangereuse');
});

it('la forge : un bouclier (blanc ou noir) ne blesse personne, et le jet est tout de même annoncé', function (int $face) {
    $ctx = salScene([salMeuble('Forge des nains', 7, 3)]);
    desFiges(array_fill(0, 200, $face));

    salDeplacer($ctx, 7, 4);

    expect($ctx['heros']->fresh()->pv_body)->toBe(8)
        ->and(collect(salEvenements($ctx))->pluck('payload.type')->all())->toContain('salle_dangereuse');
})->with([4, 6]);

it('la forge : traverser la salle sans y FINIR son déplacement ne roule rien', function () {
    $ctx = salScene([salMeuble('Forge des nains', 7, 3)]);
    desFiges(array_fill(0, 200, 1));

    // Le trajet passe par (6,4) à (9,4), dans la salle, et s'arrête sur le couloir (11,4).
    salDeplacer($ctx, 11, 4);

    expect($ctx['heros']->fresh()->pv_body)->toBe(8)
        ->and(collect(salEvenements($ctx))->pluck('payload.type')->all())->not->toContain('salle_dangereuse');
});

it('la forge : le NAIN n\'est pas frappé — « except the dwarf »', function () {
    $ctx = salScene([salMeuble('Forge des nains', 7, 3)], ['classe' => 'nain']);
    desFiges(array_fill(0, 200, 1));

    salDeplacer($ctx, 7, 4);

    expect($ctx['heros']->fresh()->pv_body)->toBe(8)
        ->and(collect(salEvenements($ctx))->pluck('payload.type')->all())->not->toContain('salle_dangereuse');
});

it('la forge ne se désamorce pas : aucune option de désamorçage, et le résolveur refuse un choix forcé', function () {
    $ctx = salScene([salMeuble('Forge des nains', 7, 3)], ['classe' => 'nain'], [7, 4]);
    desFiges(array_fill(0, 200, 4));

    expect(salOptions($ctx))->not->toContain('desamorcer_7_3');

    salDesamorcer($ctx, 7, 3)->assertStatus(422);
});

// =====================================================================
// LA FOURNAISE — fin de TOUR, les monstres exceptés (ici : le héros seul)
// =====================================================================

it('la fournaise : finir son DÉPLACEMENT dans la salle ne frappe pas — c\'est la fin du TOUR qui compte', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)]);
    desFiges(array_fill(0, 200, 1));

    salDeplacer($ctx, 7, 4);

    expect($ctx['heros']->fresh()->pv_body)->toBe(8);
});

it('la fournaise : un héros qui TERMINE son tour dans la salle roule un dé — un crâne = 1 PV de Body', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)]);
    desFiges(array_fill(0, 200, 1));

    salDeplacer($ctx, 7, 4);
    salTerminerTour($ctx);

    expect($ctx['heros']->fresh()->pv_body)->toBe(7)
        ->and(collect(salEvenements($ctx))->pluck('payload.type')->all())->toContain('salle_dangereuse');
});

it('la fournaise : un héros qui termine son tour HORS de la salle n\'est pas frappé', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)]);
    desFiges(array_fill(0, 200, 1));

    salDeplacer($ctx, 11, 4);
    salTerminerTour($ctx);

    expect($ctx['heros']->fresh()->pv_body)->toBe(8);
});

it('la fournaise frappe le NAIN aussi : son exemption est celle de la forge, pas celle de la fournaise', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)], ['classe' => 'nain'], [7, 4]);
    desFiges(array_fill(0, 200, 1));

    salTerminerTour($ctx);

    expect($ctx['heros']->fresh()->pv_body)->toBe(7);
});

it('une salle, un dé : deux fournaises dans la même salle ne donnent pas deux coups à un héros', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3), salMeuble('Fournaise', 9, 5)], [], [7, 4]);
    desFiges(array_fill(0, 200, 1));

    salTerminerTour($ctx);

    expect($ctx['heros']->fresh()->pv_body)->toBe(7);
});

it('un héros à 1 PV tombé sous la fournaise est marqué tombé : même règle que tout coup de salle', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)], ['pv_body' => 1], [7, 4]);
    desFiges(array_fill(0, 200, 1));

    salTerminerTour($ctx);

    $etat = EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->firstOrFail();

    expect($ctx['heros']->fresh()->pv_body)->toBe(0)
        ->and((bool) $etat->tombe)->toBeTrue();
});

// =====================================================================
// LE DÉSAMORÇAGE DE LA FOURNAISE — la couture des pièges, un état DURABLE
// =====================================================================

// Les dés sont MÊMES sur toute la file : l'ordre des tirages (déplacement, désamorçage, coup de fin de tour) ne change
// rien à l'issue. Le coup de salle est ANNONCÉ même sans crâne : on compte donc les `salle_dangereuse` journalisés.

it('la fournaise se désamorce (Nain, un dé, bouclier blanc) : elle est écrite désarmée, et ne frappe plus à la fin du tour', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)], ['classe' => 'nain'], [7, 4]);
    desFiges(array_fill(0, 200, 4)); // bouclier blanc : le désamorçage réussit, et tout coup de fin de tour serait muet

    expect(salOptions($ctx))->toContain('desamorcer_7_3');

    salDesamorcer($ctx, 7, 3)->assertSuccessful();

    expect(salMeubleEnBase($ctx, 'Fournaise')['desarme'] ?? false)->toBeTrue();

    $jetsAvant = collect(salEvenements($ctx))->where('payload.type', 'salle_dangereuse')->count();
    salTerminerTour($ctx);
    $jetsApres = collect(salEvenements($ctx))->where('payload.type', 'salle_dangereuse')->count();

    expect($jetsAvant)->toBe(0)
        ->and($jetsApres)->toBe(0)
        ->and(collect(salEvenements($ctx))->pluck('payload.type')->all())->toContain('desamorcage_mobilier');
});

it('un désamorçage raté (bouclier noir) laisse la fournaise allumée : elle joue toujours à la fin du tour', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)], ['classe' => 'nain'], [7, 4]);
    desFiges(array_fill(0, 200, 6)); // bouclier noir : le désamorçage du Nain échoue, le coup de fin de tour est joué (sans crâne)

    salDesamorcer($ctx, 7, 3)->assertSuccessful();

    expect(salMeubleEnBase($ctx, 'Fournaise')['desarme'] ?? false)->toBeFalse()
        ->and(collect(salEvenements($ctx))->firstWhere('payload.type', 'desamorcage_mobilier')->payload['succes'])->toBeFalse();

    salTerminerTour($ctx);

    expect(collect(salEvenements($ctx))->where('payload.type', 'salle_dangereuse')->count())->toBe(1)
        ->and($ctx['heros']->fresh()->pv_body)->toBe(8);
});

it('le menu n\'offre pas le désamorçage à un héros sans outil (ni Nain, ni trousse), et le résolveur le refuse', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)], ['classe' => 'barbare'], [7, 4]);
    desFiges(array_fill(0, 200, 4));

    expect(salOptions($ctx))->not->toContain('desamorcer_7_3');

    salDesamorcer($ctx, 7, 3)->assertStatus(422);
});

it('le désamorçage d\'une fournaise EN PORTÉE SEULEMENT : à deux cases, l\'option n\'existe pas', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)], ['classe' => 'nain'], [6, 5]);
    desFiges(array_fill(0, 200, 4));

    // (6,5) est en diagonale de (7,4)… mais à deux cases orthogonales de la fournaise en (7,3) : pas adjacente.
    expect(salOptions($ctx))->not->toContain('desamorcer_7_3');
});

it('le résolveur REVALIDE sur l\'état vivant : une option déjà offerte qui n\'est plus légale est refusée (le héros n\'a plus d\'outil)', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)], ['classe' => 'nain'], [7, 4]);
    desFiges(array_fill(0, 200, 4));

    // Le menu offre le désamorçage au Nain…
    expect(salOptions($ctx))->toContain('desamorcer_7_3');

    // …puis le héros n'est plus un Nain avant que le choix n'arrive : la liste blanche du menu ne suffit pas.
    $ctx['heros']->update(['classe' => 'barbare']);

    salDesamorcer($ctx, 7, 3)->assertStatus(422);
    expect(salMeubleEnBase($ctx, 'Fournaise')['desarme'] ?? false)->toBeFalse();
});

it('le résolveur REVALIDE la position : une option offerte au contact ne vaut plus une fois le héros éloigné du meuble', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)], ['classe' => 'nain'], [7, 4]);
    desFiges(array_fill(0, 200, 4));

    expect(salOptions($ctx))->toContain('desamorcer_7_3');

    // Le héros n'est plus au contact (il est en (9,5) : deux cases de la fournaise en (7,3), pas orthogonalement voisin).
    EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->firstOrFail()->update(['position_x' => 9, 'position_y' => 5]);

    salDesamorcer($ctx, 7, 3)->assertStatus(422);
    expect(salMeubleEnBase($ctx, 'Fournaise')['desarme'] ?? false)->toBeFalse();
});

// =====================================================================
// L'ANNONCE — chaque jet de la salle se dit (journal + fil), aucune salle ne frappe en silence
// =====================================================================

it('chaque jet de la salle dangereuse est journalisé avec son meuble, son dé et ses dégâts', function () {
    $ctx = salScene([salMeuble('Fournaise', 7, 3)], [], [7, 4]);
    desFiges(array_fill(0, 200, 1));

    salTerminerTour($ctx);

    $evenement = collect(salEvenements($ctx))->firstWhere('payload.type', 'salle_dangereuse');

    expect($evenement)->not->toBeNull()
        ->and($evenement->payload['mobilier']['nom'])->toBe('Fournaise')
        ->and($evenement->payload['moment'])->toBe('fin_tour')
        ->and($evenement->payload['degats'])->toBe(1)
        ->and($evenement->payload['personnage']['nom'])->toBe('Albrecht');
});
