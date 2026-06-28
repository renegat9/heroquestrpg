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
 * Capacités de monstre à choix tactique (Phase 2, 3.7) : l'« Ours polaire de
 * guerre » frappe en coup massif unique si la cible est robuste (PV > seuil),
 * sinon en double attaque. Décision 100 % moteur (ResolveurTour::jouerMonstre).
 *
 * Dés tous à 4 (bouclier blanc) → aucun crâne, aucun dégât : les attaques ont
 * lieu mais ne font pas tomber le héros, ce qui rend le MODE déterministe.
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

it('frappe en coup massif unique quand la cible est robuste (PV > seuil)', function () {
    // Héros à 8 PV (> seuil 2) → mode massif.
    $ctx = demarrerQueteAvecMonstre('Ours polaire de guerre');

    desFiges(array_fill(0, 200, 4)); // boucliers blancs : aucun dégât

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $attaques = collect($reponse->json('resultat.tour_monstres.actions'))
        ->where('type', 'attaque_monstre')
        ->values();

    expect($attaques)->toHaveCount(1)
        ->and($attaques[0]['mode'])->toBe('massive');
});

it('enchaîne une double attaque quand la cible est affaiblie (PV <= seuil)', function () {
    // Héros à 2 PV (== seuil) → mode double : deux attaques.
    $ctx = demarrerQueteAvecMonstre('Ours polaire de guerre', [
        'pv_body_max' => 2, 'pv_body' => 2,
    ]);

    desFiges(array_fill(0, 200, 4)); // boucliers blancs : aucun dégât, le héros ne tombe pas

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $attaques = collect($reponse->json('resultat.tour_monstres.actions'))
        ->where('type', 'attaque_monstre')
        ->values();

    expect($attaques)->toHaveCount(2)
        ->and($attaques[0]['mode'])->toBe('double')
        ->and($attaques[1]['mode'])->toBe('double')
        ->and($attaques[0]['coup'])->toBe(1)
        ->and($attaques[1]['coup'])->toBe(2);
});
