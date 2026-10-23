<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
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
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * SPIRIT QUEEN'S TORMENT — lot C : le DOUBLE SORT (`double_sort`), Kavra et Nelath EN JEU (chantier EXT-SPIRIT, 2026-10-23).
 *
 *  - Kavra (G0053 p. 11) : « On her first turn only, she may cast both spells simultaneously. » Capacité
 *    `double_sort` { premier_tour } : deux sorts au PREMIER tour de Dread joué, un seul ensuite.
 *  - Nelath (G0053 p. 33) : « She may cast two spells in a single turn. » Capacité nue : deux sorts à CHAQUE tour.
 *  - Un sorcier sans la capacité (le Sorcier du Dread générique) reste à UN sort par tour : la règle générale ne bouge pas.
 *  - Budget : chaque sort se paie un usage du budget du palier (précédent Fellmarak). Les tests qui vérifient la
 *    CADENCE relèvent le budget à la main, pour que le budget ne masque pas la règle testée.
 *
 * Tests en jeu, par les vraies routes (`POST /choix` puis `GenererMenu`), dés injectés. Chaque cas de preuve dit,
 * dans son commentaire, ce que l'ancien code faisait (un seul sort par tour, sans relecture de la capacité).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

/** Les sorts de Dread lancés dans le tour des monstres de la dernière réponse, dans l'ordre. */
function sortsLancesAuTour(\Illuminate\Testing\TestResponse $reponse): array
{
    return collect($reponse->json('resultat.tour_monstres.actions') ?? [])
        ->where('type', 'sort_dread')
        ->pluck('sort')
        ->values()
        ->all();
}

/** Un tour complet du héros : une nouvelle carte de choix, puis « Attendre » (les monstres jouent). */
function attendreUnTour(array $ctx): \Illuminate\Testing\TestResponse
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);
}

it('Kavra lance DEUX sorts de Dread au premier tour, et jamais le même (dés injectés)', function () {
    // Sans `double_sort`, le résolveur de Dread s'arrêtait au premier sort : le tour ne contenait qu'un
    // `sort_dread`. La sorcière, elle, dispose de ses deux sorts d'un coup.
    $ctx = demarrerQueteAvecMonstre('Kavra', ['pv_mind' => 20, 'pv_mind_max' => 20]);

    desFiges(array_fill(0, 200, 4));

    $reponse = attendreUnTour($ctx);

    $lances = sortsLancesAuTour($reponse);

    expect($lances)->toHaveCount(2, 'Kavra doit lancer deux sorts au premier tour')
        ->and(array_unique($lances))->toHaveCount(2, 'deux sorts DIFFÉRENTS')
        ->and($lances)->each->toBeIn(config('archetypes_lanceurs.kavra_sorciere.sorts'));

    // Chaque sort se paie un usage : le budget de sous-boss (2) est épuisé par les deux.
    expect((int) $ctx['instance']->fresh()->usages_dread)->toBe(0)
        ->and((int) $ctx['instance']->fresh()->tours_dread_joues)->toBe(1);
});

it('Kavra ne lance son double sort qu\'AU PREMIER tour : au suivant, un sort seul (budget relevé pour l\'isoler)', function () {
    // Preuve de la clause « premier tour seulement » : le budget est relevé à la main après le premier tour, pour
    // que seule la règle de premier tour décide. Sans le lecteur `premier_tour`, Kavra relancerait deux sorts.
    $ctx = demarrerQueteAvecMonstre('Kavra', ['pv_mind' => 20, 'pv_mind_max' => 20]);

    desFiges(array_fill(0, 400, 4));

    attendreUnTour($ctx);
    $ctx['instance']->fresh()->update(['usages_dread' => 5]);

    $reponse = attendreUnTour($ctx);

    expect(sortsLancesAuTour($reponse))->toHaveCount(1, 'au second tour, Kavra retombe à un sort par tour')
        ->and((int) $ctx['instance']->fresh()->tours_dread_joues)->toBe(2);
});

it('Nelath lance DEUX sorts à CHAQUE tour (capacité nue) : le premier tour comme le second', function () {
    // Preuve de la forme nue : sans relecture, Nelath (boss) ne donnait qu'un sort par tour. Budget relevé à la main
    // pour que le compteur de usages ne limite pas la cadence qu'on veut voir.
    $ctx = demarrerQueteAvecMonstre('Nelath, la Reine des Esprits', ['pv_mind' => 20, 'pv_mind_max' => 20]);
    $ctx['instance']->update(['usages_dread' => 9]);

    desFiges(array_fill(0, 400, 4));

    $premier = attendreUnTour($ctx);
    $second = attendreUnTour($ctx);

    expect(sortsLancesAuTour($premier))->toHaveCount(2, 'premier tour : deux sorts')
        ->and(sortsLancesAuTour($second))->toHaveCount(2, 'second tour : deux sorts encore')
        ->and(sortsLancesAuTour($second))->each->toBeIn(config('archetypes_lanceurs.nelath_reine_des_esprits.sorts'));
});

it('un sorcier SANS double_sort reste à UN sort par tour : la règle générale ne bouge pas', function () {
    // Témoin : le Sorcier du Dread générique (sous-boss, répertoire de cinq sorts, aucune capacité `double_sort`).
    $ctx = demarrerQueteAvecMonstre('Sorcier du Dread', ['pv_mind' => 20, 'pv_mind_max' => 20]);
    $ctx['instance']->update(['usages_dread' => 9]);

    expect(Monstre::where('nom_base', 'Sorcier du Dread')->firstOrFail()->capacites)->not->toHaveKey('double_sort');

    desFiges(array_fill(0, 200, 4));

    $reponse = attendreUnTour($ctx);

    expect(sortsLancesAuTour($reponse))->toHaveCount(1);
});

it('la CAPACITÉ est déclarée sur les deux fiches, et sur aucune autre', function () {
    // Le registre (`MotsClesAdversaires`) est testé dans les deux sens par `AdversairesNommesTest` ; ici, la donnée :
    // exactement les deux sorciers de la boîte la portent, avec la forme que leur livret donne.
    $porteurs = Monstre::all()
        ->filter(fn (Monstre $m) => array_key_exists('double_sort', (array) $m->capacites)
            || in_array('double_sort', (array) $m->capacites, true))
        ->pluck('nom_base')->sort()->values()->all();

    expect($porteurs)->toBe(['Kavra', 'Nelath, la Reine des Esprits']);

    expect(Monstre::where('nom_base', 'Kavra')->firstOrFail()->capacites)
        ->toBe(['double_sort' => ['premier_tour' => true]])
        ->and(Monstre::where('nom_base', 'Nelath, la Reine des Esprits')->firstOrFail()->capacites)
        ->toBe(['double_sort']);
});
