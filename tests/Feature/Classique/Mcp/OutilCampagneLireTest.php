<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilCampagneLire;
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
 * Outil `campagne_lire` (lot P1) : le document SANS le détail des quêtes. Chaque quête n'y est que `rang`, `slug`, `titre` :
 * une réponse trop longue est tronquée côté client, le détail se lit par `quete_lire`.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
});

it('rend les champs de campagne et le plateau, à l\'identique du document', function () {
    $reponse = ServeurConstructeur::tool(OutilCampagneLire::class, ['slug' => 'campagne-d-essai']);

    $reponse->assertHasNoErrors();
    $contenu = ReponseMcp::structure($reponse);

    expect($contenu['titre'])->toBe(DocumentExemple::campagne()['titre'])
        ->and($contenu['prerequis_heros'])->toBe(['barbare'])
        ->and($contenu['plateau'])->toBe(DocumentExemple::campagne()['plateau']);
});

it('ne donne, pour chaque quête, que son rang, son slug et son titre', function () {
    $quetes = ReponseMcp::structure(ServeurConstructeur::tool(OutilCampagneLire::class, ['slug' => 'campagne-d-essai']))['quetes'];

    expect($quetes)->toBe([
        ['rang' => 1, 'slug' => 'premiere-marche', 'titre' => 'La première marche'],
        ['rang' => 2, 'slug' => 'la-crypte', 'titre' => 'La crypte'],
    ]);
});

it('rend une erreur d\'usage en français pour une campagne inconnue', function () {
    ServeurConstructeur::tool(OutilCampagneLire::class, ['slug' => 'absente'])
        ->assertHasErrors(['Aucune campagne construite « absente ».']);
});

it('refuse un appel sans slug, en erreur d\'usage', function () {
    ServeurConstructeur::tool(OutilCampagneLire::class, [])->assertHasErrors([]);
});
