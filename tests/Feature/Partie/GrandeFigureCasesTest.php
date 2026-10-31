<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\MoteurDread;
use App\Partie\ResolveurTour;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortDreadSeeder;

/*
 * Les cases d'une figure de DEUX cases (2026-10-31, avant de passer Magrian à 2 cases) : où elle s'arrête, et où l'on
 * pose autour d'elle.
 *
 * Elle finit sa marche sur deux cases où elle a le droit de S'ARRÊTER. `marcheVersLaCible()` vérifiait l'arrivée sur la grille de PASSAGE : celle où les autres monstres
 * se franchissent (« franchitAllies », 2026-09-04) et où, pour un éthéré, la roche et les héros s'effacent. Traverser
 * n'est pas s'arrêter : la seconde case d'un Ogre champion se posait sur un autre monstre, et un éthéré de deux cases
 * pouvait finir à moitié dans la roche ou sur un héros. Même règle que `derniereCaseOuSArreter()` pour une case.
 *
 * Ses sbires surgissent AUTOUR d'elle, jamais sur elle : l'invocation cherchait les voisines de l'ancre sur une grille
 * qui exclut le lanceur, et sa seconde case y passait pour libre — un spectre de Magrian y serait apparu.
 */

beforeEach(function () {
    $this->seed([GabaritQueteSeeder::class, SortDreadSeeder::class, ObjetSeeder::class, MonstreSeeder::class]);
});

/**
 * Une salle construite à la main, un héros, un Ogre champion (1×2, ancre = case HAUTE) et d'autres figures.
 *
 * @param  list<list<string>>  $cases  m = roche, s = sol
 * @param  array{x: int, y: int}  $herosPos
 * @param  array{x: int, y: int}  $ogrePos
 * @param  list<array{nom: string, x: int, y: int}>  $autres
 * @return array{quete: Quete, ogre: InstanceMonstre}
 */
function sceneGrandeFigure(array $cases, array $herosPos, array $ogrePos, array $autres = [], bool $ethere = false, bool $jouer = true): array
{
    $groupe = creerGroupe('table-grande-figure-'.uniqid());
    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->firstOrFail()->id,
        'titre' => 'Quête de test — arrêt d une grande figure',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    $largeur = count($cases[0]);
    $hauteur = count($cases);
    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => $hauteur,
            'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'mobilier' => [], 'epreuves' => [], 'terrain' => [],
            'spawn_heros' => [$herosPos], 'spawn_monstres' => [], 'aretes' => [],
        ],
    ]);
    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);

    $heros = creerHeros(connecterJoueur('grande-'.uniqid()), $groupe, 'Testeur', 1);
    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => $herosPos['x'], 'position_y' => $herosPos['y'], 'tombe' => false, 'a_joue' => false,
    ]);

    $poser = function (string $nom, array $pos) use ($quete): InstanceMonstre {
        $catalogue = Monstre::where('nom_base', $nom)->firstOrFail();

        return InstanceMonstre::create([
            'quete_id' => $quete->id, 'monstre_id' => $catalogue->id,
            'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
            'position_x' => $pos['x'], 'position_y' => $pos['y'], 'etat' => 'actif', 'revele' => true,
        ]);
    };

    if ($ethere) {
        Monstre::where('nom_base', 'Ogre champion')->firstOrFail()->update(['capacites' => ['ethere']]);
    }
    // L'ogre d'abord : il joue avant les autres, qui n'ont pas encore bougé.
    $ogre = $poser('Ogre champion', $ogrePos);
    foreach ($autres as $autre) {
        $poser($autre['nom'], $autre);
    }

    if (! $jouer) {
        return ['quete' => $quete, 'ogre' => $ogre->fresh()];
    }

    desFiges(array_fill(0, 400, 4)); // boucliers blancs : personne ne tombe, rien ne change de place sous un coup
    app(ResolveurTour::class)->resoudre(
        $groupe->fresh(), $heros,
        ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'], [],
    );

    return ['quete' => $quete, 'ogre' => $ogre->fresh()];
}

/** Les deux cases de l'ogre sont du sol, et aucune autre figure ne s'y tient. */
function attendreEmpriseLegale(Quete $quete, InstanceMonstre $ogre): void
{
    $cases = $quete->carte->fresh()->grille['cases'];
    $autres = collect($quete->etatsPersonnages()->get()->map(fn ($e) => [(int) $e->position_x, (int) $e->position_y]))
        ->merge($quete->instancesMonstres()->where('etat', 'actif')->whereKeyNot($ogre->id)->get()
            ->map(fn ($i) => [(int) $i->position_x, (int) $i->position_y]));

    foreach ([[(int) $ogre->position_x, (int) $ogre->position_y], [(int) $ogre->position_x, (int) $ogre->position_y + 1]] as [$x, $y]) {
        expect($cases[$y][$x] ?? 'hors carte')->toBe('s', "case ({$x},{$y}) de l'ogre : du sol")
            ->and($autres->contains([$x, $y]))->toBeFalse("case ({$x},{$y}) de l'ogre : aucune autre figure");
    }
}

it('ne pose jamais la seconde case d une figure de deux cases sur un autre monstre', function () {
    // Héros en (0,1). Le plus court, pour l'ancre de l'ogre, finit en (1,1) — sa case basse serait (1,2), où se tient
    // une Effigie gardienne (immobile : elle ne s'écarte pas avant que l'ogre ait joué).
    $scene = sceneGrandeFigure(
        array_fill(0, 3, array_fill(0, 9, 's')),
        herosPos: ['x' => 0, 'y' => 1],
        ogrePos: ['x' => 6, 'y' => 1],
        autres: [['nom' => 'Effigie gardienne', 'x' => 1, 'y' => 2]],
    );

    expect((int) $scene['ogre']->position_x)->toBeLessThan(6, 'l ogre a marché');
    attendreEmpriseLegale($scene['quete'], $scene['ogre']);
});

it('ne laisse jamais un ÉTHÉRÉ de deux cases finir dans la roche ni sur un héros', function () {
    // La rangée du bas est de la roche. Au contact du héros (0,1), l'ancre n'a que (1,1) — sa case basse serait la
    // roche (1,2) — et (0,0) — sa case basse serait le héros. Un éthéré PASSE par l'une comme par l'autre, il ne s'y
    // arrête pas.
    $scene = sceneGrandeFigure(
        [array_fill(0, 9, 's'), array_fill(0, 9, 's'), array_fill(0, 9, 'm')],
        herosPos: ['x' => 0, 'y' => 1],
        ogrePos: ['x' => 6, 'y' => 0],
        ethere: true,
    );

    expect((int) $scene['ogre']->position_x)->toBeLessThan(6, 'l ogre a marché');
    attendreEmpriseLegale($scene['quete'], $scene['ogre']);
});

it('invoque autour de TOUTE l emprise d un lanceur de deux cases, jamais sur sa seconde case', function () {
    // Ogre champion en (3,0)-(3,1), héros loin en (0,2).
    $scene = sceneGrandeFigure(
        array_fill(0, 3, array_fill(0, 7, 's')),
        herosPos: ['x' => 0, 'y' => 2],
        ogrePos: ['x' => 3, 'y' => 0],
        jouer: false,
    );
    $methode = new ReflectionMethod(MoteurDread::class, 'casesLibresAdjacentes');
    $methode->setAccessible(true);

    $cases = $methode->invoke(app(MoteurDread::class), $scene['quete'], $scene['ogre']->load('monstre'));

    // Les voisines de l'ancre (droite, gauche ; le haut sort de la carte), puis celles de la case basse.
    expect($cases)->toBe([
        ['x' => 4, 'y' => 0], ['x' => 2, 'y' => 0],
        ['x' => 4, 'y' => 1], ['x' => 2, 'y' => 1], ['x' => 3, 'y' => 2],
    ]);
});

it('n invoque « en vue » jamais sur la seconde case du lanceur', function () {
    $scene = sceneGrandeFigure(
        array_fill(0, 3, array_fill(0, 7, 's')),
        herosPos: ['x' => 0, 'y' => 2],
        ogrePos: ['x' => 3, 'y' => 0],
        jouer: false,
    );
    $methode = new ReflectionMethod(MoteurDread::class, 'casesDInvocation');
    $methode->setAccessible(true);

    $cases = collect($methode->invoke(app(MoteurDread::class), $scene['quete'], $scene['ogre']->load('monstre'), new Illuminate\Database\Eloquent\Collection, 50))
        ->map(fn (array $c) => "{$c['x']},{$c['y']}");

    expect($cases)->not->toBeEmpty()
        ->and($cases->contains('3,0'))->toBeFalse('l ancre du lanceur')
        ->and($cases->contains('3,1'))->toBeFalse('la seconde case du lanceur');
});
