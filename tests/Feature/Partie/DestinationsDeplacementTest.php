<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Mobilier;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\DeplacementHeros;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * LE SERVEUR PUBLIE LES CASES ATTEIGNABLES (René, 2026-10-10 : « ça serait plus
 * efficace ainsi ») — `se_deplacer.parametres.destinations: [{x, y, cout}]`.
 *
 * Deux propriétés, et c'est tout ce que ces tests défendent :
 *
 *  1. LA LISTE EST LA LISTE BLANCHE. Toute case publiée est ACCEPTÉE par le
 *     résolveur, toute case non publiée est REFUSÉE — sur une carte qui réunit
 *     ce qui faisait mentir la manette (un bloc tombé, un allié à traverser, un
 *     terrain entravant, un meuble).
 *
 *  2. ELLE NE RÉVÈLE RIEN. Deux cartes jumelles, l'une portant un secret (piège
 *     caché, passage secret non découvert, monstre caché, faux coffre), l'autre
 *     non : la liste des destinations — et le chemin de l'aperçu vers une case
 *     au-delà — sont STRICTEMENT identiques. Exigence explicite de René.
 *
 * La carte est écrite à la main (une salle, un couloir, une seconde salle), pour
 * que chaque secret ait une place connue.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class, TerrainSeeder::class, CompetenceSeeder::class, ClasseHerosSeeder::class]);
});

/**
 * Pose une carte à la main sur une quête fraîchement démarrée.
 *
 *   salle 0 : intérieur x 2..9, y 2..9 (anneau de mur x 1..10, y 1..10) — le héros y est ;
 *   porte A : arête est de (10,5) — l'embrasure est (10,5) ; couloir (10..13, 5) ;
 *   porte B : arête est de (13,5) — l'embrasure est (14,5) ;
 *   salle 1 : intérieur x 15..21, y 2..9 (anneau x 14..22) — NON découverte.
 *
 * @param  array<string, mixed>  $o  portes_a: 'ouverte'|'secrete'|'absente', porte_b, pieges, mobilier, terrain, monstre_cache, monstre_cache_en, allie
 * @return array<string, mixed>
 */
function carteAvecSecrets(array $o = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $compagnon = null;
    if (! empty($o['allie'])) {
        $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
        $compagnon = creerHeros($bob, $groupe, 'Brunhilde', 2);
    }

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();
    $etatAllie = $compagnon === null ? null
        : EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $compagnon->id)->firstOrFail();

    $ctx = compact('alice', 'groupe', 'quete', 'etat', 'heros', 'etatAllie');
    poserCarte($ctx, $o);

    return $ctx;
}

/**
 * (Re)pose la carte ET remet la scène à zéro : une même base peut ainsi porter
 * DEUX cartes jumelles l'une après l'autre — c'est tout l'intérêt des tests de
 * non-fuite, qui comparent ce que le joueur observe.
 *
 * @param  array<string, mixed>  $ctx
 * @param  array<string, mixed>  $o
 */
function poserCarte(array $ctx, array $o = []): void
{
    ['alice' => $alice, 'groupe' => $groupe, 'quete' => $quete, 'etat' => $etat, 'etatAllie' => $etatAllie] = $ctx;

    // Plus aucun monstre ne gêne — sauf ceux que le scénario pose.
    $quete->instancesMonstres()->update(['etat' => 'vaincu', 'revele' => true]);

    $largeur = 24;
    $hauteur = 12;
    $cases = array_fill(0, $hauteur, array_fill(0, $largeur, 'm'));

    $sol = function (int $x1, int $y1, int $x2, int $y2) use (&$cases) {
        for ($y = $y1; $y <= $y2; $y++) {
            for ($x = $x1; $x <= $x2; $x++) {
                $cases[$y][$x] = 's';
            }
        }
    };

    $sol(2, 2, 9, 9);        // salle 0
    $sol(10, 5, 13, 5);      // couloir (porte A en 10, porte B en 13/14)
    $sol(14, 5, 14, 5);      // embrasure de la salle 1
    $sol(15, 2, 21, 9);      // salle 1

    $porteA = $o['porte_a'] ?? 'ouverte';
    $porteB = $o['porte_b'] ?? 'fermee';

    $portes = [];
    if ($porteA === 'absente') {
        // La jumelle d'un passage secret : le mur est un mur, l'embrasure n'a
        // jamais été percée.
        $cases[5][10] = 'm';
    } else {
        $portes[] = ['x' => 10, 'y' => 5, 'cote' => 'e', 'etat' => $porteA === 'secrete' ? 'secrete' : $porteA, 'revele' => false];
    }
    $portes[] = ['x' => 13, 'y' => 5, 'cote' => 'e', 'etat' => $porteB];

    $salles = [
        ['x' => 1, 'y' => 1, 'largeur' => 10, 'hauteur' => 10],
        ['x' => 14, 'y' => 1, 'largeur' => 9, 'hauteur' => 10],
    ];

    $quete->carte->update([
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'grille' => [
            'cases' => $cases,
            'salles' => $salles,
            'portes' => $portes,
            'pieges' => $o['pieges'] ?? [],
            'mobilier' => $o['mobilier'] ?? [],
            'terrain' => $o['terrain'] ?? [],
            'leviers' => [],
        ],
    ]);
    $quete->update(['salles_decouvertes' => [0]]);

    $etat->update([
        'position_x' => 2, 'position_y' => 5,
        'deplacement_tour' => 8, 'deplacement_restant' => null, 'a_deplace' => false, 'a_joue' => false,
        'a_agi' => false, 'tombe' => false, 'detail_deplacement_tour' => null,
    ]);

    $etatAllie?->update(['position_x' => $o['allie'][0] ?? 3, 'position_y' => $o['allie'][1] ?? 8]);

    if (! empty($o['monstre_cache'])) {
        [$mx, $my] = $o['monstre_cache'];
        $quete->instancesMonstres()->orderBy('id')->firstOrFail()
            ->update(['etat' => 'actif', 'revele' => false, 'position_x' => $mx, 'position_y' => $my]);
    }

    Cache::forget(GenererMenu::cleMenu($groupe->id, $alice->id));
}

/** Les destinations que PUBLIE le menu du héros. @return list<array{x: int, y: int, cout: int}> */
function destinationsPubliees(JoueurAuthentifiable $alice): array
{
    $menu = test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options');
    $option = collect($menu)->firstWhere('id', 'se_deplacer');

    expect($option)->not->toBeNull('« Se déplacer » devrait être offert');

    return $option['parametres']['destinations'];
}

function apercuVers(JoueurAuthentifiable $alice, int $x, int $y): array
{
    return test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/deplacement/apercu', ['x' => $x, 'y' => $y])
        ->assertOk()->json();
}

/** Remet le héros en (2,5) avec toute son allonce, comme au début d'un tour. */
function remettreAuDepart(EtatPersonnageQuete $etat, JoueurAuthentifiable $alice): void
{
    // ⚠ Une requête, pas `$etat->update()` : le modèle croit encore le héros en
    // (2,5) et n'écrirait RIEN, la route ayant déjà bougé la ligne en base.
    EtatPersonnageQuete::whereKey($etat->id)->update([
        'position_x' => 2, 'position_y' => 5, 'deplacement_tour' => 8, 'deplacement_restant' => null,
        'a_deplace' => false, 'a_joue' => false, 'a_agi' => false, 'tombe' => false,
    ]);
    Cache::forget(GenererMenu::cleMenu($etat->quete->groupe_id, $alice->id));
}

it('publie des destinations {x, y, cout} triées, portée respectée', function () {
    ['alice' => $alice] = carteAvecSecrets();

    $d = destinationsPubliees($alice);

    expect($d)->not->toBeEmpty()
        ->and($d[0])->toHaveKeys(['x', 'y', 'cout'])
        // Trié par ligne puis colonne, indépendamment du parcours.
        ->and($d)->toBe(collect($d)->sortBy([['y', 'asc'], ['x', 'asc']])->values()->all())
        // 8 points, pas un de plus ; la case de départ n'est pas une destination.
        ->and(collect($d)->max('cout'))->toBeLessThanOrEqual(8)
        ->and(collect($d)->contains(fn ($c) => $c['x'] === 2 && $c['y'] === 5))->toBeFalse()
        // Sol ordinaire : un point par pas.
        ->and(collect($d)->firstWhere(fn ($c) => $c['x'] === 5 && $c['y'] === 5)['cout'])->toBe(3);
});

it('LISTE BLANCHE : toute case publiée est acceptée, toute case non publiée refusée (bloc tombé, allié, terrain entravant, meuble)', function () {
    $bloc = Piege::where('nom', 'Chute de blocs')->firstOrFail();
    $sable = Terrain::where('nom', 'Sable entravant')->firstOrFail();
    $table = Mobilier::where('nom', 'Table')->firstOrFail();

    $ctx = carteAvecSecrets([
        'allie' => [4, 5],                                              // à TRAVERSER, jamais but
        'pieges' => [['x' => 3, 'y' => 5, 'piege_id' => $bloc->id, 'etat' => 'bloc']], // bloc tombé : un mur
        'terrain' => [['x' => 3, 'y' => 6, 'terrain_id' => $sable->id], ['x' => 4, 'y' => 6, 'terrain_id' => $sable->id]],
        'mobilier' => [['mobilier_id' => $table->id, 'x' => 3, 'y' => 4, 'l' => 2, 'h' => 1]],
    ]);
    ['alice' => $alice, 'etat' => $etat] = $ctx;

    $publiees = destinationsPubliees($alice);
    $cles = collect($publiees)->mapWithKeys(fn ($d) => ["{$d['x']},{$d['y']}" => $d['cout']]);

    // Ce que la manette devait cesser de deviner :
    expect($cles->has('3,5'))->toBeFalse('le bloc tombé n\'est pas une destination')
        ->and($cles->has('4,5'))->toBeFalse('la case d\'un allié se traverse, ne se vise pas')
        ->and($cles->has('3,4'))->toBeFalse('le meuble bloque')
        ->and($cles->has('4,4'))->toBeFalse('le meuble bloque (emprise 2×1)')
        // (3,6) : 1 pas depuis (2,6)… mais (2,5)→(2,6)→(3,6) = 1 + 2 (sable) = 3
        ->and($cles->get('3,6'))->toBe(3, 'le sable entravant coûte 2 pour entrer')
        // Derrière le bloc, par le détour : (5,5) = (2,5)→(2,6)→(3,6)→(4,6)→(5,6)→(5,5) = 1+2+2+1+1 = 7
        ->and($cles->get('5,5'))->toBe(7);

    // ── Les DEUX sens ─────────────────────────────────────────────────────────
    $region = [];
    for ($y = 1; $y <= 10; $y++) {
        for ($x = 1; $x <= 10; $x++) {
            $region[] = [$x, $y];
        }
    }

    $acceptees = 0;
    foreach ($region as [$x, $y]) {
        if ($x === 2 && $y === 5) {
            continue; // sur place
        }

        remettreAuDepart($etat, $alice);

        $reponse = $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
            'option_id' => 'se_deplacer', 'parametres' => ['x' => $x, 'y' => $y],
        ]);

        if ($cles->has("{$x},{$y}")) {
            $reponse->assertStatus(202);
            $acceptees++;
            // Et le coût annoncé est le coût PAYÉ.
            expect((int) $etat->fresh()->deplacement_restant)->toBe(8 - $cles->get("{$x},{$y}"), "coût payé en ({$x},{$y})");
        } else {
            $reponse->assertStatus(422);
            expect([(int) $etat->fresh()->position_x, (int) $etat->fresh()->position_y])->toBe([2, 5], "rien dépensé en ({$x},{$y})");
        }
    }

    expect($acceptees)->toBe($cles->count());
});

it('une case HORS liste est refusée par la liste blanche même si le parcours l\'accepterait (brouillard)', function () {
    // La salle 1 existe, son couloir est ouvert, mais elle n'est pas découverte :
    // la carte du groupe la masque, la liste ne la publie pas, le résolveur la refuse.
    ['alice' => $alice, 'etat' => $etat] = carteAvecSecrets(['porte_b' => 'ouverte']);

    $publiees = destinationsPubliees($alice);

    expect(collect($publiees)->contains(fn ($d) => $d['x'] >= 14))->toBeFalse();

    remettreAuDepart($etat, $alice);
    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => 14, 'y' => 5],
    ])->assertStatus(422);
});

it('DeplacementHeros::figureParmi lit la liste', function () {
    $liste = [['x' => 1, 'y' => 2, 'cout' => 1]];

    expect(DeplacementHeros::figureParmi($liste, 1, 2))->toBeTrue()
        ->and(DeplacementHeros::figureParmi($liste, 2, 1))->toBeFalse();
});

it('le reliquat : après un pas, la liste suivante est recalculée sur les points RESTANTS', function () {
    ['alice' => $alice, 'etat' => $etat] = carteAvecSecrets();

    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => 5, 'y' => 5],
    ])->assertStatus(202);

    expect((int) $etat->fresh()->deplacement_restant)->toBe(5);

    $apres = destinationsPubliees($alice);

    expect(collect($apres)->max('cout'))->toBeLessThanOrEqual(5)
        // On est en (5,5) : un pas = 1 point.
        ->and(collect($apres)->firstWhere(fn ($d) => $d['x'] === 6 && $d['y'] === 5)['cout'])->toBe(1);
});

// ─────────────────────────────────────────────────────────────────────────────
// ⛔ AUCUNE FUITE D'INFORMATION CACHÉE
// ─────────────────────────────────────────────────────────────────────────────

/** Ce qu'un joueur peut observer de ses déplacements : la liste, et le chemin vers une case au-delà. */
function observationsDeplacement(array $ctx, array $cibles): array
{
    $alice = $ctx['alice'];
    $obs = ['destinations' => destinationsPubliees($alice), 'apercus' => []];

    foreach ($cibles as [$x, $y]) {
        $a = apercuVers($alice, $x, $y);
        // `pieges` est ce que la CARTE montre déjà : on l'inclut, il doit être identique aussi.
        $obs['apercus']["{$x},{$y}"] = $a;
    }

    return $obs;
}

it('NE FUIT PAS : un piège caché sur une case atteignable ne change ni la liste, ni les coûts, ni le trajet', function () {
    $fosse = Piege::where('nom', 'Fosse')->firstOrFail();
    $cibles = [[6, 5], [9, 5], [8, 8], [5, 3]];

    // Un piège caché pile sur le trajet direct (2,5)→(6,5), un autre sur une case latérale.
    $avec = carteAvecSecrets(['pieges' => [
        ['x' => 4, 'y' => 5, 'piege_id' => $fosse->id, 'etat' => 'cache'],
        ['x' => 7, 'y' => 6, 'piege_id' => $fosse->id, 'etat' => 'cache'],
    ]]);
    $obsAvec = observationsDeplacement($avec, $cibles);

    // Rien n'est publié comme piège : le contraire serait déjà une fuite.
    expect($obsAvec['apercus']['6,5']['pieges'])->toBe([]);

    // La jumelle SANS piège : la même scène, la même base.
    poserCarte($avec);
    $obsSans = observationsDeplacement($avec, $cibles);

    expect($obsAvec['destinations'])->toBe($obsSans['destinations'])
        ->and($obsAvec['apercus'])->toBe($obsSans['apercus']);
    // La case du piège est là, au coût d'une case ordinaire.
    expect(collect($obsAvec['destinations'])->firstWhere(fn ($d) => $d['x'] === 4 && $d['y'] === 5)['cout'])->toBe(2);
});

it('NE FUIT PAS : un piège CONNU, lui, se voit — il infléchit le trajet, comme dans l\'aperçu', function () {
    $fosse = Piege::where('nom', 'Fosse')->firstOrFail();

    $ctx = carteAvecSecrets(['pieges' => [['x' => 4, 'y' => 5, 'piege_id' => $fosse->id, 'etat' => 'detecte']]]);
    $apercu = apercuVers($ctx['alice'], 6, 5);

    // Le piège détecté est évité par un détour payable : 4 pas (2,5)→(3,5)→(3,4)→(4,4)→(5,4)→(5,5)→(6,5) ... = 6 ;
    // c'est le coût de la liste ET celui de l'aperçu, jamais deux nombres.
    $publie = collect(destinationsPubliees($ctx['alice']))->firstWhere(fn ($d) => $d['x'] === 6 && $d['y'] === 5);

    expect($apercu['atteignable'])->toBeTrue()
        ->and($publie['cout'])->toBe($apercu['cout'])
        ->and(collect($apercu['chemin'])->contains(fn ($c) => $c['x'] === 4 && $c['y'] === 5))->toBeFalse();
});

it('NE FUIT PAS : un passage secret non découvert vaut une roche ordinaire', function () {
    $secrete = carteAvecSecrets(['porte_a' => 'secrete']);
    $obsSecrete = observationsDeplacement($secrete, [[10, 5], [11, 5], [13, 5], [9, 5]]);

    // Aucune destination au-delà du mur de la salle, ni l'embrasure elle-même.
    expect(collect($obsSecrete['destinations'])->contains(fn ($d) => $d['x'] >= 10))->toBeFalse();

    // La jumelle : le même mur, SANS porte.
    poserCarte($secrete, ['porte_a' => 'absente']);
    $obsMur = observationsDeplacement($secrete, [[10, 5], [11, 5], [13, 5], [9, 5]]);

    expect($obsSecrete['destinations'])->toBe($obsMur['destinations'])
        ->and($obsSecrete['apercus'])->toBe($obsMur['apercus']);
});

it('NE FUIT PAS : un monstre caché dans une salle non révélée ne retire ni n\'ajoute aucune case', function (string $porteB) {
    // Porte A OUVERTE (le couloir est vu), la salle 1 est un secret. Deux cas :
    // porte B close (la salle est inatteignable) et porte B OUVERTE sans que la salle
    // soit découverte — là, SEUL le brouillard empêche la liste de contourner le secret
    // (sans lui, la case du monstre manquerait à la liste, et les cases au-delà
    // changeraient de coût).
    $cache = carteAvecSecrets(['monstre_cache' => [16, 5], 'porte_b' => $porteB]);
    $obsCache = observationsDeplacement($cache, [[13, 5], [12, 5], [14, 5], [15, 5], [16, 5], [17, 5]]);

    // Le couloir est offert, la salle 1 ne l'est pas.
    expect(collect($obsCache['destinations'])->contains(fn ($d) => $d['x'] === 10 && $d['y'] === 5))->toBeTrue()
        ->and(collect($obsCache['destinations'])->contains(fn ($d) => $d['x'] >= 14))->toBeFalse();

    poserCarte($cache, ['porte_b' => $porteB]);
    $obsSans = observationsDeplacement($cache, [[13, 5], [12, 5], [14, 5], [15, 5], [16, 5], [17, 5]]);

    expect($obsCache['destinations'])->toBe($obsSans['destinations'])
        ->and($obsCache['apercus'])->toBe($obsSans['apercus']);
})->with(['porte B close' => ['fermee'], 'porte B ouverte, salle non découverte' => ['ouverte']]);

it('NE FUIT PAS : le monstre caché SOUS un faux coffre se refuse comme n\'importe quel meuble', function () {
    // Un Dreadshifter déguisé : un Coffre, et dessous un monstre non révélé.
    $coffre = Mobilier::where('nom', 'Coffre')->firstOrFail();
    $meuble = [['mobilier_id' => $coffre->id, 'x' => 5, 'y' => 5, 'l' => 1, 'h' => 1]];

    $faux = carteAvecSecrets(['mobilier' => $meuble, 'monstre_cache' => [5, 5]]);
    $obsFaux = observationsDeplacement($faux, [[5, 5], [6, 5], [8, 5]]);

    poserCarte($faux, ['mobilier' => $meuble]);
    $obsVrai = observationsDeplacement($faux, [[5, 5], [6, 5], [8, 5]]);

    // Même liste, même aperçu — la RAISON du refus comprise : « un meuble », jamais « une figure ».
    expect($obsFaux['destinations'])->toBe($obsVrai['destinations'])
        ->and($obsFaux['apercus'])->toBe($obsVrai['apercus']);
});
