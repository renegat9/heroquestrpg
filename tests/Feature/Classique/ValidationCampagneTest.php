<?php

declare(strict_types=1);

use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;

/*
 * Validation d'une campagne construite (MODE CLASSIQUE, lot K1) : un cas par code de refus, un document valide, et la
 * garantie dans les DEUX SENS — chaque code déclaré est produit par un cas, et chaque refus produit porte un code déclaré.
 * Tout le contenu est inventé (tests/Feature/Classique/DocumentExemple.php) ; seuls les slugs de catalogue sont réels.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

/** Les refus que la validation rend pour ce document. */
function classiqueRefus(array $document, ?string $slugAttendu = null): array
{
    return app(ValidationCampagne::class)->valider($document, $slugAttendu);
}

/**
 * Un cas par code de refus : [code attendu, fabrique du document fautif -> refus]. Chaque fabrique ne produit que la
 * faute visée, pour que le test dise exactement quoi corriger.
 *
 * @return array<string, array{0: string, 1: Closure}>
 */
function casDeRefus(): array
{
    return [
        'clé inconnue' => [ValidationCampagne::CLE_INCONNUE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['cle_bidon'] = 1;

            return classiqueRefus($doc);
        }],
        'notes : clé d\'un lot ultérieur' => [ValidationCampagne::CLE_PAS_ENCORE_PRISE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['notes'] = [];

            return classiqueRefus($doc);
        }],
        'clé obligatoire absente' => [ValidationCampagne::CLE_MANQUANTE, function (): array {
            $doc = DocumentExemple::campagne();
            unset($doc['titre']);

            return classiqueRefus($doc);
        }],
        'entier attendu' => [ValidationCampagne::TYPE_INVALIDE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['plateau']['largeur'] = '12';

            return classiqueRefus($doc);
        }],
        'titre vide' => [ValidationCampagne::VALEUR_INVALIDE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['titre'] = '   ';

            return classiqueRefus($doc);
        }],
        'format 2 inconnu' => [ValidationCampagne::FORMAT_INCONNU, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['format'] = 2;

            return classiqueRefus($doc);
        }],
        'slug avec espaces' => [ValidationCampagne::SLUG_INVALIDE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['slug'] = 'Ma Campagne';

            return classiqueRefus($doc);
        }],
        'deux quêtes, même slug' => [ValidationCampagne::SLUG_EN_DOUBLE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][1]['slug'] = 'premiere-marche';

            return classiqueRefus($doc);
        }],
        'salle qui sort du plateau' => [ValidationCampagne::SALLE_HORS_PLATEAU, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['plateau']['salles'][2]['x'] = 10; // 10 + 4 > 12

            return classiqueRefus($doc);
        }],
        'deux salles, même id' => [ValidationCampagne::SALLE_ID_EN_DOUBLE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['plateau']['salles'][1]['id'] = 'entree';

            return classiqueRefus($doc);
        }],
        'deux salles qui partagent une case' => [ValidationCampagne::SALLES_SE_CHEVAUCHENT, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['plateau']['salles'][1]['x'] = 4; // galerie touche entree en colonne 4

            return classiqueRefus($doc);
        }],
        'salle ouverte inconnue' => [ValidationCampagne::SALLE_INCONNUE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['salles_ouvertes'] = ['fantome'];

            return classiqueRefus($doc);
        }],
        'monstre absent du catalogue' => [ValidationCampagne::CATALOGUE_INCONNU, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['monstres'][0]['slug'] = 'monstre-inexistant';

            return classiqueRefus($doc);
        }],
        'classe de héros inconnue' => [ValidationCampagne::CLASSE_INCONNUE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['prerequis_heros'] = ['classe-inventee'];

            return classiqueRefus($doc);
        }],
        'monstre hors du plateau' => [ValidationCampagne::HORS_PLATEAU, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['monstres'][0]['x'] = 12; // largeur 12 : la case 12 n'existe pas

            return classiqueRefus($doc);
        }],
        'monstre dans une salle fermée' => [ValidationCampagne::DANS_SALLE_FERMEE, function (): array {
            $doc = DocumentExemple::campagne();
            // `entree` est fermée dans la seconde quête : on y pose un gobelin.
            $doc['quetes'][1]['monstres'] = [['slug' => 'gobelin', 'x' => 2, 'y' => 1]];

            return classiqueRefus($doc);
        }],
        'bloc posé dans une salle' => [ValidationCampagne::BLOC_HORS_COULOIR, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['blocs'] = [['x' => 2, 'y' => 2]]; // dans `entree`, ouverte

            return classiqueRefus($doc);
        }],
        'départ dans une salle fermée' => [ValidationCampagne::DEPART_INVALIDE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][1]['depart'] = ['x' => 2, 'y' => 1]; // `entree` est fermée dans la seconde quête

            return classiqueRefus($doc);
        }],
        'porte entre deux cases de la même salle' => [ValidationCampagne::PORTE_SANS_BORD, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['portes'] = [['x' => 2, 'y' => 2, 'cote' => 'e', 'type' => 'normale']]; // (3, 2) est dans `entree`

            return classiqueRefus($doc);
        }],
        'slug de l\'URL différent du document' => [ValidationCampagne::SLUG_NON_CONCORDANT, function (): array {
            return classiqueRefus(DocumentExemple::campagne(), 'une-autre-campagne');
        }],
        'empreinte d\'un fichier modifié' => [ValidationCampagne::EMPREINTE_INVALIDE, function (): array {
            return app(ValidationCampagne::class)->verifierEmpreinte(DocumentExemple::campagne(), 'empreinte-fausse');
        }],
    ];
}

it('accepte le document d\'exemple, sans aucun refus', function () {
    expect(classiqueRefus(DocumentExemple::campagne()))->toBe([]);
});

it('accepte une campagne sans quête (brouillon) et des listes vides', function () {
    $doc = DocumentExemple::campagne();
    $doc['quetes'] = [];
    $doc['prerequis_heros'] = [];
    $doc['plateau']['salles'] = [];

    expect(classiqueRefus($doc))->toBe([]);
});

it('accepte les champs nullables à null : auteur, mention, monstre errant, récompense', function () {
    $doc = DocumentExemple::campagne();
    $doc['auteur'] = null;
    $doc['mention'] = null;
    $doc['quetes'][1]['monstre_errant'] = null;
    $doc['quetes'][1]['recompense'] = null;

    expect(classiqueRefus($doc))->toBe([]);
});

it('rend chaque refus sous la forme {chemin, code, message}, en français', function (string $code, Closure $fabrique) {
    foreach ($fabrique() as $refus) {
        expect($refus)->toHaveKeys(['chemin', 'code', 'message'])
            ->and($refus['message'])->toBeString()->not->toBe('');
    }
})->with(array_map(fn (array $cas) => [$cas[0], $cas[1]], casDeRefus()));

it('produit le code attendu pour chaque cas de refus', function (string $code, Closure $fabrique) {
    expect(array_column($fabrique(), 'code'))->toContain($code);
})->with(array_map(fn (array $cas) => [$cas[0], $cas[1]], casDeRefus()));

it('garantit les deux sens : chaque code déclaré a un cas, et chaque refus porte un code déclaré', function () {
    $produits = [];
    foreach (casDeRefus() as $cas) {
        foreach ($cas[1]() as $refus) {
            $produits[] = $refus['code'];
        }
    }
    $produits = array_values(array_unique($produits));

    // Sens 1 : tout refus produit porte un code déclaré.
    expect(array_diff($produits, ValidationCampagne::CODES))->toBe([]);

    // Sens 2 : tout code déclaré est produit par au moins un cas.
    expect(array_diff(ValidationCampagne::CODES, $produits))->toBe([]);
});

it('ne déclare aucun code en double', function () {
    expect(array_unique(ValidationCampagne::CODES))->toHaveCount(count(ValidationCampagne::CODES));
});

it('nomme la clé réservée au lot K3 dans le message', function () {
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['notes'] = [];

    $refus = classiqueRefus($doc);

    expect($refus)->toHaveCount(1)
        ->and($refus[0]['chemin'])->toBe('quetes.0.notes')
        ->and($refus[0]['message'])->toContain('pas encore pris en charge (lot K3)');
});

it('nomme le chemin exact d\'un slug de catalogue inconnu', function () {
    $doc = DocumentExemple::campagne();
    $doc['quetes'][1]['monstres'][0]['slug'] = 'monstre-inexistant';

    $refus = classiqueRefus($doc);

    expect($refus)->toHaveCount(1)
        ->and($refus[0]['chemin'])->toBe('quetes.1.monstres.0.slug')
        ->and($refus[0]['code'])->toBe(ValidationCampagne::CATALOGUE_INCONNU)
        ->and($refus[0]['message'])->toContain('monstre-inexistant');
});

it('une clé obligatoire absente ne fait pas tomber les cases dans une salle fermée (pas de cascade)', function () {
    $doc = DocumentExemple::campagne();
    unset($doc['quetes'][0]['salles_ouvertes']);

    $refus = classiqueRefus($doc);

    expect(array_column($refus, 'code'))->toBe([ValidationCampagne::CLE_MANQUANTE])
        ->and($refus[0]['chemin'])->toBe('quetes.0.salles_ouvertes');
});

it('refuse un titre plus long que la colonne (200 caractères) et un slug de plus de 100 caractères', function () {
    $doc = DocumentExemple::campagne();
    $doc['titre'] = str_repeat('a', 201);
    $doc['quetes'][0]['slug'] = str_repeat('a', 101);

    $refus = classiqueRefus($doc);

    expect(array_column($refus, 'code'))->toBe([ValidationCampagne::VALEUR_INVALIDE, ValidationCampagne::SLUG_INVALIDE])
        ->and(array_column($refus, 'chemin'))->toBe(['titre', 'quetes.0.slug']);
});

it('un plateau sans liste de salles ne produit pas de salle_inconnue en cascade', function () {
    $doc = DocumentExemple::campagne();
    unset($doc['plateau']['salles']);

    $refus = classiqueRefus($doc);

    expect(array_column($refus, 'code'))->toBe([ValidationCampagne::CLE_MANQUANTE])
        ->and($refus[0]['chemin'])->toBe('plateau.salles');
});
