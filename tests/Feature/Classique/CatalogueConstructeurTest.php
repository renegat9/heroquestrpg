<?php

declare(strict_types=1);

use App\Models\ClasseHeros;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Piege;
use App\Models\Terrain;
use App\Partie\Classique\CatalogueConstructeur;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Schema;

/*
 * Catalogue du constructeur (MODE CLASSIQUE, lot K1) : le slug est `Str::slug()` du nom, calculé dans UN seul endroit ;
 * les tables n'ont pas de colonne slug ; l'index est juste dans les deux sens sur les catalogues SEMÉS, et lève une
 * erreur nommée quand deux lignes donnent le même slug.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('calcule le slug par Str::slug du nom', function () {
    expect(CatalogueConstructeur::slug('Guerrier du Chaos'))->toBe('guerrier-du-chaos')
        ->and(CatalogueConstructeur::slug('Piège à lances'))->toBe('piege-a-lances');
});

it('ne dote aucune table de catalogue d\'une colonne slug', function () {
    foreach (['mobiliers', 'monstres', 'pieges', 'terrains', 'classes_heros'] as $table) {
        expect(Schema::hasColumn($table, 'slug'))->toBeFalse();
    }
});

it('donne un slug unique à chaque ligne de chaque catalogue seedé (aucune collision)', function (string $modele, string $colonne) {
    $slugs = $modele::query()->get()
        ->map(fn ($ligne): string => CatalogueConstructeur::slug((string) $ligne->{$colonne}))
        ->all();

    expect(array_unique($slugs))->toHaveCount(count($slugs));
})->with([
    'mobilier' => [Mobilier::class, 'nom'],
    'monstres' => [Monstre::class, 'nom_base'],
    'pièges' => [Piege::class, 'nom'],
    'terrain' => [Terrain::class, 'nom'],
    'classes' => [ClasseHeros::class, 'nom'],
]);

it('l\'index d\'une couche contient chaque ligne de la base, et rien d\'autre', function (string $couche, string $modele, string $colonne) {
    $index = app(CatalogueConstructeur::class)->index($couche);
    $lignes = $modele::query()->get();

    // Sens 1 : chaque ligne de la base est à sa place dans l'index.
    foreach ($lignes as $ligne) {
        $slug = CatalogueConstructeur::slug((string) $ligne->{$colonne});
        expect($index)->toHaveKey($slug)
            ->and($index[$slug]->getKey())->toBe($ligne->getKey());
    }

    // Sens 2 : l'index ne contient pas plus que la base.
    expect($index)->toHaveCount($lignes->count());
})->with([
    'mobilier' => ['mobilier', Mobilier::class, 'nom'],
    'monstres' => ['monstres', Monstre::class, 'nom_base'],
    'pièges' => ['pieges', Piege::class, 'nom'],
    'terrain' => ['terrain', Terrain::class, 'nom'],
    'classes' => ['classes', ClasseHeros::class, 'nom'],
]);

it('contient() dit oui pour un slug du catalogue, et non pour un slug inconnu', function () {
    $catalogue = app(CatalogueConstructeur::class);

    expect($catalogue->contient('monstres', 'gobelin'))->toBeTrue()
        ->and($catalogue->contient('monstres', 'monstre-inexistant'))->toBeFalse()
        // Un slug de monstre n'est pas un slug de piège : chaque couche a son index.
        ->and($catalogue->contient('pieges', 'gobelin'))->toBeFalse();
});

it('palette() rend, par couche, un slug et un nom par ligne, triés par nom', function () {
    $palette = app(CatalogueConstructeur::class)->palette('monstres');
    $noms = array_column($palette, 'nom');
    $attendus = $noms;
    sort($attendus, SORT_STRING); // le tri SQL de la base de test (sqlite) est un tri binaire

    expect($palette)->not->toBeEmpty()
        ->and(array_keys($palette[0]))->toBe(['slug', 'nom'])
        ->and($noms)->toBe($attendus);
});

it('payload() publie les couches posables, les classes et les vocabulaires lus dans ValidationCampagne', function () {
    $payload = app(CatalogueConstructeur::class)->payload();

    expect(array_keys($payload['couches']))->toBe(['mobilier', 'monstres', 'pieges', 'terrain'])
        ->and($payload['classes'])->not->toBeEmpty()
        ->and($payload['vocabulaires'])->toBe([
            'rotations' => ValidationCampagne::ROTATIONS,
            'cotes' => ValidationCampagne::COTES,
            'types_porte' => ValidationCampagne::TYPES_PORTE,
            'partages' => ValidationCampagne::PARTAGES,
            'types_fin' => ValidationCampagne::TYPES_FIN,
            'formats_acceptes' => ValidationCampagne::FORMATS_ACCEPTES,
        ]);
});

it('lève une erreur nommée quand deux lignes d\'une même couche donnent le même slug', function () {
    // « Étroite » et « etroite » donnent le même slug, et deux noms distincts passent la contrainte unique.
    Piege::query()->create(['nom' => 'Fosse Étroite', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique', 'effet' => []]);
    Piege::query()->create(['nom' => 'Fosse etroite', 'detectable' => true, 'desarmable' => 'oui', 'usage' => 'unique', 'effet' => []]);

    expect(fn () => app(CatalogueConstructeur::class)->index('pieges'))
        ->toThrow(LogicException::class, 'même slug « fosse-etroite »');
});
