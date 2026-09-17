<?php

declare(strict_types=1);

use App\Engine\Des\FaceDeCombat;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\FabriqueGrille;
use App\Partie\Grille;
use App\Partie\MoteurDread;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;

/*
 * PLAN GLACE — PHASE 2 (docs/plan-glace-et-degats-mind.md) : les trois sorts
 * manquants de l'Horreur des Glaces — Gel de l'Esprit (Mind Freeze), Mur de
 * Glace (Ice Wall), Patinage (Skate). Textes de carte : `config/cartes.php`
 * §dread (entrées non portées, avant cette passe).
 *
 * Toutes les scènes sont construites À LA MAIN (comme
 * `TerrainCarteTest::queteAvecCarteEtTerrain()`) pour un contrôle GÉOMÉTRIQUE
 * total — l'invariant de connexité et l'entretien lié à la vue n'ont de sens
 * qu'avec des positions exactes. La preuve « choisi par choisirSort() » passe
 * par `MoteurDread::jouerTourDread()`, PUBLIQUE et appelée par ResolveurTour
 * en production — c'est le vrai point d'entrée, pas un détour.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

// ------------------------------------------------------------------
// Scène — quête + carte construites à la main, un héros, un lanceur
// ------------------------------------------------------------------

/**
 * @param  list<list<string>>  $cases  m = mur, s = sol
 * @param  array{x: int, y: int}  $herosPos
 * @param  array{x: int, y: int}  $instancePos
 * @param  list<array{x: int, y: int, source_instance_id: int, cranes: int}>  $glace
 * @return array{groupe: Groupe, quete: Quete, heros: Personnage, etatHeros: EtatPersonnageQuete, instance: InstanceMonstre}
 */
function sceneGlace(
    array $cases,
    array $herosPos,
    array $instancePos,
    array $glace = [],
    string $monstreCatalogue = 'Gobelin',
    array $herosAttrs = [],
): array {
    $groupe = creerGroupe('table-glace-'.uniqid());
    $gabarit = GabaritQuete::query()->firstOrFail();

    $largeur = count($cases[0] ?? []);
    $hauteur = count($cases);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — glace',
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
            'leviers' => [], 'pieges' => [], 'mobilier' => [], 'epreuves' => [], 'terrain' => [],
            'glace' => $glace,
            'spawn_heros' => [$herosPos],
            'spawn_monstres' => [$instancePos],
            'aretes' => [],
        ],
    ]);

    $groupe->update(['quete_courante_id' => $quete->id]);

    $joueur = connecterJoueur('glace-'.uniqid());
    $heros = creerHeros($joueur, $groupe, 'Testeur', 1, $herosAttrs);

    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id,
        'personnage_id' => $heros->id,
        'position_x' => $herosPos['x'],
        'position_y' => $herosPos['y'],
        'tombe' => false,
        'a_joue' => false,
    ]);

    $catalogue = Monstre::where('nom_base', $monstreCatalogue)->firstOrFail();
    $instance = InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'position_x' => $instancePos['x'],
        'position_y' => $instancePos['y'],
        'etat' => 'actif',
        'revele' => true,
    ]);
    $instance->refresh()->load('monstre');

    return compact('groupe', 'quete', 'heros', 'etatHeros', 'instance');
}

/** Force le répertoire du lanceur à un seul sort — isole ce qui est testé. */
function forcerSortUnique(InstanceMonstre $instance, string $sort): void
{
    $instance->monstre->update(['sorts_dread' => [$sort], 'archetype_lanceur' => null]);
    $instance->refresh()->load('monstre');

    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $instance->quete);
    $instance->refresh()->load('monstre');
}

/** Fige les dés puis joue le tour Dread — retourne le payload résolu. */
function jouerTourGlace(array $scene, array $des): array
{
    desFiges([...$des, ...array_fill(0, 200, 4)]);

    return app(MoteurDread::class)->jouerTourDread(
        $scene['groupe'], $scene['quete']->fresh(), $scene['instance']->fresh()->load('monstre'),
        new Collection([$scene['etatHeros']->fresh()]),
    ) ?? [];
}

/** Aplatit une action éventuellement composite (entretien + sort) sur le sort nommé. */
function actionGlace(array $payload, string $nom): ?array
{
    if (($payload['type'] ?? null) === 'actions_composites') {
        foreach ((array) $payload['actions'] as $action) {
            if (($action['sort'] ?? null) === $nom) {
                return $action;
            }
        }

        return null;
    }

    return ($payload['sort'] ?? null) === $nom ? $payload : null;
}

// ==================================================================
// GEL DE L'ESPRIT (Mind Freeze)
// ==================================================================

it("lance autant de dés de combat que de PV DE MIND — jamais l'attribut", function () {
    // Attribut 4, jauge à 2 : si le sort lisait l'attribut, il lancerait 4 dés.
    $scene = sceneGlace(
        [['m', 's', 's', 'm']],
        herosPos: ['x' => 2, 'y' => 0], instancePos: ['x' => 1, 'y' => 0],
        // Palier `boss` du sort : le lanceur doit être de tier boss. L'Archimage
        // elfe est choisi précisément parce qu'il ne porte NI `invocation` NI
        // `charge` — un Seigneur, lui, aurait basculé sur sa capacité
        // d'invocation dès que `choisirSort()` renvoie `null`, masquant un
        // vrai refus du sort derrière un faux positif « une action a eu lieu ».
        monstreCatalogue: 'Archimage elfe',
        herosAttrs: ['attribut_mind' => 4, 'pv_mind_max' => 4, 'pv_mind' => 2],
    );
    forcerSortUnique($scene['instance'], "Gel de l'Esprit");

    $sort = actionGlace(jouerTourGlace($scene, [1, 1]), "Gel de l'Esprit");

    expect($sort)->not->toBeNull()
        ->and($sort['resultats'][0]['des'])->toHaveCount(2)
        ->and((int) $scene['heros']->attribut_mind)->toBe(4);
});

it('un bouclier blanc laisse 1 Mind, et le héros reste debout', function () {
    $scene = sceneGlace(
        [['m', 's', 's', 'm']],
        herosPos: ['x' => 2, 'y' => 0], instancePos: ['x' => 1, 'y' => 0],
        monstreCatalogue: 'Archimage elfe',
        herosAttrs: ['attribut_mind' => 3, 'pv_mind_max' => 3, 'pv_mind' => 3],
    );
    forcerSortUnique($scene['instance'], "Gel de l'Esprit");

    // 1 (crâne), 4 (bouclier blanc), 1 (crâne) : au moins un blanc parmi les 3.
    $sort = actionGlace(jouerTourGlace($scene, [1, 4, 1]), "Gel de l'Esprit");

    expect($sort['resultats'][0]['bouclier_blanc'])->toBeTrue()
        ->and((int) $scene['heros']->fresh()->pv_mind)->toBe(1)
        ->and($scene['etatHeros']->fresh()->tombe)->toBeFalse();
});

it('AUCUN bouclier blanc met le Mind à ZÉRO et fait TOMBER le héros', function () {
    $scene = sceneGlace(
        [['m', 's', 's', 'm']],
        herosPos: ['x' => 2, 'y' => 0], instancePos: ['x' => 1, 'y' => 0],
        monstreCatalogue: 'Archimage elfe',
        herosAttrs: ['attribut_mind' => 3, 'pv_mind_max' => 3, 'pv_mind' => 3],
    );
    forcerSortUnique($scene['instance'], "Gel de l'Esprit");

    // 1, 2, 3 : trois crânes, aucun bouclier blanc.
    $sort = actionGlace(jouerTourGlace($scene, [1, 2, 3]), "Gel de l'Esprit");

    expect($sort['resultats'][0]['bouclier_blanc'])->toBeFalse()
        ->and((int) $scene['heros']->fresh()->pv_mind)->toBe(0)
        ->and($scene['etatHeros']->fresh()->tombe)->toBeTrue();
});

it("est réellement CHOISI par choisirSort() (via jouerTourDread, le vrai point d'entrée)", function () {
    $scene = sceneGlace(
        [['m', 's', 's', 'm']],
        herosPos: ['x' => 2, 'y' => 0], instancePos: ['x' => 1, 'y' => 0],
        monstreCatalogue: 'Archimage elfe',
        herosAttrs: ['attribut_mind' => 2, 'pv_mind_max' => 2, 'pv_mind' => 2],
    );
    forcerSortUnique($scene['instance'], "Gel de l'Esprit");

    $payload = jouerTourGlace($scene, [4, 4]);

    expect(actionGlace($payload, "Gel de l'Esprit"))->not->toBeNull();
});

// ==================================================================
// ORBE CÉLESTE (Sky Orb) — absorption à jetons sur Gel de l'Esprit
// ==================================================================

/**
 * Pose une pièce dans un emplacement donné, sans passer par les garde-fous.
 * (Copie locale : les fonctions d'un fichier Pest ne sont visibles qu'une
 * fois ce fichier chargé, donc jamais fiables d'un fichier de test à l'autre —
 * même patron que `ChargesEtSortsTest::poser()`.)
 */
function poserPourGlace(Personnage $p, string $nom, string $emplacement): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

it("l'Orbe Céleste absorbe la perte de Mind un jeton à la fois, et épargne le héros si elle couvre tout", function () {
    $scene = sceneGlace(
        [['m', 's', 's', 'm']],
        herosPos: ['x' => 2, 'y' => 0], instancePos: ['x' => 1, 'y' => 0],
        monstreCatalogue: 'Archimage elfe',
        herosAttrs: ['attribut_mind' => 4, 'pv_mind_max' => 4, 'pv_mind' => 4],
    );
    forcerSortUnique($scene['instance'], "Gel de l'Esprit");
    $orbe = poserPourGlace($scene['heros'], 'Orbe Céleste', 'talisman');

    // 4 dés, aucun bouclier blanc (que des crânes) : Mind à zéro SANS l'Orbe —
    // 4 points de perte, exactement les 4 jetons de la carte.
    $sort = actionGlace(jouerTourGlace($scene, [1, 2, 3, 1]), "Gel de l'Esprit");

    expect($sort['resultats'][0]['bouclier_blanc'])->toBeFalse()
        ->and($sort['resultats'][0]['degats_mind'])->toBe(0)
        ->and($sort['resultats'][0]['mind_absorbe'])->toBe(4)
        ->and((int) $scene['heros']->fresh()->pv_mind)->toBe(4)
        ->and($scene['etatHeros']->fresh()->tombe)->toBeFalse()
        // Quatre jetons donnés : « the Sky Orb is rendered useless » — elle se
        // BRISE désormais (René, 2026-09-16), et redevient trouvable.
        ->and($orbe->fresh())->toBeNull();
});

it('laisse passer le reste une fois ses jetons épuisés — absorption PARTIELLE, pas une immunité', function () {
    $scene = sceneGlace(
        [['m', 's', 's', 'm']],
        herosPos: ['x' => 2, 'y' => 0], instancePos: ['x' => 1, 'y' => 0],
        monstreCatalogue: 'Archimage elfe',
        herosAttrs: ['attribut_mind' => 4, 'pv_mind_max' => 4, 'pv_mind' => 4],
    );
    forcerSortUnique($scene['instance'], "Gel de l'Esprit");
    $orbe = poserPourGlace($scene['heros'], 'Orbe Céleste', 'talisman');
    // Déjà entamée : il ne reste que 2 jetons sur les 4 de la carte.
    $orbe->update(['charges' => 2]);

    // 4 dés, aucun bouclier blanc : 4 points de perte, 2 absorbés, 2 encaissés.
    $sort = actionGlace(jouerTourGlace($scene, [1, 2, 3, 1]), "Gel de l'Esprit");

    expect($sort['resultats'][0]['mind_absorbe'])->toBe(2)
        ->and($sort['resultats'][0]['degats_mind'])->toBe(2)
        ->and((int) $scene['heros']->fresh()->pv_mind)->toBe(2)
        ->and($orbe->fresh())->toBeNull(); // jetons épuisés : l'Orbe s'est brisée
});

it('épuisée, l\'Orbe Céleste reste au sac mais ne protège plus de rien', function () {
    $scene = sceneGlace(
        [['m', 's', 's', 'm']],
        herosPos: ['x' => 2, 'y' => 0], instancePos: ['x' => 1, 'y' => 0],
        monstreCatalogue: 'Archimage elfe',
        herosAttrs: ['attribut_mind' => 3, 'pv_mind_max' => 3, 'pv_mind' => 3],
    );
    forcerSortUnique($scene['instance'], "Gel de l'Esprit");
    $orbe = poserPourGlace($scene['heros'], 'Orbe Céleste', 'talisman');
    $orbe->update(['charges' => 0]);

    // 3 dés, aucun bouclier blanc : Mind à zéro, EXACTEMENT comme sans l'Orbe.
    $sort = actionGlace(jouerTourGlace($scene, [1, 2, 3]), "Gel de l'Esprit");

    expect($sort['resultats'][0]['mind_absorbe'])->toBe(0)
        ->and($sort['resultats'][0]['degats_mind'])->toBe(3)
        ->and((int) $scene['heros']->fresh()->pv_mind)->toBe(0)
        ->and($scene['etatHeros']->fresh()->tombe)->toBeTrue()
        // L'objet reste en inventaire — rien ne le supprime au sac.
        ->and($orbe->fresh())->not->toBeNull();
});

// ==================================================================
// MUR DE GLACE (Ice Wall)
// ==================================================================

it('bloque le DÉPLACEMENT mais PAS une flèche — la vue reste dégagée', function () {
    $scene = sceneGlace(
        [array_fill(0, 5, 's')],
        herosPos: ['x' => 0, 'y' => 0], instancePos: ['x' => 4, 'y' => 0],
        glace: [['x' => 2, 'y' => 0, 'source_instance_id' => 999, 'cranes' => 0]],
    );

    $grille = FabriqueGrille::pour($scene['quete']->fresh());

    expect($grille->estTraversable(2, 0))->toBeFalse('une case de glace doit être infranchissable')
        ->and($grille->ligneDeVue(0, 0, 4, 0))->toBeTrue()
        ->and($grille->ligneDeVue(0, 0, 4, 0, figuresBloquent: true))->toBeTrue(
            'un mur de glace ne doit JAMAIS arrêter une flèche — le bug historique du mobilier, un cran plus loin',
        );
});

it("N'ISOLE JAMAIS une case accessible — un couloir strict d'une case ne reçoit AUCUNE glace", function () {
    // Couloir 1×5 : (1,0) le lanceur, (5,0) le héros, rien d'autre entre les
    // deux. Poser la moindre case de glace couperait le héros du lanceur —
    // l'invariant dur doit s'appliquer aux TROIS candidates, pas à une seule.
    $scene = sceneGlace(
        [['m', 's', 's', 's', 's', 's', 'm']],
        herosPos: ['x' => 5, 'y' => 0], instancePos: ['x' => 1, 'y' => 0],
    );
    forcerSortUnique($scene['instance'], 'Mur de Glace');

    $methode = new ReflectionMethod(MoteurDread::class, 'planMurDeGlace');
    $methode->setAccessible(true);

    $retenues = $methode->invoke(
        app(MoteurDread::class), $scene['quete']->fresh(), $scene['instance']->fresh()->load('monstre'),
        new Collection([$scene['etatHeros']->fresh()]), 4,
    );

    expect($retenues)->toBe([], 'un couloir strict ne doit recevoir aucune case — la poser isolerait le héros');
});

it('POSE bien de la glace quand la pièce laisse un détour — la contrainte ne bloque pas tout, seulement le nécessaire', function () {
    // Pièce ouverte 3 lignes × 5 colonnes (intérieur sans mur) : bloquer une
    // seule case de la ligne médiane laisse toujours un détour par le haut ou
    // le bas — la connexité n'est jamais rompue, donc la pose doit réussir.
    $cases = [
        ['m', 'm', 'm', 'm', 'm', 'm', 'm'],
        ['m', 's', 's', 's', 's', 's', 'm'],
        ['m', 's', 's', 's', 's', 's', 'm'],
        ['m', 's', 's', 's', 's', 's', 'm'],
        ['m', 'm', 'm', 'm', 'm', 'm', 'm'],
    ];
    $scene = sceneGlace($cases, herosPos: ['x' => 5, 'y' => 2], instancePos: ['x' => 1, 'y' => 2]);
    forcerSortUnique($scene['instance'], 'Mur de Glace');

    $methode = new ReflectionMethod(MoteurDread::class, 'planMurDeGlace');
    $methode->setAccessible(true);

    $retenues = $methode->invoke(
        app(MoteurDread::class), $scene['quete']->fresh(), $scene['instance']->fresh()->load('monstre'),
        new Collection([$scene['etatHeros']->fresh()]), 4,
    );

    expect($retenues)->not->toBe([], 'une pièce avec détour doit recevoir au moins une case de glace');

    // …et l'invariant tient AUSSI ici : vérification indépendante, sur la
    // grille réelle augmentée des cases retenues, qu'une case ADJACENTE au
    // héros reste atteignable depuis le lanceur. ⚠ Viser (5,2) — la case du
    // héros LUI-MÊME — ne prouverait rien : une case OCCUPÉE n'est jamais
    // « traversable », donc `chemin()` la rendrait `null` même sans la
    // moindre glace, juste parce que le héros s'y tient.
    $grille = FabriqueGrille::pour($scene['quete']->fresh(), exceptInstanceId: $scene['instance']->id);
    $grille->obstruer($retenues);
    $chemin = $grille->chemin(1, 2, 4, 2);

    expect($chemin)->not->toBeNull('la case adjacente au héros ne doit jamais devenir inatteignable après la pose');
});

it('disparaît quand le lanceur ne le voit plus, mais PAS quand il le voit encore', function () {
    // Couloir en L : segment horizontal ligne 1 (cols 1-5), segment vertical
    // colonne 1 (lignes 1-3) — un mur plein sépare les deux bras hors de la
    // jonction (1,1).
    $cases = [
        ['m', 'm', 'm', 'm', 'm', 'm', 'm'],
        ['m', 's', 's', 's', 's', 's', 'm'],
        ['m', 's', 'm', 'm', 'm', 'm', 'm'],
        ['m', 's', 'm', 'm', 'm', 'm', 'm'],
        ['m', 'm', 'm', 'm', 'm', 'm', 'm'],
    ];
    $scene = sceneGlace($cases, herosPos: ['x' => 5, 'y' => 1], instancePos: ['x' => 1, 'y' => 1]);

    $carte = $scene['quete']->carte;
    $grilleData = (array) $carte->grille;
    $grilleData['glace'] = [['x' => 4, 'y' => 1, 'source_instance_id' => (int) $scene['instance']->id, 'cranes' => 0]];
    $carte->update(['grille' => $grilleData]);

    $methode = new ReflectionMethod(MoteurDread::class, 'entretienMurDeGlace');
    $methode->setAccessible(true);
    $acteur = ['type' => 'monstre', 'id' => $scene['instance']->id, 'nom' => 'Testeur'];

    // Depuis la jonction (1,1), la case (4,1) est en ligne droite, dégagée : VUE.
    $resultat = $methode->invoke(app(MoteurDread::class), $scene['groupe'], $scene['quete']->fresh(), $scene['instance']->fresh(), $acteur);
    expect($resultat)->toBeNull('rien ne doit disparaître : la case reste en vue');
    expect((array) $scene['quete']->carte->fresh()->grille['glace'])->toHaveCount(1);

    // Le lanceur descend dans le bras vertical (1,3) : la case (4,1) tombe
    // derrière le mur qui sépare les deux bras — HORS DE VUE.
    $scene['instance']->update(['position_x' => 1, 'position_y' => 3]);
    $resultat = $methode->invoke(app(MoteurDread::class), $scene['groupe'], $scene['quete']->fresh(), $scene['instance']->fresh(), $acteur);

    expect($resultat)->not->toBeNull('la case hors de vue doit disparaître')
        ->and($resultat['type'])->toBe('glace_dissipee')
        ->and($resultat['raison'])->toBe('hors_de_vue');
    expect((array) $scene['quete']->carte->fresh()->grille['glace'])->toBe([]);
});

it('cède après 5 CRÂNES cumulés, jamais avant, et un bouclier ne compte pas', function () {
    $scene = sceneGlace(
        [array_fill(0, 5, 's')],
        herosPos: ['x' => 0, 'y' => 0], instancePos: ['x' => 4, 'y' => 0],
        glace: [['x' => 2, 'y' => 0, 'source_instance_id' => (int) 1, 'cranes' => 0]],
    );

    $dread = app(MoteurDread::class);

    // Un bouclier blanc ne fait pas avancer le compteur.
    expect($dread->endommagerMurDeGlace($scene['quete']->fresh(), 2, 0, FaceDeCombat::BouclierBlanc))->toBeFalse();

    for ($i = 0; $i < 4; $i++) {
        expect($dread->endommagerMurDeGlace($scene['quete']->fresh(), 2, 0, FaceDeCombat::Crane))
            ->toBeFalse("crâne #{$i} : ne doit pas encore céder");
    }

    expect(FabriqueGrille::pour($scene['quete']->fresh())->estTraversable(2, 0))
        ->toBeFalse('4 crânes seulement : toujours debout');

    // Le 5e crâne fait céder la case.
    expect($dread->endommagerMurDeGlace($scene['quete']->fresh(), 2, 0, FaceDeCombat::Crane))->toBeTrue();
    expect(FabriqueGrille::pour($scene['quete']->fresh())->estTraversable(2, 0))
        ->toBeTrue('5 crânes : la case a cédé, la voie est libre');
});

it("est réellement CHOISI par choisirSort() quand un héros en vue n'est pas déjà au contact", function () {
    // ⚠ Un couloir strict d'une case de large REFUSERAIT toute pose (voir le
    // test d'isolement plus haut) : la preuve de sélection a donc besoin
    // d'une pièce qui laisse un détour, exactement comme le test « POSE bien
    // de la glace » — sans quoi ce test se contredirait lui-même.
    $cases = [
        ['m', 'm', 'm', 'm', 'm', 'm', 'm'],
        ['m', 's', 's', 's', 's', 's', 'm'],
        ['m', 's', 's', 's', 's', 's', 'm'],
        ['m', 's', 's', 's', 's', 's', 'm'],
        ['m', 'm', 'm', 'm', 'm', 'm', 'm'],
    ];
    $scene = sceneGlace(
        $cases,
        herosPos: ['x' => 5, 'y' => 2], instancePos: ['x' => 1, 'y' => 2],
        monstreCatalogue: 'Archimage elfe',
    );
    forcerSortUnique($scene['instance'], 'Mur de Glace');

    $sort = actionGlace(jouerTourGlace($scene, []), 'Mur de Glace');

    expect($sort)->not->toBeNull()
        ->and($sort['cases'])->not->toBeEmpty();

    // Et la case posée est bien lue par FabriqueGrille — la boucle du moteur,
    // pas seulement le payload.
    $case = $sort['cases'][0];
    expect(FabriqueGrille::pour($scene['quete']->fresh())->estTraversable($case['x'], $case['y']))->toBeFalse();
});

// ==================================================================
// PATINAGE (Skate)
// ==================================================================

it('TRAVERSE les figures mais ne s\'arrête PAS dessus', function () {
    // Pièce 3×3 : le lanceur COINCÉ dans un coin (1,1), ses deux SEULES
    // sorties orthogonales bouchées par deux bloqueurs (2,1) et (1,2) — le
    // déplacement NORMAL est donc totalement impossible (0 case atteignable).
    // La VUE, elle, reste dégagée : `ligneDeVue()` coupe les coins en
    // diagonale parfaite et ne teste JAMAIS les arêtes qu'elle traverse, si
    // bien que le héros en (3,3) reste VISIBLE malgré les deux bloqueurs —
    // exactement le cas où Patinage change quelque chose qu'aucun autre sort
    // ne peut faire : voir une cible que son propre corps ne peut pas
    // atteindre à pied.
    $cases = [
        ['m', 'm', 'm', 'm', 'm'],
        ['m', 's', 's', 's', 'm'],
        ['m', 's', 's', 's', 'm'],
        ['m', 's', 's', 's', 'm'],
        ['m', 'm', 'm', 'm', 'm'],
    ];
    $scene = sceneGlace(
        $cases,
        herosPos: ['x' => 3, 'y' => 3], instancePos: ['x' => 1, 'y' => 1],
        monstreCatalogue: 'Archimage elfe',
    );
    forcerSortUnique($scene['instance'], 'Patinage');

    $bloqueurs = [];
    foreach ([[2, 1], [1, 2]] as [$bx, $by]) {
        $bloqueurs[] = InstanceMonstre::create([
            'quete_id' => $scene['quete']->id,
            'monstre_id' => Monstre::where('nom_base', 'Gobelin')->value('id'),
            'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 1,
            'position_x' => $bx, 'position_y' => $by,
            'etat' => 'actif', 'revele' => true,
        ]);
    }

    // Preuve que le déplacement NORMAL est bien impossible — sans quoi ce
    // test ne démontrerait rien de plus qu'un déplacement ordinaire.
    $grilleNormale = FabriqueGrille::pour($scene['quete']->fresh(), exceptInstanceId: $scene['instance']->id);
    expect($grilleNormale->casesAtteignables(1, 1, 20))->toBe([]);

    $sort = actionGlace(jouerTourGlace($scene, []), 'Patinage');

    expect($sort)->not->toBeNull()
        ->and($sort['depart'])->toBe(['x' => 1, 'y' => 1])
        // Adjacent au héros (3,3) — jamais SUR le héros, jamais sur un bloqueur.
        ->and($sort['arrivee'])->toBe(['x' => 2, 'y' => 3])
        // La case (2,1), occupée par un bloqueur, fait bien partie du trajet
        // FRANCHI — la preuve que la traversée a servi à quelque chose.
        ->and($sort['cases_franchies'])->toBe(3);

    // Les bloqueurs n'ont ni bougé ni été délogés : traversés, pas heurtés.
    foreach ($bloqueurs as $i => $bloqueur) {
        $attendu = [[2, 1], [1, 2]][$i];
        expect((int) $bloqueur->fresh()->position_x)->toBe($attendu[0])
            ->and((int) $bloqueur->fresh()->position_y)->toBe($attendu[1]);
    }

    expect((int) $scene['instance']->fresh()->position_x)->toBe(2)
        ->and((int) $scene['instance']->fresh()->position_y)->toBe(3);
});

it('ne se déclenche PAS quand le lanceur est déjà au contact — rien à traverser', function () {
    $scene = sceneGlace(
        [['m', 's', 's', 'm']],
        herosPos: ['x' => 2, 'y' => 0], instancePos: ['x' => 1, 'y' => 0],
    );

    $methode = new ReflectionMethod(MoteurDread::class, 'planPatinage');
    $methode->setAccessible(true);

    $plan = $methode->invoke(
        app(MoteurDread::class), $scene['quete']->fresh(), $scene['instance']->fresh(),
        new Collection([$scene['etatHeros']->fresh()]), 12,
    );

    expect($plan)->toBeNull();
});

it('est réellement CHOISI par choisirSort() quand une figure bloque le seul passage normal', function () {
    // Même scène que le test de traversée — mais celui-ci se concentre
    // uniquement sur la PREUVE DE SÉLECTION : `choisirSort()` doit retenir
    // Patinage quand il est le seul sort du répertoire ET que
    // `sortUtilisable()` le juge jouable, et `jouerTourDread()` (le vrai
    // point d'entrée de ResolveurTour) doit produire l'action, pas un
    // repli silencieux.
    $cases = [
        ['m', 'm', 'm', 'm', 'm'],
        ['m', 's', 's', 's', 'm'],
        ['m', 's', 's', 's', 'm'],
        ['m', 's', 's', 's', 'm'],
        ['m', 'm', 'm', 'm', 'm'],
    ];
    $scene = sceneGlace(
        $cases,
        herosPos: ['x' => 3, 'y' => 3], instancePos: ['x' => 1, 'y' => 1],
        monstreCatalogue: 'Archimage elfe',
    );
    forcerSortUnique($scene['instance'], 'Patinage');

    foreach ([[2, 1], [1, 2]] as [$bx, $by]) {
        InstanceMonstre::create([
            'quete_id' => $scene['quete']->id,
            'monstre_id' => Monstre::where('nom_base', 'Gobelin')->value('id'),
            'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 1,
            'position_x' => $bx, 'position_y' => $by,
            'etat' => 'actif', 'revele' => true,
        ]);
    }

    $sort = actionGlace(jouerTourGlace($scene, []), 'Patinage');

    expect($sort)->not->toBeNull();
});
