<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilQueteLire;
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
 * Outil `quete_lire` (lot P1) : le document COMPLET d'une quête, et lui seul.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
});

it('rend le document complet de la quête demandée', function () {
    $reponse = ServeurConstructeur::tool(OutilQueteLire::class, ['campagne' => 'campagne-d-essai', 'quete' => 'premiere-marche']);

    $reponse->assertHasNoErrors();
    expect(ReponseMcp::structure($reponse))->toEqual(DocumentExemple::premiereQuete());
});

it('rend le détail d\'une autre quête de la même campagne, pas celui de la première', function () {
    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilQueteLire::class, ['campagne' => 'campagne-d-essai', 'quete' => 'la-crypte']));

    // Comparé au document d'exemple, jamais à des coordonnées recopiées ici : l'exemple a été redessiné au lot K2
    // (géométrie du moteur) et ce test avait gardé l'ancienne case du squelette.
    expect($contenu)->toEqual(DocumentExemple::secondeQuete())
        ->and($contenu['monstres'])->toBe(DocumentExemple::secondeQuete()['monstres'])
        ->and($contenu['monstres'])->not->toBe(DocumentExemple::premiereQuete()['monstres']);
});

it('rend une erreur d\'usage quand la quête n\'existe pas dans la campagne', function () {
    ServeurConstructeur::tool(OutilQueteLire::class, ['campagne' => 'campagne-d-essai', 'quete' => 'absente'])
        ->assertHasErrors(['La campagne « campagne-d-essai » n\'a pas de quête « absente ».']);
});

it('rend une erreur d\'usage quand la campagne n\'existe pas', function () {
    ServeurConstructeur::tool(OutilQueteLire::class, ['campagne' => 'absente', 'quete' => 'premiere-marche'])
        ->assertHasErrors(['Aucune campagne construite « absente ».']);
});
