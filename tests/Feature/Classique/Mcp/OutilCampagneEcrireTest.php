<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilCampagneEcrire;
use App\Mcp\ServeurConstructeur;
use App\Models\CampagneConstruite;
use App\Models\QueteConstruite;
use App\Partie\Classique\CampagnesConstruites;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;
use Tests\Feature\Classique\Mcp\ReponseMcp;

/*
 * Outil `campagne_ecrire` (lot P1) : crée ou remplace la campagne ENTIÈRE par `enregistrer()`. Un refus n'écrit rien et est
 * rendu dans la réponse, sans `isError`.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('enregistre une campagne valide et rend son résumé', function () {
    $reponse = ServeurConstructeur::tool(OutilCampagneEcrire::class, ['document' => DocumentExemple::campagne()]);

    $reponse->assertHasNoErrors();
    $contenu = ReponseMcp::structure($reponse);

    expect($contenu['enregistree'])->toBeTrue()
        ->and($contenu['campagne']['slug'])->toBe('campagne-d-essai')
        ->and($contenu['campagne']['nb_quetes'])->toBe(2)
        ->and($contenu['campagne']['empreinte'])->toBe(app(CampagnesConstruites::class)->exporter('campagne-d-essai')['empreinte']);
    expect(CampagneConstruite::query()->count())->toBe(1)
        ->and(QueteConstruite::query()->count())->toBe(2);
});

it('rend un refus nommé, sans erreur de protocole, et n\'écrit rien', function () {
    $document = DocumentExemple::campagne();
    $document['quetes'][0]['monstres'][0]['slug'] = 'monstre-inexistant';

    $reponse = ServeurConstructeur::tool(OutilCampagneEcrire::class, ['document' => $document]);

    $reponse->assertHasNoErrors();
    $contenu = ReponseMcp::structure($reponse);

    expect($contenu['enregistree'])->toBeFalse()
        ->and($contenu['refus'][0]['code'])->toBe('catalogue_inconnu')
        ->and($contenu['refus'][0]['chemin'])->toBe('quetes.0.monstres.0.slug');
    expect(CampagneConstruite::query()->count())->toBe(0)
        ->and(QueteConstruite::query()->count())->toBe(0);
});

it('refuse un fichier exporté (avec « empreinte ») : c\'est importer qui le reçoit', function () {
    $document = [...DocumentExemple::campagne(), 'empreinte' => 'x'];

    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilCampagneEcrire::class, ['document' => $document]));

    expect($contenu['enregistree'])->toBeFalse()
        ->and(collect($contenu['refus'])->pluck('code'))->toContain('cle_inconnue');
    expect(CampagneConstruite::query()->count())->toBe(0);
});

it('remplace une campagne déjà enregistrée sous le même slug', function () {
    $service = app(CampagnesConstruites::class);
    $service->enregistrer(DocumentExemple::campagne());

    $document = DocumentExemple::campagne();
    $document['titre'] = 'Titre remplacé';
    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilCampagneEcrire::class, ['document' => $document]));

    expect($contenu['enregistree'])->toBeTrue()
        ->and($contenu['campagne']['titre'])->toBe('Titre remplacé');
    expect(CampagneConstruite::query()->count())->toBe(1)
        ->and($service->lire('campagne-d-essai')['titre'])->toBe('Titre remplacé');
});

it('refuse un document qui n\'est pas un objet, en erreur d\'usage', function () {
    ServeurConstructeur::tool(OutilCampagneEcrire::class, ['document' => 'pas un objet'])->assertHasErrors([]);
    expect(CampagneConstruite::query()->count())->toBe(0);
});
