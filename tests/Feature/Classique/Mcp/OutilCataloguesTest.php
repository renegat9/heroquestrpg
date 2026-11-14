<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilCatalogues;
use App\Mcp\ServeurConstructeur;
use App\Partie\Classique\CatalogueConstructeur;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\Mcp\ReponseMcp;

/*
 * Outil `catalogues` (lot P1) : rend EXACTEMENT ce que rend `CatalogueConstructeur::payload()`, le corps de
 * GET /api/constructeur/catalogues. Aucune copie de la liste dans l'outil.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('rend le payload du catalogue, tel quel', function () {
    $reponse = ServeurConstructeur::tool(OutilCatalogues::class);

    $reponse->assertHasNoErrors();
    expect(ReponseMcp::structure($reponse))->toEqual(app(CatalogueConstructeur::class)->payload());
});

it('publie les quatre couches posables, les classes et les vocabulaires fermés', function () {
    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilCatalogues::class));

    expect(array_keys($contenu['couches']))->toBe(['mobilier', 'monstres', 'pieges', 'terrain'])
        ->and(array_keys($contenu['vocabulaires']))->toBe(['rotations', 'cotes', 'types_porte', 'partages', 'types_fin', 'formats_acceptes'])
        ->and(collect($contenu['couches']['monstres'])->pluck('slug'))->toContain('gobelin')
        ->and(collect($contenu['classes'])->pluck('slug'))->toContain('barbare');
});
