<?php

declare(strict_types=1);

use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Grandes figurines / monstres multi-cases (Phase 2, 3.9). L'Ogre a une emprise
 * 1×2 : il est attaquable au contact de l'une de ses deux cases, et à la phase
 * des monstres il frappe un héros adjacent à son emprise.
 *
 * Dés tous à 4 (bouclier blanc) → aucun dégât : les attaques ont lieu mais
 * restent déterministes (personne ne tombe).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

it('expose une emprise 1×2 pour l Ogre (1×1 par défaut sinon)', function () {
    $ogre = \App\Models\Monstre::where('nom_base', 'Ogre')->firstOrFail();
    $gobelin = \App\Models\Monstre::where('nom_base', 'Gobelin')->first();

    expect($ogre->emprise())->toBe(['l' => 1, 'h' => 2])
        ->and($ogre->grandeTaille())->toBeTrue();

    if ($gobelin) {
        expect($gobelin->emprise())->toBe(['l' => 1, 'h' => 1])
            ->and($gobelin->grandeTaille())->toBeFalse();
    }
});

it('offre et résout l attaque de l Ogre via le contact de sa case BASSE seule', function () {
    $ctx = demarrerQueteAvecMonstre('Ogre');
    $instance = $ctx['instance'];
    $etat = $ctx['etatHeros'];
    $quete = $ctx['quete'];

    // On cherche une colonne avec 3 cases de sol contiguës libres : héros en bas,
    // emprise 1×2 de l'ogre au-dessus (ancre = case haute). La case BASSE de
    // l'ogre jouxte le héros ; l'ancre (haute) est à 2 cases → NON adjacente.
    $cases = $quete->carte->grille['cases'];
    $run = null;
    foreach ($cases as $y => $ligne) {
        foreach ($ligne as $x => $_) {
            if (caseQueteLibre($quete, $x, $y)
                && caseQueteLibre($quete, $x, $y + 1)
                && caseQueteLibre($quete, $x, $y + 2)) {
                $run = ['x' => $x, 'y' => $y]; // ancre ogre = (x,y) ; héros = (x,y+2)
                break 2;
            }
        }
    }

    if ($run === null) {
        test()->markTestSkipped('Carte sans colonne de 3 cases libres pour ce cas précis.');
    }

    [$ancreX, $ancreY] = [$run['x'], $run['y']];
    $hx = $ancreX;
    $hy = $ancreY + 2;

    $etat->update(['position_x' => $hx, 'position_y' => $hy]);
    $instance->update(['position_x' => $ancreX, 'position_y' => $ancreY]);

    // L'ancre seule n'est PAS Manhattan-adjacente au héros (distance 2) : seul le
    // contact de la case BASSE de l'emprise rend l'ogre attaquable.
    expect(abs($ancreX - $hx) + abs($ancreY - $hy))->toBe(2);

    // Le menu moteur DOIT offrir l'attaque (adjacence à l'emprise).
    \App\Jobs\GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    desFiges(array_fill(0, 50, 4)); // boucliers : aucun dégât

    test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$instance->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'attaque');
});

it('fait attaquer l Ogre, à la phase des monstres, un héros adjacent à son emprise', function () {
    $ctx = demarrerQueteAvecMonstre('Ogre');

    desFiges(array_fill(0, 200, 4)); // boucliers blancs : aucun dégât

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $attaques = collect($reponse->json('resultat.tour_monstres.actions'))
        ->where('type', 'attaque_monstre')
        ->values();

    expect($attaques)->toHaveCount(1)
        ->and($attaques[0]['monstre'])->toBe('Ogre');
});

it('expose l emprise du monstre dans l état du groupe', function () {
    $ctx = demarrerQueteAvecMonstre('Ogre');

    $etat = app(\App\Partie\EtatGroupe::class)->payload($ctx['groupe']->fresh());

    $ogre = collect(data_get($etat, 'entites', []))->firstWhere('nom', 'Ogre');

    expect($ogre)->not->toBeNull()
        ->and($ogre['emprise'])->toBe(['l' => 1, 'h' => 2]);
});
