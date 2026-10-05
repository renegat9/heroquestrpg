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
 * L'« Ours polaire de guerre » frappe DEUX fois par tour : « attacks once with
 * its mighty paw and once with its spiked mace. Two attacks can be made against
 * one opponent or one attack can be made against each of two different
 * opponents » (The Frozen Horror, p. 37 — `docs/plan-correctifs-2026-10-04.md`
 * C4). Décision 100 % moteur (`ResolveurTour::deuxAttaques()`) : les deux coups
 * sur la cible, le second passe à un autre héros au contact si elle tombe.
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

function attaquesDeLOurs(array $ctx): Illuminate\Support\Collection
{
    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    return collect($reponse->json('resultat.tour_monstres.actions'))
        ->where('type', 'attaque_monstre')
        ->values();
}

it('frappe deux fois une cible seule, en UN jet de défense (Frozen Horror p. 9)', function () {
    $ctx = demarrerQueteAvecMonstre('Ours polaire de guerre');

    desFiges(array_fill(0, 200, 4)); // boucliers blancs : aucun dégât

    $attaques = attaquesDeLOurs($ctx);
    $ours = App\Models\Monstre::where('nom_base', 'Ours polaire de guerre')->firstOrFail();

    // Deux attaques de N dés contre une défense = une volée de 2N dés.
    expect($attaques)->toHaveCount(1)
        ->and($attaques[0]['mode'])->toBe('deux_attaques')
        ->and($attaques[0]['repartition'])->toBe('une_cible')
        ->and($attaques[0]['faces_attaque'])->toHaveCount(2 * (int) $ours->attaque)
        ->and($attaques[0]['faces_defense'])->toHaveCount((int) $ctx['heros']->fresh()->des_defense);
});

it('répartit ses deux attaques quand un second héros est au contact', function () {
    $ctx = demarrerQueteAvecMonstre('Ours polaire de guerre');

    $bob = connecterJoueur('bob');
    $second = creerHeros($bob, $ctx['groupe'], 'Bertrand', 2);
    $case = caseAdjacenteLibre($ctx['quete'], (int) $ctx['instance']->position_x, (int) $ctx['instance']->position_y);
    App\Models\EtatPersonnageQuete::create([
        'quete_id' => $ctx['quete']->id, 'personnage_id' => $second->id,
        'position_x' => $case['x'], 'position_y' => $case['y'],
        'tombe' => false, 'a_joue' => true,
    ]);

    desFiges(array_fill(0, 200, 4));

    $attaques = attaquesDeLOurs($ctx);

    expect($attaques)->toHaveCount(2)
        ->and($attaques->pluck('repartition')->unique()->all())->toBe(['deux_cibles'])
        ->and($attaques->pluck('cible.personnage_id')->unique())->toHaveCount(2);
});
