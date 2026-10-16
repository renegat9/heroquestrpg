<?php

declare(strict_types=1);

use App\Events\EtatGroupeDiffuse;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\MoteurDread;
use App\Partie\SceneDeTable;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * First Light — la carte « Dragon » (docs/plan-first-light.md ; docs/regles/sorts-dread.md) :
 * « The dragon uses Draconic Flight and may cast Ball of Flame at will. » Tests EN JEU.
 *
 *  - sort_a_volonte : Boule de Flammes échappe au compteur `usages_dread` pour CE monstre seul
 *    (contraste avec le Spectre, bridé malgré sa carte à lui aussi dire « at will »).
 *  - Draconic Flight, TOUR FRACTIONNÉ (2026-10-16) : un budget de déplacement coupé en DEUX jambes
 *    autour d'UNE action. Texte de la carte (FL-Q p. 7) : « The dragon may take partial movement,
 *    perform an action, and then finish its movement. The dragon's powerful wings allow it to move
 *    through squares occupied by heroes and monsters, though it cannot end its movement on an
 *    occupied square. » Les tests marqués « AVANT » échouaient sur l'ancien vol d'un bloc : un seul
 *    payload, pas de jambe 2, et un sort qui se lançait sans jamais bouger.
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

/**
 * Carte d'après un dessin (`s` sol, `m` mur). UNE salle couvre le plateau : la coupure dépend de la
 * grille et des figures, jamais des salles. Deux rangées au moins : le Dragon fait 1 × 2
 * (`grande_taille`) et doit pouvoir tenir sur une case d'arrêt.
 *
 * @param  list<string>  $dessin
 */
function dragonQueteDessinee(array $dessin): Quete
{
    $groupe = creerGroupe('table-dragon-'.uniqid());
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();
    $largeur = strlen($dessin[0]);
    $hauteur = count($dessin);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => $gabarit->id,
        'titre' => 'Quête de test — Draconic Flight',
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
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'cases' => array_map(fn (string $ligne) => str_split($ligne), $dessin),
            'salles' => [['x' => 0, 'y' => 0, 'largeur' => $largeur, 'hauteur' => $hauteur, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
            'portes' => [],
            'leviers' => [],
            'pieges' => [],
            'mobilier' => [],
            'epreuves' => [],
            'terrain' => [],
            'spawn_heros' => [['x' => 0, 'y' => 0]],
            'spawn_monstres' => [],
            'aretes' => [],
        ],
    ]);

    return $quete->fresh();
}

/** Héros debout en (x, y), état de quête créé directement (pas de passage par l'API). */
function dragonHeroEn(Quete $quete, string $nom, int $ordre, int $x, int $y): array
{
    $joueur = connecterJoueur('joueur-'.$nom.'-'.uniqid());
    $personnage = creerHeros($joueur, $quete->groupe, $nom, $ordre);

    $etat = EtatPersonnageQuete::create([
        'personnage_id' => $personnage->id,
        'quete_id' => $quete->id,
        'position_x' => $x,
        'position_y' => $y,
        'tombe' => false,
    ]);

    return [$personnage, $etat];
}

/**
 * Une instance de monstre en (x, y), révélée et active. `$sansMagie` retire les sorts du bloc
 * catalogue : le Dragon n'a plus que `vol_draconique`, et seul le vol est alors en jeu.
 */
function dragonMonstreEn(Quete $quete, string $nomBase, int $x, int $y, bool $sansMagie = false): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nomBase)->firstOrFail();

    if ($sansMagie) {
        $catalogue->update(['capacites' => ['vol_draconique'], 'sorts_dread' => []]);
        $catalogue->refresh();
    }

    return InstanceMonstre::create([
        'quete_id' => $quete->id,
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'position_x' => $x,
        'position_y' => $y,
        'etat' => 'actif',
        'elite' => false,
        'revele' => true,
        'usages_dread' => 0,
        'invocation_dread_utilisee' => false,
        'fuite_dread_utilisee' => false,
    ]);
}

/**
 * Les actions d'un tour de Dread, à plat (un tour fractionné rend un `actions_composites`).
 *
 * @return list<array<string, mixed>>
 */
function dragonActions(array $resultat): array
{
    return $resultat['type'] === 'actions_composites' ? $resultat['actions'] : [$resultat];
}

// ---------------------------------------------------------------------
// sort_a_volonte — Boule de Flammes du Dragon ignore le compteur de sorts
// ---------------------------------------------------------------------

it('le Dragon lance Boule de Flammes même à 0 usage restant — sort_a_volonte', function () {
    $ctx = demarrerQueteAvecMonstre('Dragon');
    $instance = $ctx['instance']->fresh()->load('monstre');

    expect($instance->monstre->capacites)->toHaveKey('sort_a_volonte')
        ->and(app(MoteurDread::class)->sortAVolonte($instance))->toBe('Boule de Flammes');

    // Compteur épuisé comme si le Dragon avait déjà tout dépensé ce combat.
    $instance->update(['usages_dread' => 0]);
    $instance->refresh();

    desFiges(array_fill(0, 200, 4)); // aucun impact sur le dé rouge de résistance (2-3), peu importe ici

    $cibles = Collection::make([$ctx['etatHeros']]);

    foreach (range(1, 3) as $tour) {
        $resultat = app(MoteurDread::class)->jouerTourDread(
            $ctx['groupe'], $ctx['quete'], $instance->fresh()->load('monstre'), $cibles,
        );

        expect($resultat)->not->toBeNull()
            ->and($resultat['type'])->toBe('sort_dread', "tour {$tour}")
            ->and($resultat['sort'])->toBe('Boule de Flammes', "tour {$tour}");
    }

    // ⚠ Jamais décrémenté : c'est la DIFFÉRENCE avec tout le reste du
    // bestiaire — le compteur reste à 0 parce qu'il n'a jamais été entamé
    // pour CE sort, pas parce qu'il a été reconstitué.
    expect($instance->fresh()->usages_dread)->toBe(0);
});

it('le Spectre, lui, reste bridé à 1 usage malgré sa carte « à volonté » (divergence assumée)', function () {
    // Contraste délibéré avec le Dragon : `USAGES_BASE` documente cette
    // divergence depuis le 2026-09-04 (« un spectre par salle canaliserait
    // sinon à chaque tour de monstre ») — rien de ce lot ne doit la changer.
    $ctx = demarrerQueteAvecMonstre('Spectre');
    $instance = $ctx['instance']->fresh()->load('monstre');

    expect(app(MoteurDread::class)->sortAVolonte($instance))->toBeNull();

    $instance->update(['usages_dread' => 0]);
    $instance->refresh();

    desFiges(array_fill(0, 50, 4));

    $resultat = app(MoteurDread::class)->jouerTourDread(
        $ctx['groupe'], $ctx['quete'], $instance->fresh()->load('monstre'), Collection::make([$ctx['etatHeros']]),
    );

    // Compteur à sec, pas de sort gratuit : comportement de base (approche
    // au contact, pas de sort), donc aucune action Dread.
    expect($resultat)->toBeNull();
});

// ---------------------------------------------------------------------
// Draconic Flight — un budget, deux jambes, une action au milieu
// ---------------------------------------------------------------------

it('AVANT : à volonté, le Dragon en vue d\'un héros lointain lance Boule de Flammes ET achève son vol', function () {
    // Une rangée de sol, deux de haut. Le héros est en ligne droite à 6 cases : le sort part du départ,
    // puis le Dragon ne reste PAS immobile — il vole jusqu'au contact avec le reste de son budget.
    $quete = dragonQueteDessinee(['sssssssss', 'sssssssss']);
    [, $etatHeros] = dragonHeroEn($quete, 'Cible', 1, 6, 0);
    $dragon = dragonMonstreEn($quete, 'Dragon', 0, 0);

    desFiges(array_fill(0, 200, 4));

    $resultat = app(MoteurDread::class)->jouerTourDread(
        $quete->groupe, $quete, $dragon->fresh()->load('monstre'), Collection::make([$etatHeros->fresh()]),
    );
    $actions = dragonActions($resultat);

    expect(array_column($actions, 'type'))->toBe(['sort_dread', 'deplacement_monstre'])
        ->and($actions[0]['sort'])->toBe('Boule de Flammes')
        ->and($actions[1])->toMatchArray([
            'vol' => true,
            'jambe' => 2,
            'depart' => ['x' => 0, 'y' => 0],
            'vers' => ['x' => 5, 'y' => 0],
        ])
        ->and(count($actions[1]['chemin']))->toBe(5);

    $dragon->refresh();
    expect([(int) $dragon->position_x, (int) $dragon->position_y])->toBe([5, 0]);
});

it('AVANT : la jambe 1 traverse un monstre sans jamais s\'y arrêter — la coupure tombe là où le sort voit sa cible', function () {
    // Un Gobelin (une figure, pas une cible) occupe (3, 0) : il coupe la ligne de vue du Dragon depuis le
    // départ, et il ne peut pas être une case d'arrêt. Le Dragon traverse, s'arrête en (4, 0) — d'où
    // Boule de Flammes voit le héros —, lance, puis achève son vol jusqu'au contact en (5, 0).
    $quete = dragonQueteDessinee(['sssssssss', 'sssssssss']);
    [, $etatHeros] = dragonHeroEn($quete, 'Cible', 1, 6, 0);
    $gobelin = dragonMonstreEn($quete, 'Gobelin', 3, 0);
    $dragon = dragonMonstreEn($quete, 'Dragon', 0, 0);

    desFiges(array_fill(0, 200, 4));

    $resultat = app(MoteurDread::class)->jouerTourDread(
        $quete->groupe, $quete, $dragon->fresh()->load('monstre'), Collection::make([$etatHeros->fresh()]),
    );
    $actions = dragonActions($resultat);

    expect(array_column($actions, 'type'))->toBe(['deplacement_monstre', 'sort_dread', 'deplacement_monstre'])
        ->and($actions[0])->toMatchArray(['vol' => true, 'jambe' => 1, 'vers' => ['x' => 4, 'y' => 0]])
        ->and($actions[2])->toMatchArray(['vol' => true, 'jambe' => 2, 'vers' => ['x' => 5, 'y' => 0]]);

    // La jambe 1 a bien TRAVERSÉ la case du Gobelin…
    expect(collect($actions[0]['chemin'])->contains(fn (array $c) => $c === ['x' => 3, 'y' => 0]))->toBeTrue();

    // …sans s'y arrêter, et le Gobelin n'a pas bougé.
    $dragon->refresh();
    $gobelin->refresh();
    expect([(int) $dragon->position_x, (int) $dragon->position_y])->toBe([5, 0])
        ->and([(int) $gobelin->position_x, (int) $gobelin->position_y])->toBe([3, 0]);
});

it('AVANT : sans action, une seule jambe — le Dragon ne finit jamais sur une figure, même au bout de son budget', function () {
    // Le Gobelin tombe pile sur la 10e case du budget, (10, 0) : la jambe s'arrête sur (9, 0), jamais dessus.
    // Aucune ligne de vue avant : la ligne vers le héros passe par le Gobelin, aucun sort ne part.
    $quete = dragonQueteDessinee([str_repeat('s', 14), str_repeat('s', 14)]);
    [, $etatHeros] = dragonHeroEn($quete, 'Cible', 1, 13, 0);
    dragonMonstreEn($quete, 'Gobelin', 10, 0);
    $dragon = dragonMonstreEn($quete, 'Dragon', 0, 0);

    desFiges(array_fill(0, 200, 4));

    $resultat = app(MoteurDread::class)->jouerTourDread(
        $quete->groupe, $quete, $dragon->fresh()->load('monstre'), Collection::make([$etatHeros->fresh()]),
    );

    expect($resultat)->not->toBeNull()
        ->and($resultat)->toMatchArray([
            'type' => 'deplacement_monstre',
            'vol' => true,
            'jambe' => null,
            'vers' => ['x' => 9, 'y' => 0],
        ])
        ->and($resultat['chemin'])->toHaveCount(9);

    $dragon->refresh();
    expect([(int) $dragon->position_x, (int) $dragon->position_y])->toBe([9, 0]);
});

it('AVANT : le coup de vol traverse un héros interposé, frappe au contact, et n\'a pas de jambe 2', function () {
    // Le Dragon n'a plus de magie : seul le vol est en jeu. Blocage (2, 0) n'est pas une cible : le vol le
    // traverse sans s'y arrêter, et frappe Cible (4, 0) depuis (3, 0). Au contact, rien ne reste à faire.
    $quete = dragonQueteDessinee(['sssss', 'sssss']);
    [$blocage, $etatBlocage] = dragonHeroEn($quete, 'Blocage', 1, 2, 0);
    [$cible, $etatCible] = dragonHeroEn($quete, 'Cible', 2, 4, 0);
    $dragon = dragonMonstreEn($quete, 'Dragon', 0, 0, sansMagie: true);

    desFiges([
        1, 1, 1, 1, 1, // 5 crânes — attaque du Dragon (5 dés, sans bonus : la carte n'en donne aucun)
        4, 4,          // défense du héros, aucun succès
        ...array_fill(0, 50, 4),
    ]);

    $pvAvant = (int) $cible->pv_body;

    $resultat = app(MoteurDread::class)->jouerTourDread(
        // ⚠ `$etatBlocage` n'est PAS dans les cibles : il reste un simple obstacle sur le trajet.
        $quete->groupe, $quete, $dragon->fresh()->load('monstre'), Collection::make([$etatCible->fresh()]),
    );
    $actions = dragonActions($resultat);

    expect(array_column($actions, 'type'))->toBe(['deplacement_monstre', 'vol_draconique'])
        ->and($actions[0])->toMatchArray(['vol' => true, 'jambe' => 1, 'vers' => ['x' => 3, 'y' => 0]])
        ->and($actions[1]['cible']['personnage_id'])->toBe($cible->id);

    $dragon->refresh();
    expect([(int) $dragon->position_x, (int) $dragon->position_y])->toBe([3, 0])
        ->and((int) $etatBlocage->fresh()->position_x)->toBe(2)
        ->and($etatBlocage->fresh()->tombe)->toBeFalse()
        ->and((int) $cible->fresh()->pv_body)->toBeLessThan($pvAvant);
});

it('Draconic Flight ne fait rien de spécial quand le Dragon est déjà au contact', function () {
    // Au contact dès le départ : rien à traverser, et pas question de contourner sa proie pour la frapper
    // d'un autre côté. Le coup reste à la résolution normale (ResolveurTour), comme avant.
    $quete = dragonQueteDessinee(['ss', 'ss']);
    [, $etatCible] = dragonHeroEn($quete, 'Cible', 1, 1, 0);
    $dragon = dragonMonstreEn($quete, 'Dragon', 0, 0, sansMagie: true);

    $resultat = app(MoteurDread::class)->jouerTourDread(
        $quete->groupe, $quete, $dragon->fresh()->load('monstre'), Collection::make([$etatCible->fresh()]),
    );

    $apres = $dragon->fresh();
    expect($resultat)->toBeNull();
    expect([(int) $apres->position_x, (int) $apres->position_y])->toBe([0, 0]);
});

it('AVANT : la diffusion de l\'état porte les jambes du Dragon — la table les marche, il ne se téléporte pas', function () {
    Event::fake([EtatGroupeDiffuse::class]);

    ['alice' => $alice, 'groupe' => $groupe, 'quete' => $quete, 'instance' => $instance, 'etatHeros' => $etatHeros]
        = demarrerQueteAvecMonstre('Dragon');

    // Le Dragon est au contact au départ : on l'éloigne de 4 à 6 cases, sur une case où il tient en entier
    // (1 × 2) ET que le héros atteint à pied (même grille que la résolution), pour qu'il ait un trajet à
    // faire ce tour-ci. Une case à vol d'oiseau derrière un mur ne donnerait aucun trajet.
    $grille = App\Partie\FabriqueGrille::pour($quete, exceptInstanceId: $instance->id);
    $hx = (int) $etatHeros->position_x;
    $hy = (int) $etatHeros->position_y;
    $loin = null;

    foreach (array_keys($grille->casesAtteignables($hx, $hy, 6)) as $cle) {
        [$x, $y] = array_map('intval', explode(',', (string) $cle));
        $distance = abs($x - $hx) + abs($y - $hy);

        if ($distance >= 4 && $distance <= 6 && $grille->empriseLibre($x, $y, 1, 2)) {
            $loin = ['x' => $x, 'y' => $y];
            break;
        }
    }

    expect($loin)->not->toBeNull('aucune case libre à distance pour éloigner le Dragon');
    $instance->update(['position_x' => $loin['x'], 'position_y' => $loin['y']]);

    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    Event::assertDispatched(EtatGroupeDiffuse::class, function ($e) use ($groupe, $instance, $loin) {
        $trajet = collect($e->mouvements)->first(
            fn (array $m) => $m['type'] === 'monstre' && (int) $m['id'] === (int) $instance->id,
        );

        return $e->groupe->id === $groupe->id
            && $trajet !== null
            && $trajet['depart'] == $loin
            && count($trajet['chemin']) >= 1;
    });
});

it('le fil annonce les trois temps du vol : prend son vol, l\'action, achève son vol', function () {
    $depart = ['x' => 0, 'y' => 0];
    $resultat = ['tour_monstres' => ['actions' => [
        [
            'type' => 'deplacement_monstre', 'id' => 7, 'monstre' => 'Dragon', 'vol' => true, 'jambe' => 1,
            'depart' => $depart, 'vers' => ['x' => 4, 'y' => 0],
            'chemin' => [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0], ['x' => 3, 'y' => 0], ['x' => 4, 'y' => 0]],
        ],
        [
            'type' => 'deplacement_monstre', 'id' => 7, 'monstre' => 'Dragon', 'vol' => true, 'jambe' => 2,
            'depart' => ['x' => 4, 'y' => 0], 'vers' => ['x' => 5, 'y' => 0],
            'chemin' => [['x' => 5, 'y' => 0]],
        ],
        [
            'type' => 'deplacement_monstre', 'id' => 7, 'monstre' => 'Dragon', 'vol' => true, 'jambe' => null,
            'depart' => $depart, 'vers' => ['x' => 3, 'y' => 0],
            'chemin' => [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0], ['x' => 3, 'y' => 0]],
        ],
    ]]];

    $textes = array_column((new JournalCombat)->depuisResultat($resultat, 'Dragon'), 'texte');

    expect($textes)->toContain('Dragon prend son vol — 4 cases')
        ->toContain('Dragon achève son vol — 1 case')
        ->toContain('Dragon fond en vol et avance de 3 cases');
});

it('la scène d\'un sort de monstre attend la marche qui le PRÉCÈDE dans le tour, et seulement celle-là', function () {
    $acteur = new Personnage(['nom' => 'Albrecht']);
    $marche = [
        'type' => 'deplacement_monstre', 'id' => 7, 'monstre' => 'Dragon', 'vol' => true, 'jambe' => 1,
        'depart' => ['x' => 0, 'y' => 0], 'vers' => ['x' => 2, 'y' => 0],
        'chemin' => [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0]],
    ];
    $sort = [
        'type' => 'sort_dread', 'sort' => 'Boule de Flammes', 'lanceur' => ['instance_id' => 7, 'nom' => 'Dragon'],
        'resultats' => [['cible' => ['personnage_id' => 0, 'nom' => 'Albrecht'], 'faces_attaque' => [6, 4], 'faces_defense' => [4]]],
    ];
    $suite = [
        'type' => 'deplacement_monstre', 'id' => 7, 'monstre' => 'Dragon', 'vol' => true, 'jambe' => 2,
        'depart' => ['x' => 2, 'y' => 0], 'vers' => ['x' => 3, 'y' => 0],
        'chemin' => [['x' => 3, 'y' => 0]],
    ];

    // `figure` vaut null quand la scène n'attend rien : on distingue donc « pas de scène » d'une attente nulle.
    $figureDuSort = function (array $actions, array $enMarche) use ($acteur) {
        $scene = collect(app(SceneDeTable::class)->depuisResultat(['tour_monstres' => ['actions' => $actions]], $acteur, $enMarche))
            ->firstWhere('genre', 'sort');

        return $scene === null ? 'pas de scène' : $scene['figure'];
    };

    // Marche AVANT le sort : la scène attend la fin du trajet de la figurine.
    expect($figureDuSort([$marche, $sort, $suite], ['monstre:7']))->toBe('monstre:7');

    // Sort AU DÉPART, la marche ne vient qu'après : la scène n'attend rien.
    expect($figureDuSort([$sort, $suite], ['monstre:7']))->toBeNull();
});

it('une emprise 1 × 2 ne finit son mouvement que si TOUTES ses cases sont libres', function () {
    $grille = new Grille([['s', 's', 's'], ['s', 's', 's']]);
    $grille->occuper([['x' => 1, 'y' => 1]]);

    expect($grille->empriseArretPossible(0, 0, 1, 2))->toBeTrue()
        ->and($grille->empriseArretPossible(1, 0, 1, 2))->toBeFalse() // (1,1) occupée par une figure
        ->and($grille->empriseArretPossible(2, 0, 1, 2))->toBeTrue();
});
