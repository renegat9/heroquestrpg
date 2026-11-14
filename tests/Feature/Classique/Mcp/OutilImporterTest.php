<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilImporter;
use App\Mcp\ServeurConstructeur;
use App\Models\CampagneConstruite;
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
 * Outil `importer` (lot P1) : `CampagnesConstruites::importer()`. Une empreinte présente est vérifiée ; un refus n'écrit rien.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('importe un fichier exporté dont l\'empreinte est juste', function () {
    $fichier = [...DocumentExemple::campagne(), 'empreinte' => EmpreinteCampagne::de(DocumentExemple::campagne())];

    $reponse = ServeurConstructeur::tool(OutilImporter::class, ['document' => $fichier]);

    $reponse->assertHasNoErrors();
    $contenu = ReponseMcp::structure($reponse);
    expect($contenu['enregistree'])->toBeTrue()
        ->and($contenu['campagne']['slug'])->toBe('campagne-d-essai')
        ->and($contenu['campagne']['empreinte'])->toBe(EmpreinteCampagne::de(DocumentExemple::campagne()));
    expect(CampagneConstruite::query()->count())->toBe(1);
});

it('refuse un fichier dont l\'empreinte ne correspond plus au document, et n\'écrit rien', function () {
    $fichier = [...DocumentExemple::campagne(), 'empreinte' => 'sha-faux'];

    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilImporter::class, ['document' => $fichier]));

    expect($contenu['enregistree'])->toBeFalse()
        ->and($contenu['refus'][0]['code'])->toBe('empreinte_invalide')
        ->and($contenu['refus'][0]['chemin'])->toBe('empreinte');
    expect(CampagneConstruite::query()->count())->toBe(0);
});

it('importe un document sans empreinte, comme une campagne écrite à la main', function () {
    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilImporter::class, ['document' => DocumentExemple::campagne()]));

    expect($contenu['enregistree'])->toBeTrue();
    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual(DocumentExemple::campagne());
});
