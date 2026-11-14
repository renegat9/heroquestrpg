<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilQueteEcrire;
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
 * Outil `quete_ecrire` (lot P1) : remplace la quête de même slug, ou l'ajoute à `rang` (sinon à la fin), puis enregistre la
 * campagne ENTIÈRE revalidée. Un refus, comme une erreur de rang, n'écrit rien.
 */

/** Une troisième quête INVENTÉE, valide sur la galerie (ouverte seule par elle). */
function troisiemeQueteInventee(): array
{
    return [
        'slug' => 'troisieme',
        'titre' => 'La troisième, inventée',
        'ouverture' => 'Texte inventé.',
        'monstre_errant' => null,
        'salles_ouvertes' => ['galerie'],
        'portes' => [],
        'blocs' => [],
        'mobilier' => [],
        // Un gobelin dans `galerie` (8..11 × 2..5), hors de l'escalier (8..9 × 2..3) : la cible de la condition de fin.
        'monstres' => [['slug' => 'gobelin', 'x' => 11, 'y' => 5]],
        'pieges' => [],
        'terrain' => [],
        'depart' => ['x' => 8, 'y' => 2],
        'sortie' => null,
        'recompense' => null,
        'fin' => ['type' => 'vaincre', 'cibles' => [0], 'libelle' => 'Vaincre le gobelin de la galerie.'],
    ];
}

/** Les slugs des quêtes de la campagne, dans l'ordre de jeu, tels que le service les enregistre. */
function slugsDesQuetes(): array
{
    return collect(app(CampagnesConstruites::class)->lire('campagne-d-essai')['quetes'])->pluck('slug')->all();
}

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
});

it('remplace la quête de même slug, à sa place, et rend la campagne revalidée', function () {
    $quete = DocumentExemple::premiereQuete();
    $quete['titre'] = 'Titre remplacé';

    $reponse = ServeurConstructeur::tool(OutilQueteEcrire::class, ['campagne' => 'campagne-d-essai', 'quete' => $quete]);

    $reponse->assertHasNoErrors();
    $contenu = ReponseMcp::structure($reponse);
    expect($contenu['enregistree'])->toBeTrue()
        ->and($contenu['campagne']['nb_quetes'])->toBe(2);

    $quetes = app(CampagnesConstruites::class)->lire('campagne-d-essai')['quetes'];
    expect($quetes[0]['titre'])->toBe('Titre remplacé')
        ->and($quetes[1])->toEqual(DocumentExemple::secondeQuete());
});

it('ajoute une quête nouvelle à la fin quand rang est absent', function () {
    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilQueteEcrire::class, [
        'campagne' => 'campagne-d-essai', 'quete' => troisiemeQueteInventee(),
    ]));

    expect($contenu['enregistree'])->toBeTrue()
        ->and($contenu['campagne']['nb_quetes'])->toBe(3)
        ->and(slugsDesQuetes())->toBe(['premiere-marche', 'la-crypte', 'troisieme']);
    expect(QueteConstruite::query()->where('slug', 'troisieme')->value('rang'))->toBe(3);
});

it('insère une quête nouvelle au rang demandé', function () {
    ServeurConstructeur::tool(OutilQueteEcrire::class, [
        'campagne' => 'campagne-d-essai', 'quete' => troisiemeQueteInventee(), 'rang' => 1,
    ])->assertHasNoErrors();

    expect(slugsDesQuetes())->toBe(['troisieme', 'premiere-marche', 'la-crypte']);
});

it('déplace une quête existante quand un rang lui est donné', function () {
    ServeurConstructeur::tool(OutilQueteEcrire::class, [
        'campagne' => 'campagne-d-essai', 'quete' => DocumentExemple::secondeQuete(), 'rang' => 1,
    ])->assertHasNoErrors();

    expect(slugsDesQuetes())->toBe(['la-crypte', 'premiere-marche']);
});

it('rend une erreur d\'usage pour un rang hors de l\'intervalle, sans rien modifier', function () {
    $avant = app(CampagnesConstruites::class)->lire('campagne-d-essai');

    ServeurConstructeur::tool(OutilQueteEcrire::class, [
        'campagne' => 'campagne-d-essai', 'quete' => troisiemeQueteInventee(), 'rang' => 9,
    ])->assertHasErrors(['Le rang 9 est hors de l\'intervalle 1 à 3 pour cette campagne.']);

    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual($avant)
        ->and(QueteConstruite::query()->count())->toBe(2);
});

it('refuse une quête invalide, nomme le chemin, et ne modifie RIEN en base', function () {
    $avantCampagne = app(CampagnesConstruites::class)->lire('campagne-d-essai');
    $avantProjection = QueteConstruite::query()->orderBy('rang')->get(['rang', 'slug', 'document'])->toArray();

    // Le départ tombe dans l'INTÉRIEUR de « entree » (2..6 × 2..5), que cette quête ferme : refus de la CAMPAGNE, la
    // quête est à l'index 2. (Avant le lot K2 le test visait (1,1), devenue une case d'anneau de mur.)
    $quete = troisiemeQueteInventee();
    $quete['depart'] = ['x' => 3, 'y' => 3];

    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilQueteEcrire::class, [
        'campagne' => 'campagne-d-essai', 'quete' => $quete,
    ]));

    expect($contenu['enregistree'])->toBeFalse()
        ->and($contenu['refus'][0]['code'])->toBe('depart_invalide')
        ->and($contenu['refus'][0]['chemin'])->toBe('quetes.2.depart');

    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual($avantCampagne)
        ->and(QueteConstruite::query()->orderBy('rang')->get(['rang', 'slug', 'document'])->toArray())->toEqual($avantProjection)
        ->and(CampagneConstruite::query()->count())->toBe(1);
});

it('rend une erreur d\'usage pour une campagne inconnue', function () {
    ServeurConstructeur::tool(OutilQueteEcrire::class, [
        'campagne' => 'absente', 'quete' => troisiemeQueteInventee(),
    ])->assertHasErrors(['Aucune campagne construite « absente ».']);
});
