<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\GabaritQuete;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\CarteRefusee;
use App\Partie\Classique\CatalogueConstructeur;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Tests\Feature\Classique\DocumentExemple;

/*
 * Le producteur de carte d'une quête construite (MODE CLASSIQUE, lot K2). Tout le contenu est INVENTÉ
 * (tests/Feature/Classique/DocumentExemple.php) ; seuls les slugs de catalogue sont réels.
 *
 * Les valeurs attendues (embrasures, cases, spawns, aperçu) sont dérivées À LA MAIN du plan de l'exemple, pas copiées
 * depuis la sortie du producteur : un écart signale une erreur de l'un des deux, à trancher, jamais à recopier.
 */

beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class,
    ]);
});

/** @return array{carte: array<string, mixed>, monstres: list<array<string, mixed>>} */
function produitDeLExemple(int $indice = 0): array
{
    $campagne = DocumentExemple::campagne();

    return app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][$indice]);
}

/** La grille moteur qu'une carte construite donne au jeu : `Grille::depuisCarte()`, son premier pas dans `FabriqueGrille`. */
function grilleDeLExemple(int $indice, ?callable $avant = null): Grille
{
    $carte = produitDeLExemple($indice)['carte'];
    if ($avant !== null) {
        $avant($carte);
    }

    return Grille::depuisCarte(new Carte(['grille' => $carte]));
}

/**
 * Ouvre la porte moteur d'ARÊTE (x, y, cote) — ce que `MoteurPortes::ouvrir()` fait à la carte en jeu. Les coordonnées sont
 * celles de l'arête moteur (`CarteConstruite::porteMoteur()`), pas celles de la porte du document.
 */
function ouvrirLaPorte(array &$carte, int $x, int $y, string $cote): void
{
    foreach ($carte['portes'] as $i => $porte) {
        if ($porte['x'] === $x && $porte['y'] === $y && $porte['cote'] === $cote) {
            $carte['portes'][$i]['etat'] = 'ouverte';
        }
    }
}

// ─── Parité : les mêmes clés que la carte réellement assemblée ───────────────────────────────────────────────────────

it('rend les mêmes clés de premier niveau que AssembleurCarte::assembler(), dans les deux sens', function () {
    $gabarit = GabaritQuete::query()->orderBy('id')->firstOrFail();
    $assemblee = app(AssembleurCarte::class)->assembler($gabarit, 4242);
    $produite = produitDeLExemple()['carte'];

    $clesAssemblees = array_keys($assemblee);
    $clesProduites = array_keys($produite);

    expect(array_diff($clesProduites, $clesAssemblees))->toBe([])
        ->and(array_diff($clesAssemblees, $clesProduites))->toBe([])
        ->and($clesProduites)->toHaveCount(17);
});

it('rend la carte et les monstres, et rien d\'autre au premier niveau', function () {
    expect(array_keys(produitDeLExemple()))->toBe(['carte', 'monstres']);
});

// ─── Le moteur LA JOUE : la carte produite, chargée comme au jeu ───────────────────────────────────────────────────

it('depuis l\'escalier, la sortie vers le couloir du nord passe quand la porte est ouverte, et ne passe pas quand elle est fermée', function () {
    $fermee = grilleDeLExemple(0);
    expect($fermee->chemin(2, 4, 3, 0))->toBeNull();

    $carte = produitDeLExemple(0)['carte'];
    ouvrirLaPorte($carte, 3, 0, 's'); // la porte nord (3, 2) n : arête (3, 0)–(3, 1), embrasure (3, 1)
    $ouverte = Grille::depuisCarte(new Carte(['grille' => $carte]));

    expect($ouverte->chemin(2, 4, 3, 0))->not->toBeNull();
});

it('la porte nord ne s\'ouvre que par son embrasure : la case (3, 1) est traversable une fois ouverte, jamais fermée', function () {
    expect(grilleDeLExemple(0)->estTraversable(3, 1))->toBeFalse();

    $ouverte = grilleDeLExemple(0, function (array &$carte): void {
        ouvrirLaPorte($carte, 3, 0, 's');
    });
    expect($ouverte->estTraversable(3, 1))->toBeTrue();
});

it('une case de salle fermée est infranchissable : galerie, dans la seconde quête', function () {
    $grille = grilleDeLExemple(1);

    // `galerie` est fermée dans la seconde quête : son intérieur et la réserve sont de la roche.
    expect($grille->estTraversable(9, 3))->toBeFalse()
        ->and($grille->estTraversable(15, 3))->toBeFalse()
        ->and($grille->estRoche(9, 3))->toBeTrue();
});

it('une case de bloc est infranchissable, et la case voisine du couloir ne l\'est pas', function () {
    $grille = grilleDeLExemple(0); // bloc en (11, 7), dans le couloir

    expect($grille->estTraversable(11, 7))->toBeFalse()
        ->and($grille->estTraversable(10, 7))->toBeTrue()
        ->and($grille->estTraversable(12, 7))->toBeTrue();
});

it('la porte entre deux salles qui partagent un mur ne laisse passer qu\'une fois ouverte, par son embrasure (7, 3)', function () {
    expect(grilleDeLExemple(0)->estTraversable(7, 3))->toBeFalse()
        ->and(grilleDeLExemple(0)->chemin(2, 4, 8, 3))->toBeNull();

    $ouverte = grilleDeLExemple(0, function (array &$carte): void {
        ouvrirLaPorte($carte, 7, 3, 'e'); // la porte (6, 3) e : arête (7, 3)–(8, 3)
    });
    expect($ouverte->estTraversable(7, 3))->toBeTrue()
        ->and($ouverte->chemin(2, 4, 8, 3))->not->toBeNull();
});

it('le mobilier de la quête bloque le passage là où le jeu le lit : FabriqueGrille, la table en (4–6, 4–5)', function () {
    $quete = new Quete();
    $quete->setRelation('carte', new Carte(['grille' => produitDeLExemple(0)['carte']]));

    $grille = FabriqueGrille::pour($quete);

    expect($grille->estTraversable(5, 4))->toBeFalse()
        ->and($grille->estTraversable(3, 4))->toBeTrue(); // la marche de l'escalier, libre
});

// ─── Correspondance des portes : la table de `docs/contrat-api.md` contre `Grille::caseEmbrasure()` ─────────────────

it('la table des portes correspond à Grille::caseEmbrasure(), pour les quatre côtés', function (int $indice, array $porteK1, array $embrasureAttendue, array $ouAttendue) {
    $carte = produitDeLExemple($indice)['carte'];
    $arete = CarteConstruite::porteMoteur($porteK1)['arete'];

    // Le moteur désigne l'embrasure de l'arête par la même règle que le jeu.
    expect(Grille::caseEmbrasure($arete, $carte['salles']))->toBe($embrasureAttendue)
        // L'arête relie bien l'embrasure à la case juste dehors, dans cet ordre.
        ->and(Grille::casesPorte($arete))->toEqualCanonicalizing([$ouAttendue, $embrasureAttendue]);
})->with([
    'nord : (3, 2) n → embrasure (3, 1), dehors (3, 0)' => [0, ['x' => 3, 'y' => 2, 'cote' => 'n'],
        ['x' => 3, 'y' => 1], ['x' => 3, 'y' => 0]],
    'est : (6, 3) e → embrasure (7, 3), dehors (8, 3) (salle voisine partagée)' => [0, ['x' => 6, 'y' => 3, 'cote' => 'e'],
        ['x' => 7, 'y' => 3], ['x' => 8, 'y' => 3]],
    'sud : (4, 5) s → embrasure (4, 6), dehors (4, 7)' => [1, ['x' => 4, 'y' => 5, 'cote' => 's'],
        ['x' => 4, 'y' => 6], ['x' => 4, 'y' => 7]],
    'ouest : (3, 10) o → embrasure (2, 10), dehors (1, 10)' => [1, ['x' => 3, 'y' => 10, 'cote' => 'o'],
        ['x' => 2, 'y' => 10], ['x' => 1, 'y' => 10]],
]);

it('une porte secrète est posée fermée et non révélée, sur la case de sa porte', function () {
    $carte = produitDeLExemple(0)['carte'];
    $secrete = collect($carte['portes'])->first(fn (array $p) => $p['etat'] === 'secrete');

    // Porte (9, 5) côté s : l'arête moteur est celle de l'embrasure (9, 6) vers le couloir (9, 7).
    expect($secrete)->toMatchArray(['x' => 9, 'y' => 6, 'cote' => 's', 'etat' => 'secrete', 'revele' => false]);
});

// ─── Départ, meubles, décisions de forme ──────────────────────────────────────────────────────────────────────────

it('spawn_heros : l\'escalier d\'abord, puis les cases libres de la salle de départ, de la plus proche à la plus lointaine', function () {
    // Escalier en (2–3, 4–5). Libres dans `entree` : (3,2) (4,2) (6,2) (2,3) (3,3) (4,3) (5,3) (6,3), car le fosse est en
    // (2,2), le gobelin en (5,2), la table en (4–6, 4–5). Distance de Tchebychev à l'escalier : 1 pour (2,3) (3,3) (4,3),
    // 2 pour (3,2) (4,2) (5,3), 3 pour (6,2) (6,3). Plafond : 8.
    expect(produitDeLExemple(0)['carte']['spawn_heros'])->toBe([
        ['x' => 2, 'y' => 4], ['x' => 3, 'y' => 4], ['x' => 2, 'y' => 5], ['x' => 3, 'y' => 5],
        ['x' => 2, 'y' => 3], ['x' => 3, 'y' => 3], ['x' => 4, 'y' => 3], ['x' => 3, 'y' => 2],
    ]);
});

it('spawn_heros de la seconde quête part du départ en (5, 2), dans l\'entrée ouverte', function () {
    expect(array_slice(produitDeLExemple(1)['carte']['spawn_heros'], 0, 4))->toBe([
        ['x' => 5, 'y' => 2], ['x' => 6, 'y' => 2], ['x' => 5, 'y' => 3], ['x' => 6, 'y' => 3],
    ]);
});

it('la salle 0 est celle du départ, et la première de la liste moteur ; les autres suivent l\'ordre du plateau', function () {
    $salles = produitDeLExemple(0)['carte']['salles'];

    // Moteur = rectangle mur compris : l'intérieur (2–6, 2–5) de `entree` devient (1–7, 1–6).
    expect($salles[0])->toMatchArray(['x' => 1, 'y' => 1, 'largeur' => 7, 'hauteur' => 6, 'theme' => 'generique'])
        ->and($salles[0]['mediane_x'])->toBe(1 + intdiv(7, 2))
        ->and($salles[0]['mediane_y'])->toBe(1 + intdiv(6, 2))
        ->and($salles[1])->toMatchArray(['x' => 7, 'y' => 1, 'largeur' => 6, 'hauteur' => 6])
        ->and(count($salles))->toBe(2); // `reserve` est fermée, `crypte` n'est pas ouverte : deux salles seulement
});

it('le meuble porte l\'index moteur de sa salle, et son emprise tournée', function () {
    // Seconde quête : `bibliotheque` (3×1) posée à 90° en (6, 9) : emprise 1×3, dans `crypte`, la salle 1 (après l'entrée).
    expect(produitDeLExemple(1)['carte']['mobilier'])->toBe([[
        'mobilier_id' => (int) app(CatalogueConstructeur::class)->index('mobilier')['bibliotheque']->id,
        'x' => 6, 'y' => 9, 'l' => 1, 'h' => 3, 'salle' => 1,
    ]]);
});

it('les monstres voyagent dans `monstres`, pas dans `carte`, et portent l\'id du catalogue', function () {
    $produit = produitDeLExemple(0);

    expect($produit['monstres'])->toBe([[
        'monstre_id' => (int) app(CatalogueConstructeur::class)->index('monstres')['gobelin']->id, 'x' => 5, 'y' => 2,
    ]])
        ->and($produit['carte']['spawn_monstres'])->toBe([])
        ->and($produit['carte']['spawn_figures_fixes'])->toBe([]);
});

it('les décisions de forme : aretes vides, passage_secret vrai dès qu\'une porte est secrète, pas de levier ni d\'épreuve', function () {
    $carte = produitDeLExemple(0)['carte'];

    expect($carte['aretes'])->toBe([])
        ->and($carte['passage_secret'])->toBeTrue()
        ->and($carte['leviers'])->toBe([])
        ->and($carte['epreuves'])->toBe([])
        ->and($carte['dangers'])->toBe([]);
});

it('la carte qui ne porte aucune porte secrète le dit : passage_secret est faux', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['portes'] = [['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale']];

    expect(app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0])['carte']['passage_secret'])->toBeFalse();
});

it('les cases : roche hors des salles ouvertes, sol dans l\'intérieur ouvert et dans le couloir, embrasures ouvertes', function () {
    $cases = produitDeLExemple(0)['carte']['cases'];

    expect($cases[0][0])->toBe('s')        // couloir
        ->and($cases[1][1])->toBe('m')      // anneau de `entree` : mur
        ->and($cases[1][3])->toBe('s')      // embrasure de la porte nord
        ->and($cases[3][7])->toBe('s')      // embrasure de la porte est
        ->and($cases[2][2])->toBe('s')      // intérieur de `entree`
        ->and($cases[10][4])->toBe('m')     // intérieur de `crypte` : salle fermée, roche
        ->and($cases[7][11])->toBe('m');    // bloc en couloir (11, 7)
});

// ─── Refus : rien n'est réparé, rien n'est produit ────────────────────────────────────────────────────────────────

it('refuse de produire une carte d\'un document invalide, en portant les refus nommés', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][1]['portes'][] = ['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale'];

    $levee = null;
    try {
        app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][1]);
    } catch (CarteRefusee $e) {
        $levee = $e;
    }

    expect($levee)->toBeInstanceOf(CarteRefusee::class)
        ->and(array_column($levee->refus, 'code'))->toBe([ValidationCampagne::PORTE_DANS_LA_ROCHE]);
});

it('refuse une quête qui n\'appartient pas au document, sans carte', function () {
    $campagne = DocumentExemple::campagne();
    $etrangere = DocumentExemple::secondeQuete();
    $etrangere['slug'] = 'quete-venue-d-ailleurs';

    expect(fn () => app(CarteConstruite::class)->produire($campagne, $etrangere))
        ->toThrow(InvalidArgumentException::class);
});

// ─── L'aperçu texte : la quête relue sans écran ───────────────────────────────────────────────────────────────────

it('l\'aperçu de la première quête de l\'exemple est exactement celui qu\'on a dessiné à la main', function () {
    $campagne = DocumentExemple::campagne();

    $apercu = app(CarteConstruite::class)->apercu($campagne, $campagne['quetes'][0]);

    expect($apercu)->toBe(<<<'TXT'
....................
.##+##############..
.#^..M.#....######..
.#.....+.~..######..
.#<<mmm#....######..
.#<<mmm#....######..
.########?###.......
...........#........
..######............
..######............
..######............
..######............
..######............
....................

Légende :
  + porte normale : intérieur (6, 3), côté e, embrasure (7, 3)
  + porte normale : intérieur (3, 2), côté n, embrasure (3, 1)
  ? porte secrète : intérieur (9, 5), côté s, embrasure (9, 6)
  # bloc : case (11, 7)
  < escalier de départ : coin (2, 4), 2×2
  m table : ancre (4, 4), rotation 0, emprise 3×2
  M gobelin : case (5, 2)
  ^ fosse : case (2, 2)
  ~ glace-glissante : case (9, 3)
TXT);
});
