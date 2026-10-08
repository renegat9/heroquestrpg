<?php

declare(strict_types=1);

use App\Models\Sort;
use Database\Seeders\SortSeeder;

/**
 * « Les cartes sont la source » pour les SORTS DE HÉROS de Wizards of Morcar
 * (`config/cartes.php`, section `sorts_heros`), confronté au catalogue DANS LES
 * DEUX SENS — le même principe que `SortsDreadSourcesTest` pour la magie du MJ.
 *
 *  1. toute carte PORTÉE (`sort`) existe en base, dans le répertoire qui lui
 *     correspond (Spells of Protection = `protection`, etc.) ;
 *  2. aucun sort de ces trois répertoires n'existe en base SANS carte ;
 *  3. une carte NON portée (`manque`) n'est PAS en base : sinon elle serait
 *     semée à moitié, une règle promise au joueur et jamais tenue.
 */
beforeEach(function () {
    $this->seed([SortSeeder::class]);
});

/** Répertoire de la carte → élément porté par `sorts.element`. */
function repertoiresMorcar(): array
{
    return [
        'Spells of Protection' => 'protection',
        'Spells of Detection' => 'detection',
        'Spells of Darkness' => 'tenebres',
    ];
}

it('recense les neuf sorts des trois répertoires, portés et non portés', function () {
    $cartes = collect(config('cartes.sorts_heros.cartes'));

    expect($cartes)->toHaveCount(9);

    // Les NEUF sont portés depuis le 2026-10-08 (*Future Sight* → « Vision du futur »,
    // *Cloak of Shadows* → « Voile d'ombre »). Une carte écartée à l'avenir porterait
    // `nom`, `texte` et `manque` — la boucle ci-dessous les exige.
    expect($cartes->whereNotNull('sort'))->toHaveCount(9);

    foreach ($cartes->whereNull('sort') as $carte) {
        expect($carte['texte'] ?? '')->not->toBeEmpty("{$carte['carte']} : texte de carte manquant")
            ->and($carte['manque'] ?? '')->not->toBeEmpty("{$carte['carte']} : mécanique manquante non dite")
            ->and($carte['nom'] ?? '')->not->toBeEmpty("{$carte['carte']} : nom français manquant");
    }
});

it('toute carte marquée PORTÉE existe au catalogue, dans le bon répertoire', function () {
    $repertoires = repertoiresMorcar();

    foreach (collect(config('cartes.sorts_heros.cartes'))->whereNotNull('sort') as $carte) {
        $sort = Sort::where('nom', $carte['sort'])->first();

        expect($sort)->not->toBeNull("{$carte['carte']} → « {$carte['sort']} » absent du catalogue.");
        expect($sort->element)->toBe(
            $repertoires[$carte['paquet']],
            "{$carte['carte']} : répertoire « {$carte['paquet']} » ≠ élément « {$sort->element} ».",
        );
    }
});

it('aucun sort de ces trois répertoires n\'existe SANS carte source', function () {
    $declares = collect(config('cartes.sorts_heros.cartes'))->pluck('sort')->filter()->all();

    $orphelins = Sort::whereIn('element', array_values(repertoiresMorcar()))
        ->pluck('nom')
        ->reject(fn ($nom) => in_array($nom, $declares, true))
        ->values()
        ->all();

    expect($orphelins)->toBe([], 'Sorts de héros sans carte source : '.implode(', ', $orphelins));
});

it('une carte NON portée n\'est PAS au catalogue', function () {
    $intrus = [];

    foreach (collect(config('cartes.sorts_heros.cartes'))->whereNull('sort') as $carte) {
        if (Sort::where('nom', $carte['nom'])->exists()) {
            $intrus[] = $carte['nom'];
        }
    }

    expect($intrus)->toBe([], 'Cartes déclarées non portées mais présentes en base : '
        .implode(', ', $intrus).' — déclare `sort` sur la carte et retire `manque`.');
});

it('déclare *Unlearn* sous son nom anglais, catalogué en français', function () {
    $carte = collect(config('cartes.sorts_heros.cartes'))->firstWhere('carte', 'Unlearn');

    expect($carte['sort'] ?? null)->toBe('Désapprentissage');
    expect(Sort::where('nom', 'Unlearn')->exists())->toBeFalse();
    expect(Sort::where('nom', 'Désapprentissage')->where('element', 'protection')->exists())->toBeTrue();
});
