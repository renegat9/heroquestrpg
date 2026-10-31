<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\DeplacementHeros;
use App\Partie\EtatGroupe;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\ResolveurTour;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Validation\ValidationException;

/*
 * TRAPPE DE TÉLÉPORTATION (EXT-CARTE2, 2026-10-23) — jouée, pas seulement posée.
 *
 * Source : The Mage of the Mirror (F7539 p. 5 et p. 15 note A) et Kellar's Keep (F4543 p. 22 note C) :
 * « Any hero or monster landing on one of these squares immediately moves to the other trap door square.
 * [...] After a hero moves through it, they roll one combat die. If a skull is rolled, the hero loses 1
 * Body Point. After moving from one trap door square to the other, the hero's or monster's turn is over. »
 *
 * Le montage reprend celui de `sceneTerrainGlace()` (TerrainEnJeuTest) — sans en dépendre, pour que ce
 * fichier tourne seul. Une rangée de cases, deux salles, une paire de trappes en (2,0) et (6,0).
 */

beforeEach(function () {
    $this->seed([GabaritQueteSeeder::class, TerrainSeeder::class, SortDreadSeeder::class, ObjetSeeder::class, MonstreSeeder::class]);
});

/**
 * @param  list<list<string>>  $cases  m = mur, s = sol
 * @param  list<array{x: int, y: int, terrain_id: int, paire_id?: string}>  $terrain
 * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $sallesSupplementaires
 * @param  array{x: int, y: int, largeur: int, hauteur: int}  $salle0
 * @param  list<int>  $decouvertes  index des salles déjà découvertes (la salle 0 est toujours découverte)
 * @return array{groupe: Groupe, quete: Quete, heros: Personnage, etatHeros: EtatPersonnageQuete}
 */
function sceneTrappe(array $cases, array $herosPos, array $terrain, array $sallesSupplementaires, array $salle0, array $decouvertes = []): array
{
    $groupe = creerGroupe('table-trappe-'.uniqid());
    $gabarit = GabaritQuete::query()->firstOrFail();

    $largeur = count($cases[0] ?? []);
    $hauteur = count($cases);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — trappe de téléportation en jeu',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    $salles = [
        [...$salle0, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0],
        ...$sallesSupplementaires,
    ];

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => $hauteur,
            'cases' => $cases,
            'salles' => $salles,
            'portes' => [],
            'leviers' => [], 'pieges' => [], 'mobilier' => [], 'epreuves' => [],
            'terrain' => $terrain,
            'glace' => [],
            'spawn_heros' => [$herosPos],
            'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    if ($decouvertes !== []) {
        $quete->update(['salles_decouvertes' => $decouvertes]);
    }

    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);

    $joueur = connecterJoueur('trappe-'.uniqid());
    $heros = creerHeros($joueur, $groupe, 'Testeur', 1);

    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id,
        'personnage_id' => $heros->id,
        'position_x' => $herosPos['x'],
        'position_y' => $herosPos['y'],
        'tombe' => false,
        'a_joue' => false,
    ]);

    return ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros, 'etatHeros' => $etatHeros];
}

/** La paire de trappes du test : (2,0) et (6,0), salle 0 = x0-3, salle 1 = x5-8. */
function paireTrappeDuTest(): array
{
    $trappe = Terrain::where('nom', 'Trappe de téléportation')->firstOrFail();

    return [
        ['x' => 2, 'y' => 0, 'terrain_id' => $trappe->id, 'paire_id' => 'trappe-1'],
        ['x' => 6, 'y' => 0, 'terrain_id' => $trappe->id, 'paire_id' => 'trappe-1'],
    ];
}

/** Scène standard : une rangée de 9 cases, héros en x=0, salle 1 (x5-8) découverte ou non. */
function sceneTrappeStandard(bool $salle1Decouverte = true): array
{
    $scene = sceneTrappe(
        [array_fill(0, 9, 's')],
        ['x' => 0, 'y' => 0],
        paireTrappeDuTest(),
        [['x' => 5, 'y' => 0, 'largeur' => 4, 'hauteur' => 1]],
        ['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 1],
        $salle1Decouverte ? [0, 1] : [0],
    );

    // Un SECOND héros, en (3,0) — au-delà de l'entrée, hors du chemin et de la jumelle. Avec un seul
    // héros, la fin de son tour referme le round et réarme `a_deplace` : il garde le round ouvert.
    ajouterSecondHeroTrappe($scene['quete'], $scene['groupe'], ['x' => 3, 'y' => 0]);

    return $scene;
}

function optionDeplacementTrappe(): array
{
    return ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'];
}

// ---------------------------------------------------------------------
// 1. Le héros qui marche SUR la trappe en ressort sur la jumelle
// ---------------------------------------------------------------------

it('téléporte le héros au contact : il ressort sur la jumelle, un crâne lui coûte 1 PV, et son tour s\'achève', function () {
    $scene = sceneTrappeStandard();
    $pvAvant = (int) $scene['heros']->fresh()->pv_body;

    // Le dé du passage : un crâne (1-3 sur le d6 de combat).
    desFiges(array_fill(0, 20, 1));

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacementTrappe(), ['x' => 6, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $etat->position_x)->toBe(6, 'arrivé sur la jumelle')
        ->and((int) $etat->position_y)->toBe(0)
        ->and($resultat['vers'])->toBe(['x' => 6, 'y' => 0])
        ->and($resultat['teleportation'])->toMatchArray([
            'de' => ['x' => 2, 'y' => 0],
            'vers' => ['x' => 6, 'y' => 0],
            'passage' => ['nom' => 'Trappe de téléportation', 'jet' => 'crane', 'degats' => 1, 'retenus' => 1],
        ])
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pvAvant - 1, 'un crâne = 1 PV de Body')
        ->and((bool) $etat->a_deplace)->toBeTrue('la marche est finie')
        ->and((int) $etat->deplacement_restant)->toBe(0, 'la fin de tour ne laisse aucun point : « the hero\'s turn is over »')
        // Le décompte porte sur le trajet MARCHÉ jusqu'à l'entrée (2 cases), jamais sur le saut.
        ->and($resultat['distance'])->toBe(2);
});

it('un passage sans crâne ne fait AUCUN mal, mais la marche s\'arrête tout de même sur la jumelle', function () {
    $scene = sceneTrappeStandard();
    $pvAvant = (int) $scene['heros']->fresh()->pv_body;

    // Bouclier blanc (4-5) : le dé ne fait pas de crâne.
    desFiges(array_fill(0, 20, 4));

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacementTrappe(), ['x' => 6, 'y' => 0],
    );

    expect((int) $scene['etatHeros']->fresh()->position_x)->toBe(6)
        ->and($resultat['teleportation']['passage'])->toMatchArray(['jet' => 'bouclier_blanc', 'degats' => 0, 'retenus' => 0])
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pvAvant);
});

it('une jumelle OCCUPÉE par une autre figure n\'est pas une destination, et le résolveur la refuse', function () {
    $scene = sceneTrappeStandard();
    ajouterSecondHeroTrappe($scene['quete'], $scene['groupe'], ['x' => 6, 'y' => 0]);

    $etat = $scene['etatHeros']->fresh();
    $destinations = app(DeplacementHeros::class)->destinations($scene['quete']->fresh(), $scene['heros']->fresh(), $etat, 20);

    expect(DeplacementHeros::figureParmi($destinations, 6, 0))->toBeFalse('une jumelle occupée n\'est jamais proposée')
        ->and(DeplacementHeros::figureParmi($destinations, 1, 0))->toBeTrue('la marche simple reste offerte');

    desFiges(array_fill(0, 20, 1));

    expect(fn () => app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros']->fresh(), optionDeplacementTrappe(), ['x' => 6, 'y' => 0],
    ))->toThrow(ValidationException::class);
});

it('une jumelle dans une salle NON DÉCOUVERTE n\'est jamais une destination — ni l\'entrée non plus', function () {
    $scene = sceneTrappeStandard(salle1Decouverte: false);

    $destinations = app(DeplacementHeros::class)->destinations(
        $scene['quete']->fresh(), $scene['heros']->fresh(), $scene['etatHeros']->fresh(), 20,
    );

    expect(DeplacementHeros::figureParmi($destinations, 6, 0))->toBeFalse('la jumelle est dans l\'inconnu')
        ->and(DeplacementHeros::figureParmi($destinations, 2, 0))->toBeFalse('la trappe elle-même n\'est jamais une destination');
});

// ---------------------------------------------------------------------
// 2. Le GRAPHE : la trappe est un portail, jamais un passage à pied
// ---------------------------------------------------------------------

it('le portail ne se traverse pas à pied : rien au-delà de l\'entrée ni de la jumelle n\'est atteignable en marchant', function () {
    $trappe = Terrain::where('nom', 'Trappe de téléportation')->firstOrFail();
    $grille = new Grille([array_fill(0, 9, 's')]);
    $grille->definirPortails(['2,0' => '6,0', '6,0' => '2,0']);

    $atteignables = array_keys($grille->casesAtteignables(0, 0, 100));
    sort($atteignables);

    // Atteignables : la marche simple jusqu'à l'entrée (1,0), et la jumelle (6,0), arrivée par la trappe.
    // Pas (2,0) : on n'y finit jamais. Pas (3,0)-(5,0) ni (7,0)-(8,0) : on ne traverse pas une trappe à pied.
    expect($atteignables)->toBe(['1,0', '6,0'])
        ->and($trappe->effet['teleportation'])->toBe('contact');
});

it('le chemin vers la jumelle s\'achève sur l\'ENTRÉE : la téléportation, pas un pas de plus, est ce qui mène à la jumelle', function () {
    $grille = new Grille([array_fill(0, 9, 's')]);
    $grille->definirPortails(['2,0' => '6,0', '6,0' => '2,0']);

    $chemin = $grille->chemin(0, 0, 6, 0);

    expect($chemin)->toBe([['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0]])
        ->and($grille->coutChemin($chemin))->toBe(2, 'le saut vers la jumelle ne coûte rien de plus que l\'entrée');
});

it('le TUNNEL de glace (teleportation: true) reste une arrivée seule : il ne devient pas un portail', function () {
    $grille = new Grille([array_fill(0, 9, 's')]);
    // Aucun portail posé : la case d'entrée est un sol ordinaire, comme avant.

    $atteignables = array_keys($grille->casesAtteignables(0, 0, 100));
    sort($atteignables);

    expect(in_array('6,0', $atteignables, true))->toBeTrue('sans portail, la marche atteint la case 6')
        ->and(in_array('7,0', $atteignables, true))->toBeTrue('et au-delà');
});

// ---------------------------------------------------------------------
// 3. Le MONSTRE qui entre dans une trappe ressort sur la jumelle, et son tour s'achève
// ---------------------------------------------------------------------

it('un monstre qui entre dans la trappe est porté sur la jumelle, sans frapper ce tour-là', function () {
    // Couloir : monstre en x=0, héros en x=7 (dans la salle 1, découverte), trappes en (2,0) et (6,0).
    // La seule case au contact du héros est la jumelle (6,0) : le monstre y arrive en marchant sur (2,0).
    $scene = sceneTrappe(
        [array_fill(0, 8, 's')],
        ['x' => 7, 'y' => 0],
        paireTrappeDuTest(),
        [['x' => 5, 'y' => 0, 'largeur' => 3, 'hauteur' => 1]],
        ['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 1],
        [0, 1],
    );

    $catalogue = Monstre::where('nom_base', 'Squelette')->firstOrFail();
    $instance = InstanceMonstre::create([
        'quete_id' => $scene['quete']->id,
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => 0, 'position_y' => 0,
        'etat' => 'actif', 'revele' => true,
    ]);

    $pvHeros = (int) $scene['heros']->fresh()->pv_body;
    desFiges(array_fill(0, 80, 1));

    app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'],
        ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'], [],
    );

    $instance->refresh();

    expect((int) $instance->position_x)->toBe(6, 'le monstre ressort sur la jumelle')
        ->and((int) $instance->position_y)->toBe(0)
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pvHeros, 'il n\'a pas frappé en arrivant : son tour s\'est achevé à la trappe');
});

it('un monstre ne ressort pas sur une jumelle où se tient déjà un autre monstre', function () {
    // Même couloir, mais un second Squelette tient la jumelle (6,0), au contact du héros. La marche du premier se calcule
    // sur la grille de PASSAGE, où les monstres se franchissent : la jumelle y passait pour libre (2026-10-31).
    $scene = sceneTrappe(
        [array_fill(0, 8, 's')],
        ['x' => 7, 'y' => 0],
        paireTrappeDuTest(),
        [['x' => 5, 'y' => 0, 'largeur' => 3, 'hauteur' => 1]],
        ['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 1],
        [0, 1],
    );

    $catalogue = Monstre::where('nom_base', 'Squelette')->firstOrFail();
    $poser = fn (int $x) => InstanceMonstre::create([
        'quete_id' => $scene['quete']->id,
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => $x, 'position_y' => 0,
        'etat' => 'actif', 'revele' => true,
    ]);
    $marcheur = $poser(0); // joue le premier
    $poser(6);

    desFiges(array_fill(0, 80, 4));

    app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'],
        ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'], [],
    );

    expect((int) $marcheur->fresh()->position_x)->not->toBe(6, 'deux figures ne partagent jamais une case');
});

// ---------------------------------------------------------------------
// 4. L'annonce : la téléportation, son dé et la fin de tour se disent dans le fil
// ---------------------------------------------------------------------

it('le fil de table annonce le passage de trappe — héros et monstre — et le dé de passage', function () {
    $payloadHeros = [
        'type' => 'deplacement',
        'option_id' => 'se_deplacer',
        'chemin' => [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0]],
        'distance' => 2,
        'vers' => ['x' => 6, 'y' => 0],
        'teleportation' => [
            'de' => ['x' => 2, 'y' => 0],
            'vers' => ['x' => 6, 'y' => 0],
            'passage' => ['nom' => 'Trappe de téléportation', 'jet' => 'crane', 'degats' => 1, 'retenus' => 1],
        ],
    ];

    $texteHeros = implode(' | ', array_column((new JournalCombat)->depuisResultat($payloadHeros, 'Testeur'), 'texte'));

    expect($texteHeros)->toContain('trappe de téléportation')
        ->and($texteHeros)->toContain('ressort ailleurs')
        ->and($texteHeros)->toContain('−1 PV de Body')
        ->and($texteHeros)->toContain('son tour s\'achève');

    $payloadMonstre = [
        'type' => 'deplacement_monstre',
        'monstre' => 'Squelette',
        'chemin' => [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0]],
        'teleportation' => ['de' => ['x' => 2, 'y' => 0], 'vers' => ['x' => 6, 'y' => 0]],
    ];

    $texteMonstre = implode(' | ', array_column((new JournalCombat)->depuisResultat($payloadMonstre, 'Squelette'), 'texte'));

    expect($texteMonstre)->toContain('trappe de téléportation')
        ->and($texteMonstre)->toContain('son tour s\'achève');
});

// ---------------------------------------------------------------------
// Helper local : un second héros occupe une case (le montage de `ajouterSecondHeros`, sans sa dépendance)
// ---------------------------------------------------------------------

function ajouterSecondHeroTrappe(Quete $quete, Groupe $groupe, array $pos): void
{
    $joueur = connecterJoueur('trappe-second-'.uniqid());
    $second = creerHeros($joueur, $groupe, 'Second', 2);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id,
        'personnage_id' => $second->id,
        'position_x' => $pos['x'],
        'position_y' => $pos['y'],
        'tombe' => false,
        'a_joue' => false,
    ]);
}

// ---------------------------------------------------------------------
// 5. La PUBLICATION sous brouillard, et l'allié qui ne franchit pas la trappe
// ---------------------------------------------------------------------

it('publie la trappe et son paire_id SOUS un brouillard levé, jamais une jumelle masquée', function () {
    $trappe = Terrain::where('nom', 'Trappe de téléportation')->firstOrFail();
    $carte = new Carte(['grille' => ['terrain' => [
        ['x' => 2, 'y' => 0, 'terrain_id' => $trappe->id, 'paire_id' => 'trappe-1'],
        ['x' => 6, 'y' => 0, 'terrain_id' => $trappe->id, 'paire_id' => 'trappe-1'],
    ]]]);

    $cases = [array_fill(0, 9, 's')];
    $cases[0][6] = 'b'; // la jumelle est sous le brouillard

    $methode = new ReflectionMethod(EtatGroupe::class, 'terrain');
    $methode->setAccessible(true);
    $publies = collect($methode->invoke(app(EtatGroupe::class), $carte, $cases));

    expect($publies)->toHaveCount(1)
        ->and($publies->first()['x'])->toBe(2, 'l\'entrée vue est publiée')
        ->and($publies->first()['paire_id'])->toBe('trappe-1', 'le couple est publié : le client sait qu\'elle est une paire')
        ->and($publies->first()['nom'])->toBe('Trappe de téléportation');
});

it('un allié ne franchit pas une trappe : case fermée pour lui, la jumelle n\'est jamais une destination', function () {
    $grille = new Grille([array_fill(0, 9, 's')]);
    $grille->definirPortails(['2,0' => '6,0', '6,0' => '2,0']);
    $grille->fermerPortails();

    $atteignables = array_keys($grille->casesAtteignables(0, 0, 100));

    expect($atteignables)->toBe(['1,0'], 'la trappe bloque l\'allié : ni la jumelle, ni rien au-delà');
});
