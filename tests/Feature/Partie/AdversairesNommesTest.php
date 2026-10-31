<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\MotsClesAdversaires;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Models\SortDread;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\DemarreurQuete;
use App\Partie\EtatGroupe;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\MoteurSorts;
use App\Partie\SceneDeTable;
use App\Partie\Salles;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LE PATRON TOURELLE ET LES QUATRE ADVERSAIRES NOMMÉS DE JUNGLES OF DELTHRAK
 * (René, 2026-10-15).
 *
 *  - UN mécanisme de tourelle pour le *Guardian Effigy* (Against the Ogre Horde p. 27) et le
 *    *Dread Skull* (The Crypt of Perpetual Darkness) : figure immobile, tir chaque tour sur un
 *    héros en ligne de vue, immunité TOTALE aux sorts (Effigy), apparition à la vue (Skull),
 *    placement en SALLE seulement ;
 *  - *Deathspinner* (Silencing Snare), *Forgeheart Golem* (Breath of the Construct),
 *    *Bloomrot Scion* (sweep diagonal, Vrilles, sacrifice, immunité au sommeil et au tour perdu),
 *    *Bakabri the Cruel* (tir au choix + trois sorts).
 *
 * Un test de CATALOGUE ne prouve rien (`BestiaireSourceTest` ne vérifie que ça) : chaque
 * mécanique est éprouvée ICI en jeu, par les vraies routes (`GET /menu`, `POST /choix`) sur une
 * quête minimale — une grande salle, des figures posées à la main. Les cas qui servent de
 * preuve échouent sans le correctif : le commentaire de chacun dit ce que l'ancien code faisait.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : celui-ci dérive un parchemin par sort.
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class]);
});

// =====================================================================
// Une quête minimale : UNE salle 16×9 tout en sol (ou deux salles côte à côte)
// =====================================================================

/**
 * @param  list<array{x: int, y: int}>  $murs  cases de roche
 * @param  list<array<string, int>>  $salles  rectangles ; défaut : une seule salle couvrant la carte
 * @return array{alice: JoueurAuthentifiable, bob: JoueurAuthentifiable|null, groupe: App\Models\Groupe, quete: Quete, albrecht: Personnage, bertrand: Personnage|null}
 */
function advScene(int $nbHeros = 1, array $herosAttrs = [], array $murs = [], ?array $salles = null, array $positions = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);
    $bob = null;
    $bertrand = null;

    if ($nbHeros > 1) {
        $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
        $bertrand = creerHeros($bob, $groupe, 'Bertrand', 2);
    }

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — adversaires nommés',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $cases = array_fill(0, 9, array_fill(0, 16, 's'));
    foreach ($murs as $m) {
        $cases[$m['y']][$m['x']] = 'm';
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => $cases,
            'salles' => $salles ?? [['x' => 0, 'y' => 0, 'largeur' => 16, 'hauteur' => 9,
                'theme' => 'generique', 'mediane_x' => 8, 'mediane_y' => 4]],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => [],
            'spawn_heros' => [['x' => 1, 'y' => 4]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $albrecht->id,
        'position_x' => $positions['albrecht'][0] ?? 1, 'position_y' => $positions['albrecht'][1] ?? 4,
    ]);

    if ($bertrand !== null) {
        EtatPersonnageQuete::create([
            'quete_id' => $quete->id, 'personnage_id' => $bertrand->id,
            'position_x' => $positions['bertrand'][0] ?? 1, 'position_y' => $positions['bertrand'][1] ?? 5,
        ]);
    }

    return ['alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'),
        'albrecht' => $albrecht, 'bertrand' => $bertrand];
}

/** Pose une figure du catalogue, révélée, ses usages de Dread armés comme en jeu. */
function advPoser(array $ctx, string $nom, int $x, int $y, array $extra = []): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nom)->firstOrFail();

    $instance = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => $x, 'position_y' => $y, 'etat' => 'actif', 'revele' => true,
        ...$extra,
    ]);
    $instance->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $ctx['quete']);

    return $instance->fresh()->load('monstre');
}

function advMenu(): array
{
    return test()->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu') ?? [];
}

/** Le héros du joueur donné finit son tour par la VRAIE route. */
function advAttendre(array $ctx, string $qui = 'alice')
{
    test()->actingAs($ctx[$qui], 'joueur');
    advMenu();

    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
}

/** Les actions de la phase des monstres, à plat. */
function advActionsMonstres($reponse): array
{
    return collect($reponse->json('resultat.tour_monstres.actions') ?? [])
        ->flatMap(fn ($a) => ($a['type'] ?? null) === 'actions_composites' ? $a['actions'] : [$a])
        ->values()->all();
}

function advPv(Personnage $heros): int
{
    return (int) $heros->fresh()->pv_body;
}

/** Une file de dés : les `$premiers`, puis des 4 (bouclier blanc : ni crâne ni bouclier noir). */
function advDes(array $premiers = [], int $fond = 4): void
{
    desFiges([...$premiers, ...array_fill(0, 200, $fond)]);
}

// =====================================================================
// 1. LE REGISTRE — vocabulaire fermé, testé dans les DEUX SENS
// =====================================================================

it('REGISTRE : chaque clé de MotsClesAdversaires a un lecteur qui la NOMME, et une donnée — ou une absence écrite', function () {
    $portees = Monstre::all()->flatMap(fn (Monstre $m) => array_map(
        fn ($cle, $valeur) => is_int($cle) ? $valeur : $cle,
        array_keys((array) $m->capacites),
        (array) $m->capacites,
    ))->unique()->values()->all();

    foreach (MotsClesAdversaires::CAPACITES as $cle => $declaration) {
        [$classe, $methode] = explode('::', $declaration['lecteur']);

        // Le lecteur EXISTE…
        expect(method_exists($classe, $methode))->toBeTrue("« {$cle} » : le lecteur {$declaration['lecteur']} n'existe pas.");

        // …et son FICHIER nomme la clé (c'est l'assertion qui manquait à CapacitesInnees : quatre
        // lecteurs pointaient un fichier où le mot n'apparaissait pas).
        $source = (string) file_get_contents((new ReflectionClass($classe))->getFileName());
        expect(str_contains($source, "'{$cle}'"))->toBeTrue("« {$cle} » : {$declaration['lecteur']} ne nomme pas la clé.");

        expect($declaration['libelle'])->not->toBeEmpty();

        // Une donnée la porte, OU l'absence est ÉCRITE avec sa raison.
        if (isset(MotsClesAdversaires::SANS_DONNEE[$cle])) {
            expect(in_array($cle, $portees, true))->toBeFalse("« {$cle} » est déclarée SANS donnée mais une ligne la porte : retirer l'entrée de SANS_DONNEE.")
                ->and(MotsClesAdversaires::SANS_DONNEE[$cle])->not->toBeEmpty();
        } else {
            expect(in_array($cle, $portees, true))->toBeTrue("« {$cle} » : aucune ligne du catalogue ne la porte — une clé décorative.");
        }
    }

    // L'autre sens : SANS_DONNEE ne nomme que des clés déclarées.
    expect(array_diff(array_keys(MotsClesAdversaires::SANS_DONNEE), MotsClesAdversaires::cles()))->toBe([]);
});

it('REGISTRE : conditionsPoseesSurMonstre() dit, pour chaque sort du catalogue, ce que le résolveur pose', function () {
    $sorts = app(MoteurSorts::class);
    $vus = [];

    foreach (Sort::all() as $sort) {
        $attendu = [];

        if (data_get($sort->effet, 'condition_appliquee') === 'Endormi') {
            $attendu[] = 'endormi';
        }
        if (! empty($sort->effet['saute_tour'])) {
            $attendu[] = 'saute_tour';
        }
        if (is_string($sort->effet['condition_monstre'] ?? null)) {
            $attendu[] = $sort->effet['condition_monstre'];
        }

        expect($sorts->conditionsPoseesSurMonstre($sort))->toEqualCanonicalizing($attendu, $sort->nom);

        foreach ($attendu as $cle) {
            $vus[$cle] = true;
        }
    }

    // Le Scion est immunisé à `endormi` et `saute_tour` : les deux sont bien posés par des sorts du catalogue.
    expect(array_keys($vus))->toContain('endormi', 'saute_tour');
    // Et ce que l'immunité nomme est une condition que le moteur connaît.
    foreach (Monstre::all() as $m) {
        foreach ($m->conditionsImmunisees() as $cle) {
            expect(in_array($cle, MoteurSorts::CONDITIONS_MONSTRE, true))->toBeTrue("{$m->nom_base} : « {$cle} » n'est pas une condition de monstre.");
        }
    }
});

it('CATALOGUE : les six lignes portent les stats relues sur le livret, rendu à l\'appui', function () {
    // [déplacement, attaque, défense, Body, Mind, palier, boîte]
    $attendu = [
        'Effigie gardienne' => [0, 3, 5, 2, 0, 'sous_boss', 'horde_ogre'],            // F9528 p. 27
        'Deathspinner la Reine du Fléau' => [6, 5, 4, 4, 4, 'sous_boss', 'jungles_delthrak'], // F9907 p. 17 (« 6 dice » = 6 cases)
        'Golem Cœur-de-Forge' => [8, 5, 5, 5, 0, 'sous_boss', 'jungles_delthrak'],     // F9907 p. 23
        'Scion Fleur-Pourrie' => [0, 5, 5, 12, 0, 'boss', 'jungles_delthrak'],          // F9907 p. 39
        'Vrille' => [6, 3, 2, 2, 0, 'base', 'jungles_delthrak'],                        // F9907 p. 39
        'Bakabri le Cruel' => [8, 3, 4, 4, 5, 'sous_boss', 'jungles_delthrak'],         // F9907 p. 41
    ];

    foreach ($attendu as $nom => [$dep, $att, $def, $body, $mind, $palier, $boite]) {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();

        expect([(int) $m->deplacement, (int) $m->attaque, (int) $m->defense, (int) $m->pv_body, (int) $m->pv_mind, $m->tier, $m->boite])
            ->toBe([$dep, $att, $def, $body, $mind, $palier, $boite], $nom);
    }

    // Les deux figures IMMOBILES — et elles seules — ont Move 0 : c'est le catalogue qui le dit.
    expect(Monstre::all()->filter(fn (Monstre $m) => $m->estImmobile())->pluck('nom_base')->sort()->values()->all())
        // + le Crâne du Dread (The Crypt of Perpetual Darkness, 2026-10-23) : tourelle immobile, posée en salle.
        ->toBe(['Crâne du Dread', 'Effigie gardienne', 'Scion Fleur-Pourrie']);

    // La Vrille ne s'achète JAMAIS (cout 0 : le Scion la pose) ; les cinq autres ont un coût de palier.
    expect((int) Monstre::where('nom_base', 'Vrille')->value('cout'))->toBe(0);
    foreach (['Effigie gardienne' => 8, 'Deathspinner la Reine du Fléau' => 10, 'Golem Cœur-de-Forge' => 11, 'Scion Fleur-Pourrie' => 21, 'Bakabri le Cruel' => 10] as $nom => $cout) {
        expect((int) Monstre::where('nom_base', $nom)->value('cout'))->toBe($cout, $nom);
    }

    // Les capacités, exactement celles que le livret écrit.
    $cap = fn (string $nom) => (array) Monstre::where('nom_base', $nom)->value('capacites');
    expect($cap('Effigie gardienne'))->toBe(['tourelle', 'immunite_sorts'])
        ->and($cap('Deathspinner la Reine du Fléau'))->toBe(['filet_silencieux' => ['sort' => 'Canaliser l\'Effroi']])
        ->and($cap('Golem Cœur-de-Forge'))->toBe(['souffle_du_construit' => ['degats' => 2, 'des_rouges' => 2]])
        ->and($cap('Bakabri le Cruel'))->toBe(['tir_au_choix']);

    $scion = Monstre::where('nom_base', 'Scion Fleur-Pourrie')->firstOrFail();
    expect($scion->immuniseAuxSorts())->toBeFalse()
        ->and($scion->conditionsImmunisees())->toBe(['endormi', 'saute_tour'])
        ->and(array_key_exists('vrille_en_fin_de_tour', (array) $scion->capacites))->toBeTrue()
        ->and(in_array('sacrifice_de_vrille', (array) $scion->capacites, true))->toBeTrue()
        ->and($scion->sorts_dread)->toBe(['Canaliser l\'Effroi']);

    // Bakabri : « Creeping Grasp, Channel Dread, and Lightning Bolt » — les trois existent au catalogue.
    foreach (config('archetypes_lanceurs.bakabri_le_cruel.sorts') as $nomSort) {
        expect(SortDread::where('nom', $nomSort)->exists())->toBeTrue("sort « {$nomSort} » absent");
    }
    expect(config('archetypes_lanceurs.bakabri_le_cruel.sorts'))->toBe(['Étreinte des Ronces', 'Canaliser l\'Effroi', 'Éclair de Chaos']);
});

it('POOLS : les adversaires entrent dans les rotations du palier de leur livret, et la Vrille n\'est jamais achetée', function () {
    $final = fn (string $nom) => (array) GabaritQuete::where('nom', $nom)->firstOrFail()->structure['rencontre_finale'];

    $sousBoss = $final('Antre du sous-boss');
    $boss = $final('Confrontation finale');

    expect($sousBoss['creatures'])->toContain('Effigie gardienne', 'Deathspinner la Reine du Fléau', 'Golem Cœur-de-Forge')
        ->and($sousBoss['archetypes'])->toContain('bakabri_le_cruel')
        ->and($boss['creatures'])->toContain('Scion Fleur-Pourrie');

    $acheter = new ReflectionMethod(DemarreurQuete::class, 'acheterMonstres');
    $acheter->setAccessible(true);
    $demarreur = app(DemarreurQuete::class);

    foreach (['jungles_delthrak', 'horde_ogre', null] as $theme) {
        $bestiaire = $theme === null ? BestiaireGroupe::auto(null) : BestiaireGroupe::auto($theme);

        foreach (['Antre du sous-boss', 'Confrontation finale'] as $nom) {
            $structure = (array) GabaritQuete::where('nom', $nom)->firstOrFail()->structure;

            for ($graine = 0; $graine < 14; $graine++) {
                foreach ([1, 2, 3] as $arc) {
                    $achats = collect($acheter->invoke($demarreur, $structure, 60, 7, $arc, $graine, $bestiaire));

                    expect($achats->pluck('nom_base')->contains('Vrille'))->toBeFalse("Vrille achetée ({$nom}, {$theme}, graine {$graine})")
                        ->and($achats->filter(fn (Monstre $m) => $m->estImmobile())->count())->toBeLessThanOrEqual(1);

                    // Sans case sûre sur la carte : AUCUNE figure immobile n'est achetée.
                    $sansCase = collect($acheter->invoke($demarreur, $structure, 60, 7, $arc, $graine, $bestiaire, false));
                    expect($sansCase->filter(fn (Monstre $m) => $m->estImmobile())->count())->toBe(0);
                }
            }
        }
    }

    // Le thème Jungles tire bien le Scion, la Deathspinner, le Golem et Bakabri au fil des rotations.
    $vus = [];
    $bestiaire = BestiaireGroupe::auto('jungles_delthrak');

    for ($graine = 0; $graine < 40; $graine++) {
        foreach ([1, 2, 3, 4] as $arc) {
            foreach (['Antre du sous-boss', 'Confrontation finale'] as $nom) {
                $structure = (array) GabaritQuete::where('nom', $nom)->firstOrFail()->structure;
                $premier = $acheter->invoke($demarreur, $structure, 60, 7, $arc, $graine, $bestiaire)[0];
                $vus[$premier->nom_base] = true;
            }
        }
    }

    expect(array_keys($vus))->toContain('Scion Fleur-Pourrie', 'Deathspinner la Reine du Fléau', 'Golem Cœur-de-Forge', 'Bakabri le Cruel');
});

// =====================================================================
// 2. LE PLACEMENT — en salle, hors parvis, plancher tenu, jamais en couloir
// =====================================================================

/** Le PARVIS recalculé HORS du code testé : les voisines orthogonales d'une porte, d'un levier, d'une épreuve. */
function advParvis(array $carte): array
{
    $parvis = [];
    $voisines = function (int $x, int $y) use (&$parvis) {
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $parvis[($x + $dx).','.($y + $dy)] = true;
        }
    };

    foreach ($carte['portes'] as $porte) {
        foreach (Grille::casesPorte($porte) as $c) {
            $voisines((int) $c['x'], (int) $c['y']);
        }
    }
    foreach (['leviers', 'epreuves'] as $couche) {
        foreach ($carte[$couche] as $e) {
            $voisines((int) $e['x'], (int) $e['y']);
        }
    }

    return $parvis;
}

it('PLACEMENT : chaque case sûre est en salle, hors parvis, et laisse la salle connexe et jouable', function () {
    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail();
    $avecCase = 0;
    $total = 0;

    foreach (range(1, 60) as $graine) {
        $carte = app(AssembleurCarte::class)->assembler($gabarit, $graine);
        $sures = $carte['spawn_figures_fixes'];
        $parvis = advParvis($carte);
        $spawns = array_map(fn ($p) => "{$p['x']},{$p['y']}", $carte['spawn_monstres']);

        $total++;
        $avecCase += $sures !== [] ? 1 : 0;

        // Un sous-ensemble des spawns de monstres : jamais une case que le générateur n'offrait pas.
        foreach ($sures as $p) {
            $cle = "{$p['x']},{$p['y']}";
            $i = Salles::indexDe($carte['salles'], $p['x'], $p['y']);

            expect(in_array($cle, $spawns, true))->toBeTrue("graine {$graine} : {$cle} n'est pas un spawn")
                ->and($i)->not->toBeNull("graine {$graine} : {$cle} est en COULOIR")
                ->and($i)->not->toBe(0, "graine {$graine} : {$cle} est dans la salle de départ")
                ->and(isset($parvis[$cle]))->toBeFalse("graine {$graine} : {$cle} est sur un parvis");

            // Plancher : cases libres de la salle une fois le mobilier ET la figure posés.
            $salle = $carte['salles'][$i];
            $prises = [$cle => true];
            foreach ($carte['mobilier'] as $m) {
                for ($dy = 0; $dy < max(1, (int) ($m['h'] ?? 1)); $dy++) {
                    for ($dx = 0; $dx < max(1, (int) ($m['l'] ?? 1)); $dx++) {
                        $prises[((int) $m['x'] + $dx).','.((int) $m['y'] + $dy)] = true;
                    }
                }
            }

            $libres = [];
            for ($y = $salle['y']; $y < $salle['y'] + $salle['hauteur']; $y++) {
                for ($x = $salle['x']; $x < $salle['x'] + $salle['largeur']; $x++) {
                    if (($carte['cases'][$y][$x] ?? 'm') === 's' && ! isset($prises["{$x},{$y}"])) {
                        $libres[] = [$x, $y];
                    }
                }
            }

            expect(count($libres))->toBeGreaterThanOrEqual(6, "graine {$graine} : la salle {$i} tombe sous le plancher de cases jouables");
        }
    }

    // Une carte sur deux au moins en offre une : la règle n'est pas un refus déguisé.
    expect($avecCase)->toBeGreaterThan((int) ($total / 2), 'trop de cartes sans aucune case pour une figure fixe');
});

it('PLACEMENT : la figure immobile prend une case sûre et CÈDE la sienne — jamais un doublon', function () {
    $positionsDesMonstres = new ReflectionMethod(DemarreurQuete::class, 'positionsDesMonstres');
    $positionsDesMonstres->setAccessible(true);

    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 4, 'hauteur' => 4],
        ['x' => 6, 'y' => 0, 'largeur' => 6, 'hauteur' => 6],
    ];
    // A (7,1) est sur un parvis (non sûre) ; B (9,3) est sûre ; C (10,4) aussi, dans la même salle.
    $carte = [
        'salles' => $salles,
        'spawn_monstres' => [['x' => 7, 'y' => 1], ['x' => 9, 'y' => 3], ['x' => 10, 'y' => 4]],
        'spawn_figures_fixes' => [['x' => 9, 'y' => 3], ['x' => 10, 'y' => 4]],
    ];
    $scion = Monstre::where('nom_base', 'Scion Fleur-Pourrie')->firstOrFail();
    $gobelin = Monstre::where('nom_base', 'Gobelin')->firstOrFail();

    $positions = $positionsDesMonstres->invoke(app(DemarreurQuete::class), [$scion, $gobelin, $gobelin], $carte);

    expect($positions[0])->toBe(['x' => 9, 'y' => 3], 'le Scion prend une case sûre de SA salle')
        ->and($positions[1])->toBe(['x' => 7, 'y' => 1], 'le gobelin hérite de la case que le Scion quitte')
        ->and(collect($positions)->map(fn ($p) => "{$p['x']},{$p['y']}")->unique()->count())->toBe(3);

    // Déjà sur une case sûre : rien ne bouge. Un monstre mobile ne déplace personne.
    $carte['spawn_monstres'] = [['x' => 10, 'y' => 4], ['x' => 7, 'y' => 1], ['x' => 9, 'y' => 3]];
    expect($positionsDesMonstres->invoke(app(DemarreurQuete::class), [$scion, $gobelin, $gobelin], $carte))->toBe($carte['spawn_monstres'])
        ->and($positionsDesMonstres->invoke(app(DemarreurQuete::class), [$gobelin, $gobelin, $gobelin], $carte))->toBe($carte['spawn_monstres']);
});

it('PLACEMENT : de bout en bout, un Scion acheté est posé sur une case sûre — jamais sur un parvis ni en couloir', function () {
    $acheter = new ReflectionMethod(DemarreurQuete::class, 'acheterMonstres');
    $acheter->setAccessible(true);
    $positionner = new ReflectionMethod(DemarreurQuete::class, 'positionsDesMonstres');
    $positionner->setAccessible(true);

    $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
    $structure = (array) $gabarit->structure;
    $structure['rencontre_finale'] = ['tier' => 'boss', 'escorte_budget' => 6, 'archetypes' => [], 'creatures' => ['Scion Fleur-Pourrie']];
    $gabarit->structure = $structure;

    $poses = 0;

    foreach (range(1, 40) as $graine) {
        $carte = app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: BestiaireGroupe::auto('jungles_delthrak'));
        $monstres = $acheter->invoke(app(DemarreurQuete::class), $structure, 60, count($carte['spawn_monstres']), 1, $graine,
            BestiaireGroupe::auto('jungles_delthrak'), $carte['spawn_figures_fixes'] !== []);

        if ($monstres[0]->nom_base !== 'Scion Fleur-Pourrie') {
            // Sans case sûre, il n'est PAS acheté (un adversaire du pool restant prend la place, ou le repli).
            expect($carte['spawn_figures_fixes'])->toBe([], "graine {$graine} : Scion écarté alors qu'une case sûre existait");

            continue;
        }

        $positions = $positionner->invoke(app(DemarreurQuete::class), $monstres, $carte);
        $sures = array_map(fn ($p) => "{$p['x']},{$p['y']}", $carte['spawn_figures_fixes']);

        expect(in_array("{$positions[0]['x']},{$positions[0]['y']}", $sures, true))->toBeTrue("graine {$graine} : le Scion n'est pas sur une case sûre")
            ->and(Salles::indexDe($carte['salles'], $positions[0]['x'], $positions[0]['y']))->not->toBeNull('en couloir');

        $poses++;
    }

    expect($poses)->toBeGreaterThan(25, 'le Scion est presque toujours posable : les cases sûres ne sont pas rares');
});

it('PUBLICATION : `spawn_figures_fixes` reste INTERNE — jamais dans l\'état publié ; une figure posée en cours de quête, si', function () {
    $ctx = advScene();
    $grille = $ctx['quete']->carte->grille;
    $grille['spawn_figures_fixes'] = [['x' => 6, 'y' => 4]];
    $ctx['quete']->carte->update(['grille' => $grille]);
    $effigie = advPoser($ctx, 'Effigie gardienne', 6, 4);

    $payload = app(EtatGroupe::class)->payload($ctx['groupe']->fresh());

    expect(json_encode($payload['carte']))->not->toContain('spawn_figures_fixes')
        ->and(collect($payload['entites'])->contains(fn ($e) => ($e['type'] ?? null) === 'monstre' && $e['id'] === $effigie->id && $e['x'] === 6))->toBeTrue();
});

// =====================================================================
// 3. LA TOURELLE EN JEU — le Guardian Effigy
// =====================================================================

it('EN JEU — l\'Effigie fait feu de loin : 3 dés, portée « distance », drapeau tourelle, et elle ne bouge pas d\'une case', function () {
    $ctx = advScene();
    $effigie = advPoser($ctx, 'Effigie gardienne', 8, 4);

    advDes([], 1); // que des crânes : 3 crânes d'attaque, aucune parade
    $reponse = advAttendre($ctx);

    $attaque = collect(advActionsMonstres($reponse))->firstWhere('type', 'attaque_monstre');

    // Avant le correctif : Move 0 + aucun lecteur de tourelle → la figure « avançait de 0 case »
    // (`deplacement_monstre`, chemin vide) et ne tirait jamais.
    expect($attaque)->not->toBeNull('l\'Effigie en ligne de vue doit faire feu')
        ->and($attaque['portee'])->toBe('distance')
        ->and($attaque['tourelle'])->toBeTrue()
        ->and(count($attaque['faces_attaque']))->toBe(3, '« a ball of fire of 3 Attack dice »')
        ->and($attaque['degats'])->toBe(3)
        ->and(advPv($ctx['albrecht']))->toBe(5)
        ->and(collect(advActionsMonstres($reponse))->firstWhere('type', 'deplacement_monstre'))->toBeNull('une tourelle ne se déplace jamais')
        ->and([(int) $effigie->fresh()->position_x, (int) $effigie->fresh()->position_y])->toBe([8, 4]);
});

it('EN JEU — au contact, la tourelle FAIT FEU quand même (aux mêmes dés), au lieu de frapper en mêlée', function () {
    $ctx = advScene();
    advPoser($ctx, 'Effigie gardienne', 2, 4);

    advDes([], 1);
    $attaque = collect(advActionsMonstres(advAttendre($ctx)))->firstWhere('type', 'attaque_monstre');

    // Avant : `portee` valait `corps_a_corps` et rien ne disait « tourelle ».
    expect($attaque['portee'])->toBe('distance')
        ->and($attaque['tourelle'])->toBeTrue()
        ->and(count($attaque['faces_attaque']))->toBe(3);
});

it('EN JEU — sans ligne de vue, la tourelle ne tire pas ET ne bouge pas, même quand un chemin existe', function () {
    // Un mur plein sauf une brèche en bas : un chemin EXISTE, la vue est coupée.
    $murs = array_map(fn ($y) => ['x' => 4, 'y' => $y], range(0, 7));
    $ctx = advScene(murs: $murs);
    $effigie = advPoser($ctx, 'Effigie gardienne', 8, 4);

    advDes([], 1);
    $reponse = advAttendre($ctx);
    $actions = advActionsMonstres($reponse);

    expect(collect($actions)->firstWhere('type', 'attaque_monstre'))->toBeNull()
        // Avant : le monstre d'un Move 0 « avançait » le long du chemin (payload `deplacement_monstre`).
        ->and(collect($actions)->firstWhere('type', 'deplacement_monstre'))->toBeNull()
        ->and([(int) $effigie->fresh()->position_x, (int) $effigie->fresh()->position_y])->toBe([8, 4])
        ->and(advPv($ctx['albrecht']))->toBe(8);
});

it('LE FIL ET LA TABLE — le tir de tourelle se dit « fait feu », et la scène porte « Tourelle »', function () {
    $ctx = advScene();
    advPoser($ctx, 'Effigie gardienne', 8, 4);

    advDes([], 1);
    $reponse = advAttendre($ctx);
    $resultat = $reponse->json('resultat');

    $lignes = collect((new JournalCombat)->depuisResultat($resultat, 'Albrecht'))->pluck('texte')->implode(' ¦ ');
    expect($lignes)->toContain('Effigie gardienne fait feu sur Albrecht');

    $scenes = (new SceneDeTable(app(App\Partie\Images\BibliothequeImages::class)))->depuisResultat($resultat, $ctx['albrecht']);
    $tir = collect($scenes)->first(fn ($s) => ($s['genre'] ?? null) === 'attaque' && str_contains((string) ($s['titre'] ?? ''), 'Effigie'));

    expect($tir)->not->toBeNull()
        ->and($tir['sous_titre'])->toContain('Tourelle');
});

// =====================================================================
// 4. L'IMMUNITÉ AUX SORTS — totale (Effigie), partielle (Scion)
// =====================================================================

/** Le héros magicien du contexte, avec les écoles demandées. */
function advMage(array $ecoles): array
{
    $ctx = advScene(herosAttrs: ['classe' => 'magicien']);
    foreach ($ecoles as $ecole) {
        app(MoteurSorts::class)->attacherElement($ctx['albrecht'], $ecole);
    }

    return $ctx;
}

/** Les noms des cibles que le menu offre pour le sort `$nomSort`. */
function advCiblesDuSort(array $ctx, string $nomSort): ?array
{
    $options = app(MoteurSorts::class)->options($ctx['groupe']->fresh(), $ctx['quete']->fresh()->load('carte'), $ctx['albrecht']->fresh());
    $entree = collect(collect($options)->firstWhere('id', 'lancer_sort')['parametres']['sorts'] ?? [])
        ->first(fn ($e) => $e['nom'] === $nomSort);

    return $entree === null ? null : array_map(fn ($c) => $c['nom'], $entree['cibles'] ?? []);
}

it('LE MENU — l\'Effigie « immune to all spell effects » n\'est la cible d\'AUCUN sort, un gobelin voisin si', function () {
    // Deux lignes distinctes : une figure interposée coupe la vue — le gobelin ne doit pas se cacher derrière.
    $ctx = advMage(['feu', 'eau', 'air']);
    advPoser($ctx, 'Effigie gardienne', 5, 5);
    advPoser($ctx, 'Gobelin', 5, 3);

    foreach (['Boule de Feu', 'Sommeil', 'Tempête'] as $sort) {
        $cibles = advCiblesDuSort($ctx, $sort);

        // Avant le correctif : l'Effigie figurait dans la liste de chacun des trois.
        expect($cibles)->toBe(['Gobelin'], "{$sort} : seul le gobelin est une cible légale");
    }

    // Seule l'Effigie en vue : les sorts à cible disparaissent du menu (jamais « cibles: [] »).
    InstanceMonstre::where('quete_id', $ctx['quete']->id)->whereHas('monstre', fn ($q) => $q->where('nom_base', 'Gobelin'))->update(['etat' => 'vaincu']);
    expect(advCiblesDuSort($ctx, 'Boule de Feu'))->toBeNull();
});

it('LE MENU — le Scion est immunisé au sommeil et au tour perdu, pas au feu', function () {
    $ctx = advMage(['feu', 'eau', 'air']);
    advPoser($ctx, 'Scion Fleur-Pourrie', 5, 5);
    advPoser($ctx, 'Gobelin', 5, 3);

    expect(advCiblesDuSort($ctx, 'Sommeil'))->toBe(['Gobelin'], '« immune to spells that would cause it to sleep »')
        ->and(advCiblesDuSort($ctx, 'Tempête'))->toBe(['Gobelin'], '« …or miss its turn »')
        ->and(advCiblesDuSort($ctx, 'Boule de Feu'))->toContain('Scion Fleur-Pourrie', 'Gobelin');
});

it('EN JEU — un sort de ZONE passe sans atteindre l\'Effigie, et le DIT (au lieu de la sauter en silence)', function () {
    $ctx = advScene(herosAttrs: ['classe' => 'elfe']);
    $effigie = advPoser($ctx, 'Effigie gardienne', 5, 4);
    $gobelin = advPoser($ctx, 'Gobelin', 6, 4, ['pv_mind' => 1]);
    $sort = Sort::where('nom', 'Flamme hypnotique')->firstOrFail();

    advDes([], 6); // un 6 dépasse tout Mind : le gobelin est pris

    $resultat = (new ReflectionMethod(App\Partie\ResolveurTour::class, 'sortDeZone'))->invoke(
        app(App\Partie\ResolveurTour::class), $ctx['quete']->fresh()->load('carte'), $sort, $ctx['albrecht'],
    );

    $touches = collect($resultat['touches']);
    $deLEffigie = $touches->firstWhere('instance_id', $effigie->id);

    // Avant : Mind 0 → « sans esprit, rien à hypnotiser » : sauté SANS un mot, ni dans `touches` ni au fil.
    expect($deLEffigie)->not->toBeNull()->and($deLEffigie['immunise'])->toBeTrue()
        ->and($touches->firstWhere('instance_id', $gobelin->id)['type'] ?? null)->toBe('monstre')
        ->and(app(MoteurSorts::class)->monstreA($effigie->fresh(), 'paralyse'))->toBeFalse();

    $ligne = collect((new JournalCombat)->depuisResultat([
        'type' => 'sort', 'sort' => ['nom' => 'Flamme hypnotique'], ...$resultat,
    ], 'Albrecht'))->pluck('texte')->implode(' ¦ ');
    expect($ligne)->toContain('Effigie gardienne est immunisé contre Flamme hypnotique')
        ->and($ligne)->toContain('Gobelin est pris dans le sort');
});

it('EN JEU — le rayon d\'un SORT (Éclair) traverse l\'Effigie sans la blesser ; la technique du Moine, non', function () {
    $ctx = advScene();
    $effigie = advPoser($ctx, 'Effigie gardienne', 5, 4);
    $gobelin = advPoser($ctx, 'Gobelin', 7, 4);
    $etat = $ctx['quete']->etatsPersonnages()->firstOrFail();
    $sort = Sort::where('nom', 'Éclair')->firstOrFail();

    $resultat = (new ReflectionMethod(App\Partie\ResolveurTour::class, 'rayonDeSort'))->invoke(
        app(App\Partie\ResolveurTour::class), $ctx['quete']->fresh()->load('carte'), $ctx['albrecht'], $etat, $sort,
        ['parametres' => ['direction' => 'e']],
    );

    $touches = collect($resultat['touches']);

    // Avant : 2 PV d'Éclair → l'Effigie (Body 2) était foudroyée.
    expect($touches->firstWhere('instance_id', $effigie->id)['immunise'])->toBeTrue()
        ->and($effigie->fresh()->etat)->toBe('actif')
        ->and((int) $effigie->fresh()->pv_body)->toBe(2)
        ->and($touches->firstWhere('instance_id', $gobelin->id)['vaincu'])->toBeTrue('le gobelin, lui, est foudroyé');

    $lignes = collect((new JournalCombat)->depuisResultat(['type' => 'sort', 'sort' => ['nom' => 'Éclair'], ...$resultat], 'Albrecht'))
        ->pluck('texte')->implode(' ¦ ');
    expect($lignes)->toContain('Effigie gardienne est immunisé contre Éclair')
        ->and($lignes)->toContain('Gobelin est foudroyé');

});

it('EN JEU — la technique du Moine (un rayon qui n\'est PAS un sort) blesse l\'Effigie normalement', function () {
    $ctx = advScene();
    $effigie = advPoser($ctx, 'Effigie gardienne', 5, 4);

    (new ReflectionMethod(App\Partie\ResolveurTour::class, 'projeterRayon'))->invoke(
        app(App\Partie\ResolveurTour::class), $ctx['groupe'], $ctx['quete']->fresh()->load('carte'),
        $ctx['quete']->etatsPersonnages()->firstOrFail(), $ctx['albrecht'], 'e', 2, false, 'Esprit ardent',
    );

    expect($effigie->fresh()->etat)->toBe('vaincu');
});

it('EN JEU — la magie du Dread épargne l\'Effigie : la zone de Tempête de feu ne la compte pas', function () {
    $ctx = advScene();
    $lanceur = advPoser($ctx, 'Seigneur Sorcier de Barak Tor', 10, 4);
    $effigie = advPoser($ctx, 'Effigie gardienne', 8, 4);
    $gobelin = advPoser($ctx, 'Gobelin', 9, 4);
    $sort = SortDread::where('nom', 'Tempête de feu')->firstOrFail();

    $touches = (new ReflectionMethod(MoteurDread::class, 'monstresDuSort'))->invoke(
        app(MoteurDread::class), $sort, $ctx['quete']->fresh()->load('carte'), $lanceur,
    );

    // Avant : « all heroes AND monsters in the room » — l'Effigie, « immune to all spell effects », y était.
    expect($touches->pluck('id')->all())->toContain($gobelin->id)->not->toContain($effigie->id);
});

it('EN JEU — le soin et le drain du Dread épargnent aussi l\'Effigie : un soin est un effet de sort comme un autre', function () {
    $ctx = advScene();
    $lanceur = advPoser($ctx, 'Seigneur Sorcier de Barak Tor', 10, 4);
    $effigie = advPoser($ctx, 'Effigie gardienne', 8, 4);
    $effigie->update(['pv_body' => 1]); // blessée : la plus blessée de la salle
    $gobelin = advPoser($ctx, 'Orque', 9, 4);
    $quete = $ctx['quete']->fresh()->load('carte');

    $soin = SortDread::where('nom', 'Apaisement')->firstOrFail();
    $cible = (new ReflectionMethod(MoteurDread::class, 'cibleSoin'))->invoke(app(MoteurDread::class), $soin, $quete, $lanceur);

    // Avant : « the most wounded monster » — l'Effigie, à 1 PV sur 2, recevait le soin.
    expect($cible)->toBeNull('l\'Effigie blessée n\'est pas soignable, et personne d\'autre ne manque de PV');

    $drain = SortDread::where('nom', 'Drain de vie')->firstOrFail();
    $figures = (new ReflectionMethod(MoteurDread::class, 'figuresDuDrain'))->invoke(
        app(MoteurDread::class), $drain, $quete, $lanceur, $quete->etatsPersonnages()->with('personnage')->get(),
    );

    expect($figures['monstres']->pluck('id')->all())->toContain($gobelin->id)->not->toContain($effigie->id);
});

// =====================================================================
// 5. LE DREAD SKULL — apparition à la vue (mécanisme ; aucune ligne de catalogue ne le porte encore)
// =====================================================================

/** Un crâne de TEST (jamais seedé : `MotsClesAdversaires::SANS_DONNEE`) — les stats du livret G1798. */
function advCraneDuTest(): Monstre
{
    return Monstre::create([
        'nom_base' => 'Crâne du Dread (test)', 'deplacement' => 0, 'attaque' => 2, 'defense' => 0, 'pv_body' => 1, 'pv_mind' => 0,
        'tier' => 'base', 'boite' => null, 'cout' => 0, 'capacites' => ['tourelle', 'apparait_a_la_vue'], 'sorts_dread' => [],
    ]);
}

it('EN JEU — le crâne paraît dès qu\'un héros le VOIT pendant sa marche, et tire AUSSITÔT (René)', function () {
    // Un mur de x=4, y=0..7 : la brèche est en bas (y=8). Le crâne est caché derrière, en (7, 8) :
    // depuis le départ (1, 3) la vue est coupée ; en arrivant sur la rangée 8 elle est libre.
    $murs = array_map(fn ($y) => ['x' => 4, 'y' => $y], range(0, 7));
    $ctx = advScene(murs: $murs, positions: ['albrecht' => [1, 3]]);
    advCraneDuTest();
    $crane = advPoser($ctx, 'Crâne du Dread (test)', 7, 8, ['revele' => false]);

    advDes([6], 1); // le d6 de mouvement vaut 6 (10 cases), puis des crânes : le tir porte
    test()->actingAs($ctx['alice'], 'joueur');
    advMenu();
    $reponse = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => ['x' => 1, 'y' => 8]])
        ->assertStatus(202);

    $apparitions = $reponse->json('resultat.apparitions');

    // Avant : rien ne cachait ni ne révélait de figure à la vue — le crâne restait invisible pour toujours.
    expect($apparitions)->toHaveCount(1)
        ->and($apparitions[0]['type'])->toBe('apparition_a_la_vue')
        ->and($apparitions[0]['declencheur']['nom'])->toBe('Albrecht')
        ->and($apparitions[0]['action']['type'])->toBe('attaque_monstre')
        ->and($apparitions[0]['action']['tourelle'])->toBeTrue()
        ->and(count($apparitions[0]['action']['faces_attaque']))->toBe(2, '« the strength of 2 combat dice »')
        ->and($crane->fresh()->revele)->toBeTrue()
        ->and(advPv($ctx['albrecht']))->toBeLessThan(8, 'le tir a porté');

    // Annoncé au fil ET à la table.
    $lignes = collect((new JournalCombat)->depuisResultat($reponse->json('resultat'), 'Albrecht'))->pluck('texte')->implode(' ¦ ');
    expect($lignes)->toContain('Crâne du Dread (test) paraît')->and($lignes)->toContain('fait feu sur Albrecht');

    $scenes = (new SceneDeTable(app(App\Partie\Images\BibliothequeImages::class)))->depuisResultat($reponse->json('resultat'), $ctx['albrecht']);
    expect(collect($scenes)->pluck('titre')->implode(' ¦ '))->toContain('Crâne du Dread (test) paraît');
});

it('EN JEU — un crâne que nul héros ne voit reste caché, et n\'est pas publié', function () {
    $murs = array_map(fn ($y) => ['x' => 4, 'y' => $y], range(0, 7));
    $ctx = advScene(murs: $murs, positions: ['albrecht' => [1, 3]]);
    advCraneDuTest();
    $crane = advPoser($ctx, 'Crâne du Dread (test)', 7, 8, ['revele' => false]);

    advDes([6], 1);
    test()->actingAs($ctx['alice'], 'joueur');
    advMenu();
    $reponse = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => ['x' => 1, 'y' => 1]])
        ->assertStatus(202);

    expect($reponse->json('resultat.apparitions'))->toBeNull()
        ->and($crane->fresh()->revele)->toBeFalse()
        ->and(collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['entites'])->pluck('nom_base')->all())
        ->not->toContain('Crâne du Dread (test)');
});

it('EN JEU — le crâne n\'est PAS révélé comme un dormant à l\'ouverture d\'une salle : il paraît au regard', function () {
    $source = (string) file_get_contents(base_path('app/Partie/ResolveurTour.php'));

    // revelerSalle() écarte les figures `apparait_a_la_vue` — le test en jeu (ci-dessus) prouve qu'elles
    // paraissent, celui-ci que la pose cachée d'un crâne ne se confond pas avec un monstre endormi.
    expect($source)->toContain('MoteurTourelle::apparaitALaVue($i)');

    $ctx = advScene();
    advCraneDuTest();
    $crane = advPoser($ctx, 'Crâne du Dread (test)', 3, 4, ['revele' => false]);

    expect(App\Partie\MoteurTourelle::apparaitALaVue($crane))->toBeTrue()
        ->and(app(App\Partie\MoteurTourelle::class)->apparitionsEnVue($ctx['quete']->fresh()->load('carte'), [['x' => 1, 'y' => 4]]))
        ->toHaveCount(1);
});

// =====================================================================
// 6. DEATHSPINNER — Silencing Snare
// =====================================================================

it('EN JEU — le héros qui finit son tour sous le regard de la Deathspinner subit Canaliser l\'Effroi, SANS action ni usage', function () {
    $ctx = advScene();
    $deathspinner = advPoser($ctx, 'Deathspinner la Reine du Fléau', 8, 4);
    $usages = (int) $deathspinner->usages_dread;

    advDes([], 6); // d6 = 6 → « On 6+ = 2 Body Points »
    $reponse = advAttendre($ctx);

    $filets = $reponse->json('resultat.filets_silencieux');

    // Avant : la capacité n'existait pas — la Deathspinner n'avait aucun sort et le héros ne subissait rien.
    expect($filets)->toHaveCount(2)
        ->and($filets[0]['type'])->toBe('filet_silencieux')
        ->and($filets[0]['sort'])->toBe('Canaliser l\'Effroi')
        ->and($filets[1]['type'])->toBe('sort_dread')
        ->and($filets[1]['sort'])->toBe('Canaliser l\'Effroi')
        ->and($filets[1]['lanceur']['nom'])->toBe('Deathspinner la Reine du Fléau')
        ->and($filets[1]['resultats'][0]['degats'])->toBe(2)
        ->and((int) $deathspinner->fresh()->usages_dread)->toBe($usages, '« at will » : le budget n\'est pas entamé');

    // Annoncé au fil (annonce, sort) et à la table (scène du sort).
    $lignes = collect((new JournalCombat)->depuisResultat($reponse->json('resultat'), 'Albrecht'))->pluck('texte')->implode(' ¦ ');
    expect($lignes)->toContain('Deathspinner la Reine du Fléau tisse son filet : Albrecht finit son tour sous son regard')
        ->and($lignes)->toContain('sans action');
});

it('EN JEU — pas de filet avant la fin du tour : un simple déplacement ne le déclenche pas', function () {
    $ctx = advScene();
    advPoser($ctx, 'Deathspinner la Reine du Fléau', 8, 4);

    advDes([4], 6);
    test()->actingAs($ctx['alice'], 'joueur');
    advMenu();
    $reponse = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => ['x' => 2, 'y' => 4]])
        ->assertStatus(202);

    expect($reponse->json('resultat.filets_silencieux'))->toBeNull()
        ->and(advPv($ctx['albrecht']))->toBe(8);
});

it('EN JEU — « she cannot use this ability if she would not be able to take her action » : Tempête, sommeil, paralysie', function (string $condition) {
    $ctx = advScene();
    $deathspinner = advPoser($ctx, 'Deathspinner la Reine du Fléau', 8, 4);
    app(MoteurSorts::class)->poserConditionMonstre($deathspinner, $condition);

    advDes([], 6);
    $reponse = advAttendre($ctx);

    expect($reponse->json('resultat.filets_silencieux'))->toBeNull("{$condition} : aucun filet")
        ->and(advPv($ctx['albrecht']))->toBe(8, "{$condition} : aucun dégât");
})->with(['saute_tour', 'endormi', 'paralyse']);

it('EN JEU — hors de sa ligne de vue (un mur), aucun filet', function () {
    $murs = array_map(fn ($y) => ['x' => 4, 'y' => $y], range(0, 7));
    $ctx = advScene(murs: $murs);
    advPoser($ctx, 'Deathspinner la Reine du Fléau', 8, 4);

    advDes([], 6);
    $reponse = advAttendre($ctx);

    expect($reponse->json('resultat.filets_silencieux'))->toBeNull();
});

// =====================================================================
// 7. GOLEM CŒUR-DE-FORGE — Breath of the Construct
// =====================================================================

it('EN JEU — le Souffle du Construit : 2 PV à toute la salle, réduits de 1 par 5 ou 6 sur deux dés rouges, UNE fois', function () {
    $ctx = advScene(2);
    $golem = advPoser($ctx, 'Golem Cœur-de-Forge', 12, 4);

    // Les deux premiers dés sont les d6 de MOUVEMENT que chaque menu lance à l'ouverture du tour (un par héros).
    // Puis le souffle — Albrecht : deux 6 → tout est annulé ; Bertrand : 1 et 2 → les 2 PV passent.
    advDes([4, 4, 5, 6, 1, 2], 4);
    advAttendre($ctx, 'alice');
    $reponse = advAttendre($ctx, 'bob');

    $souffle = collect(advActionsMonstres($reponse))->firstWhere('type', 'souffle_du_construit');

    // Avant : le Golem avançait vers le héros le plus proche et frappait — aucun souffle de salle.
    expect($souffle)->not->toBeNull()
        ->and($souffle['resultats'])->toHaveCount(2)
        ->and($souffle['resultats'][0]['des_rouges'])->toBe([5, 6])
        ->and($souffle['resultats'][0]['degats'])->toBe(0)
        ->and($souffle['resultats'][0]['degats_annules'])->toBe(2)
        ->and($souffle['resultats'][1]['des_rouges'])->toBe([1, 2])
        ->and($souffle['resultats'][1]['degats'])->toBe(2)
        ->and(advPv($ctx['albrecht']))->toBe(8)
        ->and(advPv($ctx['bertrand']))->toBe(6)
        // Une ACTION : le Golem n'a pas, en plus, avancé ni frappé ce tour-là.
        ->and(collect(advActionsMonstres($reponse))->firstWhere('type', 'attaque_monstre'))->toBeNull()
        ->and([(int) $golem->fresh()->position_x, (int) $golem->fresh()->position_y])->toBe([12, 4])
        // Dépensé pour la quête, dans une COLONNE (donc dans le snapshot), jamais en cache.
        ->and((array) $golem->fresh()->capacites_reactives_utilisees)->toContain('souffle_du_construit');

    // « Once » : le tour suivant, plus de souffle — le Golem se bat comme n'importe qui.
    advDes([], 4);
    advAttendre($ctx, 'alice');
    $suite = advAttendre($ctx, 'bob');
    expect(collect(advActionsMonstres($suite))->firstWhere('type', 'souffle_du_construit'))->toBeNull();

    // Annoncé au fil et à la table : un jet de dés rouges par héros pris.
    $lignes = collect((new JournalCombat)->depuisResultat($reponse->json('resultat'), 'Bertrand'))->pluck('texte')->implode(' ¦ ');
    expect($lignes)->toContain('exhale son Souffle du Construit')
        ->and($lignes)->toContain('Albrecht résiste au souffle')
        ->and($lignes)->toContain('Bertrand encaisse −2 PV');

    $scenes = (new SceneDeTable(app(App\Partie\Images\BibliothequeImages::class)))->depuisResultat($reponse->json('resultat'), $ctx['bertrand']);
    $scene = collect($scenes)->firstWhere('titre', 'Golem Cœur-de-Forge — Souffle du Construit');
    expect($scene)->not->toBeNull()->and($scene['objets'])->toHaveCount(2);
});

it('EN JEU — un groupe réduit à un héros souffle quand même sur lui ; jamais sur un seul quand d\'autres tiennent debout ailleurs', function () {
    // Un seul héros debout : le souffle le prend.
    $ctx = advScene();
    advPoser($ctx, 'Golem Cœur-de-Forge', 12, 4);
    advDes([4, 1, 1], 4); // le d6 de mouvement du menu, puis les deux dés rouges
    $souffle = collect(advActionsMonstres(advAttendre($ctx)))->firstWhere('type', 'souffle_du_construit');
    expect($souffle['resultats'])->toHaveCount(1)->and(advPv($ctx['albrecht']))->toBe(6);
});

it('EN JEU — le souffle ne gaspille pas son unique usage sur UN héros quand un autre tient dans une AUTRE salle', function () {
    // Deux salles côte à côte : Bertrand est dans l'autre. Le Golem n'atteindrait qu'Albrecht → il se bat.
    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 8, 'hauteur' => 9, 'theme' => 'generique', 'mediane_x' => 4, 'mediane_y' => 4],
        ['x' => 8, 'y' => 0, 'largeur' => 8, 'hauteur' => 9, 'theme' => 'generique', 'mediane_x' => 12, 'mediane_y' => 4],
    ];
    $ctx = advScene(2, salles: $salles, positions: ['albrecht' => [10, 4], 'bertrand' => [1, 4]]);
    $golem = advPoser($ctx, 'Golem Cœur-de-Forge', 13, 4);

    advDes([], 4);
    advAttendre($ctx, 'alice');
    $reponse = advAttendre($ctx, 'bob');

    expect(collect(advActionsMonstres($reponse))->firstWhere('type', 'souffle_du_construit'))->toBeNull()
        ->and((array) $golem->fresh()->capacites_reactives_utilisees)->not->toContain('souffle_du_construit');
});

// =====================================================================
// 8. SCION FLEUR-POURRIE — balayage, Vrilles, sacrifice
// =====================================================================

it('EN JEU — le balayage du Scion atteint les héros ADJACENTS ET EN DIAGONALE (« adjacent and diagonal to it »)', function () {
    $ctx = advScene(2, positions: ['albrecht' => [4, 4], 'bertrand' => [3, 4]]);
    advPoser($ctx, 'Scion Fleur-Pourrie', 3, 3);

    advDes([], 1); // des crânes partout
    advAttendre($ctx, 'alice');
    $reponse = advAttendre($ctx, 'bob');

    $balayage = collect(advActionsMonstres($reponse))->firstWhere('type', 'frappe_de_zone');
    $touches = collect($balayage['resultats'] ?? [])->pluck('cible.nom')->sort()->values()->all();

    // Avant : `MoteurDread::frappeDeZone()` refaisait sa propre adjacence, ORTHOGONALE — Albrecht, en
    // diagonale, n'était jamais touché alors que le déclencheur (ResolveurTour) l'avait compté.
    expect($touches)->toBe(['Albrecht', 'Bertrand'])
        ->and(collect(advActionsMonstres($reponse))->firstWhere('type', 'sort_dread'))->toBeNull('il frappe d\'abord, il ne lance pas');
});

it('EN JEU — sans personne à sa portée, le Scion lance Canaliser l\'Effroi à volonté PUIS pose une Vrille, publiée à la table', function () {
    $ctx = advScene();
    $scion = advPoser($ctx, 'Scion Fleur-Pourrie', 8, 4);
    $usages = (int) $scion->usages_dread;

    advDes([], 4);
    $reponse = advAttendre($ctx);
    $actions = advActionsMonstres($reponse);

    $sort = collect($actions)->firstWhere('type', 'sort_dread');
    $vrille = collect($actions)->firstWhere('type', 'vrille_posee');

    // Avant : le Scion n'avait ni sort ni Vrille ; Move 0 → « avançait de 0 case ».
    expect($sort['sort'])->toBe('Canaliser l\'Effroi')
        ->and((int) $scion->fresh()->usages_dread)->toBe($usages, '« at will »')
        ->and($vrille)->not->toBeNull()
        // La case libre la plus PROCHE : un pas au nord (tchebychev 1, coût 1, ligne la plus haute).
        ->and($vrille['vrille'])->toMatchArray(['nom' => 'Vrille', 'x' => 8, 'y' => 3])
        ->and(array_search($sort, $actions, true))->toBeLessThan(array_search($vrille, $actions, true), 'à la FIN de son tour');

    // La Vrille est une créature du plateau : révélée, publiée, mais elle ne joue qu'au round suivant.
    $instance = InstanceMonstre::find($vrille['vrille']['instance_id']);
    expect($instance->revele)->toBeTrue()
        ->and($instance->etat)->toBe('actif')
        ->and((int) $instance->etatDread('maitre'))->toBe((int) $scion->id);

    $entites = collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['entites']);
    $publiee = $entites->first(fn ($e) => ($e['nom_base'] ?? null) === 'Vrille');
    expect($publiee)->not->toBeNull()->and([$publiee['x'], $publiee['y']])->toBe([8, 3])
        ->and($publiee['des_attaque'])->toBe(3)->and($publiee['pv_body'])->toBe(2);

    // Annoncée au fil et à la table.
    $lignes = collect((new JournalCombat)->depuisResultat($reponse->json('resultat'), 'Albrecht'))->pluck('texte')->implode(' ¦ ');
    expect($lignes)->toContain('Scion Fleur-Pourrie fait jaillir une Vrille');

    $scenes = (new SceneDeTable(app(App\Partie\Images\BibliothequeImages::class)))->depuisResultat($reponse->json('resultat'), $ctx['albrecht']);
    expect(collect($scenes)->pluck('titre')->all())->toContain('Une Vrille jaillit');

    // Une Vrille de plus à chaque fin de tour.
    advAttendre($ctx);
    expect($ctx['quete']->instancesMonstres()->whereHas('monstre', fn ($q) => $q->where('nom_base', 'Vrille'))->count())->toBeGreaterThanOrEqual(2);
});

it('EN JEU — la Vrille se pose sur la case libre la plus proche : jamais sur une figure, sur un mur ni de l\'autre côté d\'une cloison', function () {
    $ctx = advScene(positions: ['albrecht' => [8, 3]]);
    $scion = advPoser($ctx, 'Scion Fleur-Pourrie', 8, 4);
    advPoser($ctx, 'Gobelin', 8, 5);
    advPoser($ctx, 'Gobelin', 7, 4);
    advPoser($ctx, 'Gobelin', 9, 4);

    // Les quatre voisines orthogonales sont prises (héros au nord, trois gobelins) : une diagonale suit.
    $vrille = app(App\Partie\AdversairesDelthrak::class)->vrilleEnFinDeTour($ctx['groupe'], $ctx['quete']->fresh()->load('carte'), $scion);

    expect($vrille['vrille'])->not->toBeNull()
        ->and(max(abs($vrille['vrille']['x'] - 8), abs($vrille['vrille']['y'] - 4)))->toBe(1)
        ->and(in_array([$vrille['vrille']['x'], $vrille['vrille']['y']], [[8, 3], [8, 5], [7, 4], [9, 4]], true))->toBeFalse('une case occupée');
});

it('EN JEU — au coup fatal, le Scion sacrifie une Vrille et reste à 1 PV (par la vraie route d\'attaque)', function () {
    $ctx = advScene(positions: ['albrecht' => [2, 4]]);
    $scion = advPoser($ctx, 'Scion Fleur-Pourrie', 3, 4);
    $scion->update(['pv_body' => 2, 'pv_body_max' => 2]);
    $vrille = advPoser($ctx, 'Vrille', 3, 6);
    $vrille->poserEtatDread('maitre', (int) $scion->id);

    // Trois crânes d'attaque, puis 4 (aucun bouclier noir : le Scion ne pare pas).
    desFiges([4, 1, 1, 1, ...array_fill(0, 200, 4)]); // le d6 de mouvement du menu, puis 3 crânes
    test()->actingAs($ctx['alice'], 'joueur');
    advMenu();
    $reponse = test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $scion->id],
    ])->assertStatus(202);

    // Avant : 3 dégâts sur 2 PV, aucune phase, aucune réaction → « vaincu ».
    expect($scion->fresh()->etat)->toBe('actif')
        ->and((int) $scion->fresh()->pv_body)->toBe(1)
        ->and($vrille->fresh()->etat)->toBe('vaincu')
        ->and($reponse->json('resultat.reaction_monstre'))->toBe('sacrifice_de_vrille');

    $lignes = collect((new JournalCombat)->depuisResultat($reponse->json('resultat'), 'Albrecht'))->pluck('texte')->implode(' ¦ ');
    expect($lignes)->toContain('sacrifie une de ses Vrilles et tient debout à 1 PV');

    $scenes = (new SceneDeTable(app(App\Partie\Images\BibliothequeImages::class)))->depuisResultat($reponse->json('resultat'), $ctx['albrecht']);
    expect(collect($scenes)->pluck('issue.libelle')->implode(' ¦ '))->toContain('sacrifie une Vrille et tient debout à 1 PV');
});

it('le sacrifice se paie à CHAQUE coup fatal tant qu\'il reste une Vrille — et sans Vrille, le Scion meurt', function () {
    $ctx = advScene();
    $scion = advPoser($ctx, 'Scion Fleur-Pourrie', 8, 4);
    $a = advPoser($ctx, 'Vrille', 8, 6);
    $a->poserEtatDread('maitre', (int) $scion->id);
    $b = advPoser($ctx, 'Vrille', 9, 6);
    $b->poserEtatDread('maitre', (int) $scion->id);
    // Une Vrille d'un AUTRE maître n'est pas la sienne : elle ne paie rien.
    $etrangere = advPoser($ctx, 'Vrille', 10, 6);
    $etrangere->poserEtatDread('maitre', 999);

    $degats = app(MoteurDegats::class);

    $r1 = $degats->infligerAMonstre($scion->fresh()->load('monstre'), 20, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r1['vaincu'])->toBeFalse()->and($r1['reaction'])->toBe('sacrifice_de_vrille')
        ->and($r1['sacrifice']['instance_id'])->toBe($a->id, 'la plus ANCIENNE part la première')
        ->and((int) $scion->fresh()->pv_body)->toBe(1);

    $r2 = $degats->infligerAMonstre($scion->fresh()->load('monstre'), 1, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r2['vaincu'])->toBeFalse()->and($r2['sacrifice']['instance_id'])->toBe($b->id)
        ->and((int) $scion->fresh()->pv_body)->toBe(1);

    $r3 = $degats->infligerAMonstre($scion->fresh()->load('monstre'), 1, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r3['vaincu'])->toBeTrue()->and($r3['reaction'])->toBeNull()
        ->and($scion->fresh()->etat)->toBe('vaincu')
        ->and($etrangere->fresh()->etat)->toBe('actif');
});

it('les Vrilles et l\'état de sacrifice survivent à la reprise : l\'id du maître est conservé par le snapshot', function () {
    $ctx = advScene();
    $scion = advPoser($ctx, 'Scion Fleur-Pourrie', 8, 4);
    $vrille = advPoser($ctx, 'Vrille', 8, 6);
    $vrille->poserEtatDread('maitre', (int) $scion->id);

    $source = (string) file_get_contents(base_path('app/Partie/Sauvegarde.php'));
    expect($source)->toContain("'habillage' => \$i->habillage")->and($source)->toContain("'id' => \$instance['id']");
});
