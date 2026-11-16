<?php

declare(strict_types=1);

use App\Models\CampagneConstruite;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;

/*
 * Les six routes du constructeur (MODE CLASSIQUE, lot K1, `docs/contrat-api.md`) : PUBLIQUES, sans compte ni session de
 * groupe. Le contrôleur est mince ; ces tests vérifient la FORME des réponses, la règle vit dans les services.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('GET /api/constructeur/catalogues est public et publie couches, classes et vocabulaires', function () {
    $this->getJson('/api/constructeur/catalogues')
        ->assertOk()
        ->assertJsonStructure([
            'couches' => ['mobilier', 'monstres', 'pieges', 'terrain'],
            'classes',
            'vocabulaires' => ['rotations', 'cotes', 'types_porte', 'partages', 'formats_acceptes'],
        ]);
});

it('GET /api/constructeur/campagnes liste les campagnes, vide au départ', function () {
    $this->getJson('/api/constructeur/campagnes')
        ->assertOk()
        ->assertExactJson(['campagnes' => []]);
});

it('PUT /api/constructeur/campagnes/{slug} crée la campagne et rend son résumé', function () {
    $this->putJson('/api/constructeur/campagnes/campagne-d-essai', DocumentExemple::campagne())
        ->assertOk()
        ->assertJsonPath('campagne.slug', 'campagne-d-essai')
        ->assertJsonPath('campagne.nb_quetes', 2)
        ->assertJsonStructure(['campagne' => ['empreinte', 'mise_a_jour']]);

    expect(CampagneConstruite::query()->count())->toBe(1);
});

it('PUT rend 422 avec refus[] nommés quand le document est invalide, et n\'enregistre rien', function () {
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['monstres'][0]['slug'] = 'monstre-inexistant';

    $this->putJson('/api/constructeur/campagnes/campagne-d-essai', $doc)
        ->assertStatus(422)
        ->assertJsonPath('refus.0.code', ValidationCampagne::CATALOGUE_INCONNU)
        ->assertJsonPath('refus.0.chemin', 'quetes.0.monstres.0.slug')
        ->assertJsonStructure(['message', 'refus' => [['chemin', 'code', 'message']]]);

    expect(CampagneConstruite::query()->count())->toBe(0);
});

it('PUT rend 422 slug_non_concordant quand le slug de l\'URL ne vaut pas celui du document', function () {
    $this->putJson('/api/constructeur/campagnes/une-autre-campagne', DocumentExemple::campagne())
        ->assertStatus(422)
        ->assertJsonPath('refus.0.code', ValidationCampagne::SLUG_NON_CONCORDANT);

    expect(CampagneConstruite::query()->count())->toBe(0);
});

it('GET /api/constructeur/campagnes/{slug} rend le document, et 404 pour un slug inconnu', function () {
    $this->putJson('/api/constructeur/campagnes/campagne-d-essai', DocumentExemple::campagne())->assertOk();

    $this->getJson('/api/constructeur/campagnes/campagne-d-essai')
        ->assertOk()
        ->assertJsonPath('slug', 'campagne-d-essai')
        ->assertJsonPath('quetes.1.slug', 'la-crypte');

    $this->getJson('/api/constructeur/campagnes/aucune')->assertNotFound();
});

it('POST /api/constructeur/validation rend les refus sans rien enregistrer', function () {
    $this->postJson('/api/constructeur/validation', DocumentExemple::campagne())
        ->assertOk()
        ->assertExactJson(['refus' => []]);

    $fautif = DocumentExemple::campagne();
    $fautif['cle_bidon'] = true;

    $this->postJson('/api/constructeur/validation', $fautif)
        ->assertOk()
        ->assertJsonPath('refus.0.code', ValidationCampagne::CLE_INCONNUE)
        ->assertJsonPath('refus.0.chemin', 'cle_bidon');

    expect(CampagneConstruite::query()->count())->toBe(0);
});

it('GET /api/constructeur/campagnes/{slug}/export rend le document plus empreinte, en pièce jointe', function () {
    $this->putJson('/api/constructeur/campagnes/campagne-d-essai', DocumentExemple::campagne())->assertOk();

    $this->getJson('/api/constructeur/campagnes/campagne-d-essai/export')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="campagne-d-essai.json"')
        ->assertJsonPath('slug', 'campagne-d-essai')
        ->assertJsonStructure(['empreinte']);

    $this->getJson('/api/constructeur/campagnes/aucune/export')->assertNotFound();
});

it('valide et enregistre un document TEL QU\'IL A ÉTÉ ENVOYÉ : un texte vide reste un texte vide', function () {
    // `ConvertEmptyStringsToNull` changeait « » en null à l'entrée de la route : un texte d'ouverture vide, permis par
    // le format, était refusé (« un texte est attendu »). Trouvé en éprouvant l'écran du constructeur (2026-11-16).
    $document = \Tests\Feature\Classique\DocumentExemple::campagne();
    $document['quetes'][0]['ouverture'] = '';

    $this->postJson('/api/constructeur/validation', $document)->assertOk()->assertJsonPath('refus', []);

    $this->putJson('/api/constructeur/campagnes/'.$document['slug'], $document)->assertOk();
    expect($this->getJson('/api/constructeur/campagnes/'.$document['slug'])->json('quetes.0.ouverture'))->toBe('');
});
