<?php

declare(strict_types=1);

use App\Models\Monstre;
use Database\Seeders\MonstreSeeder;

// Registre `config/barks.php` → `archetypes`, testé DANS LES DEUX SENS (CLAUDE.md :
// « a registry is tested BOTH WAYS »). Jusqu'au 2026-10-20, seuls les 8 monstres de
// base, le Champion et le Seigneur avaient une voix : les 63 créatures des
// extensions retombaient en silence sur `defaut` — le Dragon, la Liche et le
// Gremlin des glaces disaient les mêmes « Grrraah ! » de la même voix.

beforeEach(fn () => $this->seed(MonstreSeeder::class));

it('donne une voix déclarée à CHAQUE monstre du bestiaire', function () {
    $archetypes = (array) config('barks.archetypes');

    $sansVoix = Monstre::query()->pluck('nom_base')
        ->reject(fn (string $nom) => array_key_exists($nom, $archetypes))
        ->values()->all();

    expect($sansVoix)->toBe([]);
});

it('ne déclare la voix d\'aucun monstre absent du bestiaire', function () {
    $connus = Monstre::query()->pluck('nom_base')->all();

    $orphelins = array_values(array_diff(array_keys((array) config('barks.archetypes')), $connus));

    expect($orphelins)->toBe([]);
});

it('chaque profil désigné existe, a une voix, et des répliques pour chaque événement', function () {
    $profils = (array) config('barks.profils');
    $lignes = (array) config('barks.lignes');

    foreach (array_unique(array_values((array) config('barks.archetypes'))) as $profil) {
        expect($profils)->toHaveKey($profil)
            ->and($profils[$profil]['voix'] ?? null)->not->toBeEmpty();

        foreach ((array) config('barks.evenements') as $evenement) {
            expect($lignes[$profil][$evenement] ?? [])->not->toBeEmpty("{$profil}/{$evenement} sans réplique");
        }
    }
});

it('aucun profil déclaré ne reste sans monstre (sauf le repli `defaut`)', function () {
    $utilises = array_unique(array_values((array) config('barks.archetypes')));

    $inutiles = array_values(array_diff(array_keys((array) config('barks.profils')), [...$utilises, 'defaut']));

    expect($inutiles)->toBe([]);
});
