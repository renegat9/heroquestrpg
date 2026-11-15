<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilApercu;
use App\Mcp\ServeurConstructeur;
use App\Models\CampagneConstruite;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CarteConstruite;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\Feature\Classique\DocumentExemple;

/*
 * Outil `apercu` (lot P2) : la carte d'UNE quête, en texte, telle que `CarteConstruite::apercu()` la produit. Lecture seule.
 * Le texte de la réponse est lu ici par une fonction de test : le paquet ne l'expose qu'à travers ses assertions.
 */

/** Le texte renvoyé par `apercu`, tel quel. Outil de TEST seulement. */
function texteRenduParApercu(TestResponse $reponse): string
{
    return Closure::bind(fn (): string => implode("\n", $this->content()), $reponse, TestResponse::class)();
}

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
});

it('rend la carte de la quête en texte, à l\'identique de CarteConstruite::apercu()', function () {
    $reponse = ServeurConstructeur::tool(OutilApercu::class, ['campagne' => 'campagne-d-essai', 'quete' => 'premiere-marche']);

    $reponse->assertHasNoErrors();
    expect(texteRenduParApercu($reponse))->toBe(
        app(CarteConstruite::class)->apercu(DocumentExemple::campagne(), DocumentExemple::premiereQuete()),
    );
});

it('dessine le plateau à sa taille, une ligne par rangée, puis la légende', function () {
    $lignes = explode("\n", texteRenduParApercu(
        ServeurConstructeur::tool(OutilApercu::class, ['campagne' => 'campagne-d-essai', 'quete' => 'premiere-marche']),
    ));

    // Plateau de 20 × 14 (DocumentExemple) : 14 rangées de 20 cases, une ligne vide, puis « Légende : ».
    expect(array_slice($lignes, 0, 14))->each->toHaveLength(20)
        ->and($lignes[14])->toBe('')
        ->and($lignes[15])->toBe('Légende :');
});

it('marque sur la grille les éléments de la quête à leur case', function () {
    $lignes = explode("\n", texteRenduParApercu(
        ServeurConstructeur::tool(OutilApercu::class, ['campagne' => 'campagne-d-essai', 'quete' => 'premiere-marche']),
    ));

    // (5, 2) gobelin · (2, 2) fosse · (6, 3) porte normale · (2, 4) entrée (escalier 2×2, ancré en (2, 4)).
    expect($lignes[2][5])->toBe('M')
        ->and($lignes[2][2])->toBe('^')
        ->and($lignes[3][6])->toBe('+')
        ->and($lignes[4][2])->toBe('<')
        ->and($lignes[5][3])->toBe('<');
});

it('donne dans la légende le slug et la case de chaque élément', function () {
    ServeurConstructeur::tool(OutilApercu::class, ['campagne' => 'campagne-d-essai', 'quete' => 'premiere-marche'])
        ->assertSee([
            'M gobelin : case (5, 2)',
            '^ fosse : case (2, 2)',
            '~ glace-glissante : case (9, 3)',
            'm table : ancre (4, 4)',
            '# bloc : case (11, 7)',
            '+ porte normale : case (6, 3)',
            '? porte secrète : case (9, 5)',
            '< entrée et sortie : escalier, coin (2, 4), 2×2',
        ]);
});

it('ne modifie rien en base', function () {
    $avant = app(CampagnesConstruites::class)->lire('campagne-d-essai');

    ServeurConstructeur::tool(OutilApercu::class, ['campagne' => 'campagne-d-essai', 'quete' => 'premiere-marche'])
        ->assertHasNoErrors();

    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual($avant);
});

it('rend une erreur d\'usage quand la campagne n\'existe pas', function () {
    ServeurConstructeur::tool(OutilApercu::class, ['campagne' => 'absente', 'quete' => 'premiere-marche'])
        ->assertHasErrors(['Aucune campagne construite « absente ».']);
});

it('rend une erreur d\'usage quand la quête n\'existe pas dans la campagne', function () {
    ServeurConstructeur::tool(OutilApercu::class, ['campagne' => 'campagne-d-essai', 'quete' => 'absente'])
        ->assertHasErrors(['La campagne « campagne-d-essai » n\'a pas de quête « absente ».']);
});

it('rend un refus, et pas de carte, pour une campagne que la validation ne passe plus', function () {
    // Une campagne enregistrée avant un contrôle ajouté depuis peut ne plus passer : on la pose ici à la main, dans la base de test.
    $campagne = CampagneConstruite::query()->where('slug', 'campagne-d-essai')->firstOrFail();
    $document = $campagne->document;
    // Le départ dans la réserve, qui est fermée dans cette quête : le producteur ne doit rien dessiner.
    $document['quetes'][0]['depart'] = ['x' => 15, 'y' => 3];
    $campagne->document = $document;
    $campagne->save();

    ServeurConstructeur::tool(OutilApercu::class, ['campagne' => 'campagne-d-essai', 'quete' => 'premiere-marche'])
        ->assertHasErrors(['Document refusé, aucune carte produite', 'depart_invalide']);
});
