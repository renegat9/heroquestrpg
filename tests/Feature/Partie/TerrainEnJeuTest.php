<?php

declare(strict_types=1);

use App\Engine\Des\FaceDeCombat;
use App\Engine\MotsClesTerrain;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\FabriqueGrille;
use App\Partie\MenuMoteur;
use App\Partie\MoteurDread;
use App\Partie\ResolveurTour;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\TerrainSeeder;

/*
 * Couche TERRAIN, EN JEU (doc 18 §4, The Frozen Horror — plan glace phase 4b) :
 * `TerrainCarteTest` prouve les fondations (catalogue, placement, lecture de
 * grille, publication) ; ce fichier prouve que les QUATRE tuiles qui portent
 * une règle la tiennent VRAIMENT au moteur — Glace glissante, Glissière de
 * glace, Chambre forte de glace, Tunnel de glace — plus le VOCABULAIRE fermé
 * de `MotsClesTerrain`, dans les deux sens, comme `GrilleTalentsTest`.
 *
 * En prime (ajout du coordinateur, phase 2 déjà livrée) : le MUR DE GLACE
 * (Ice Wall, `carte.grille['glace']`, sort du boss) avait sa rupture écrite et
 * testée DIRECTEMENT dans `MoteurDread::endommagerMurDeGlace()`, mais aucun
 * geste de héros ne l'atteignait — l'option de menu et la résolution qui
 * manquaient sont prouvées ici, bout en bout.
 *
 * ⚠ Les 7 terrains sourcés valent tous `boite = horreur_des_glaces`, une boîte
 * volontairement DÉSACTIVÉE (`DemarreurQuete::BOITES_INCOMPLETES`) : aucune
 * carte assemblée procéduralement n'en pose aujourd'hui. Les scènes sont donc
 * construites À LA MAIN, comme `TerrainCarteTest::queteAvecCarteEtTerrain()`
 * et `SortsGlaceTest::sceneGlace()` — même patron, nom distinct pour ne rien
 * redéclarer entre fichiers de test.
 */

beforeEach(function () {
    $this->seed([GabaritQueteSeeder::class, TerrainSeeder::class, SortDreadSeeder::class]);
});

// ---------------------------------------------------------------------
// Scène — quête + carte construites à la main, un héros
// ---------------------------------------------------------------------

/**
 * @param  list<list<string>>  $cases  m = mur, s = sol
 * @param  array{x: int, y: int}  $herosPos
 * @param  list<array{x: int, y: int, terrain_id: int, paire_id?: string}>  $terrain
 * @param  list<array{x: int, y: int, largeur: int, hauteur: int}>  $sallesSupplementaires  au-delà de la salle 0 (départ, toujours découverte)
 * @param  array{x: int, y: int, largeur: int, hauteur: int}|null  $salle0  rectangle de la salle de départ ; `null` = toute la grille (défaut, pour les scènes qui n'ont besoin que d'UNE salle). ⚠ Passer un rectangle EXPLICITE dès que `$sallesSupplementaires` est utilisé — sinon la salle 0 par défaut (toute la grille) engloberait toujours la salle supplémentaire, et `Salles::indexDe()` la résoudrait toujours à l'index 0 (« toujours découverte »), quel que soit `salles_decouvertes`.
 * @param  list<array{x: int, y: int, source_instance_id: int, cranes: int}>  $muraille  `carte.grille['glace']` — Mur de Glace
 * @return array{groupe: Groupe, quete: Quete, heros: Personnage, etatHeros: EtatPersonnageQuete}
 */
function sceneTerrainGlace(
    array $cases,
    array $herosPos,
    array $terrain = [],
    array $sallesSupplementaires = [],
    ?array $salle0 = null,
    array $muraille = [],
    array $herosAttrs = [],
): array {
    $groupe = creerGroupe('table-terrain-glace-'.uniqid());
    $gabarit = GabaritQuete::query()->firstOrFail();

    $largeur = count($cases[0] ?? []);
    $hauteur = count($cases);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — terrain de glace en jeu',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    $salle0 ??= ['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur];
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
            'glace' => $muraille,
            'spawn_heros' => [$herosPos],
            'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    $groupe->update(['quete_courante_id' => $quete->id, 'phase' => 'quete']);

    $joueur = connecterJoueur('terrain-'.uniqid());
    $heros = creerHeros($joueur, $groupe, 'Testeur', 1, $herosAttrs);

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
 * Un SECOND héros actif, n'ayant pas encore joué — sert à la fois à BLOQUER
 * une case (sortie de tunnel occupée) et à garder le ROUND ouvert : avec un
 * seul héros dans la quête, forcer SA fin de tour boucle aussitôt sur
 * `jouerFinDeRound()` → `ouvrirNouveauTour()`, qui réarme `a_joue` à `false`
 * pour le round suivant — un artefact du test à un seul acteur, pas une
 * absence de fin de tour. Un second héros qui n'a PAS encore joué garde
 * `enAttente` vrai, donc `a_joue` du premier héros reste observable.
 */
function ajouterSecondHeros(Quete $quete, Groupe $groupe, array $pos): void
{
    $joueur = connecterJoueur('second-'.uniqid());
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

/** Option de déplacement minimale, patron de `MenuMoteur::generer()` (id `se_deplacer`). */
function optionDeplacement(): array
{
    return ['id' => 'se_deplacer', 'libelle' => 'Se déplacer', 'type' => 'deplacement'];
}

// =======================================================================
// 1. VOCABULAIRE FERMÉ — dans les deux sens, comme GrilleTalentsTest
// =======================================================================

/**
 * Clés RÉELLEMENT portées par une entrée `terrains.effet` : les clés de
 * premier niveau, PLUS les sous-clés de `sur.{face}` (chute, fin_tour,
 * degats_pv_body...) — jamais la FACE elle-même (`bouclier_blanc`...), qui
 * appartient au registre fermé et distinct de `App\Engine\Des\FaceDeCombat`.
 *
 * @return list<string>
 */
function clesEffetTerrain(array $effet): array
{
    $cles = [];

    foreach ($effet as $cle => $valeur) {
        $cles[] = $cle;

        if ($cle === 'sur' && is_array($valeur)) {
            foreach ($valeur as $issues) {
                if (is_array($issues)) {
                    foreach (array_keys($issues) as $sousCle) {
                        $cles[] = $sousCle;
                    }
                }
            }
        }
    }

    return $cles;
}

it("n'emploie AUCUNE clé de terrain sans lecteur déclaré ni dette écrite — et n'en déclare aucune que personne ne porte", function () {
    $portees = [];

    foreach (Terrain::all() as $terrain) {
        foreach (clesEffetTerrain((array) $terrain->effet) as $cle) {
            expect(MotsClesTerrain::connue($cle) || MotsClesTerrain::estNonImplementee($cle))
                ->toBeTrue("{$terrain->nom} : clé « {$cle} » sans lecteur déclaré NI dette écrite.");

            $portees[$cle] = true;
        }
    }

    foreach ([...array_keys(MotsClesTerrain::VOCABULAIRE), ...array_keys(MotsClesTerrain::NON_IMPLEMENTES)] as $declaree) {
        expect(array_key_exists($declaree, $portees))
            ->toBeTrue("« {$declaree} » est déclarée, mais aucun terrain du catalogue ne la porte.");
    }
});

it('confronte chaque lecteur DÉCLARÉ à la réalité : la classe et la méthode existent, et le fichier lit bien la clé', function () {
    foreach (MotsClesTerrain::VOCABULAIRE as $cle => $entree) {
        expect($entree['libelle'] ?? '')->not->toBe('', "« {$cle} » : libellé joueur manquant.");

        $nomme = false;

        foreach ((array) $entree['lecteur'] as $lecteur) {
            [$classe, $methode] = explode('::', str_replace('()', '', $lecteur));

            expect(class_exists($classe))->toBeTrue("« {$cle} » : lecteur {$classe} introuvable.");

            $reflexion = new ReflectionClass($classe);

            expect($reflexion->hasMethod($methode))
                ->toBeTrue("« {$cle} » : {$classe}::{$methode}() n'existe pas.");

            $nomme = $nomme
                || str_contains((string) file_get_contents((string) $reflexion->getFileName()), $cle);
        }

        // ⚠ `toContain` prend des AIGUILLES, jamais un message (même piège que
        // `toHaveKey`) : l'assertion se fait donc sur un booléen construit à la
        // main, pas via une chaîne de recherche directe.
        expect($nomme)->toBeTrue("« {$cle} » : aucun lecteur déclaré ne nomme cette clé, dans son propre fichier.");
    }
});

it('exclut explicitement les FACES de combat (crane/bouclier_blanc/bouclier_noir) du vocabulaire terrain', function () {
    foreach (['crane', 'bouclier_blanc', 'bouclier_noir'] as $face) {
        expect(MotsClesTerrain::connue($face))->toBeFalse("« {$face} » est une FACE de FaceDeCombat, pas un mot du vocabulaire terrain.")
            ->and(MotsClesTerrain::estNonImplementee($face))->toBeFalse();
    }
});

// =======================================================================
// 2. GLACE GLISSANTE — chute + fin de tour IMMÉDIATE, arrêté LÀ
// =======================================================================

it("la Glace glissante arrête le héros SUR la case de contact — jamais à sa destination demandée — et force la fin du tour", function () {
    $glace = Terrain::where('nom', 'Glace glissante')->firstOrFail();

    $scene = sceneTerrainGlace(
        [array_fill(0, 6, 's'), array_fill(0, 6, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [['x' => 2, 'y' => 0, 'terrain_id' => $glace->id]],
    );
    $pvAvant = (int) $scene['heros']->pv_body;

    // Un SECOND héros hors du chemin testé (rangée du dessous), qui n'a pas
    // encore joué : sans lui, forcer la fin du tour de l'UNIQUE héros de la
    // quête boucle aussitôt sur un nouveau round, qui réarme `a_joue` — voir
    // `ajouterSecondHeros()`.
    ajouterSecondHeros($scene['quete'], $scene['groupe']->fresh(), ['x' => 0, 'y' => 1]);

    // Bouclier blanc partout (valeur 4 du d6) : couvre à la fois le dé de
    // déplacement (peu importe la face, seul le TOTAL compte) et le dé de
    // combat de la glace, qui doit tomber sur bouclier blanc pour déclencher.
    desFiges(array_fill(0, 40, 4));

    // Destination demandée bien AU-DELÀ de la case de glace (x=5), pour
    // prouver que le héros n'y arrive jamais : c'est LE piège de ce chantier
    // — raccourcir le chemin sans réécrire la position téléporterait le héros
    // à son but tout en le déclarant arrêté.
    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacement(), ['x' => 5, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $etat->position_x)->toBe(2, 'arrêté SUR la glace, pas au-delà')
        ->and((int) $etat->position_y)->toBe(0)
        ->and($resultat['vers'])->toBe(['x' => 2, 'y' => 0])
        ->and($etat->a_joue)->toBeTrue('bouclier blanc = fin de tour IMMÉDIATE (doc 18 §4)')
        ->and($resultat['terrain']['nom'])->toBe('Glace glissante')
        ->and($resultat['terrain']['chute'])->toBeTrue()
        ->and($resultat['terrain']['fin_tour'])->toBeTrue()
        // « chute » est purement NARRATIF ici — la carte ne rend personne
        // inconscient, `tombe` (0 PV, relevable) reste intact.
        ->and($etat->tombe)->toBeFalse()
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pvAvant, 'aucun dégât : Glace glissante ne porte pas degats_pv_body');
});

it("laisse le héros passer sans encombre sur la Glace glissante quand le dé ne tombe PAS sur bouclier blanc", function () {
    $glace = Terrain::where('nom', 'Glace glissante')->firstOrFail();

    $scene = sceneTerrainGlace(
        [array_fill(0, 6, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [['x' => 2, 'y' => 0, 'terrain_id' => $glace->id]],
    );

    // Crâne (1) partout : le dé de glace rate son bouclier blanc — passage normal.
    desFiges(array_fill(0, 40, 1));

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacement(), ['x' => 4, 'y' => 0],
    );

    expect((int) $scene['etatHeros']->fresh()->position_x)->toBe(4, 'aucun arrêt : le jet a manqué le bouclier blanc')
        ->and($resultat)->not->toHaveKey('terrain');
});

it("la Glissière de glace finit le tour INCONDITIONNELLEMENT — le dé ne décide que de la blessure", function () {
    $glissiere = Terrain::where('nom', 'Glissière de glace')->firstOrFail();

    $scene = sceneTerrainGlace(
        [array_fill(0, 6, 's'), array_fill(0, 6, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [['x' => 2, 'y' => 0, 'terrain_id' => $glissiere->id]],
    );
    $pvAvant = (int) $scene['heros']->pv_body;
    ajouterSecondHeros($scene['quete'], $scene['groupe']->fresh(), ['x' => 0, 'y' => 1]);

    // Crâne (1) : le bouclier blanc n'est PAS touché, donc AUCUNE blessure —
    // mais la carte dit que le tour finit TOUJOURS au contact, dé ou pas.
    desFiges(array_fill(0, 40, 1));

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacement(), ['x' => 5, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $etat->position_x)->toBe(2, 'arrêté sur la glissière, pas au-delà')
        ->and($etat->a_joue)->toBeTrue('fin de tour INCONDITIONNELLE, même sans bouclier blanc')
        ->and($resultat['terrain']['fin_tour'])->toBeTrue()
        ->and($resultat['terrain']['chute'])->toBeFalse('la Glissière ne porte pas `chute` — seule la Glace glissante le fait')
        ->and($resultat['terrain'])->not->toHaveKey('degats')
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pvAvant);
});

it("la Glissière de glace blesse EN PLUS de finir le tour, sur bouclier blanc", function () {
    $glissiere = Terrain::where('nom', 'Glissière de glace')->firstOrFail();

    $scene = sceneTerrainGlace(
        [array_fill(0, 6, 's'), array_fill(0, 6, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [['x' => 2, 'y' => 0, 'terrain_id' => $glissiere->id]],
    );
    $pvAvant = (int) $scene['heros']->pv_body;
    ajouterSecondHeros($scene['quete'], $scene['groupe']->fresh(), ['x' => 0, 'y' => 1]);

    desFiges(array_fill(0, 40, 4)); // bouclier blanc

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacement(), ['x' => 5, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $etat->position_x)->toBe(2)
        ->and($etat->a_joue)->toBeTrue()
        ->and($resultat['terrain']['fin_tour'])->toBeTrue()
        ->and($resultat['terrain']['degats'])->toBe(1)
        ->and((int) $scene['heros']->fresh()->pv_body)->toBe($pvAvant - 1);
});

// =======================================================================
// 3. CHAMBRE FORTE DE GLACE — 1 Body PAR TOUR passé dedans, AUCUNE réaction
// =======================================================================

it("la Chambre forte de glace saigne 1 Body PAR TOUR passé dedans, sans le moindre jet de défense ni réaction hors tour", function () {
    $vault = Terrain::where('nom', 'Chambre forte de glace')->firstOrFail();

    $scene = sceneTerrainGlace(
        [array_fill(0, 3, 's'), array_fill(0, 3, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [['x' => 0, 'y' => 0, 'terrain_id' => $vault->id]],
    );
    $pvAvant = (int) $scene['heros']->pv_body;
    ajouterSecondHeros($scene['quete'], $scene['groupe']->fresh(), ['x' => 0, 'y' => 1]);

    // Crâne (1) : c'est la face qui déclenche la Chambre forte (« sur un
    // skull »), contrairement à la Glace glissante qui déclenche sur bouclier
    // blanc — les deux tuiles ne partagent PAS la même polarité.
    desFiges(array_fill(0, 20, 1));

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'],
        ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'], [],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $scene['heros']->fresh()->pv_body)->toBe($pvAvant - 1)
        ->and($etat->a_joue)->toBeTrue()
        // Aucun jet de défense, aucune proposition de réaction hors tour : la
        // chambre forte est un danger de DÉCOR, pas un coup reçu — même
        // exclusion de `ReactionEffet::SOURCES_REACTIVES` que le poison et
        // l'étreinte du Yéti.
        ->and($etat->reaction_en_attente)->toBeNull()
        ->and($resultat['type'])->toBe('attente');

    // « PAR TOUR passé dedans » : rejouer la même couture doit saigner ENCORE
    // — ce n'est pas un piège à usage unique qui se consomme après la
    // première touche (contrairement à une chausse-trappe, retirée du
    // plateau une fois foulée).
    $methode = new ReflectionMethod(ResolveurTour::class, 'saignerParTerrain');
    $methode->setAccessible(true);

    desFiges(array_fill(0, 20, 1));
    $methode->invoke(app(ResolveurTour::class), $etat->fresh());

    expect((int) $scene['heros']->fresh()->pv_body)->toBe($pvAvant - 2, 'un second passage doit saigner à nouveau');
});

it('ne saigne PAS si le jet ne tombe pas sur un crâne', function () {
    $vault = Terrain::where('nom', 'Chambre forte de glace')->firstOrFail();

    $scene = sceneTerrainGlace(
        [array_fill(0, 3, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [['x' => 0, 'y' => 0, 'terrain_id' => $vault->id]],
    );
    $pvAvant = (int) $scene['heros']->pv_body;

    // Bouclier blanc (4) : la Chambre forte ne réagit qu'au crâne.
    desFiges(array_fill(0, 20, 4));

    app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'],
        ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'], [],
    );

    expect((int) $scene['heros']->fresh()->pv_body)->toBe($pvAvant);
});

// =======================================================================
// 4. TUNNEL DE GLACE — téléportation appariée, sortie libre, salle découverte
// =======================================================================

it('le Tunnel de glace téléporte vers son autre extrémité quand la sortie est libre et la salle découverte', function () {
    $tunnel = Terrain::where('nom', 'Tunnel de glace')->firstOrFail();

    $scene = sceneTerrainGlace(
        [array_fill(0, 9, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [
            ['x' => 2, 'y' => 0, 'terrain_id' => $tunnel->id, 'paire_id' => 'paire-1'],
            ['x' => 6, 'y' => 0, 'terrain_id' => $tunnel->id, 'paire_id' => 'paire-1'],
        ],
        // Salle 0 (départ, x0-3) est TOUJOURS découverte ; salle 1 (x5-8)
        // porte l'AUTRE extrémité et doit être marquée découverte à part.
        sallesSupplementaires: [['x' => 5, 'y' => 0, 'largeur' => 4, 'hauteur' => 1]],
        salle0: ['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 1],
    );
    $scene['quete']->update(['salles_decouvertes' => [1]]);

    desFiges(array_fill(0, 30, 1));

    // Destination demandée = l'entrée du tunnel elle-même (2,0) : la
    // téléportation se résout sur l'arrivée déjà déterminée, pas un pas de
    // plus.
    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacement(), ['x' => 2, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $etat->position_x)->toBe(6, "téléporté à l'autre extrémité")
        ->and((int) $etat->position_y)->toBe(0)
        ->and($resultat['vers'])->toBe(['x' => 6, 'y' => 0])
        ->and($resultat['teleportation'])->toBe(['de' => ['x' => 2, 'y' => 0], 'vers' => ['x' => 6, 'y' => 0]])
        // Le décompte de points dépensés porte sur le trajet MARCHÉ (2 cases),
        // pas sur le saut : la téléportation n'est pas un pas de plus.
        ->and($resultat['distance'])->toBe(2);
});

it('ne téléporte JAMAIS vers une salle NON DÉCOUVERTE — le héros reste sur l\'entrée', function () {
    $tunnel = Terrain::where('nom', 'Tunnel de glace')->firstOrFail();

    $scene = sceneTerrainGlace(
        [array_fill(0, 9, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [
            ['x' => 2, 'y' => 0, 'terrain_id' => $tunnel->id, 'paire_id' => 'paire-1'],
            ['x' => 6, 'y' => 0, 'terrain_id' => $tunnel->id, 'paire_id' => 'paire-1'],
        ],
        sallesSupplementaires: [['x' => 5, 'y' => 0, 'largeur' => 4, 'hauteur' => 1]],
        salle0: ['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 1],
        // ⚠ Salle 1 volontairement PAS marquée découverte cette fois.
    );

    desFiges(array_fill(0, 30, 1));

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacement(), ['x' => 2, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $etat->position_x)->toBe(2, "reste sur l'entrée : la salle d'arrivée n'est pas découverte")
        ->and((int) $etat->position_y)->toBe(0)
        ->and($resultat)->not->toHaveKey('teleportation');
});

it('refuse une sortie de tunnel OCCUPÉE par une autre figure — traverser n\'est pas s\'arrêter', function () {
    $tunnel = Terrain::where('nom', 'Tunnel de glace')->firstOrFail();

    $scene = sceneTerrainGlace(
        [array_fill(0, 9, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        terrain: [
            ['x' => 2, 'y' => 0, 'terrain_id' => $tunnel->id, 'paire_id' => 'paire-1'],
            ['x' => 6, 'y' => 0, 'terrain_id' => $tunnel->id, 'paire_id' => 'paire-1'],
        ],
        sallesSupplementaires: [['x' => 5, 'y' => 0, 'largeur' => 4, 'hauteur' => 1]],
        salle0: ['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 1],
    );
    $scene['quete']->update(['salles_decouvertes' => [1]]);
    ajouterSecondHeros($scene['quete'], $scene['groupe']->fresh(), ['x' => 6, 'y' => 0]);

    desFiges(array_fill(0, 30, 1));

    $resultat = app(ResolveurTour::class)->resoudre(
        $scene['groupe']->fresh(), $scene['heros'], optionDeplacement(), ['x' => 2, 'y' => 0],
    );

    $etat = $scene['etatHeros']->fresh();

    expect((int) $etat->position_x)->toBe(2, "deux figurines ne peuvent pas partager une case : le héros n'a pas bougé")
        ->and((int) $etat->position_y)->toBe(0)
        ->and($resultat)->not->toHaveKey('teleportation');
});

// =======================================================================
// 5. MUR DE GLACE (Ice Wall) — l'attaque qui manquait à endommagerMurDeGlace()
// =======================================================================

it("propose d'attaquer une case de Mur de Glace ADJACENTE, et refuse celle qui ne l'est pas", function () {
    $scene = sceneTerrainGlace(
        [array_fill(0, 5, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        muraille: [
            ['x' => 1, 'y' => 0, 'source_instance_id' => 999, 'cranes' => 0], // adjacente
            ['x' => 4, 'y' => 0, 'source_instance_id' => 999, 'cranes' => 0], // hors de portée
        ],
    );

    desFiges(array_fill(0, 30, 1));

    $menu = app(MenuMoteur::class)->generer($scene['groupe']->fresh(), $scene['heros']->fresh());
    $options = collect($menu['options'])->where('type', 'briser_glace');

    expect($options)->toHaveCount(1, 'une seule case de glace est adjacente au héros')
        ->and($options->first()['id'])->toBe('briser_glace_1_0')
        ->and($options->first()['parametres'])->toBe(['x' => 1, 'y' => 0]);
});

it('brise un Mur de Glace à 5 crânes cumulés, ni avant, et rend le déplacement à nouveau possible par là', function () {
    $scene = sceneTerrainGlace(
        [array_fill(0, 5, 's')],
        herosPos: ['x' => 0, 'y' => 0],
        muraille: [['x' => 1, 'y' => 0, 'source_instance_id' => 999, 'cranes' => 0]],
    );
    $option = ['id' => 'briser_glace_1_0', 'libelle' => 'Frapper le mur de glace', 'type' => 'briser_glace', 'parametres' => ['x' => 1, 'y' => 0]];

    expect(FabriqueGrille::pour($scene['quete']->fresh())->estTraversable(1, 0))->toBeFalse();

    // Premier coup, VIA LE VRAI POINT D'ENTRÉE (ResolveurTour::resoudre) : la
    // preuve que le geste du joueur atteint bien MoteurDread::endommagerMurDeGlace().
    desFiges(array_fill(0, 5, 1)); // crâne
    $resultat = app(ResolveurTour::class)->resoudre($scene['groupe']->fresh(), $scene['heros'], $option, []);

    expect($resultat['type'])->toBe('briser_glace')
        ->and($resultat['brisee'])->toBeFalse('1 crâne seulement : encore debout')
        ->and(FabriqueGrille::pour($scene['quete']->fresh())->estTraversable(1, 0))->toBeFalse();

    // Crânes 2, 3 et 4 : la PROGRESSION du compteur est déjà prouvée ailleurs
    // (SortsGlaceTest, « cède après 5 CRÂNES cumulés ») — on la rejoue ici au
    // plus court, directement sur la méthode que ResolveurTour vient d'appeler,
    // pour isoler ce que CE fichier prouve : l'attaque ATTEINT bien la case.
    $dread = app(MoteurDread::class);
    foreach ([2, 3, 4] as $i) {
        expect($dread->endommagerMurDeGlace($scene['quete']->fresh(), 1, 0, FaceDeCombat::Crane))
            ->toBeFalse("crâne #{$i} : ne doit pas encore céder");
    }

    // Cinquième coup, DE NOUVEAU par le vrai point d'entrée — c'est celui-ci
    // qui doit faire céder la case ET rouvrir le passage.
    $scene['etatHeros']->update(['a_agi' => false, 'a_joue' => false]);
    desFiges(array_fill(0, 5, 1));
    $dernier = app(ResolveurTour::class)->resoudre($scene['groupe']->fresh(), $scene['heros']->fresh(), $option, []);

    expect($dernier['brisee'])->toBeTrue('le 5e crâne fait céder la case')
        ->and(FabriqueGrille::pour($scene['quete']->fresh())->estTraversable(1, 0))
        ->toBeTrue('le déplacement redevient possible par là');
});
