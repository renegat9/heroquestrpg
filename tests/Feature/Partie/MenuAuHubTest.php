<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Partie\MenuMoteur;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * AU HUB, AUCUN MENU DE QUÊTE (partie réelle, 2026-10-17).
 * Après une quête, `GET /menu` servait le repli « Le groupe se prépare au hub —
 * attendre / continuer », et « attendre » était acceptée sans effet : le harnais disait
 * pourtant qu'il n'y a pas de menu au hub. Le filet « Terminer le tour » reste, lui, pour
 * une partie EN COURS — et seulement celle-là.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Un héros, quête démarrée, son menu de quête en cache : [alice, groupe, héros, clé du menu]. */
function demarrerQuetePourHub(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    return [$alice, $groupe, $hero, GenererMenu::cleMenu($groupe->id, (int) $alice->id)];
}

it('au hub, GET /menu ne sert JAMAIS le menu de la quête finie, et « attendre » est refusée', function () {
    [, $groupe, , $cle] = demarrerQuetePourHub();
    expect(Cache::has($cle))->toBeTrue('le menu de quête doit être en cache avant la fin de la quête');

    acheverLaQuete($groupe);
    expect($groupe->fresh()->phase)->toBe('hub');

    // Le menu resté en cache est jeté à la lecture, pas servi.
    $this->getJson('/api/groupes/table-1/menu')->assertOk()->assertJsonPath('menu', null);
    expect(Cache::has($cle))->toBeFalse();

    // Un choix forgé sur l'ancienne option « attendre » est refusé, sans effet.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('option_id');
});

it('au hub, le job de menu ne publie RIEN : ni le repli « attendre / continuer », ni « Terminer le tour »', function () {
    [$alice, $groupe, $hero, $cle] = demarrerQuetePourHub();
    acheverLaQuete($groupe);
    Cache::forget($cle);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    expect(Cache::has($cle))->toBeFalse();

    // Le filet n'a pas davantage de partie à remettre en marche au hub.
    (new GenererMenu($groupe->id, (int) $alice->id, (int) $hero->id))->failed(new RuntimeException('boum'));
    expect(Cache::has($cle))->toBeFalse();
});

it('au hub, le moteur de menu ne fabrique aucun menu de quête', function () {
    [, $groupe, $hero] = demarrerQuetePourHub();
    acheverLaQuete($groupe);

    expect(fn () => app(MenuMoteur::class)->generer($groupe->fresh(), $hero->fresh()))
        ->toThrow(LogicException::class);
});

it('en quête, le filet « Terminer le tour » est toujours publié quand le job meurt', function () {
    [$alice, $groupe, $hero, $cle] = demarrerQuetePourHub();
    Cache::forget($cle);

    (new GenererMenu($groupe->id, (int) $alice->id, (int) $hero->id))->failed(new RuntimeException('boum'));

    $libelles = collect(Cache::get($cle)['menu']['options'] ?? [])->pluck('libelle')->all();
    expect($libelles)->toContain('Terminer le tour');
});
