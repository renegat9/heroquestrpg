<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilValider;
use App\Mcp\ServeurConstructeur;
use App\Models\CampagneConstruite;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;
use Tests\Feature\Classique\Mcp\ReponseMcp;

/*
 * Outil `valider` (lot P1) : contrôle SANS rien enregistrer. Liste vide = valide ; sinon les refus, nommés.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('rend une liste de refus vide pour un document valide, sans rien enregistrer', function () {
    $reponse = ServeurConstructeur::tool(OutilValider::class, ['document' => DocumentExemple::campagne()]);

    $reponse->assertHasNoErrors();
    expect(ReponseMcp::structure($reponse))->toBe(['refus' => []]);
    expect(CampagneConstruite::query()->count())->toBe(0);
});

it('rend les refus nommés d\'un document invalide, sans rien enregistrer', function () {
    $document = DocumentExemple::campagne();
    // La première quête de l'exemple pose un piège (« fosse ») : on lui donne un slug que le catalogue ne connaît pas.
    $document['quetes'][0]['pieges'][0]['slug'] = 'piege-inexistant';

    $reponse = ServeurConstructeur::tool(OutilValider::class, ['document' => $document]);

    $reponse->assertHasNoErrors();
    $refus = ReponseMcp::structure($reponse)['refus'];
    expect($refus)->toHaveCount(1)
        ->and($refus[0])->toHaveKeys(['chemin', 'code', 'message'])
        ->and($refus[0]['code'])->toBe('catalogue_inconnu')
        ->and($refus[0]['chemin'])->toBe('quetes.0.pieges.0.slug');
    expect(CampagneConstruite::query()->count())->toBe(0);
});

it('rend une erreur d\'usage quand le document n\'est pas un objet', function () {
    ServeurConstructeur::tool(OutilValider::class, ['document' => 42])->assertHasErrors([]);
});
