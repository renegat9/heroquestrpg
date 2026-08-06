<?php

declare(strict_types=1);

use App\Engine\MotsClesSort;
use App\Models\Objet;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;

/**
 * Les sorts et parchemins sont-ils FONCTIONNELS ?
 *
 * Même garde-fou que `ObjetsFonctionnelsTest` : chaque clé de `sorts.effet` est
 * une promesse faite au joueur. On fige l'inventaire en deux ensembles — celles
 * qu'un moteur lit, et celles qu'on sait INERTES — pour qu'ajouter une clé au
 * seeder force une décision : lui écrire un lecteur, ou la déclarer décorative.
 */
beforeEach(function () {
    Illuminate\Support\Facades\Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([SortSeeder::class, ObjetSeeder::class,
        Database\Seeders\MonstreSeeder::class, Database\Seeders\TuileSeeder::class,
        Database\Seeders\GabaritQueteSeeder::class, Database\Seeders\PiegeSeeder::class,
        Database\Seeders\ConditionSeeder::class]);
});

/** Clés lues par le moteur (audit du 2026-08-06, fichier applicatif en regard). */
const CLES_SORT_ACTIVES = [
    'des_degats',            // ResolveurTour::sortDegats()
    'portee',                // ciblage à distance
    'soin_pv_body',          // sort utilitaire de soin
    'bonus_des_attaque',     // MoteurSorts::bonusDes()
    'bonus_des_defense',     // idem
    'condition_appliquee',   // appliquerConditionCatalogue()
    'duree',                 // DureeEffet / expirerBuffs()
    'deplacement_multiplie', // multiplicateurDeplacement()
    'franchit_mur',          // ResolveurTour, franchissement
    'saute_tour',            // condition posée sur le monstre (Tempête)
    'cible',                 // MoteurSorts::ciblesLegales()
    'cout',                  // ResolveurTour::appliquerCoutSort()
    'defense_applicable',    // ResolveurTour::sortDegats(), pilote le jet de défense
    'resistance',            // ResolveurTour::sortMental(), pilote la résistance
];

/**
 * Clés SANS lecteur, tolérées en connaissance de cause.
 */
const CLES_SORT_INERTES = [
    'fin', // descriptif (le réveil est câblé dans reveillerHeros)
];

it('n\'introduit aucune clé d\'effet inconnue dans le catalogue de sorts', function () {
    $cles = collect(Sort::all())
        ->flatMap(fn (Sort $s) => array_keys((array) $s->effet))
        ->unique()->sort()->values()->all();

    $connues = collect(CLES_SORT_ACTIVES)->merge(CLES_SORT_INERTES)->all();
    $inconnues = array_values(array_diff($cles, $connues));

    expect($inconnues)->toBe([], implode(', ', $inconnues)
        .' — clé(s) de sort sans lecteur connu. Écris-lui un lecteur, ou ajoute-la '
        .'à CLES_SORT_INERTES en connaissance de cause.');
});

it('donne à chaque sort un effet mécanique que le moteur sait appliquer', function () {
    // Un sort qui ne fait ni dégâts, ni soin, ni condition, ni bonus, ni
    // déplacement est un sort qu'on lance pour rien.
    $agissantes = ['des_degats', 'soin_pv_body', 'condition_appliquee', 'bonus_des_attaque',
        'bonus_des_defense', 'deplacement_multiplie', 'franchit_mur', 'saute_tour'];

    foreach (Sort::all() as $sort) {
        expect(array_intersect($agissantes, array_keys((array) $sort->effet)))
            ->not->toBeEmpty("{$sort->nom} : aucun effet mécanique applicable.");
    }
});

it('n\'expose de sorts qu\'aux classes lanceuses', function () {
    expect(MoteurSorts::LANCEURS)->toBe(['magicien', 'elfe'])
        ->and(MoteurSorts::LANCEURS)->not->toContain('barbare')
        ->and(MoteurSorts::LANCEURS)->not->toContain('nain');
});

it('n\'emploie que des cibles, coûts et résistances déclarés', function () {
    foreach (Sort::all() as $sort) {
        $effet = (array) $sort->effet;

        // ⚠ toContain() de Pest accepte PLUSIEURS valeurs : y glisser un message
        // le transformerait en second élément à chercher. On passe donc par
        // in_array + message sur toBeTrue().
        foreach ([['cible', MotsClesSort::CIBLES], ['cout', MotsClesSort::COUTS],
            ['resistance', MotsClesSort::RESISTANCES]] as [$cle, $vocabulaire]) {
            if (! isset($effet[$cle])) {
                continue;
            }

            expect(in_array($effet[$cle], $vocabulaire, true))
                ->toBeTrue("{$sort->nom} : {$cle} « {$effet[$cle]} » hors vocabulaire.");
        }
    }
});

it('recense explicitement les mots dont la mécanique n\'existe pas', function () {
    // Une dette déclarée est une dette qu'on peut retrouver. Ce test tombe le
    // jour où l'un de ces mots est implémenté : c'est le rappel de le retirer
    // de NON_IMPLEMENTES et de le documenter comme acquis.
    expect(MotsClesSort::NON_IMPLEMENTES)->toHaveKeys(['monstres_zone', 'invocation_ephemere'])
        ->and(MotsClesSort::estNonImplemente('monstres_zone'))->toBeTrue()
        ->and(MotsClesSort::estNonImplemente('cout'))->toBeFalse();

    // …mais AUCUN sort ne doit plus s'appuyer dessus. Tempête portait
    // `monstres_zone` alors que le texte officiel dit « un monstre choisi »
    // (Kellar's Keep p. 15) : la dette était en réalité une erreur de donnée.
    foreach (Sort::all() as $sort) {
        foreach ((array) $sort->effet as $cle => $valeur) {
            expect(MotsClesSort::estNonImplemente($cle))
                ->toBeFalse("{$sort->nom} : s'appuie sur « {$cle} », non implémenté.");

            if ($cle === 'cible') {
                expect(MotsClesSort::estNonImplemente((string) $valeur))
                    ->toBeFalse("{$sort->nom} : cible « {$valeur} », non implémentée.");
            }
        }
    }
});

it('fait payer son déplacement à Traverser la Pierre (le sort était gratuit)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'elfe']);

    $sort = Sort::where('nom', 'Traverser la Pierre')->firstOrFail();
    expect(($sort->effet)['cout'])->toBe(MotsClesSort::COUT_DEPLACEMENT_DU_TOUR);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = App\Models\Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = App\Models\EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $hero->id)->firstOrFail();

    // Le héros a des points de déplacement en réserve…
    $etat->update(['deplacement_tour' => 8, 'deplacement_restant' => 8, 'a_deplace' => false]);

    // …que le coût du sort doit intégralement consommer.
    app(App\Partie\ResolveurTour::class);
    $reflet = new ReflectionMethod(App\Partie\ResolveurTour::class, 'appliquerCoutSort');
    $reflet->invoke(app(App\Partie\ResolveurTour::class), (array) $sort->effet, $etat);

    $etat->refresh();
    expect((int) $etat->deplacement_restant)->toBe(0)
        ->and((bool) $etat->a_deplace)->toBeTrue();
});

it('donne un parchemin par sort, chacun résoluble et de difficulté synchronisée', function () {
    $sorts = Sort::all();
    $parchemins = Objet::where('categorie', 'parchemin')->get();

    expect($parchemins)->toHaveCount($sorts->count());

    foreach ($parchemins as $parchemin) {
        $effet = (array) $parchemin->effet;
        $sort = Sort::find($effet['sort_id'] ?? 0);

        // `sort_id` est ce que lit resoudreParchemin() : sans lui, le parchemin
        // est irrésoluble et l'action est refusée en 422.
        expect($sort)->not->toBeNull("{$parchemin->nom} : sort_id introuvable.")
            ->and((int) ($effet['difficulte_non_lanceur'] ?? -1))
            ->toBe((int) $sort->difficulte_parchemin, "{$parchemin->nom} : difficulté affichée ≠ lancée.")
            ->and((int) $sort->difficulte_parchemin)->toBeGreaterThan(0)
            ->and((int) $sort->difficulte_parchemin)->toBeLessThanOrEqual(3);
    }
});
