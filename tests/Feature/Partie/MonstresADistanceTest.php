<?php

declare(strict_types=1);

use App\Partie\Grille;
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
 * Monstres à distance (Phase 2, 3.4) : avec ligne de vue, le monstre TIRE sans
 * avoir besoin d'être adjacent (dés `attaque_distance`) ; au contact il frappe
 * en corps-à-corps (dés de mêlée, moindres). Dés tous à 4 → aucun dégât, MODE
 * déterministe (on vérifie la portée, pas les dégâts).
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

it('tire à distance sur un héros en ligne de vue, sans adjacence', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin archer');
    ['quete' => $quete, 'instance' => $archer, 'etatHeros' => $etatHeros] = $ctx;

    $hx = (int) $etatHeros->position_x;
    $hy = (int) $etatHeros->position_y;

    // Repositionne l'archer sur une case à distance (>1) avec ligne de vue dégagée.
    $grille = Grille::depuisCarte($quete->carte);
    $spot = null;
    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true)) {
                continue;
            }
            if (abs($x - $hx) + abs($y - $hy) < 2) {
                continue; // même case ou adjacent
            }
            if ($grille->ligneDeVue($hx, $hy, $x, $y)) {
                $spot = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }
    expect($spot)->not->toBeNull('Aucune case à distance avec ligne de vue sur la carte.');

    $archer->update(['position_x' => $spot['x'], 'position_y' => $spot['y']]);

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'attaque_monstre');

    expect($attaque)->not->toBeNull()
        ->and($attaque['portee'])->toBe('distance');

    // L'archer n'a PAS eu besoin de se coller au héros : il est resté à distance.
    $archer->refresh();
    expect(abs((int) $archer->position_x - $hx) + abs((int) $archer->position_y - $hy))
        ->toBeGreaterThan(1);
});

it('frappe en corps-à-corps (et non en tir) quand le héros est adjacent', function () {
    // demarrerQueteAvecMonstre place le monstre AU CONTACT du héros.
    $ctx = demarrerQueteAvecMonstre('Gobelin archer');

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))
        ->firstWhere('type', 'attaque_monstre');

    expect($attaque)->not->toBeNull()
        ->and($attaque['portee'])->toBe('corps_a_corps');
});
