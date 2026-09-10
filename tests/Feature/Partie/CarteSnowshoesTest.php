<?php

declare(strict_types=1);

use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\ResolveurTour;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\TerrainSeeder;

/*
 * « Raquettes de Vitesse » / Snowshoes of Speed (Frozen Horror) : audit du
 * 2026-09-10. Sa dette du 2026-09-06 disait « MenuMoteur et ResolveurTour,
 * hors périmètre de cette phase » — un découpage de travail, jamais un
 * blocage technique. `Equipement::bonusDeplacementActif()` /
 * `Equipement::annuleGlaceGlissante()` sont désormais lus aux DEUX points de
 * passage (`MenuMoteur::deplacementDuTour()`, `ResolveurTour::resoudreDeplacement()`
 * / `tronquerSurGlace()`) — ce fichier le prouve EN JEU, via le résolveur, la
 * seule autorité que ce projet reconnaisse sur ce qui est réellement permis.
 */

beforeEach(function () {
    $this->seed([GabaritQueteSeeder::class, TerrainSeeder::class, ObjetSeeder::class]);
});

/**
 * @param  list<list<string>>  $cases  m = mur, s = sol
 * @param  array{x: int, y: int}  $herosPos
 * @param  list<array{x: int, y: int, terrain_id: int}>  $terrain
 * @return array{groupe: Groupe, quete: Quete, heros: Personnage, etatHeros: EtatPersonnageQuete}
 */
function sceneSnowshoes(
    array $cases,
    array $herosPos,
    array $terrain = [],
    ?string $theme = null,
): array {
    $groupe = creerGroupe('table-raquettes-'.uniqid());
    $gabarit = GabaritQuete::query()->firstOrFail();

    $largeur = count($cases[0] ?? []);
    $hauteur = count($cases);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — Raquettes de Vitesse',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $largeur,
        'hauteur' => $hauteur,
        'grille' => [
            'largeur' => $largeur, 'hauteur' => $hauteur,
            'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [],
            'leviers' => [], 'pieges' => [], 'mobilier' => [], 'epreuves' => [],
            'terrain' => $terrain,
            'glace' => [],
            'spawn_heros' => [$herosPos],
            'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete', 'theme_bestiaire' => $theme]);

    $joueur = connecterJoueur('raquettes-'.uniqid());
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

/**
 * Pose une pièce dans un emplacement donné, sans passer par les garde-fous.
 * (Copie locale : les fonctions d'un fichier Pest ne sont visibles qu'une
 * fois ce fichier chargé, donc jamais fiables d'un fichier de test à
 * l'autre — même patron que `ChargesEtSortsTest::poser()`.)
 */
function poserPourSnowshoes(Personnage $p, string $nom, string $emplacement): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

/** Option de déplacement minimale, patron de `MenuMoteur::generer()` (id `se_deplacer`). */
function optionDeplacementSnowshoes(): array
{
    return ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'];
}

/**
 * Un SECOND héros actif, n'ayant pas encore joué — garde le ROUND ouvert.
 * Avec un seul héros, forcer sa fin de tour boucle aussitôt sur
 * `jouerFinDeRound()` → `ouvrirNouveauTour()`, qui réarme `a_joue` à `false`
 * avant qu'on ait pu le lire (même patron que `TerrainEnJeuTest::ajouterSecondHeros()`).
 */
function ajouterSecondHerosSnowshoes(Quete $quete, Groupe $groupe, array $pos): void
{
    $joueur = connecterJoueur('raquettes-second-'.uniqid());
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

// =======================================================================
// +2 CASES DE DÉPLACEMENT — mais SEULEMENT en quête glacée
// =======================================================================

it("ajoute 2 cases au socle de déplacement quand la quête est THÉMÉE horreur_des_glaces", function () {
    $scene = sceneSnowshoes(
        [array_fill(0, 10, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        theme: 'horreur_des_glaces',
    );
    poserPourSnowshoes($scene['heros'], 'Raquettes de Vitesse', 'bottes');

    // d6 = 1 : sans les raquettes, socle 4 + 1 = 5 points. Avec elles, 7.
    desFiges(array_fill(0, 20, 1));

    app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacementSnowshoes(), ['x' => 1, 'y' => 0],
    );

    // 1 case parcourue (coût 1) sur un total de 7 : il doit en rester 6.
    expect((int) $scene['etatHeros']->fresh()->deplacement_restant)->toBe(6);
});

it("n'ajoute RIEN hors d'une quête glacée — la carte le dit, « seulement dans les quêtes glacées »", function () {
    $scene = sceneSnowshoes(
        [array_fill(0, 10, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        theme: 'jungles_delthrak',
    );
    poserPourSnowshoes($scene['heros'], 'Raquettes de Vitesse', 'bottes');

    desFiges(array_fill(0, 20, 1));

    app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacementSnowshoes(), ['x' => 1, 'y' => 0],
    );

    // Sans le bonus : socle 4 + 1 = 5, moins 1 case parcourue = 4.
    expect((int) $scene['etatHeros']->fresh()->deplacement_restant)->toBe(4);
});

it('ne donne rien à un héros qui ne les porte pas, même en quête glacée', function () {
    $scene = sceneSnowshoes(
        [array_fill(0, 10, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        theme: 'horreur_des_glaces',
    );

    desFiges(array_fill(0, 20, 1));

    app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacementSnowshoes(), ['x' => 1, 'y' => 0],
    );

    expect((int) $scene['etatHeros']->fresh()->deplacement_restant)->toBe(4);
});

// =======================================================================
// « ANNULE LA GLACE GLISSANTE » — nommément cette tuile, pas la Glissière
// =======================================================================

it("annule la Glace glissante : le porteur traverse sans jet, sans chute, sans fin de tour", function () {
    $glace = Terrain::where('nom', 'Glace glissante')->firstOrFail();

    $scene = sceneSnowshoes(
        [array_fill(0, 6, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [['x' => 2, 'y' => 0, 'terrain_id' => $glace->id]],
        theme: 'horreur_des_glaces',
    );
    poserPourSnowshoes($scene['heros'], 'Raquettes de Vitesse', 'bottes');

    // Bouclier blanc (4) : SANS les raquettes, ceci ferait chuter le héros
    // sur la case de contact et finirait son tour net (cf. TerrainEnJeuTest).
    desFiges(array_fill(0, 20, 4));

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacementSnowshoes(), ['x' => 5, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $etat->position_x)->toBe(5, 'aucun arrêt : la case de glace glissante ne coûte plus rien')
        ->and($etat->a_joue)->toBeFalse('aucune fin de tour forcée')
        ->and($resultat)->not->toHaveKey('terrain', 'rien à annoncer : la tuile est neutralisée avant tout jet');
});

it("ne couvre PAS la Glissière de glace — tuile DISTINCTE que la carte ne nomme pas", function () {
    $glissiere = Terrain::where('nom', 'Glissière de glace')->firstOrFail();

    $scene = sceneSnowshoes(
        [array_fill(0, 6, 's'), array_fill(0, 6, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [['x' => 2, 'y' => 0, 'terrain_id' => $glissiere->id]],
        theme: 'horreur_des_glaces',
    );
    poserPourSnowshoes($scene['heros'], 'Raquettes de Vitesse', 'bottes');
    ajouterSecondHerosSnowshoes($scene['quete'], $scene['groupe']->fresh(), ['x' => 0, 'y' => 1]);

    // Crâne (1) : la Glissière finit le tour INCONDITIONNELLEMENT, quelle
    // que soit la face — les raquettes ne protègent pas de cette tuile-ci.
    desFiges(array_fill(0, 20, 1));

    app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacementSnowshoes(), ['x' => 5, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $etat->position_x)->toBe(2, 'la Glissière arrête toujours le héros sur la case de contact')
        ->and($etat->a_joue)->toBeTrue('fin de tour inconditionnelle, raquettes ou pas');
});
