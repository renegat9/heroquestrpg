<?php

declare(strict_types=1);

use App\Events\MenuPropose;
use App\Jobs\GenererMenu;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Menu de SECOURS (GenererMenu::failed).
 *
 * Le repli « menu moteur » interne au job ne couvre que l'appel LLM : tout ce
 * qui le précède (MenuMoteur, carte, base) s'exécute hors du try. Une exception
 * là ne laissait AUCUN menu, et comme le menu est la seule chose qui rende la
 * main au joueur, le groupe entier restait gelé sur « Le maître du jeu prépare
 * la suite… » sans le moindre signal (constaté en test de jeu 2026-08-05).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('publie un menu jouable quand le job meurt définitivement', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    // Le job est mort avant toute publication : plus rien en cache.
    Cache::forget(GenererMenu::cleMenu($groupe->id, (int) $alice->id));
    Event::fake([MenuPropose::class]);

    (new GenererMenu($groupe->id, (int) $alice->id, $hero->id))
        ->failed(new RuntimeException('colonne disparue'));

    $cache = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id));

    expect($cache)->not->toBeNull()
        ->and($cache['personnage_id'])->toBe($hero->id)
        ->and(collect($cache['menu']['options'])->pluck('id'))->toContain('attendre');

    Event::assertDispatched(MenuPropose::class);
});

it('reste silencieux si le groupe n\'existe plus', function () {
    $alice = connecterJoueur('alice');

    Event::fake([MenuPropose::class]);

    (new GenererMenu(999_999, (int) $alice->id))->failed(new RuntimeException('boum'));

    Event::assertNotDispatched(MenuPropose::class);
});

it('le menu de secours est accepté par POST choix (le tour peut être passé)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    Cache::forget(GenererMenu::cleMenu($groupe->id, (int) $alice->id));
    (new GenererMenu($groupe->id, (int) $alice->id, $hero->id))
        ->failed(new RuntimeException('colonne disparue'));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertAccepted();
});
