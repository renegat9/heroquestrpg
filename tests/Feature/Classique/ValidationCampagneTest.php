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
        'suite : clé d\'un lot ultérieur' => [ValidationCampagne::CLE_PAS_ENCORE_PRISE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['suite'] = [];

            return classiqueRefus($doc);
        }],
        'quête solo pour une classe que la campagne n\'exige pas' => [ValidationCampagne::SOLO_SANS_PREREQUIS, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['regles'] = ['solo' => 'nain'];

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
            $doc['plateau']['salles'][2]['x'] = 18; // crypte : 18 + 4 > 20

            return classiqueRefus($doc);
        }],
        'deux salles, même id' => [ValidationCampagne::SALLE_ID_EN_DOUBLE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['plateau']['salles'][1]['id'] = 'entree';

            return classiqueRefus($doc);
        }],
        'deux salles qui partagent une case' => [ValidationCampagne::SALLES_SE_CHEVAUCHENT, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['plateau']['salles'][1]['x'] = 4; // l'intérieur de galerie entre dans celui d'entree (colonnes 4 à 6)

            return classiqueRefus($doc);
        }],
        'départ hors salle (en couloir)' => [ValidationCampagne::DEPART_HORS_SALLE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['depart'] = ['x' => 12, 'y' => 9]; // couloir : le départ depuis un couloir attend le lot K5

            return classiqueRefus($doc);
        }],
        'porte qui donne dans la roche' => [ValidationCampagne::PORTE_DANS_LA_ROCHE, function (): array {
            $doc = DocumentExemple::campagne();
            // Dans la seconde quête, `galerie` est fermée : la porte est de `entree`, elle donne sur l'intérieur de `galerie`.
            $doc['quetes'][1]['portes'][] = ['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale'];

            return classiqueRefus($doc);
        }],
        'meuble dont l\'emprise sort de sa salle' => [ValidationCampagne::MOBILIER_HORS_SALLE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['mobilier'][0] = ['slug' => 'table', 'x' => 5, 'y' => 4, 'rotation' => 0]; // x 5–7 : 7 est le mur

            return classiqueRefus($doc);
        }],
        'note dont la cible ne convient pas à son déclencheur' => [ValidationCampagne::CIBLE_DE_NOTE_INVALIDE, function (): array {
            $doc = DocumentExemple::campagne();
            // Une fouille de trésor vise une SALLE, pas une porte.
            $doc['quetes'][0]['notes'] = [[
                'repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['porte' => 0]],
                'alors' => [['effet' => 'or', 'or' => 10]],
            ]];

            return classiqueRefus($doc);
        }],
        'fin qui attend un drapeau qu\'aucune note ne pose' => [ValidationCampagne::DRAPEAU_JAMAIS_POSE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['fin'] = ['type' => 'drapeau', 'drapeau' => 'relique', 'libelle' => 'Trouver la relique.'];

            return classiqueRefus($doc);
        }],
        'cible de fin qui ne désigne aucun monstre posé' => [ValidationCampagne::CIBLE_INCONNUE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['fin']['cibles'] = [7];

            return classiqueRefus($doc);
        }],
        'deux monstres sur une même case' => [ValidationCampagne::CASE_PARTAGEE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['monstres'][] = ['slug' => 'gobelin', 'x' => 5, 'y' => 2]; // déjà un gobelin en (5, 2)

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
            $doc['quetes'][0]['monstres'][0]['x'] = 20; // largeur 20 : la case 20 n'existe pas

            return classiqueRefus($doc);
        }],
        'monstre dans une salle fermée' => [ValidationCampagne::DANS_SALLE_FERMEE, function (): array {
            $doc = DocumentExemple::campagne();
            // `galerie` est fermée dans la seconde quête : on y pose un gobelin.
            $doc['quetes'][1]['monstres'] = [['slug' => 'gobelin', 'x' => 9, 'y' => 3]];

            return classiqueRefus($doc);
        }],
        'bloc posé dans une salle' => [ValidationCampagne::BLOC_HORS_COULOIR, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['blocs'] = [['x' => 2, 'y' => 3]]; // dans `entree`, ouverte

            return classiqueRefus($doc);
        }],
        'départ dans une salle fermée' => [ValidationCampagne::DEPART_INVALIDE, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][1]['depart'] = ['x' => 9, 'y' => 3]; // `galerie` est fermée dans la seconde quête

            return classiqueRefus($doc);
        }],
        'porte entre deux cases de la même salle' => [ValidationCampagne::PORTE_SANS_BORD, function (): array {
            $doc = DocumentExemple::campagne();
            $doc['quetes'][0]['portes'] = [['x' => 3, 'y' => 3, 'cote' => 'e', 'type' => 'normale']]; // (4, 3) est dans `entree`

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

it('nomme la clé réservée à un lot ultérieur dans le message', function () {
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['suite'] = [];

    $refus = classiqueRefus($doc);

    expect($refus)->toHaveCount(1)
        ->and($refus[0]['chemin'])->toBe('quetes.0.suite')
        ->and($refus[0]['message'])->toContain('pas encore pris en charge (lot K5)');
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

/*
 * Lot K2 — partage de case. Chaque couple interdit est refusé, UNE fois, sur le dernier venu ; un couple permis passe.
 */
it('refuse chaque couple d\'éléments qui ne peuvent pas partager une case, et nomme le dernier venu', function (array $ajout, string $chemin) {
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0][$ajout[0]][] = $ajout[1];

    $refus = classiqueRefus($doc);

    expect(array_column($refus, 'code'))->toBe([ValidationCampagne::CASE_PARTAGEE])
        ->and($refus[0]['chemin'])->toBe($chemin);
})->with([
    'un meuble et un meuble (coffre sur la table)' => [['mobilier', ['slug' => 'coffre', 'x' => 5, 'y' => 4, 'rotation' => 0]], 'quetes.0.mobilier.1'],
    'un meuble et un monstre (gobelin sur la table)' => [['monstres', ['slug' => 'gobelin', 'x' => 5, 'y' => 5]], 'quetes.0.monstres.1'],
    'l\'escalier et un meuble (coffre sur la marche)' => [['mobilier', ['slug' => 'coffre', 'x' => 3, 'y' => 5, 'rotation' => 0]], 'quetes.0.mobilier.1'],
    'l\'escalier et un monstre (gobelin sur la marche)' => [['monstres', ['slug' => 'gobelin', 'x' => 2, 'y' => 5]], 'quetes.0.monstres.1'],
    'un bloc et un monstre (gobelin sur le bloc)' => [['monstres', ['slug' => 'gobelin', 'x' => 11, 'y' => 7]], 'quetes.0.monstres.1'],
    'un bloc et un piège (fosse sur le bloc)' => [['pieges', ['slug' => 'fosse', 'x' => 11, 'y' => 7]], 'quetes.0.pieges.1'],
    'un bloc et un terrain (glace sur le bloc)' => [['terrain', ['slug' => 'glace-glissante', 'x' => 11, 'y' => 7]], 'quetes.0.terrain.1'],
    'deux portes sur un même bord de case' => [['portes', ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale']], 'quetes.0.portes.3'],
]);

it('n\'impose aucun partage refusé entre un piège ou un terrain et un meuble ou un monstre (décision K2)', function () {
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['pieges'][] = ['slug' => 'fosse', 'x' => 5, 'y' => 4];          // sous la table
    $doc['quetes'][0]['terrain'][] = ['slug' => 'glace-glissante', 'x' => 5, 'y' => 2]; // sous le gobelin

    expect(classiqueRefus($doc))->toBe([]);
});

it('un meuble posé hors de toute salle (dans un couloir) est refusé en mobilier_hors_salle', function () {
    $doc = DocumentExemple::campagne();
    $doc['quetes'][0]['mobilier'][] = ['slug' => 'coffre', 'x' => 12, 'y' => 9, 'rotation' => 0];

    $refus = classiqueRefus($doc);

    expect(array_column($refus, 'code'))->toBe([ValidationCampagne::MOBILIER_HORS_SALLE])
        ->and($refus[0]['chemin'])->toBe('quetes.0.mobilier.1');
});

it('une porte entre deux salles ouvertes qui partagent un mur est valide (l\'exemple en contient une)', function () {
    $doc = DocumentExemple::campagne();

    expect($doc['quetes'][0]['portes'][0])->toMatchArray(['x' => 6, 'y' => 3, 'cote' => 'e'])
        ->and(classiqueRefus($doc))->toBe([]);
});

it('accepte deux salles qui se touchent et une salle au bord du plateau : il n\'y a plus d\'anneau de murs', function () {
    // L'exemple lui-même : `entree` (x 2–6) et `galerie` (x 7–10) partagent un bord.
    expect(classiqueRefus(DocumentExemple::campagne()))->toBe([]);

    // Une salle collée au bord gauche du plateau.
    $doc = DocumentExemple::campagne();
    $doc['plateau']['salles'][3] = ['id' => 'reserve', 'x' => 0, 'y' => 9, 'l' => 2, 'h' => 3];
    expect(classiqueRefus($doc))->toBe([]);
});


/*
 * LA CONDITION DE FIN (première quête jouable, 2026-11-14). Le format n'offre que ce que le moteur sait jouer : la liste
 * des types vit dans `App\Models\Quete::OBJECTIFS_CONSTRUITS`, et la validation ne doit jamais en accepter un de plus.
 */
it('n\'offre comme conditions de fin que celles que le moteur sait jouer', function () {
    expect(ValidationCampagne::TYPES_FIN)->toBe(array_keys(\App\Models\Quete::OBJECTIFS_CONSTRUITS));
});

it('exige une condition de fin : au moins une cible, sans doublon, et la consigne de l\'auteur', function () {
    $sansFin = DocumentExemple::campagne();
    unset($sansFin['quetes'][0]['fin']);
    expect(collect(classiqueRefus($sansFin))->firstWhere('chemin', 'quetes.0.fin')['code'] ?? null)->toBe(ValidationCampagne::CLE_MANQUANTE);

    $sansCible = DocumentExemple::campagne();
    $sansCible['quetes'][0]['fin']['cibles'] = [];
    expect(array_column(classiqueRefus($sansCible), 'code'))->toBe([ValidationCampagne::CIBLE_INCONNUE]);

    $doublon = DocumentExemple::campagne();
    $doublon['quetes'][0]['fin']['cibles'] = [0, 0];
    expect(array_column(classiqueRefus($doublon), 'chemin'))->toBe(['quetes.0.fin.cibles.1']);

    $sansConsigne = DocumentExemple::campagne();
    $sansConsigne['quetes'][0]['fin']['libelle'] = '';
    expect(array_column(classiqueRefus($sansConsigne), 'chemin'))->toBe(['quetes.0.fin.libelle']);

    $typeInconnu = DocumentExemple::campagne();
    $typeInconnu['quetes'][0]['fin']['type'] = 'nettoyer';
    expect(array_column(classiqueRefus($typeInconnu), 'code'))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
});
