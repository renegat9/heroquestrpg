<?php

declare(strict_types=1);

use App\Models\CampagneConstruite;
use App\Models\QueteConstruite;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\EmpreinteCampagne;
use App\Partie\Classique\RefusCampagne;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Classique\DocumentExemple;

/*
 * Stockage, import et export d'une campagne construite (MODE CLASSIQUE, lot K1). Le document est la source ; les lignes
 * de quêtes en sont une projection réécrite dans la même transaction ; l'empreinte survit à un aller-retour d'export.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

/** Le code du premier refus d'une exception de campagne. */
function codeDuRefus(RefusCampagne $refusé): string
{
    return $refusé->refus[0]['code'];
}

it('enregistre une campagne, puis la relit à l\'identique', function () {
    $service = app(CampagnesConstruites::class);
    $campagne = $service->enregistrer(DocumentExemple::campagne());

    expect($campagne->slug)->toBe('campagne-d-essai')
        ->and($campagne->version_format)->toBe(1)
        ->and($campagne->empreinte)->toBe(EmpreinteCampagne::de(DocumentExemple::campagne()))
        ->and($service->lire('campagne-d-essai'))->toEqual(DocumentExemple::campagne());
});

it('projette chaque quête dans quetes_construites, par rang, et réécrit la projection au remplacement', function () {
    $service = app(CampagnesConstruites::class);
    $service->enregistrer(DocumentExemple::campagne());

    $campagne = CampagneConstruite::query()->where('slug', 'campagne-d-essai')->firstOrFail();
    expect($campagne->quetes->pluck('rang')->all())->toBe([1, 2])
        ->and($campagne->quetes->pluck('slug')->all())->toBe(['premiere-marche', 'la-crypte'])
        ->and($campagne->quetes[0]->document)->toEqual(DocumentExemple::premiereQuete());

    // Remplacement avec une seule quête : la projection est réécrite EN ENTIER, pas complétée.
    $doc = DocumentExemple::campagne();
    $doc['quetes'] = [DocumentExemple::secondeQuete()];
    $service->enregistrer($doc);

    expect(QueteConstruite::query()->count())->toBe(1);
    $campagne->refresh();
    expect($campagne->quetes->pluck('rang')->all())->toBe([1])
        ->and($campagne->quetes[0]->slug)->toBe('la-crypte');
});

it('remplace la campagne de même slug, sans créer de doublon', function () {
    $service = app(CampagnesConstruites::class);
    $service->enregistrer(DocumentExemple::campagne());

    $doc = DocumentExemple::campagne();
    $doc['titre'] = 'Autre titre';
    $service->enregistrer($doc);

    expect(CampagneConstruite::query()->count())->toBe(1)
        ->and($service->lire('campagne-d-essai')['titre'])->toBe('Autre titre');
});

it('refuse un document invalide en lançant RefusCampagne (une ValidationException), et n\'écrit rien', function () {
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['monstres'][0]['slug'] = 'monstre-inexistant';

    $service = app(CampagnesConstruites::class);
    $levee = null;
    try {
        $service->enregistrer($doc);
    } catch (RefusCampagne $e) {
        $levee = $e;
    }

    expect($levee)->toBeInstanceOf(ValidationException::class)
        ->and(codeDuRefus($levee))->toBe(ValidationCampagne::CATALOGUE_INCONNU)
        ->and(CampagneConstruite::query()->count())->toBe(0)
        ->and(QueteConstruite::query()->count())->toBe(0);
});

it('refuse un slug d\'URL qui ne correspond pas au document, et n\'écrit rien', function () {
    $levee = null;
    try {
        app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne(), 'une-autre-campagne');
    } catch (RefusCampagne $e) {
        $levee = $e;
    }

    expect(codeDuRefus($levee))->toBe(ValidationCampagne::SLUG_NON_CONCORDANT)
        ->and(CampagneConstruite::query()->count())->toBe(0);
});

it('exporte le document plus son empreinte, puis l\'importe : la même empreinte revient', function () {
    $service = app(CampagnesConstruites::class);
    $service->enregistrer(DocumentExemple::campagne());

    $fichier = $service->exporter('campagne-d-essai');
    expect($fichier)->toHaveKey('empreinte')
        ->and($fichier['empreinte'])->toBe(EmpreinteCampagne::de(DocumentExemple::campagne()));

    // Import dans une base vide de cette campagne : la création, pas le remplacement.
    QueteConstruite::query()->delete();
    CampagneConstruite::query()->delete();

    $importee = $service->importer($fichier);

    expect($importee->empreinte)->toBe($fichier['empreinte'])
        ->and($service->exporter('campagne-d-essai')['empreinte'])->toBe($fichier['empreinte'])
        ->and(QueteConstruite::query()->count())->toBe(2);
});

it('importe un fichier écrit à la main, sans empreinte', function () {
    $importee = app(CampagnesConstruites::class)->importer(DocumentExemple::campagne());

    expect($importee->slug)->toBe('campagne-d-essai');
});

it('refuse à l\'import un fichier modifié après export : empreinte_invalide', function () {
    $service = app(CampagnesConstruites::class);
    $service->enregistrer(DocumentExemple::campagne());
    $fichier = $service->exporter('campagne-d-essai');
    $fichier['titre'] = 'Titre modifié à la main';

    $levee = null;
    try {
        $service->importer($fichier);
    } catch (RefusCampagne $e) {
        $levee = $e;
    }

    expect(codeDuRefus($levee))->toBe(ValidationCampagne::EMPREINTE_INVALIDE)
        ->and($levee->refus[0]['chemin'])->toBe('empreinte');
});

it('refuse à l\'import un format inconnu : format_inconnu', function () {
    $doc = DocumentExemple::campagne();
    $doc['format'] = 2;

    $levee = null;
    try {
        app(CampagnesConstruites::class)->importer($doc);
    } catch (RefusCampagne $e) {
        $levee = $e;
    }

    expect(codeDuRefus($levee))->toBe(ValidationCampagne::FORMAT_INCONNU)
        ->and(CampagneConstruite::query()->count())->toBe(0);
});

it('refuse à l\'import un slug de catalogue inconnu, en le nommant', function () {
    $doc = DocumentExemple::campagne();
    $doc['quetes'][1]['pieges'] = [['slug' => 'piege-venu-d-ailleurs', 'x' => 5, 'y' => 6]];

    $levee = null;
    try {
        app(CampagnesConstruites::class)->importer($doc);
    } catch (RefusCampagne $e) {
        $levee = $e;
    }

    expect(codeDuRefus($levee))->toBe(ValidationCampagne::CATALOGUE_INCONNU)
        ->and($levee->refus[0]['message'])->toContain('piege-venu-d-ailleurs')
        ->and(CampagneConstruite::query()->count())->toBe(0);
});

it('l\'empreinte ne dépend pas de l\'ordre des clés, et change avec le contenu', function () {
    $document = DocumentExemple::campagne();
    $clesInversees = array_reverse($document, true);
    $modifie = $document;
    $modifie['titre'] = 'Autre titre';

    expect(EmpreinteCampagne::de($clesInversees))->toBe(EmpreinteCampagne::de($document))
        ->and(EmpreinteCampagne::de($modifie))->not->toBe(EmpreinteCampagne::de($document));
});

it('lister() rend un résumé par campagne, avec son nombre de quêtes', function () {
    $service = app(CampagnesConstruites::class);
    $service->enregistrer(DocumentExemple::campagne());

    $resumes = $service->lister();

    expect($resumes)->toHaveCount(1)
        ->and($resumes[0])->toMatchArray(['slug' => 'campagne-d-essai', 'nb_quetes' => 2, 'version_format' => 1]);
});

it('lire() et exporter() rendent null pour un slug inconnu', function () {
    $service = app(CampagnesConstruites::class);

    expect($service->lire('aucune'))->toBeNull()
        ->and($service->exporter('aucune'))->toBeNull();
});
