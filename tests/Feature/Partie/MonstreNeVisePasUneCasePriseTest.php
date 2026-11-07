<?php

declare(strict_types=1);

use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
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
 * Partie réelle du 2026-11-06 (Trio, quête 187) : « Il y avait 3 monstres qui pouvaient m'attaquer et seulement 1 l'a
 * fait ». L'Orque au contact frappait ; le Fimir, en diagonale, visait la case de l'Orque (la plus courte sur la grille
 * de passage, où les monstres se franchissent), ne pouvait pas s'y arrêter, et restait sur place — une case libre au
 * contact à un pas.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Une case (x, y) de la quête dont l'ouest, le nord et le nord-ouest sont libres : la scène de ce soir-là. */
function sceneDuCoinLibre(Quete $quete, array $exclues): array
{
    $cases = $quete->carte->grille['cases'];

    foreach ($cases as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            $toutes = [[$x, $y], [$x - 1, $y], [$x, $y - 1], [$x - 1, $y - 1]];

            if (collect($toutes)->every(fn ($p) => caseQueteLibre($quete, $p[0], $p[1]) || in_array($p, $exclues, true))) {
                return ['x' => $x, 'y' => $y];
            }
        }
    }

    throw new RuntimeException('Aucun coin libre sur cette carte — scénario de test invalide.');
}

it('fait marcher le monstre en diagonale vers la case LIBRE au contact plutôt que vers celle d\'un autre monstre', function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    $exclues = [[(int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y], [(int) $ctx['instance']->position_x, (int) $ctx['instance']->position_y]];
    $coin = sceneDuCoinLibre($ctx['quete'], $exclues);

    // Le héros au coin, l'Orque à l'ouest (au contact), le Fimir au nord-ouest (en diagonale, PAS au contact).
    $ctx['etatHeros']->update(['position_x' => $coin['x'], 'position_y' => $coin['y']]);
    $ctx['instance']->update(['position_x' => $coin['x'] - 1, 'position_y' => $coin['y']]);
    $fimir = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => Monstre::where('nom_base', 'Fimir')->firstOrFail()->id,
        'pv_body' => 2, 'pv_body_max' => 2, 'pv_mind' => 3,
        'position_x' => $coin['x'] - 1, 'position_y' => $coin['y'] - 1, 'etat' => 'actif', 'revele' => true,
    ]);

    desFiges(array_fill(0, 300, 4));

    $actions = collect(test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->json('resultat.tour_monstres.actions'));

    $attaquants = $actions->where('type', 'attaque_monstre')->pluck('instance_id')->all();

    expect($attaquants)->toContain($ctx['instance']->id)
        ->and($attaquants)->toContain($fimir->id)
        // Il s'est arrêté sur la case LIBRE au contact (au nord du héros), jamais sur celle de l'Orque.
        ->and([(int) $fimir->fresh()->position_x, (int) $fimir->fresh()->position_y])->toBe([$coin['x'], $coin['y'] - 1]);
});
