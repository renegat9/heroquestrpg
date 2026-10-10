<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Condition;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Textes du catalogue (verdict Morcar, 2026-10-09 § 5) : ni le menu ni /moi ne décrivaient
 * Voile de Brume ni Eau de Guérison, et « Vaporeux » / « Intangible » n'avaient aucune
 * description. Chaque sort et chaque condition du catalogue porte désormais son texte —
 * et ce texte remonte jusqu'à la manette.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([SortSeeder::class, ConditionSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('chaque SORT du catalogue a une description — et le seeder ne décrit que des sorts qui existent', function () {
    $declares = array_keys((new ReflectionClassConstant(SortSeeder::class, 'DESCRIPTIONS'))->getValue());

    expect(Sort::count())->toBeGreaterThan(0)
        ->and(Sort::whereNull('description')->orWhere('description', '')->count())->toBe(0)
        // Dans les DEUX sens : chaque sort a un texte, et chaque texte nomme un sort.
        ->and(Sort::count())->toBe(count($declares))
        ->and(Sort::whereIn('nom', $declares)->count())->toBe(count($declares));
});

it('chaque CONDITION du catalogue a une description — et le seeder ne décrit que des conditions qui existent', function () {
    $declares = array_keys((new ReflectionClassConstant(ConditionSeeder::class, 'DESCRIPTIONS'))->getValue());

    expect(Condition::count())->toBeGreaterThan(0)
        ->and(Condition::whereNull('description')->orWhere('description', '')->count())->toBe(0)
        ->and(Condition::count())->toBe(count($declares))
        ->and(Condition::whereIn('nom', $declares)->count())->toBe(count($declares));
});

it('Voile de Brume, Eau de Guérison, Vaporeux et Intangible disent ce qu\'ils font', function () {
    expect(Sort::where('nom', 'Voile de Brume')->value('description'))->toContain('traverse', 'monstres')
        ->and(Sort::where('nom', 'Eau de Guérison')->value('description'))->toContain('4 points de Body')
        ->and(Condition::where('nom', 'Vaporeux')->value('description'))->toContain('traverse')
        ->and(Condition::where('nom', 'Intangible')->value('description'))->toContain('murs');
});

it('/moi publie la description des sorts du héros (Voile de Brume, Eau de Guérison)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    app(MoteurSorts::class)->attacherElement($hero, 'eau');

    $sorts = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages.0.sorts'));

    expect($sorts->firstWhere('nom', 'Voile de Brume')['description'])->toBeString()->not->toBe('')
        ->and($sorts->firstWhere('nom', 'Eau de Guérison')['description'])->toContain('4 points de Body');
});

it('chaque entrée de sort proposée par le menu porte sa description', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    app(MoteurSorts::class)->attacherElement($hero, 'feu');
    app(MoteurSorts::class)->attacherElement($hero, 'eau');

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $entrees = collect($menu['options'])
        ->filter(fn ($o) => $o['type'] === 'sort')
        ->flatMap(fn ($o) => $o['parametres']['sorts'] ?? []);

    expect($entrees)->not->toBeEmpty()
        ->and($entrees->every(fn ($e) => is_string($e['description'] ?? null) && $e['description'] !== ''))->toBeTrue();
});

it('l\'état du groupe publie la description des conditions du héros (Vaporeux)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated(); // les héros ne sont publiés qu'en quête
    $hero->conditions()->attach(Condition::where('nom', 'Vaporeux')->value('id'), [
        'duree' => 0, 'source' => 'sort:Voile de Brume',
    ]);

    $entites = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites'));
    $condition = collect($entites->firstWhere('id', $hero->id)['conditions'])->firstWhere('nom', 'Vaporeux');

    expect($condition['description'])->toContain('traverse');
});
