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

it('ne tire PAS sur un héros caché derrière une figure interposée (#7)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin archer');
    ['quete' => $quete, 'instance' => $archer, 'etatHeros' => $etatHeros] = $ctx;
    $hx = (int) $etatHeros->position_x;
    $hy = (int) $etatHeros->position_y;

    // Isole la scène : seul l'archer (+ le bloqueur ci-dessous) est actif.
    $quete->instancesMonstres()->whereKeyNot($archer->id)->update(['etat' => 'vaincu']);

    // Alignement DROIT sol : héros — [case intermédiaire] — archer (distance 2).
    $cases = $quete->carte->grille['cases'];
    $sol = fn ($x, $y) => in_array($cases[$y][$x] ?? 'm', ['s', 'p'], true);
    $trio = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if ($sol($hx + $dx, $hy + $dy) && $sol($hx + 2 * $dx, $hy + 2 * $dy)) {
            $trio = [['x' => $hx + $dx, 'y' => $hy + $dy], ['x' => $hx + 2 * $dx, 'y' => $hy + 2 * $dy]];
            break;
        }
    }
    expect($trio)->not->toBeNull('Pas d\'alignement droit sol pour le scénario.');
    [$inter, $spot] = $trio;

    $archer->update(['position_x' => $spot['x'], 'position_y' => $spot['y']]);
    // Figure INTERPOSÉE (un monstre) entre l'archer et le héros.
    App\Models\InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => App\Models\Monstre::where('nom_base', 'Orque')->value('id'),
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 0,
        'position_x' => $inter['x'], 'position_y' => $inter['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    desFiges(array_fill(0, 200, 4));
    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // L'archer, sans ligne de tir dégagée, ne tire PAS à distance (il approche).
    $actions = collect($reponse->json('resultat.tour_monstres.actions'));
    expect($actions->contains(fn ($a) => ($a['type'] ?? null) === 'attaque_monstre' && ($a['portee'] ?? null) === 'distance'))
        ->toBeFalse();
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
