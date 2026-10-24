<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\MoteurPortes;
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
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * PORTE À CONDITION (Kellar's Keep, 2026-10-23) — la porte verrouillée (F4543 p. 19, note A : deux dés rouges, « less
 * than their starting number of Body Points ») et la porte de l'Est (F4543 p. 25, note D : « equal to or less than their
 * current number of Mind Points », le Nain ne lance qu'un dé). Éprouvé EN JEU : le menu, le choix, le jet, la porte
 * écrite dans la carte de la base, et le tour qui se ferme sur un échec. Les dés sont injectés (`desFiges`).
 *
 * Les deux premiers d6 de la file sont les deux dés rouges (le menu n'en tire aucun : seul le déplacement en tire).
 * Les helpers portent le préfixe `pc`.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
        TerrainSeeder::class]);
});

/** Le donjon de la scène : salle 0 (x 1-4, y 3-5), couloir (5,4), salle 1 (x 6-9, y 3-5). */
function pcCases(): array
{
    $cases = array_fill(0, 9, array_fill(0, 16, 'm'));

    for ($y = 3; $y <= 5; $y++) {
        for ($x = 1; $x <= 4; $x++) {
            $cases[$y][$x] = 's';
        }

        for ($x = 6; $x <= 9; $x++) {
            $cases[$y][$x] = 's';
        }
    }

    $cases[4][5] = 's';

    return $cases;
}

/** Une porte à condition telle que `AssembleurCarte::marquerPortesDeCondition()` la pose : `verrou` = la condition copiée. */
function pcPorte(string $nom, int $x, int $y, string $cote = 'e'): array
{
    return [
        'x' => $x, 'y' => $y, 'cote' => $cote, 'etat' => 'fermee', 'jonction' => 1,
        'verrou' => ['type' => MoteurPortes::VERROU_CONDITION, 'nom' => $nom, ...MoteurPortes::CONDITIONS[$nom]],
    ];
}

/**
 * @param  list<array<string, mixed>>  $portes
 * @param  array<string, mixed>  $attributs
 * @param  array{0: int, 1: int}  $position
 * @param  list<int>  $decouvertes  salles déjà révélées (index)
 * @return array{alice: JoueurAuthentifiable, groupe: Groupe, quete: Quete, heros: Personnage}
 */
function pcScene(array $portes, array $attributs = [], array $position = [3, 4], array $decouvertes = [0]): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, $attributs + ['deplacement_base' => 12]);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — porte à condition',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
        'salles_decouvertes' => $decouvertes,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => pcCases(),
            'salles' => [
                ['x' => 1, 'y' => 3, 'largeur' => 4, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 2, 'mediane_y' => 4],
                ['x' => 6, 'y' => 3, 'largeur' => 4, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 7, 'mediane_y' => 4],
            ],
            'portes' => $portes, 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => [], 'dangers' => [],
            'spawn_heros' => [['x' => 2, 'y' => 4]], 'spawn_monstres' => [],
        ],
    ]);

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id,
        'position_x' => $position[0], 'position_y' => $position[1],
    ]);

    return ['alice' => $alice, 'groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'heros' => $heros];
}

/** Les options du menu courant (liste complète, pour lire un libellé). */
function pcOptions(array $ctx): array
{
    return test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options') ?? [];
}

/** Forcer la porte en (x, y), côté `$cote`, par POST /choix. */
function pcForcer(array $ctx, int $x, int $y, string $cote = 'e')
{
    return test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => "forcer_porte_condition_{$x}_{$y}_{$cote}",
        'parametres' => ['porte' => ['x' => $x, 'y' => $y, 'cote' => $cote]],
    ]);
}

/** La porte telle qu'elle est en BASE (état durable de la carte). */
function pcPorteEnBase(array $ctx, int $index = 0): array
{
    return Carte::query()->where('quete_id', $ctx['quete']->id)->firstOrFail()->grille['portes'][$index];
}

/**
 * Le nombre de tours OUVERTS dans ce groupe : un `nouveau_tour` est journalisé à chaque fois que le tour des héros se referme
 * et qu'un nouveau s'ouvre. Un jet raté ferme le tour (une manche de plus) ; un jet réussi ne le ferme pas.
 */
function pcNouveauxTours(array $ctx): int
{
    return Evenement::query()
        ->where('groupe_id', $ctx['groupe']->id)
        ->where('type', 'systeme')
        ->get()
        ->filter(fn (Evenement $e) => ($e->payload['action'] ?? null) === 'nouveau_tour')
        ->count();
}

/** L'état de quête du héros de la scène (créneaux, position). */
function pcEtatHeros(array $ctx): EtatPersonnageQuete
{
    return EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->firstOrFail();
}

/** Le jet journalisé de la porte (le dernier `forcer_porte_condition` du groupe). */
function pcJet(array $ctx): ?array
{
    $evenement = Evenement::query()
        ->where('groupe_id', $ctx['groupe']->id)
        ->get()
        ->filter(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'forcer_porte_condition')
        ->last();

    return $evenement?->payload;
}

// =====================================================================
// LE MENU — offerte au contact, avec son seuil publié, jamais à distance
// =====================================================================

it('la porte verrouillée est offerte au contact, avec son seuil CHIFFRÉ publié par le menu, et jamais à distance', function () {
    $ctx = pcScene([pcPorte('porte_verrouillee', 4, 4)]);
    desFiges(array_fill(0, 40, 6));

    $ids = array_column(pcOptions($ctx), 'id');
    $libelle = collect(pcOptions($ctx))->firstWhere('id', 'forcer_porte_condition_4_4_e')['libelle'] ?? '';

    expect($ids)->toContain('forcer_porte_condition_4_4_e')
        ->and($libelle)->toContain('2 dés rouges')
        ->and($libelle)->toContain('(8)'); // le Corps de départ du Barbare de la scène
});

it('une porte à condition hors de portée (case non adjacente) n\'est pas offerte', function () {
    $ctx = pcScene([pcPorte('porte_verrouillee', 4, 4)], [], [2, 4]);
    desFiges(array_fill(0, 40, 6));

    expect(array_column(pcOptions($ctx), 'id'))->not->toContain('forcer_porte_condition_4_4_e');
});

// =====================================================================
// LE CORPS — strictement sous le Corps de DÉPART (F4543 p. 19, « less than »)
// =====================================================================

it('la porte verrouillée s\'ouvre quand le total est STRICTEMENT inférieur au Corps de départ (7 < 8)', function () {
    $ctx = pcScene([pcPorte('porte_verrouillee', 4, 4)]);
    desFiges([3, 4]); // les deux dés rouges : 3 + 4

    pcOptions($ctx);
    $avant = pcNouveauxTours($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(pcPorteEnBase($ctx)['etat'])->toBe('ouverte')
        ->and(pcNouveauxTours($ctx))->toBe($avant) // ouvrir est une interaction libre : le tour ne se referme pas
        ->and(pcJet($ctx)['total'])->toBe(7)
        ->and(pcJet($ctx)['faces'])->toBe([3, 4])
        ->and(pcJet($ctx)['reussi'])->toBeTrue();
});

it('un total ÉGAL au Corps de départ n\'ouvre PAS la porte verrouillée, et le tour du héros se ferme', function () {
    $ctx = pcScene([pcPorte('porte_verrouillee', 4, 4)]);
    desFiges([4, 4]); // total 8 : pas strictement sous 8

    pcOptions($ctx);
    $avant = pcNouveauxTours($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(pcPorteEnBase($ctx)['etat'])->toBe('fermee')
        ->and(pcJet($ctx)['reussi'])->toBeFalse()
        // « If they fail to open the door, their turn ends. » : le tour se referme, une manche de plus s'ouvre.
        ->and(pcNouveauxTours($ctx))->toBe($avant + 1);
});

it('la valeur du Corps est le Corps de DÉPART (le maximum), pas les PV blessés du moment', function () {
    $ctx = pcScene([pcPorte('porte_verrouillee', 4, 4)], ['pv_body' => 2, 'pv_body_max' => 8]);
    desFiges([3, 4]); // total 7 : ouvre contre 8 (max), n'aurait pas ouvert contre 2 (blessé)

    pcOptions($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(pcPorteEnBase($ctx)['etat'])->toBe('ouverte')
        ->and(pcJet($ctx)['valeur'])->toBe(8);
});

// =====================================================================
// L'ESPRIT — au plus égal à l'Esprit ACTUEL (F4543 p. 25, « equal to or less than »)
// =====================================================================

it('la porte de l\'Est s\'ouvre quand le total est ÉGAL à l\'Esprit actuel (inclusif : 2 ≤ 2)', function () {
    $ctx = pcScene([pcPorte('porte_de_l_est', 4, 4)]);
    desFiges([1, 1]); // total 2, Esprit actuel 2

    pcOptions($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(pcPorteEnBase($ctx)['etat'])->toBe('ouverte')
        ->and(pcJet($ctx)['comparaison'])->toBe('inclusif');
});

it('la porte de l\'Est reste fermée au-dessus de l\'Esprit actuel (3 > 2) et le tour se ferme', function () {
    $ctx = pcScene([pcPorte('porte_de_l_est', 4, 4)]);
    desFiges([1, 2]);

    pcOptions($ctx);
    $avant = pcNouveauxTours($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(pcPorteEnBase($ctx)['etat'])->toBe('fermee')
        ->and(pcNouveauxTours($ctx))->toBe($avant + 1);
});

it('l\'Esprit compté est l\'Esprit ACTUEL (1), pas le maximum (6) : 3 n\'ouvre pas', function () {
    $ctx = pcScene([pcPorte('porte_de_l_est', 4, 4)], ['pv_mind' => 1, 'pv_mind_max' => 6]);
    desFiges([1, 2]); // 3 ≤ 6 ouvrirait ; 3 ≤ 1 n'ouvre pas

    pcOptions($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(pcPorteEnBase($ctx)['etat'])->toBe('fermee')
        ->and(pcJet($ctx)['valeur'])->toBe(1);
});

it('le NAIN ne lance qu\'UN dé contre l\'Esprit (F4543 p. 25) : 2 ≤ 2 ouvre, et le jet est d\'un seul dé', function () {
    $ctx = pcScene([pcPorte('porte_de_l_est', 4, 4)], ['classe' => 'nain']);
    desFiges([2]);

    pcOptions($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(pcPorteEnBase($ctx)['etat'])->toBe('ouverte')
        ->and(pcJet($ctx)['faces'])->toHaveCount(1);
});

it('la porte de l\'Est : un autre héros lance DEUX dés, comme la porte verrouillée', function () {
    $ctx = pcScene([pcPorte('porte_de_l_est', 4, 4)]);
    desFiges([1, 1]);

    pcOptions($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(pcJet($ctx)['faces'])->toHaveCount(2);
});

// =====================================================================
// LA SALLE DERRIÈRE — une porte ouverte révèle la salle qu'elle ouvre
// =====================================================================

it('ouvrir la porte révèle la salle derrière elle, sans attendre qu\'un héros y entre', function () {
    // Le héros est sur le couloir (5,4) ; la porte est sur son bord EST, donnant sur la salle 1 (x 6-9).
    $ctx = pcScene([pcPorte('porte_verrouillee', 5, 4)], [], [5, 4], [0]);
    desFiges([1, 2]);

    pcOptions($ctx);
    pcForcer($ctx, 5, 4)->assertSuccessful();

    expect(pcPorteEnBase($ctx)['etat'])->toBe('ouverte')
        ->and(Quete::find($ctx['quete']->id)->salles_decouvertes)->toContain(1);
});

it('un jet raté se RETENTE au tour suivant : la porte reste fermée, et l\'option est offerte à la manche suivante', function () {
    $ctx = pcScene([pcPorte('porte_verrouillee', 4, 4)]);
    // Deux dés ratés (4 + 4 = 8), puis le d6 de déplacement de la nouvelle manche (6), qui en ouvre le menu.
    desFiges([4, 4, 6, 6, 6, 6]);

    pcOptions($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(pcPorteEnBase($ctx)['etat'])->toBe('fermee')
        ->and(array_column(pcOptions($ctx), 'id'))->toContain('forcer_porte_condition_4_4_e');
});

it('une porte déjà ouverte ne se force plus : l\'option disparaît et le choix est refusé', function () {
    $ctx = pcScene([pcPorte('porte_verrouillee', 4, 4)]);
    desFiges(array_fill(0, 40, 1));

    pcOptions($ctx);
    pcForcer($ctx, 4, 4)->assertSuccessful();

    expect(array_column(pcOptions($ctx), 'id'))->not->toContain('forcer_porte_condition_4_4_e');

    pcForcer($ctx, 4, 4)->assertStatus(422);
});

// =====================================================================
// LE PLACEMENT — une arête de BOUCLE, au plus une, et aucun tirage hors de Kellar's Keep
// =====================================================================

/** Appelle la garde de placement telle quelle (privée), sur des portes et un bestiaire donnés. */
function pcPlacer(array $portes, BestiaireGroupe $bestiaire, int &$tirages): array
{
    $methode = new ReflectionMethod(AssembleurCarte::class, 'marquerPortesDeCondition');
    $methode->setAccessible(true);

    $suivant = function () use (&$tirages): int {
        $tirages++;

        return 7;
    };

    return $methode->invoke(app(AssembleurCarte::class), $portes, $suivant, $bestiaire);
}

/** Une porte ordinaire fermée, avec son drapeau de boucle et sa jonction (comme `liaisonsSupplementaires()` les pose). */
function pcPorteOrdinaire(int $x, int $y, bool $boucle, int $jonction): array
{
    return ['x' => $x, 'y' => $y, 'cote' => 'e', 'etat' => 'fermee', 'boucle' => $boucle, 'jonction' => $jonction];
}

it('hors de Kellar\'s Keep : AUCUNE porte à condition, et aucun tirage consommé (les autres donjons gardent leur suite PRNG)', function () {
    $tirages = 0;
    $portes = [pcPorteOrdinaire(4, 4, true, 1), pcPorteOrdinaire(8, 4, true, 2)];

    $rendu = pcPlacer($portes, BestiaireGroupe::auto('crypte_tenebres'), $tirages);

    expect($rendu)->toBe($portes)
        ->and($tirages)->toBe(0);
});

it('dans Kellar\'s Keep, la porte à condition ne se pose que sur une arête de BOUCLE — jamais sur l\'arbre couvrant', function () {
    $tirages = 0;
    $portes = [pcPorteOrdinaire(4, 4, false, 1), pcPorteOrdinaire(8, 4, true, 2)];

    $rendu = pcPlacer($portes, BestiaireGroupe::auto('kellars_keep'), $tirages);

    expect($rendu[0])->not->toHaveKey('verrou')
        ->and($rendu[1]['verrou']['type'] ?? null)->toBe(MoteurPortes::VERROU_CONDITION);
});

it('sans aucune arête de boucle, aucune porte à condition : le chemin reste toujours celui de l\'arbre', function () {
    $tirages = 0;
    $portes = [pcPorteOrdinaire(4, 4, false, 1), pcPorteOrdinaire(8, 4, false, 2)];

    $rendu = pcPlacer($portes, BestiaireGroupe::auto('kellars_keep'), $tirages);

    expect(array_filter($rendu, fn (array $p) => isset($p['verrou'])))->toBe([]);
});

it('au plus UNE porte à condition par carte, même avec plusieurs arêtes de boucle', function () {
    $tirages = 0;
    $portes = [pcPorteOrdinaire(4, 4, true, 1), pcPorteOrdinaire(8, 4, true, 2), pcPorteOrdinaire(8, 5, true, 3)];

    $rendu = pcPlacer($portes, BestiaireGroupe::auto('kellars_keep'), $tirages);

    expect(array_filter($rendu, fn (array $p) => isset($p['verrou'])))->toHaveCount(1);
});

// =====================================================================
// LE REGISTRE — les deux conditions portent leurs paramètres sourcés
// =====================================================================

it('REGISTRE : chaque condition porte son attribut, sa comparaison, ses dés et son exemption du Nain, dans les valeurs connues', function () {
    foreach (MoteurPortes::CONDITIONS as $nom => $condition) {
        expect($condition)->toHaveKeys(['libelle', 'attribut', 'comparaison', 'des', 'nain_des'])
            ->and($condition['attribut'])->toBeIn(['corps', 'esprit'], $nom)
            ->and($condition['comparaison'])->toBeIn(['strict', 'inclusif'], $nom)
            ->and($condition['des'])->toBe(2, $nom)
            ->and($condition['nain_des'])->toBeIn([null, 1], $nom);
    }

    expect(MoteurPortes::CONDITIONS['porte_verrouillee']['comparaison'])->toBe('strict')
        ->and(MoteurPortes::CONDITIONS['porte_de_l_est']['comparaison'])->toBe('inclusif')
        ->and(MoteurPortes::CONDITIONS['porte_de_l_est']['nain_des'])->toBe(1);
});
