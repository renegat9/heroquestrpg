<?php

declare(strict_types=1);

use App\Partie\MenuMoteur;
use App\Partie\Votes\VoteGroupe;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LE MENU N'OFFRE PAS CE QUE LE RÉSOLVEUR REFUSERA — cas du vote déjà ouvert
 * (trouvé en partie réelle le 2026-08-30).
 *
 * `quitter_donjon` et `battre_en_retraite` OUVRENT toutes deux un vote de
 * groupe, et `VoteGroupe` en refuse un second par un 422. Le menu continuait
 * pourtant de les proposer à chaque tour pendant que le vote qu'il venait
 * d'ouvrir attendait des bulletins : le joueur s'est heurté au refus dix-sept
 * fois de suite avant que le pilote n'abandonne.
 *
 * ⚠ C'est exactement l'anti-patron que le projet traque ailleurs (menu servi
 * hors tour, menu en cache d'un héros tombé) : une option cliquable qui répond
 * toujours non n'est pas une option, c'est un piège.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, CompetenceSeeder::class]);
});

/** Ids d'options du menu moteur pour ce héros. */
function idsMenu($ctx): array
{
    return collect(app(MenuMoteur::class)->generer($ctx['groupe']->fresh(), $ctx['heros']->fresh())['options'])
        ->pluck('id')
        ->all();
}

it('retire les deux options de VOTE tant qu\'un vote est ouvert', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    // Sans vote : « battre en retraite » est offert (elle n'a AUCUNE condition).
    expect(idsMenu($ctx))->toContain('battre_en_retraite');

    // Un vote s'ouvre — peu importe lequel, la garde du résolveur est la même.
    app(VoteGroupe::class)->lancerRetraite($ctx['groupe']->fresh(), [
        'personnage_id' => $ctx['heros']->id,
        'nom' => $ctx['heros']->nom,
    ]);

    expect(VoteGroupe::enCours($ctx['groupe']->id))->toBeTrue();

    $ids = idsMenu($ctx);

    expect($ids)->not->toContain('battre_en_retraite')
        ->and($ids)->not->toContain('quitter_donjon');
});

it('les rend dès que le vote est retombé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    app(VoteGroupe::class)->lancerRetraite($ctx['groupe']->fresh(), [
        'personnage_id' => $ctx['heros']->id,
        'nom' => $ctx['heros']->nom,
    ]);
    expect(idsMenu($ctx))->not->toContain('battre_en_retraite');

    // ⚠ Le vote vit dans le CACHE (TTL 6 h) : c'est cette clé, et elle seule,
    // que la garde consulte. Si elle changeait de nom, l'option resterait
    // masquée pour toujours — d'où le passage par `VoteGroupe::cle()`.
    Illuminate\Support\Facades\Cache::forget(VoteGroupe::cle($ctx['groupe']->id));

    expect(VoteGroupe::enCours($ctx['groupe']->id))->toBeFalse()
        ->and(idsMenu($ctx))->toContain('battre_en_retraite');
});
