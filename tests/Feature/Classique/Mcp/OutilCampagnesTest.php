<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilCampagnes;
use App\Mcp\ServeurConstructeur;
use App\Partie\Classique\CampagnesConstruites;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;
use Tests\Feature\Classique\Mcp\ReponseMcp;

/*
 * Outil `campagnes` (lot P1) : la liste des résumés, par `CampagnesConstruites::lister()`.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('rend une liste vide quand aucune campagne n\'est construite', function () {
    $reponse = ServeurConstructeur::tool(OutilCampagnes::class);

    $reponse->assertHasNoErrors();
    expect(ReponseMcp::structure($reponse))->toBe(['campagnes' => []]);
});

it('rend le résumé de chaque campagne enregistrée, sans détailler ses quêtes', function () {
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());

    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilCampagnes::class));

    expect($contenu['campagnes'])->toHaveCount(1)
        ->and($contenu['campagnes'][0])->toHaveKeys(['slug', 'titre', 'empreinte', 'nb_quetes'])
        ->and($contenu['campagnes'][0]['slug'])->toBe('campagne-d-essai')
        ->and($contenu['campagnes'][0]['nb_quetes'])->toBe(2)
        ->and($contenu['campagnes'][0])->not->toHaveKey('quetes');
});
