<?php

declare(strict_types=1);

use App\Mcp\Outils\OutilApercu;
use App\Mcp\Outils\OutilQueteModifier;
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
 * Outil `quete_modifier` (lot P2) : des opérations ciblées sur UNE quête, appliquées sur une copie, puis la campagne entière
 * enregistrée par le même chemin que `quete_ecrire`. Une opération fautive ou un refus de validation n'écrit RIEN.
 */

/** @return array<string, mixed> les entrées de l'outil pour la première quête de l'exemple, avec ces opérations */
function entreeModifier(array $operations, string $quete = 'premiere-marche'): array
{
    return ['campagne' => 'campagne-d-essai', 'quete' => $quete, 'operations' => $operations];
}

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
});

it('applique une opération de chaque sorte, enregistre la quête, et rend la campagne revalidée', function () {
    $reponse = ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'ajouter', 'chemin' => 'monstres', 'valeur' => ['slug' => 'gobelin', 'x' => 4, 'y' => 2]],
        ['op' => 'retirer', 'chemin' => 'pieges.0'],
        ['op' => 'remplacer', 'chemin' => 'titre', 'valeur' => 'Titre modifié'],
        ['op' => 'remplacer', 'chemin' => 'fin.libelle', 'valeur' => 'Libellé modifié.'],
    ]));

    $reponse->assertHasNoErrors();
    $contenu = ReponseMcp::structure($reponse);
    expect($contenu['enregistree'])->toBeTrue()
        ->and($contenu['campagne']['nb_quetes'])->toBe(2);

    $quete = app(CampagnesConstruites::class)->lireQuete('campagne-d-essai', 'premiere-marche');
    expect($quete['titre'])->toBe('Titre modifié')
        ->and($quete['fin']['libelle'])->toBe('Libellé modifié.')
        ->and($quete['pieges'])->toBe([])
        ->and($quete['monstres'])->toBe([
            ['slug' => 'gobelin', 'x' => 5, 'y' => 2],
            ['slug' => 'gobelin', 'x' => 4, 'y' => 2],
        ]);
    // La seconde quête, elle, n'a pas bougé.
    expect(app(CampagnesConstruites::class)->lireQuete('campagne-d-essai', 'la-crypte'))->toEqual(DocumentExemple::secondeQuete());
});

it('fait refléter la modification par l\'aperçu de la même quête', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'ajouter', 'chemin' => 'monstres', 'valeur' => ['slug' => 'gobelin', 'x' => 4, 'y' => 2]],
        ['op' => 'retirer', 'chemin' => 'pieges.0'],
    ]))->assertHasNoErrors();

    ServeurConstructeur::tool(OutilApercu::class, ['campagne' => 'campagne-d-essai', 'quete' => 'premiere-marche'])
        ->assertSee(['M gobelin : case (4, 2)'])
        ->assertDontSee(['^ fosse']);
});

it('remplace une valeur nullable par null', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'remplacer', 'chemin' => 'monstre_errant', 'valeur' => null],
    ], 'la-crypte'))->assertHasNoErrors();

    expect(app(CampagnesConstruites::class)->lireQuete('campagne-d-essai', 'la-crypte')['monstre_errant'])->toBeNull();
});

it('garde la place de la quête modifiée dans la campagne', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'remplacer', 'chemin' => 'titre', 'valeur' => 'Titre modifié'],
    ]))->assertHasNoErrors();

    expect(collect(app(CampagnesConstruites::class)->lire('campagne-d-essai')['quetes'])->pluck('slug')->all())
        ->toBe(['premiere-marche', 'la-crypte']);
});

it('refuse un chemin qui n\'existe pas, et nomme l\'opération fautive', function () {
    $avant = app(CampagnesConstruites::class)->lire('campagne-d-essai');

    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'remplacer', 'chemin' => 'fin.absent', 'valeur' => 'x'],
    ]))->assertHasErrors([
        'Opération n°1 : le chemin « fin.absent » n\'existe pas dans la quête',
    ]);

    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual($avant);
});

it('refuse un index hors de la liste', function () {
    $avant = app(CampagnesConstruites::class)->lire('campagne-d-essai');

    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'retirer', 'chemin' => 'monstres.5'],
    ]))->assertHasErrors(['Opération n°1 : le chemin « monstres.5 » ne désigne pas un élément']);

    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'remplacer', 'chemin' => 'monstres.5', 'valeur' => ['slug' => 'gobelin', 'x' => 4, 'y' => 2]],
    ]))->assertHasErrors(['Opération n°1 : le chemin « monstres.5 » ne désigne pas un élément']);

    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual($avant);
});

it('refuse un op qui n\'est pas dans le vocabulaire fermé', function () {
    $avant = app(CampagnesConstruites::class)->lire('campagne-d-essai');

    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'effacer', 'chemin' => 'titre'],
    ]))->assertHasErrors(['Opération n°1 : « op » doit valoir ajouter, retirer ou remplacer (reçu : effacer).']);

    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual($avant);
});

it('refuse ajouter sur autre chose qu\'une liste', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'ajouter', 'chemin' => 'titre', 'valeur' => 'x'],
    ]))->assertHasErrors(['Opération n°1 : ajouter vise une liste, et « titre » n\'en est pas une.']);

    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'ajouter', 'chemin' => 'fin', 'valeur' => 'x'],
    ]))->assertHasErrors(['Opération n°1 : ajouter vise une liste, et « fin » n\'en est pas une.']);
});

it('refuse retirer hors d\'un élément de liste, et retirer avec une valeur', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'retirer', 'chemin' => 'titre'],
    ]))->assertHasErrors(['Opération n°1 : retirer vise un élément de liste']);

    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'retirer', 'chemin' => 'monstres.0', 'valeur' => 'x'],
    ]))->assertHasErrors(['Opération n°1 : retirer n\'a pas de « valeur »']);
});

it('refuse ajouter ou remplacer sans valeur', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'remplacer', 'chemin' => 'titre'],
    ]))->assertHasErrors(['Opération n°1 : remplacer exige une « valeur »']);

    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'ajouter', 'chemin' => 'monstres'],
    ]))->assertHasErrors(['Opération n°1 : ajouter exige une « valeur »']);
});

it('refuse une clé d\'opération inconnue, au lieu de l\'ignorer', function () {
    $avant = app(CampagnesConstruites::class)->lire('campagne-d-essai');

    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'remplacer', 'chemin' => 'titre', 'valeur' => 'x', 'rang' => 1],
    ]))->assertHasErrors(['Opération n°1 : clé inconnue « rang »']);

    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual($avant);
});

it('refuse de changer le slug de la quête par une opération', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'remplacer', 'chemin' => 'slug', 'valeur' => 'autre'],
    ]))->assertHasErrors(['Opération n°1 : le slug désigne la quête']);
});

it('n\'écrit RIEN quand la validation refuse le résultat, et rend ses refus nommés', function () {
    $avantCampagne = app(CampagnesConstruites::class)->lire('campagne-d-essai');
    $avantProjection = QueteConstruite::query()->orderBy('rang')->get(['rang', 'slug', 'document'])->toArray();

    // Retirer le monstre 0 laisse la condition de fin (cibles : [0]) sans cible posée : la campagne est refusée.
    $contenu = ReponseMcp::structure(ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'retirer', 'chemin' => 'monstres.0'],
    ])));

    expect($contenu['enregistree'])->toBeFalse()
        ->and(collect($contenu['refus'])->pluck('code')->all())->toContain('cible_inconnue')
        ->and(collect($contenu['refus'])->pluck('chemin')->all())->toContain('quetes.0.fin.cibles.0');

    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual($avantCampagne)
        ->and(QueteConstruite::query()->orderBy('rang')->get(['rang', 'slug', 'document'])->toArray())->toEqual($avantProjection)
        ->and(CampagneConstruite::query()->count())->toBe(1);
});

it('est atomique : une opération fautive en 2e place n\'écrit pas la 1re', function () {
    $avant = app(CampagnesConstruites::class)->lire('campagne-d-essai');

    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'ajouter', 'chemin' => 'monstres', 'valeur' => ['slug' => 'gobelin', 'x' => 4, 'y' => 2]],
        ['op' => 'remplacer', 'chemin' => 'monstres.9', 'valeur' => ['slug' => 'gobelin', 'x' => 4, 'y' => 2]],
    ]))->assertHasErrors(['Opération n°2 : le chemin « monstres.9 » ne désigne pas un élément']);

    expect(app(CampagnesConstruites::class)->lire('campagne-d-essai'))->toEqual($avant)
        ->and(app(CampagnesConstruites::class)->lireQuete('campagne-d-essai', 'premiere-marche')['monstres'])
        ->toBe(DocumentExemple::premiereQuete()['monstres']);
});

it('rend une erreur d\'usage quand la campagne n\'existe pas', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, [
        'campagne' => 'absente', 'quete' => 'premiere-marche',
        'operations' => [['op' => 'remplacer', 'chemin' => 'titre', 'valeur' => 'x']],
    ])->assertHasErrors(['Aucune campagne construite « absente ».']);
});

it('rend une erreur d\'usage quand la quête n\'existe pas dans la campagne', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([
        ['op' => 'remplacer', 'chemin' => 'titre', 'valeur' => 'x'],
    ], 'absente'))->assertHasErrors(['La campagne « campagne-d-essai » n\'a pas de quête « absente ».']);
});

it('refuse une liste d\'opérations vide', function () {
    ServeurConstructeur::tool(OutilQueteModifier::class, entreeModifier([]))->assertHasErrors([]);
});

it('ajoute une première note à une quête qui n\'en a pas, et la joue par le service', function () {
    $note = [
        'repere' => 'A',
        'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
        'alors' => [['effet' => 'texte', 'texte' => 'Un texte inventé.']],
    ];

    // La quête d'exemple n'a pas de clé `notes` : `ajouter` la vise quand même.
    $campagnes = app(\App\Partie\Classique\CampagnesConstruites::class);
    expect($campagnes->lireQuete('campagne-d-essai', 'premiere-marche'))->not->toHaveKey('notes');

    $campagnes->modifierQuete('campagne-d-essai', 'premiere-marche', [['op' => 'ajouter', 'chemin' => 'notes', 'valeur' => $note]]);
    expect($campagnes->lireQuete('campagne-d-essai', 'premiere-marche')['notes'])->toBe([$note]);

    // Retirée : la liste, que l'auteur a désormais écrite, reste une liste vide — c'est un document valide.
    $campagnes->modifierQuete('campagne-d-essai', 'premiere-marche', [['op' => 'retirer', 'chemin' => 'notes.0']]);
    expect($campagnes->lireQuete('campagne-d-essai', 'premiere-marche')['notes'])->toBe([]);

    // Une opération qui ne touche pas aux notes n'en invente pas la clé dans une quête qui n'en a pas.
    $campagnes->modifierQuete('campagne-d-essai', 'la-crypte', [['op' => 'remplacer', 'chemin' => 'titre', 'valeur' => 'La crypte, relue']]);
    expect($campagnes->lireQuete('campagne-d-essai', 'la-crypte'))->not->toHaveKey('notes');
});
