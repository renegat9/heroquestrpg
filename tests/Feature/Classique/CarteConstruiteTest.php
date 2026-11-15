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
 * Les valeurs attendues (arêtes, murs, cases, spawns, aperçu) sont dérivées À LA MAIN du plan de l'exemple, pas copiées
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
 * celles de l'arête moteur (`CarteConstruite::porteMoteur()`) : la case la plus haute (côté `s`) ou la plus à gauche (`e`).
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
        ->and($clesProduites)->toHaveCount(18); // `murs` s'est ajoutée aux deux producteurs (murs en arêtes)
});

it('rend la carte, les monstres et les accès, et rien d\'autre au premier niveau', function () {
    // Les accès (entrée, sortie) voyagent HORS de `carte`, dont les clés restent celles de l'assembleur.
    expect(array_keys(produitDeLExemple()))->toBe(['carte', 'monstres', 'acces']);
});

// ─── Le moteur LA JOUE : la carte produite, chargée comme au jeu ───────────────────────────────────────────────────

it('depuis l\'escalier, la sortie vers le couloir du nord passe quand la porte est ouverte, et ne passe pas quand elle est fermée', function () {
    $fermee = grilleDeLExemple(0);
    expect($fermee->chemin(2, 4, 3, 0))->toBeNull();

    $carte = produitDeLExemple(0)['carte'];
    ouvrirLaPorte($carte, 3, 1, 's'); // la porte nord de (3, 2) : le bord commun de (3, 1) et (3, 2)
    $ouverte = Grille::depuisCarte(new Carte(['grille' => $carte]));

    expect($ouverte->chemin(2, 4, 3, 0))->not->toBeNull();
});

it('une porte est un BORD : ses deux cases restent du sol, fermée comme ouverte — seul le passage de l\'une à l\'autre change', function () {
    $fermee = grilleDeLExemple(0);
    // Le couloir (3, 1) et la case de salle (3, 2) : on s'y tient dans les deux cas, il n'y a pas de case d'embrasure.
    expect($fermee->estTraversable(3, 1))->toBeTrue()
        ->and($fermee->estTraversable(3, 2))->toBeTrue()
        ->and($fermee->porteFermeeSurCase(3, 1))->toBeFalse()
        ->and($fermee->areteBloquee(3, 1, 3, 2))->toBeTrue();

    $ouverte = grilleDeLExemple(0, function (array &$carte): void {
        ouvrirLaPorte($carte, 3, 1, 's');
    });
    expect($ouverte->areteBloquee(3, 1, 3, 2))->toBeFalse()
        // Le mur d'à côté, lui, ne s'ouvre pas avec la porte.
        ->and($ouverte->areteBloquee(4, 1, 4, 2))->toBeTrue();
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

it('la porte entre deux salles qui SE TOUCHENT est leur bord commun : un pas de l\'une à l\'autre, une fois ouverte', function () {
    // `entree` (x 2–6) et `galerie` (x 7–10) : aucune case entre elles. La porte est le bord commun de (6, 3) et (7, 3).
    $fermee = grilleDeLExemple(0);
    expect($fermee->estTraversable(6, 3))->toBeTrue()
        ->and($fermee->estTraversable(7, 3))->toBeTrue()
        ->and($fermee->chemin(2, 4, 7, 3))->toBeNull()
        ->and($fermee->ligneDeVue(5, 3, 8, 3))->toBeFalse();

    $ouverte = grilleDeLExemple(0, function (array &$carte): void {
        ouvrirLaPorte($carte, 6, 3, 'e');
    });
    expect($ouverte->chemin(6, 3, 7, 3))->toBe([['x' => 7, 'y' => 3]])
        ->and($ouverte->chemin(2, 4, 7, 3))->not->toBeNull()
        ->and($ouverte->ligneDeVue(5, 3, 8, 3))->toBeTrue()
        // À travers la porte, pas à travers le mur : une rangée plus haut, les deux salles ne se voient pas.
        ->and($ouverte->ligneDeVue(6, 2, 7, 2))->toBeFalse();
});

it('le mobilier de la quête bloque le passage là où le jeu le lit : FabriqueGrille, la table en (4–6, 4–5)', function () {
    $quete = new Quete();
    $quete->setRelation('carte', new Carte(['grille' => produitDeLExemple(0)['carte']]));

    $grille = FabriqueGrille::pour($quete);

    expect($grille->estTraversable(5, 4))->toBeFalse()
        ->and($grille->estTraversable(3, 4))->toBeTrue(); // la marche de l'escalier, libre
});

// ─── Correspondance des portes : l'arête moteur relie la case de la porte à la case sur laquelle elle donne ────────

it('l\'arête moteur d\'une porte relie sa case à la case sur laquelle elle donne, pour les quatre côtés', function (array $porte, array $donneSur, array $areteAttendue) {
    $arete = CarteConstruite::porteMoteur($porte)['arete'];

    expect($arete)->toBe($areteAttendue)
        ->and(CarteConstruite::caseDehors($porte))->toBe($donneSur)
        // Les deux cases que le moteur lit sur cette arête sont bien celle de la porte et celle d'en face.
        ->and(Grille::casesPorte($arete))->toEqualCanonicalizing([['x' => $porte['x'], 'y' => $porte['y']], $donneSur]);
})->with([
    'nord : (3, 2) n donne sur (3, 1)' => [['x' => 3, 'y' => 2, 'cote' => 'n'], ['x' => 3, 'y' => 1], ['x' => 3, 'y' => 1, 'cote' => 's']],
    'est : (6, 3) e donne sur (7, 3), dans la salle voisine' => [['x' => 6, 'y' => 3, 'cote' => 'e'], ['x' => 7, 'y' => 3], ['x' => 6, 'y' => 3, 'cote' => 'e']],
    'sud : (4, 5) s donne sur (4, 6)' => [['x' => 4, 'y' => 5, 'cote' => 's'], ['x' => 4, 'y' => 6], ['x' => 4, 'y' => 5, 'cote' => 's']],
    'ouest : (3, 10) o donne sur (2, 10)' => [['x' => 3, 'y' => 10, 'cote' => 'o'], ['x' => 2, 'y' => 10], ['x' => 2, 'y' => 10, 'cote' => 'e']],
]);

it('une porte de la carte est posée sur une arête, sans embrasure ; la secrète est fermée et non révélée', function () {
    $carte = produitDeLExemple(0)['carte'];

    expect(collect($carte['portes'])->every(fn (array $p) => Grille::porteSurArete($p)))->toBeTrue();

    $secrete = collect($carte['portes'])->first(fn (array $p) => $p['etat'] === 'secrete');
    // Porte (9, 5) côté s : le bord commun de (9, 5) et du couloir (9, 6).
    expect($secrete)->toMatchArray(['x' => 9, 'y' => 5, 'cote' => 's', 'etat' => 'secrete', 'revele' => false, 'arete' => true]);
});

it('les MURS sont le pourtour des salles ouvertes, sur les arêtes — sauf là où une porte est posée', function () {
    $murs = collect(produitDeLExemple(0)['carte']['murs']);
    $a = fn (int $x, int $y, string $cote): bool => $murs->contains(['x' => $x, 'y' => $y, 'cote' => $cote]);

    // Le bord commun d'`entree` et de `galerie` : muré sur toute sa hauteur, sauf au rang de la porte (y = 3).
    expect($a(6, 2, 'e'))->toBeTrue()
        ->and($a(6, 3, 'e'))->toBeFalse()
        ->and($a(6, 4, 'e'))->toBeTrue()
        ->and($a(6, 5, 'e'))->toBeTrue()
        // Le mur nord d'`entree`, face au couloir : partout, sauf au droit de la porte de (3, 2).
        ->and($a(2, 1, 's'))->toBeTrue()
        ->and($a(3, 1, 's'))->toBeFalse()
        // Le passage secret de (9, 5) n'est pas un mur de la carte : c'est une porte (l'écran le montre en mur).
        ->and($a(9, 5, 's'))->toBeFalse()
        ->and($a(8, 5, 's'))->toBeTrue()
        // Aucun mur n'est écrit deux fois, et aucun ne borde une salle fermée (la roche suffit).
        ->and($murs->unique()->count())->toBe($murs->count())
        ->and($a(2, 9, 'e'))->toBeFalse();
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

    // Sans anneau : le rectangle moteur d'une salle est exactement ses cases — `entree` (2–6, 2–5), `galerie` (7–10, 2–5).
    expect($salles[0])->toMatchArray(['x' => 2, 'y' => 2, 'largeur' => 5, 'hauteur' => 4, 'theme' => 'generique'])
        ->and($salles[0]['mediane_x'])->toBe(2 + intdiv(5, 2))
        ->and($salles[0]['mediane_y'])->toBe(2 + intdiv(4, 2))
        ->and($salles[1])->toMatchArray(['x' => 7, 'y' => 2, 'largeur' => 4, 'hauteur' => 4])
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

it('les décisions de forme : passage_secret vrai dès qu\'une porte est secrète, pas de levier ni d\'épreuve', function () {
    $carte = produitDeLExemple(0)['carte'];

    expect($carte['passage_secret'])->toBeTrue()
        ->and($carte['leviers'])->toBe([])
        ->and($carte['epreuves'])->toBe([])
        ->and($carte['dangers'])->toBe([]);
});

/*
 * JONCTIONS — quelles salles chaque porte relie, dans la forme de l'assembleur (`aretes[i] = {a, b, porte_a, porte_b}`,
 * et `portes[].jonction = i`). Le sort « ouvrir une porte au choix » n'offre que les portes dont la jonction touche une
 * salle DÉCOUVERTE : sans jonction, il n'en offrait aucune sur une carte construite.
 *
 * Première quête de l'exemple : `entree` est la salle 0 (elle contient le départ), `galerie` la salle 1.
 */
it('donne à chaque porte sa jonction : deux salles pour un mur partagé, sa seule salle pour un couloir', function () {
    $carte = produitDeLExemple(0)['carte'];

    expect(array_column($carte['portes'], 'jonction'))->toBe([0, 1, 2])
        ->and(array_column($carte['portes'], 'boucle'))->toBe([false, false, false])
        // Porte sur le mur partagé entre `entree` et `galerie`.
        ->and([$carte['aretes'][0]['a'], $carte['aretes'][0]['b']])->toBe([0, 1])
        // Porte de `entree` vers le couloir du nord : elle ne relie que sa salle.
        ->and([$carte['aretes'][1]['a'], $carte['aretes'][1]['b']])->toBe([0, 0])
        // Porte secrète de `galerie` vers le couloir du sud.
        ->and([$carte['aretes'][2]['a'], $carte['aretes'][2]['b']])->toBe([1, 1]);

    // Chaque jonction nomme le seuil de SA porte, aux coordonnées que lit la grille.
    foreach ($carte['portes'] as $porte) {
        $arete = $carte['aretes'][$porte['jonction']];
        expect($arete['porte_a'])->toBe(['x' => $porte['x'], 'y' => $porte['y']])
            ->and($arete['porte_b'])->toBe($arete['porte_a']);
    }
});

it('la carte qui ne porte aucune porte secrète le dit : passage_secret est faux', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['portes'] = [['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale']];

    expect(app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0])['carte']['passage_secret'])->toBeFalse();
});

it('les cases : sol dans les salles ouvertes et le couloir, roche dans les salles fermées et sur un bloc — aucun mur n\'est une case', function () {
    $cases = produitDeLExemple(0)['carte']['cases'];

    expect($cases[0][0])->toBe('s')        // couloir
        ->and($cases[1][1])->toBe('s')      // couloir qui longe `entree` : plus de case de mur
        ->and($cases[2][2])->toBe('s')      // `entree`
        ->and($cases[3][7])->toBe('s')      // `galerie`, collée à `entree`
        ->and($cases[10][4])->toBe('m')     // `crypte` : salle fermée, roche
        ->and($cases[3][15])->toBe('m')     // `reserve` : salle fermée, roche
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
....................
..^+.M........###...
......+..~....###...
..<<mmm.......###...
..<<mmm..?..........
....................
...........#........
....................
...####.............
...####.............
...####.............
....................
....................

Légende :
  + porte normale : case (6, 3), côté e, donne sur (7, 3)
  + porte normale : case (3, 2), côté n, donne sur (3, 1)
  ? porte secrète : case (9, 5), côté s, donne sur (9, 6)
  # bloc : case (11, 7)
  < entrée et sortie : escalier, coin (2, 4), 2×2
  m table : ancre (4, 4), rotation 0, emprise 3×2
  M gobelin : case (5, 2)
  ^ fosse : case (2, 2)
  ~ glace-glissante : case (9, 3)
TXT);
});
