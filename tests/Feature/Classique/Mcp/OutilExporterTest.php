<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilExporter;
use App\Mcp\ServeurConstructeur;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\EmpreinteCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;
use Tests\Feature\Classique\Mcp\ReponseMcp;

/*
 * Outil `exporter` (lot P1) : le document plus `empreinte`, comme `CampagnesConstruites::exporter()`.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
});

it('rend le document et son empreinte, comme le fichier d\'export', function () {
    $reponse = ServeurConstructeur::tool(OutilExporter::class, ['slug' => 'campagne-d-essai']);

    $reponse->assertHasNoErrors();
    $contenu = ReponseMcp::structure($reponse);

    expect($contenu)->toEqual([...DocumentExemple::campagne(), 'empreinte' => EmpreinteCampagne::de(DocumentExemple::campagne())])
        ->and($contenu)->toEqual(app(CampagnesConstruites::class)->exporter('campagne-d-essai'));
});

it('rend une erreur d\'usage pour une campagne inconnue', function () {
    ServeurConstructeur::tool(OutilExporter::class, ['slug' => 'absente'])
        ->assertHasErrors(['Aucune campagne construite « absente ».']);
});
