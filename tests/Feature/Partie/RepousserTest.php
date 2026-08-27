<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\MenuMoteur;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * REPOUSSER UN ENNEMI (2026-08-24) — troisième emploi de `attribut_body`, et
 * le seul des trois qui déplace une figure sans la frapper. Voir
 * `App\Partie\MenuMoteur::ciblesRepoussables()` et
 * `App\Partie\ResolveurTour::resoudrePoussee()`.
 *
 * ⚠ Un donjon PROCÉDURAL ne garantit qu'UNE case libre au contact du héros
 * (c'est tout ce que `demarrerQueteAvecMonstre()`/`caseAdjacenteLibre()`
 * promettent) — jamais la SECONDE, celle où la créature doit reculer. Tester
 * cette mécanique sur une vraie carte générée aurait donc rendu la suite
 * intermittente au gré des graines. Les cas ci-dessous montent à la place une
 * quête MINIMALE (carte 7×7 tout en sol, sans donjon), pour poser héros et
 * monstres exactement où le test l'exige — même esprit que `poserPortes()`
 * (PortesExplorationTest), qui patche `carte.grille` directement plutôt que
 * de subir la génération.
 *
 * Les jets eux-mêmes passent par RÉFLEXION sur les méthodes privées du
 * moteur (`ciblesRepoussables`, `resoudrePoussee`) — même patron que
 * `TalentsEnJeuTest` (`resoudreAttaqueMonstre`, `sortDegats`…) : ce sont les
 * VRAIES méthodes, sur de VRAIS enregistrements, juste sans la cérémonie
 * HTTP/tour (créneaux, phase des monstres) qui n'est pas ce qu'on éprouve
 * ici. Un seul cas (la réussite) passe par la vraie route `POST /choix`,
 * pour prouver que l'option est réellement JOUABLE et pas seulement calculée.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class,
        SortSeeder::class, ConditionSeeder::class, MobilierSeeder::class]);
});

/**
 * Quête minimale, carte 7×7 entièrement en sol, SANS donjon procédural — pour
 * poser un héros exactement où le test l'exige. Une seule salle (index 0,
 * toujours « découverte » par `Quete::sallesDecouvertes()`), aucune porte, un
 * seul héros.
 *
 * @return array{alice: JoueurAuthentifiable, groupe: \App\Models\Groupe, heros: Personnage, quete: Quete, etatHeros: EtatPersonnageQuete}
 */
function quetePousseeMinimale(array $herosAttrs = [], int $hx = 3, int $hy = 3, int $taille = 7): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);

    $cases = array_fill(0, $taille, array_fill(0, $taille, 's'));

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'en_cours',
        'or_initial' => 0,
    ]);

    Carte::create([
        'quete_id' => $quete->id,
        'largeur' => $taille,
        'hauteur' => $taille,
        'grille' => [
            'largeur' => $taille, 'hauteur' => $taille, 'cases' => $cases,
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $taille, 'hauteur' => $taille,
                'theme' => 'generique', 'mediane_x' => intdiv($taille, 2), 'mediane_y' => intdiv($taille, 2)]],
            'portes' => [], 'leviers' => [], 'pieges' => [], 'mobilier' => [],
            'spawn_heros' => [['x' => $hx, 'y' => $hy]], 'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    // Quête « en cours » directement, sans passer par `DemarreurQuete` : ce
    // fichier n'exerce QUE le recul, pas le reste de la mise en route.
    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    $etatHeros = EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => $hx, 'position_y' => $hy,
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete->fresh()->load('carte'), 'etatHeros' => $etatHeros];
}

/**
 * Un second héros, engagé dans la MÊME quête minimale, à sa propre position —
 * pour éprouver « une tentative par héros » (l'un a essayé, l'autre pas), ou
 * pour empêcher le tour de se boucler tout seul dans le test qui joue la
 * vraie route HTTP (le round ne se ferme qu'une fois TOUS les héros engagés
 * à `a_joue`, même raison que `demarrerFouille()`/`demarrerExplo()` ailleurs
 * dans la suite).
 *
 * @return array{joueur: JoueurAuthentifiable, heros: Personnage, etat: EtatPersonnageQuete}
 */
function ajouterHerosSecondaire(array $ctx, string $nom, int $ordre, int $x, int $y, array $attrs = []): array
{
    $joueur = JoueurAuthentifiable::create([
        'pseudo' => mb_strtolower($nom), 'identifiant' => mb_strtolower($nom), 'mot_de_passe' => 'secret',
    ]);
    $heros = creerHeros($joueur, $ctx['groupe'], $nom, $ordre, $attrs);
    $etat = EtatPersonnageQuete::create([
        'quete_id' => $ctx['quete']->id, 'personnage_id' => $heros->id, 'position_x' => $x, 'position_y' => $y,
    ]);

    return ['joueur' => $joueur, 'heros' => $heros, 'etat' => $etat];
}

/** Pose une instance ACTIVE du monstre nommé, exactement en (x, y). */
function poserInstanceMonstre(Quete $quete, string $nomMonstre, int $x, int $y, bool $revele = true): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nomMonstre)->firstOrFail();

    return InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'position_x' => $x,
        'position_y' => $y,
        'etat' => 'actif',
        'elite' => false,
        'revele' => $revele,
    ]);
}

/** Pose un piège de scénario (état choisi) sur une case précise. Rend son index. */
function poserPiegeUnique(Quete $quete, int $x, int $y, string $nom, string $etat = 'cache'): int
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $index = count($grille['pieges'] ?? []);
    $grille['pieges'][] = ['x' => $x, 'y' => $y, 'piege_id' => Piege::where('nom', $nom)->value('id'), 'etat' => $etat];
    $carte->update(['grille' => $grille]);
    $quete->load('carte');

    return $index;
}

// =====================================================================
// DIFFICULTÉ
// =====================================================================

it('la difficulté est les PV de Body du CATALOGUE — un boss blessé reste aussi dur à bousculer', function () {
    // `attribut_body` largement au-dessus de tout PV de catalogue en jeu ici :
    // on isole la lecture « catalogue », le plafond du groupe est prouvé à
    // part (cas suivant).
    $ctx = quetePousseeMinimale(['classe' => 'barbare', 'attribut_body' => 12]);
    $instance = poserInstanceMonstre($ctx['quete'], 'Gobelin', 4, 3); // au contact, à l'est du héros (3,3)

    // Un Gobelin de catalogue n'a que 1 PV de Body (doc 16) : on le fait
    // passer pour un boss en écrivant directement sur la ligne de CATALOGUE
    // (même geste que `RareteButinTest`, qui mute déjà `Mobilier`/`Objet`
    // pour son propre déterminisme) — la case-mère `Seigneur ogre` a une
    // emprise 1×2 qui aurait pollué le test avec un problème de PLACE, sans
    // rien apporter à ce qu'on éprouve ici (la lecture du chiffre).
    $instance->monstre->update(['pv_body' => 10]);

    $methode = new ReflectionMethod(MenuMoteur::class, 'ciblesRepoussables');
    $cible = fn () => collect($methode->invoke(app(MenuMoteur::class), $ctx['quete'], $ctx['heros'], 3, 3))
        ->firstWhere('instance_id', $instance->id);

    expect($cible()['difficulte'])->toBe(10);

    // Le monstre encaisse : ses PV COURANTS chutent à 1. La difficulté ne
    // doit pas bouger d'un cran — elle ne lit jamais l'instance, seulement le
    // bloc de stats du catalogue.
    $instance->update(['pv_body' => 1]);

    expect($cible()['difficulte'])->toBe(10);
});

it('la difficulté est PLAFONNÉE au meilleur Body du groupe engagé', function () {
    $ctx = quetePousseeMinimale(['classe' => 'barbare']); // Body 4 (stats par défaut de creerHeros)
    $instance = poserInstanceMonstre($ctx['quete'], 'Gobelin', 4, 3);
    // Un « Seigneur ogre » de fortune (10 PV de Body), sans le souci d'emprise
    // 1×2 de la vraie créature — voir le commentaire du cas précédent.
    $instance->monstre->update(['pv_body' => 10]);

    $methode = new ReflectionMethod(MenuMoteur::class, 'ciblesRepoussables');
    $cible = collect($methode->invoke(app(MenuMoteur::class), $ctx['quete'], $ctx['heros'], 3, 3))
        ->firstWhere('instance_id', $instance->id);

    // 4, pas 10 : un jet à 10 dés de Body est mathématiquement hors de portée
    // d'un barbare qui n'en a que 4 — DifficulteBody le sait et le dit dans
    // le menu, pas seulement dans le résolveur.
    expect($cible['difficulte'])->toBe(4);
});

// =====================================================================
// L'OPTION DISPARAÎT QUAND ELLE NE PEUT PAS ABOUTIR
// =====================================================================

it('l\'option est ABSENTE quand la case de recul est occupée par un autre monstre', function () {
    $ctx = quetePousseeMinimale();
    $cible = poserInstanceMonstre($ctx['quete'], 'Gobelin', 4, 3); // à l'est du héros (3,3) → recul en (5,3)
    // Une seconde créature plantée EXACTEMENT sur la case de recul.
    poserInstanceMonstre($ctx['quete'], 'Gobelin', 5, 3);

    $methode = new ReflectionMethod(MenuMoteur::class, 'ciblesRepoussables');
    $cibles = $methode->invoke(app(MenuMoteur::class), $ctx['quete'], $ctx['heros'], 3, 3);

    expect(collect($cibles)->pluck('instance_id')->contains($cible->id))->toBeFalse();
});

it('l\'option est ABSENTE quand la case de recul tombe hors de la carte', function () {
    // Héros collé au bord est d'une carte 7×7 (colonnes 0..6) : le monstre au
    // contact est sur la DERNIÈRE colonne, sa case de recul (colonne 7)
    // n'existe tout simplement pas.
    $ctx = quetePousseeMinimale(hx: 5, hy: 3);
    $cible = poserInstanceMonstre($ctx['quete'], 'Gobelin', 6, 3);

    $methode = new ReflectionMethod(MenuMoteur::class, 'ciblesRepoussables');
    $cibles = $methode->invoke(app(MenuMoteur::class), $ctx['quete'], $ctx['heros'], 5, 3);

    expect(collect($cibles)->pluck('instance_id')->contains($cible->id))->toBeFalse();
});

it('l\'option est ABSENTE pour un monstre au contact mais NON RÉVÉLÉ', function () {
    $ctx = quetePousseeMinimale();
    $cible = poserInstanceMonstre($ctx['quete'], 'Gobelin', 4, 3, revele: false);

    $methode = new ReflectionMethod(MenuMoteur::class, 'ciblesRepoussables');
    $cibles = $methode->invoke(app(MenuMoteur::class), $ctx['quete'], $ctx['heros'], 3, 3);

    expect(collect($cibles)->pluck('instance_id')->contains($cible->id))->toBeFalse();
});

// =====================================================================
// RÉSOLUTION — réussite, échec, embuscade
// =====================================================================

it('réussite → l\'instance recule d\'UNE case sur l\'axe héros → monstre, via la VRAIE route de jeu', function () {
    $ctx = quetePousseeMinimale(['classe' => 'barbare']); // Body 4, en (3,3)
    $instance = poserInstanceMonstre($ctx['quete'], 'Gobelin', 4, 3); // Body catalogue 1 → difficulté 1
    // Une Brunhilde inerte, ailleurs dans la carte : sans elle, l'action
    // d'Albrecht (créneau ACTION) épuiserait à elle seule son tour et
    // enchaînerait la phase des monstres avant qu'on ait pu lire la réponse.
    ajouterHerosSecondaire($ctx, 'Brunhilde', 2, 0, 0, ['classe' => 'barbare']);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $options = collect(Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'] ?? []);
    $option = $options->firstWhere('id', "repousser_{$instance->id}");

    // L'option est réellement PROPOSÉE, avec la bonne difficulté affichée —
    // pas seulement calculable en coulisses.
    expect($option)->not->toBeNull()
        ->and($option['jet']['difficulte'])->toBe(1);

    // ⚠ La pile doit couvrir TOUT ce que la requête consomme, pas seulement le
    // jet : la résolution régénère un menu, qui relance le d6 de déplacement du
    // tour, et la phase des monstres peut suivre. Quatre crânes pour le jet de
    // Body (difficulté 1), puis des boucliers blancs pour le reste — un lanceur
    // déterministe épuisé lève une exception, pas un échec de test lisible.
    desFiges([1, 1, 1, 1, ...array_fill(0, 40, 4)]);

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => "repousser_{$instance->id}"])
        ->assertStatus(202)->json('resultat');

    expect($resultat['repoussee'])->toBeTrue()
        // Le monstre était à l'EST du héros (4,3) pour un héros en (3,3) : il
        // recule encore plus à l'est, jamais vers le héros qui le repousse.
        ->and($resultat['vers'])->toBe(['x' => 5, 'y' => 3])
        ->and((int) $instance->fresh()->position_x)->toBe(5)
        ->and((int) $instance->fresh()->position_y)->toBe(3);
});

it('échec → la créature ne bouge pas, mais la tentative est dépensée pour CE héros seulement', function () {
    $ctx = quetePousseeMinimale(['classe' => 'barbare']);
    $instance = poserInstanceMonstre($ctx['quete'], 'Gobelin', 4, 3); // au contact d'Albrecht (ouest)

    // Brunhilde, elle aussi au contact du même monstre (au nord), qui n'a
    // encore RIEN tenté.
    $brunhilde = ajouterHerosSecondaire($ctx, 'Brunhilde', 2, 4, 2, ['classe' => 'barbare']);

    desFiges(array_fill(0, 4, 4)); // 4 boucliers blancs : zéro crâne, échec sec (difficulté 1)

    $methode = new ReflectionMethod(ResolveurTour::class, 'resoudrePoussee');
    $option = ['id' => "repousser_{$instance->id}", 'libelle' => 'Repousser',
        'parametres' => ['instance_id' => $instance->id]];
    $acteur = ['type' => 'personnage', 'id' => $ctx['heros']->id, 'nom' => $ctx['heros']->nom];

    $resultat = $methode->invoke(
        app(ResolveurTour::class), $ctx['groupe'], $ctx['quete'], $ctx['heros'], $ctx['etatHeros'], $option, [], $acteur,
    );

    expect($resultat['repoussee'])->toBeFalse()
        ->and((int) $instance->fresh()->position_x)->toBe(4)
        ->and((int) $instance->fresh()->position_y)->toBe(3);

    $ciblesMethode = new ReflectionMethod(MenuMoteur::class, 'ciblesRepoussables');

    // Albrecht a tenté et raté : l'option a disparu POUR LUI.
    $pourAlbrecht = collect($ciblesMethode->invoke(app(MenuMoteur::class), $ctx['quete']->fresh(), $ctx['heros']->fresh(), 3, 3))
        ->pluck('instance_id');
    expect($pourAlbrecht->contains($instance->id))->toBeFalse();

    // Brunhilde, elle, n'a rien tenté : l'option lui reste offerte.
    $pourBrunhilde = collect($ciblesMethode->invoke(app(MenuMoteur::class), $ctx['quete']->fresh(), $brunhilde['heros']->fresh(), 4, 2))
        ->pluck('instance_id');
    expect($pourBrunhilde->contains($instance->id))->toBeTrue();
});

it('ne déclenche AUCUN piège sous la créature repoussée — une embuscade, pas un bug', function () {
    $ctx = quetePousseeMinimale(['classe' => 'barbare']);
    $instance = poserInstanceMonstre($ctx['quete'], 'Gobelin', 4, 3);

    // Un piège CACHÉ exactement sur la case de recul (5,3).
    $index = poserPiegeUnique($ctx['quete'], 5, 3, 'Piège à lances');

    desFiges([1, 4, 4, 4]); // réussite garantie (1 crâne, difficulté 1)

    $methode = new ReflectionMethod(ResolveurTour::class, 'resoudrePoussee');
    $option = ['id' => "repousser_{$instance->id}", 'libelle' => 'Repousser',
        'parametres' => ['instance_id' => $instance->id]];
    $acteur = ['type' => 'personnage', 'id' => $ctx['heros']->id, 'nom' => $ctx['heros']->nom];

    $resultat = $methode->invoke(
        app(ResolveurTour::class), $ctx['groupe'], $ctx['quete'], $ctx['heros'], $ctx['etatHeros'], $option, [], $acteur,
    );

    expect($resultat['repoussee'])->toBeTrue()
        ->and((int) $instance->fresh()->position_x)->toBe(5)
        // Le piège reste CACHÉ : `MoteurPieges::declencher()` prend un
        // `Personnage`, jamais une `InstanceMonstre` — la créature qui
        // atterrit sur la case ne peut donc rien y déclencher. « Une
        // créature sur un piège est une embuscade, pas un bug » (CLAUDE.md).
        ->and($ctx['quete']->fresh()->carte->grille['pieges'][$index]['etat'])->toBe('cache');
});
