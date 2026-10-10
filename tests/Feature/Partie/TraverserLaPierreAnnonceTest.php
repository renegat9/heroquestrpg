<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Sort;
use App\Partie\EtatGroupe;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * TRAVERSER LA PIERRE — ce que le serveur PUBLIE et ce que le moteur TIENT
 * (décision de René, 2026-10-09 : « garder un seul passage, mais l'annoncer »).
 *
 * Ce que le moteur tient, et que les tests verrouillent : l'intangible vit pendant le
 * créneau de DÉPLACEMENT (`ce_tour`). Tant qu'il reste des points, la roche reste
 * franchissable ; une fois le déplacement fini, plus aucun pas dans la roche ce tour.
 * C'est ce que l'annonce dit (« une fois ton déplacement fini, tu ne pourras plus y
 * revenir ») — et le verdict parlait d'une consommation « à la fin du déplacement » :
 * c'est le même effet, pas un mécanisme distinct.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([SortSeeder::class, ObjetSeeder::class, ConditionSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, CompetenceSeeder::class,
        ClasseHerosSeeder::class]);
});

/**
 * Un héros (Albrecht) en (10,10) dans une enceinte de ROCHE, sans monstre.
 * Sol : (10,10) où il se tient, (10,11) (sol nu, sans roche à franchir), et (12,10),
 * sol enclavé qui n'est atteignable QUE par la roche (11,10).
 * Roche : (11,10) et la poche (12,11).
 */
function scenarioPierre(int $allotement = 6): array
{
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['quete' => $quete, 'etatHeros' => $etat, 'heros' => $heros] = $ctx;
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);

    $carte = $quete->carte;
    $grille = $carte->grille;
    for ($y = 8; $y <= 13; $y++) {
        for ($x = 8; $x <= 13; $x++) {
            $grille['cases'][$y][$x] = 'm';
        }
    }
    foreach ([[10, 10], [10, 11], [12, 10]] as [$x, $y]) {
        $grille['cases'][$y][$x] = 's';
    }
    $grille['pieges'] = [];
    $carte->update(['grille' => $grille]);

    $etat->update(['position_x' => 10, 'position_y' => 10, 'deplacement_tour' => $allotement,
        'deplacement_restant' => null, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    return [...$ctx, 'quete' => $quete->fresh()];
}

it('l\'aperçu dit traverse_roche=true quand le trajet franchit la roche, et false sinon', function () {
    ['alice' => $alice, 'heros' => $heros] = scenarioPierre();
    app(MoteurSorts::class)->appliquerBuff($heros, Sort::where('nom', 'Traverser la Pierre')->firstOrFail());

    // Sol nu, sans roche : le décompte publié ne dit rien de la roche.
    $nu = $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/deplacement/apercu', ['x' => 10, 'y' => 11])
        ->assertOk()->json();
    expect($nu['atteignable'])->toBeTrue()
        ->and($nu['traverse_roche'])->toBeFalse();

    // Sol enclavé derrière (11,10) : le seul chemin franchit la roche.
    $roche = $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/deplacement/apercu', ['x' => 12, 'y' => 10])
        ->assertOk()->json();
    expect($roche['atteignable'])->toBeTrue()
        ->and($roche['chemin'])->toContain(['x' => 11, 'y' => 10])
        ->and($roche['traverse_roche'])->toBeTrue();
});

it('sans intangible, la roche bloque : l\'aperçu le dit, et ne publie aucun franchissement', function () {
    ['alice' => $alice] = scenarioPierre();

    $bloque = $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/deplacement/apercu', ['x' => 12, 'y' => 10])
        ->assertOk()->json();

    expect($bloque['atteignable'])->toBeFalse()
        ->and($bloque['traverse_roche'])->toBeFalse();
});

it('l\'état publie entites[].traverse_roche = la décision du moteur, pour CE héros', function () {
    ['heros' => $heros, 'groupe' => $groupe] = scenarioPierre();

    $sans = collect(app(EtatGroupe::class)->payload($groupe->fresh())['entites'])->where('type', 'heros')->first();
    expect($sans['traverse_roche'])->toBeFalse();

    app(MoteurSorts::class)->appliquerBuff($heros, Sort::where('nom', 'Traverser la Pierre')->firstOrFail());

    $avec = collect(app(EtatGroupe::class)->payload($groupe->fresh())['entites'])->where('type', 'heros')->first();
    expect($avec['traverse_roche'])->toBeTrue();
});

it('une fois le déplacement FINI, plus aucun pas dans la roche ce tour — ce que l\'annonce dit', function () {
    // Allonce de 2 : le passage (11,10) puis (12,10) la consomme entièrement.
    ['alice' => $alice, 'heros' => $heros, 'etatHeros' => $etat, 'groupe' => $groupe] = scenarioPierre(2);
    app(MoteurSorts::class)->appliquerBuff($heros, Sort::where('nom', 'Traverser la Pierre')->firstOrFail());

    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => 12, 'y' => 10],
    ])->assertStatus(202);

    $etat->refresh();
    expect((bool) $etat->a_deplace)->toBeTrue()
        ->and((int) $etat->deplacement_restant)->toBe(0);

    // Déplacement fini : le menu ne propose plus aucun pas, donc plus de roche…
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    expect(collect($menu['options'])->firstWhere('type', 'deplacement'))->toBeNull();

    // …et le résolveur le confirme : un pas vers la roche est refusé.
    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => 12, 'y' => 11],
    ])->assertStatus(422);
});

it('tant qu\'il reste des points, la roche reste franchissable : on peut y revenir dans le même déplacement', function () {
    // Pin du comportement réel (expérience de 2026-10-09) : l'annonce ne promet pas
    // plus que le moteur ne tient. Ne pas « corriger » ce test sans trancher la règle.
    ['alice' => $alice, 'etatHeros' => $etat, 'heros' => $heros] = scenarioPierre(6);
    app(MoteurSorts::class)->appliquerBuff($heros, Sort::where('nom', 'Traverser la Pierre')->firstOrFail());

    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => 12, 'y' => 10],
    ])->assertStatus(202);

    expect((int) $etat->fresh()->deplacement_restant)->toBe(4)
        ->and((bool) $etat->fresh()->a_deplace)->toBeFalse();

    // Reliquat de 4 : la roche (12,11) reste franchissable, par la même voie.
    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => 12, 'y' => 11],
    ])->assertStatus(202);
});
