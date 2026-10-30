<?php

declare(strict_types=1);

use App\Engine\MotsClesEquipement;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\GabaritQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Mobilier;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\Equipement;
use App\Partie\Grille;
use App\Partie\JournalCombat;
use App\Partie\Images\BibliothequeImages;
use App\Partie\MoteurPortes;
use App\Partie\ResolveurTour;
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
 * GRILLE DE FER et CLÉ DE LAITON (Mage of the Mirror, lot F du plan restes, 2026-10-29). Sources : F7539 p. 4 (« Portcullises
 * x4 » : « Heroes and monsters cannot see through a portcullis until it is opened »), p. 6 (« Brass Key »), quête 7 note C-D
 * (la clé dans le râtelier d'armes d'une AUTRE salle, la grille en salle D), quête 10 note G (« a hero must roll less than their
 * Body Points on two red dice to force the portcullis open. Each hero may try once per turn »).
 *
 * Éprouvé EN JEU : le menu, le choix, le jet (dés injectés, `desFiges`), la porte écrite dans la carte de la base, la fouille du
 * râtelier, la fin de quête. Les helpers portent le préfixe `gf` (le donjon est celui de PorteConditionTest, à l'identique).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
        TerrainSeeder::class]);
});

/** Le donjon de la scène : salle 0 (x 1-4, y 3-5), couloir (5,4), salle 1 (x 6-9, y 3-5). Identique à PorteConditionTest. */
function gfCases(): array
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

/** Une grille de fer telle que `AssembleurCarte::marquerPortesDeGrille()` la pose : la force copiée, la clé désignée. */
function gfGrille(int $x = 4, int $y = 4, string $cote = 'e'): array
{
    return [
        'x' => $x, 'y' => $y, 'cote' => $cote, 'etat' => 'fermee', 'jonction' => 1, 'boucle' => true,
        'verrou' => [
            'type' => MoteurPortes::VERROU_GRILLE,
            ...MoteurPortes::FORCE_GRILLE,
            'libelle' => 'grille de fer',
            'objet_id' => (int) Objet::where('nom', MoteurPortes::CLE_DE_LAITON)->value('id'),
        ],
    ];
}

/**
 * @param  list<array<string, mixed>>  $portes
 * @param  list<array<string, mixed>>  $mobilier
 * @param  array<string, mixed>  $attributs
 * @param  array{0: int, 1: int}  $position
 * @param  list<int>  $decouvertes  salles déjà révélées (index)
 * @return array{alice: mixed, groupe: Groupe, quete: Quete, heros: Personnage}
 */
function gfScene(array $portes, array $attributs = [], array $position = [3, 4], array $decouvertes = [0], array $mobilier = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, $attributs + ['deplacement_base' => 12]);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — grille de fer',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
        'salles_decouvertes' => $decouvertes,
    ]);

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 16, 'hauteur' => 9,
        'grille' => [
            'largeur' => 16, 'hauteur' => 9, 'cases' => gfCases(),
            'salles' => [
                ['x' => 1, 'y' => 3, 'largeur' => 4, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 2, 'mediane_y' => 4],
                ['x' => 6, 'y' => 3, 'largeur' => 4, 'hauteur' => 3, 'theme' => 'generique', 'mediane_x' => 7, 'mediane_y' => 4],
            ],
            'portes' => $portes, 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => $mobilier, 'terrain' => [], 'dangers' => [],
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

/** Les options du menu courant. */
function gfOptions(array $ctx): array
{
    return test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options') ?? [];
}

/** Les identifiants d'option offerts ce tour. */
function gfIds(array $ctx): array
{
    return array_column(gfOptions($ctx), 'id');
}

/** Joue une option par POST /choix. */
function gfChoisir(array $ctx, string $optionId, array $parametres = [])
{
    return test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => $optionId,
        'parametres' => $parametres,
    ]);
}

/** La grille de fer telle qu'elle est en BASE (état durable de la carte). */
function gfPorteEnBase(array $ctx, int $index = 0): array
{
    return Carte::query()->where('quete_id', $ctx['quete']->id)->firstOrFail()->grille['portes'][$index];
}

/** Le nombre de tours OUVERTS : un jet raté ferme le tour (un `nouveau_tour` de plus). */
function gfNouveauxTours(array $ctx): int
{
    return Evenement::query()
        ->where('groupe_id', $ctx['groupe']->id)
        ->where('type', 'systeme')
        ->get()
        ->filter(fn (Evenement $e) => ($e->payload['action'] ?? null) === 'nouveau_tour')
        ->count();
}

/** Le dernier jet de grille journalisé. */
function gfJet(array $ctx): ?array
{
    return Evenement::query()
        ->where('groupe_id', $ctx['groupe']->id)
        ->get()
        ->filter(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'forcer_grille')
        ->last()?->payload;
}

/**
 * Les cases atteignables depuis le départ — l'oracle INDÉPENDANT du test (il ne réutilise pas `AssembleurCarte::accessiblesSansVerrou()`).
 * `$portesBloquees` = null : tous les verrous ET les portes secrètes tenus fermés (la règle d'accès de la clé). Sinon, SEULES ces
 * portes sont tenues fermées (`[]` : rien ; `[$grille]` : la grille seule).
 *
 * @param  array<string, mixed>  $carte
 * @param  list<array<string, mixed>>|null  $portesBloquees
 * @return array<string, true>
 */
function gfAccessibles(array $carte, ?array $portesBloquees = null): array
{
    $bloquees = [];

    foreach ($carte['portes'] as $porte) {
        $bloquee = $portesBloquees === null
            ? (($porte['etat'] ?? null) === 'secrete' || ! empty($porte['verrou']))
            : in_array($porte, $portesBloquees, true);

        if ($bloquee) {
            $bloquees[] = Grille::caseEmbrasure($porte, $carte['salles']);
        }
    }

    $cles = [];
    foreach ($bloquees as $b) {
        $cles["{$b['x']},{$b['y']}"] = true;
    }

    $depart = $carte['salles'][0];
    $origine = null;

    for ($y = $depart['y']; $y < $depart['y'] + $depart['hauteur']; $y++) {
        for ($x = $depart['x']; $x < $depart['x'] + $depart['largeur']; $x++) {
            if ($origine === null && ($carte['cases'][$y][$x] ?? 'm') === 's' && ! isset($cles["{$x},{$y}"])) {
                $origine = ['x' => $x, 'y' => $y];
            }
        }
    }

    $grille = new Grille($carte['cases']);
    $grille->obstruer($bloquees);

    return $grille->casesAtteignables($origine['x'], $origine['y'], 100000);
}

/**
 * Les salles (index) qui ont AU MOINS une case de sol atteignable, parmi `$accessibles` (`gfAccessibles()`).
 *
 * @return list<int>
 */
function gfSallesAtteintes(array $carte, array $accessibles): array
{
    $atteintes = [];

    foreach ($carte['salles'] as $i => $salle) {
        for ($y = $salle['y']; $y < $salle['y'] + $salle['hauteur']; $y++) {
            for ($x = $salle['x']; $x < $salle['x'] + $salle['largeur']; $x++) {
                if (isset($accessibles["{$x},{$y}"]) && ($carte['cases'][$y][$x] ?? 'm') === 's') {
                    $atteintes[$i] = $i;
                }
            }
        }
    }

    return array_values($atteintes);
}

/** Les salles (index) qu'une porte relie : celles qui contiennent l'une de ses deux cases. */
function gfSallesDeLaPorte(array $carte, array $porte): array
{
    $salles = [];

    foreach (Grille::casesPorte($porte) as $c) {
        foreach ($carte['salles'] as $i => $s) {
            if ($c['x'] >= $s['x'] && $c['x'] < $s['x'] + $s['largeur'] && $c['y'] >= $s['y'] && $c['y'] < $s['y'] + $s['hauteur']) {
                $salles[] = $i;
            }
        }
    }

    return $salles;
}

/** Une grille est-elle trouvée dans la carte ? Les seeds couvrent le thème, la boucle et la clé. */
function gfCartesMage(int $nombre = 60): array
{
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();
    $mage = BestiaireGroupe::auto('mage_du_miroir');

    $cartes = [];
    foreach (range(1, $nombre) as $i) {
        $cartes[] = $assembleur->assembler($gabarit, $i * 1299721, bestiaire: $mage);
    }

    return $cartes;
}

// ---------------------------------------------------------------------------------------------------------------------
// Génération : la grille est une arête de BOUCLE, au plus une, sous le thème seulement ; la clé est atteignable.
// ---------------------------------------------------------------------------------------------------------------------

it('pose au plus une grille par carte, toujours sur une arête de boucle, et seulement sous le thème mage_du_miroir', function () {
    $grillesVues = 0;

    foreach (gfCartesMage() as $carte) {
        $grilles = array_values(array_filter($carte['portes'], fn ($p) => ($p['verrou']['type'] ?? null) === MoteurPortes::VERROU_GRILLE));

        expect(count($grilles))->toBeLessThanOrEqual(1);

        foreach ($grilles as $grille) {
            expect($grille['boucle'] ?? false)->toBeTrue();
            $grillesVues++;
        }
    }

    // Non vacue : sans ce plancher, un générateur qui ne pose jamais de grille passerait.
    expect($grillesVues)->toBeGreaterThan(0);

    // Hors thème : aucune grille, et AUCUN tirage consommé (la suite PRNG d'un autre donjon ne bouge pas).
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::where('type_jalon', 'normale')->firstOrFail();
    foreach (range(1, 40) as $i) {
        $carte = $assembleur->assembler($gabarit, $i * 1299721, bestiaire: BestiaireGroupe::auto('kellars_keep'));
        expect(collect($carte['portes'])->contains(fn ($p) => ($p['verrou']['type'] ?? null) === MoteurPortes::VERROU_GRILLE))->toBeFalse();
    }
});

it('ne sépare jamais la carte : fermée ou ouverte, la grille ne change pas les salles atteignables', function () {
    $grillesVues = 0;

    foreach (gfCartesMage() as $carte) {
        $grilles = array_values(array_filter($carte['portes'], fn ($p) => ($p['verrou']['type'] ?? null) === MoteurPortes::VERROU_GRILLE));

        foreach ($grilles as $grille) {
            $grillesVues++;

            // Une boucle : la grille SEULE tenue fermée (toutes les autres portes, secrètes comprises, franchissables) ne coupe
            // aucune salle. C'est la garantie de l'arbre couvrant : la grille n'est jamais le seul chemin.
            $fermee = gfSallesAtteintes($carte, gfAccessibles($carte, [$grille]));
            $ouverte = gfSallesAtteintes($carte, gfAccessibles($carte, []));

            expect($fermee)->toBe($ouverte, 'la grille seule coupe une salle');
        }
    }

    expect($grillesVues)->toBeGreaterThan(0);
});

it('place la clé de laiton dans une AUTRE salle, atteignable sans franchir la grille ni un autre verrou', function () {
    $cles = 0;

    foreach (gfCartesMage() as $carte) {
        $rack = collect($carte['mobilier'])->firstWhere('objet_fixe', MoteurPortes::CLE_DE_LAITON);

        if ($rack === null) {
            continue;
        }

        $cles++;
        $grilles = array_values(array_filter($carte['portes'], fn ($p) => ($p['verrou']['type'] ?? null) === MoteurPortes::VERROU_GRILLE));

        expect($grilles)->not->toBeEmpty();
        expect($rack['salle'])->not->toBe(0);

        foreach ($grilles as $grille) {
            expect(gfSallesDeLaPorte($carte, $grille))->not->toContain($rack['salle']);
        }

        // La salle du râtelier a une case atteignable SANS verrou (la grille comprise).
        $accessibles = gfAccessibles($carte);
        $salle = $carte['salles'][$rack['salle']];
        $atteinte = false;

        for ($y = $salle['y']; $y < $salle['y'] + $salle['hauteur']; $y++) {
            for ($x = $salle['x']; $x < $salle['x'] + $salle['largeur']; $x++) {
                if (isset($accessibles["{$x},{$y}"]) && ($carte['cases'][$y][$x] ?? 'm') === 's') {
                    $atteinte = true;
                }
            }
        }

        expect($atteinte)->toBeTrue();
    }

    // Non vacue : au moins une carte du lot porte sa clé.
    expect($cles)->toBeGreaterThan(0);
});

it('la grille garde son objet_id de la clé du catalogue (la voie clé est lisible)', function () {
    $id = (int) Objet::where('nom', MoteurPortes::CLE_DE_LAITON)->value('id');

    expect($id)->toBeGreaterThan(0);

    foreach (gfCartesMage(20) as $carte) {
        foreach ($carte['portes'] as $porte) {
            if (($porte['verrou']['type'] ?? null) === MoteurPortes::VERROU_GRILLE) {
                expect($porte['verrou']['objet_id'])->toBe($id);
            }
        }
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// Voie FORCE (F1) : deux dés rouges, total strictement sous le Corps de DÉPART (précédent F4543 p. 19 note A).
// ---------------------------------------------------------------------------------------------------------------------

it('la force réussie ouvre la grille : deux dés sous le Corps de départ', function () {
    // Corps de départ 8 ; dés 3 + 2 = 5 < 8.
    $ctx = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 8]);
    desFiges([3, 2]);

    expect(gfIds($ctx))->toContain('forcer_grille_4_4_e');

    gfChoisir($ctx, 'forcer_grille_4_4_e', ['porte' => ['x' => 4, 'y' => 4, 'cote' => 'e']])->assertSuccessful();

    expect(gfPorteEnBase($ctx)['etat'])->toBe('ouverte');
    expect(gfJet($ctx)['reussi'])->toBeTrue();
    expect(gfJet($ctx)['total'])->toBe(5);
    expect(gfJet($ctx)['verrou'])->toBe('grille de fer');
    // Une force réussie ne ferme pas le tour.
    expect(gfNouveauxTours($ctx))->toBe(0);
});

it('la force ratée laisse la grille close et ferme le tour du héros', function () {
    // Dés 5 + 4 = 9 ≥ 8 : raté.
    $ctx = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 8]);
    desFiges([5, 4]);

    gfChoisir($ctx, 'forcer_grille_4_4_e', ['porte' => ['x' => 4, 'y' => 4, 'cote' => 'e']])->assertSuccessful();

    expect(gfPorteEnBase($ctx)['etat'])->toBe('fermee');
    expect(gfJet($ctx)['reussi'])->toBeFalse();
    expect(gfNouveauxTours($ctx))->toBe(1);
});

it('le seuil de la force est le Corps de DÉPART, pas le Corps actuel', function () {
    // Corps actuel 2, Corps de départ 8 : un total de 7 passe (7 < 8), alors que 7 ne passerait pas avec le Corps actuel.
    $ctx = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 2]);
    desFiges([3, 4]);

    gfChoisir($ctx, 'forcer_grille_4_4_e', ['porte' => ['x' => 4, 'y' => 4, 'cote' => 'e']])->assertSuccessful();

    expect(gfJet($ctx)['valeur'])->toBe(8);
    expect(gfPorteEnBase($ctx)['etat'])->toBe('ouverte');
});

it("le menu offre la force à une grille adjacente et close, avec le seuil lu sur le héros", function () {
    $ctx = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 8]);
    $option = collect(gfOptions($ctx))->firstWhere('id', 'forcer_grille_4_4_e');

    expect($option['type'])->toBe('forcer_grille');
    expect($option['libelle'])->toContain('Forcer la grille de fer');
    expect($option['libelle'])->toContain('(8)');
});

it("le menu n'offre pas la force à une grille non adjacente", function () {
    $loin = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 8], [1, 3]);

    expect(gfIds($loin))->not->toContain('forcer_grille_4_4_e');
});

it("le menu n'offre plus la force une fois la grille ouverte", function () {
    $ouverte = gfScene([[...gfGrille(), 'etat' => 'ouverte']], ['pv_body_max' => 8, 'pv_body' => 8]);

    expect(gfIds($ouverte))->not->toContain('forcer_grille_4_4_e');
});

// ---------------------------------------------------------------------------------------------------------------------
// Voie CLÉ : `ouvrir_porte` « (clé) » — le lecteur existant (`verrou.type` clé, `possedeCle()`), étendu à la grille.
// ---------------------------------------------------------------------------------------------------------------------

it("la clé dans le sac fait offrir l'ouverture « (clé) », et l'ouvre sans jet", function () {
    $cle = (int) Objet::where('nom', MoteurPortes::CLE_DE_LAITON)->value('id');
    $ctx = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 8]);
    Inventaire::create(['personnage_id' => $ctx['heros']->id, 'objet_id' => $cle, 'emplacement' => 'sac', 'quantite' => 1]);

    $option = collect(gfOptions($ctx))->firstWhere('id', 'ouvrir_porte_4_4_e');

    expect($option)->not->toBeNull();
    expect($option['libelle'])->toBe('Ouvrir la grille de fer (clé)');

    gfChoisir($ctx, 'ouvrir_porte_4_4_e', ['porte' => ['x' => 4, 'y' => 4, 'cote' => 'e']])->assertSuccessful();

    expect(gfPorteEnBase($ctx)['etat'])->toBe('ouverte');
    // La clé reste au sac : elle ne quitte le sac qu'à la fin de la quête (F2).
    expect(Inventaire::where('personnage_id', $ctx['heros']->id)->where('objet_id', $cle)->exists())->toBeTrue();
});

it("sans clé, la grille ne s'ouvre pas à la main : aucune option d'ouverture", function () {
    $ctx = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 8]);

    expect(gfIds($ctx))->not->toContain('ouvrir_porte_4_4_e');
});

// ---------------------------------------------------------------------------------------------------------------------
// Producteur : le râtelier d'armes qui porte la clé la donne à sa PREMIÈRE fouille, en plus de sa table.
// ---------------------------------------------------------------------------------------------------------------------

it('la première fouille du râtelier donne la clé ; une fouille déjà faite par un autre ne la donne plus', function () {
    $rack = Mobilier::where('nom', "Râtelier d'armes")->firstOrFail();
    $entree = ['mobilier_id' => $rack->id, 'x' => 7, 'y' => 4, 'l' => 1, 'h' => 3, 'salle' => 1, 'objet_fixe' => MoteurPortes::CLE_DE_LAITON];
    $cle = (int) Objet::where('nom', MoteurPortes::CLE_DE_LAITON)->value('id');

    // Premier fouilleur : la clé entre au sac, et le fil la nomme.
    $ctx = gfScene([], ['pv_body_max' => 8, 'pv_body' => 8], [6, 4], [0, 1], [$entree]);
    gfChoisir($ctx, 'fouiller_mobilier_0', ['index' => 0, 'nom' => "Râtelier d'armes"])->assertSuccessful();

    expect(Inventaire::where('personnage_id', $ctx['heros']->id)->where('objet_id', $cle)->exists())->toBeTrue();
    expect(Evenement::query()->where('groupe_id', $ctx['groupe']->id)->get()
        ->contains(fn (Evenement $e) => isset($e->payload['cle_de_laiton'])))->toBeTrue();
});

it('une fouille de râtelier déjà faite par un autre ne donne plus la clé', function () {
    $rack = Mobilier::where('nom', "Râtelier d'armes")->firstOrFail();
    // Un autre héros a déjà fouillé ce râtelier : la clé n'est plus là, la table ordinaire seule.
    $dejaFouille = ['mobilier_id' => $rack->id, 'x' => 7, 'y' => 4, 'l' => 1, 'h' => 3, 'salle' => 1,
        'objet_fixe' => MoteurPortes::CLE_DE_LAITON, 'fouille_par' => [999]];
    $cle = (int) Objet::where('nom', MoteurPortes::CLE_DE_LAITON)->value('id');

    $ctx = gfScene([], ['pv_body_max' => 8, 'pv_body' => 8], [6, 4], [0, 1], [$dejaFouille]);
    gfChoisir($ctx, 'fouiller_mobilier_0', ['index' => 0, 'nom' => "Râtelier d'armes"])->assertSuccessful();

    expect(Inventaire::where('personnage_id', $ctx['heros']->id)->where('objet_id', $cle)->exists())->toBeFalse();
});

it('la clé de laiton est un objet hors marché, unique, et aucune table de meuble ne la nomme', function () {
    $cle = Objet::where('nom', MoteurPortes::CLE_DE_LAITON)->firstOrFail();

    expect($cle->rarete)->toBe('unique');
    expect((array) $cle->effet)->toHaveKey(MotsClesEquipement::HORS_MARCHE);
    expect((array) $cle->effet)->toHaveKey(MotsClesEquipement::OBJET_DE_QUETE);

    // Jamais dans le butin aléatoire : aucune table de fouille ne la désigne par son nom.
    foreach (Mobilier::all() as $meuble) {
        foreach ((array) ($meuble->effet['fouille'] ?? []) as $entree) {
            expect($entree['objet'] ?? null)->not->toBe(MoteurPortes::CLE_DE_LAITON);
        }
    }
});

// ---------------------------------------------------------------------------------------------------------------------
// Ligne de vue : une grille close coupe la vue (comme toute porte close), et la laisse passer une fois ouverte.
// ---------------------------------------------------------------------------------------------------------------------

it('la grille close bloque la ligne de vue, et la laisse passer une fois ouverte', function () {
    $carte = ['cases' => gfCases(), 'salles' => [
        ['x' => 1, 'y' => 3, 'largeur' => 4, 'hauteur' => 3],
        ['x' => 6, 'y' => 3, 'largeur' => 4, 'hauteur' => 3],
    ]];

    $close = new Grille($carte['cases']);
    $close->definirPortes([gfGrille()], $carte['salles']);
    expect($close->ligneDeVue(2, 4, 7, 4))->toBeFalse();

    $ouverte = new Grille($carte['cases']);
    $ouverte->definirPortes([[...gfGrille(), 'etat' => 'ouverte']], $carte['salles']);
    expect($ouverte->ligneDeVue(2, 4, 7, 4))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------------------------------
// F2 : la clé quitte le sac à la fin de la quête (victoire ou échec), les autres objets restent.
// ---------------------------------------------------------------------------------------------------------------------

it('la clé de laiton quitte le sac à la fin de la quête, les autres objets restent', function () {
    $cle = (int) Objet::where('nom', MoteurPortes::CLE_DE_LAITON)->value('id');
    $potion = (int) Objet::where('nom', 'Potion de guérison')->value('id');
    $ctx = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 8]);

    Inventaire::create(['personnage_id' => $ctx['heros']->id, 'objet_id' => $cle, 'emplacement' => 'sac', 'quantite' => 1]);
    Inventaire::create(['personnage_id' => $ctx['heros']->id, 'objet_id' => $potion, 'emplacement' => 'sac', 'quantite' => 1]);

    expect(app(Equipement::class)->purgerObjetsDeQuete($ctx['groupe']))->toBe(1);

    expect(Inventaire::where('personnage_id', $ctx['heros']->id)->where('objet_id', $cle)->exists())->toBeFalse();
    expect(Inventaire::where('personnage_id', $ctx['heros']->id)->where('objet_id', $potion)->exists())->toBeTrue();
});

it('la victoire (terminerQuete) purge la clé du sac', function () {
    $cle = (int) Objet::where('nom', MoteurPortes::CLE_DE_LAITON)->value('id');
    $ctx = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 8]);
    Inventaire::create(['personnage_id' => $ctx['heros']->id, 'objet_id' => $cle, 'emplacement' => 'sac', 'quantite' => 1]);

    app(ResolveurTour::class)->terminerQuete($ctx['groupe'], $ctx['quete']);

    expect(Inventaire::where('personnage_id', $ctx['heros']->id)->where('objet_id', $cle)->exists())->toBeFalse();
});

it("l'échec (echouerQuete) purge aussi la clé du sac", function () {
    $cle = (int) Objet::where('nom', MoteurPortes::CLE_DE_LAITON)->value('id');
    $ctx = gfScene([gfGrille()], ['pv_body_max' => 8, 'pv_body' => 8]);
    Inventaire::create(['personnage_id' => $ctx['heros']->id, 'objet_id' => $cle, 'emplacement' => 'sac', 'quantite' => 1]);

    // `echouerQuete()` est privée : appelée par réflexion, comme la défaite la appelle.
    $echouer = new ReflectionMethod(ResolveurTour::class, 'echouerQuete');
    $echouer->invoke(app(ResolveurTour::class), $ctx['groupe'], $ctx['quete'], null);

    expect(Inventaire::where('personnage_id', $ctx['heros']->id)->where('objet_id', $cle)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------------------------------------------------
// Publication : une grille close a SON symbole (la herse, F7539 PDF 3), jamais celui de la porte close.
// ---------------------------------------------------------------------------------------------------------------------

it("le symbole de la porte close ne s'applique pas à la grille de fer : elle a la herse du livret", function () {
    $biblio = app(BibliothequeImages::class);

    expect($biblio->urlSymbolePorte('fermee', null))->toBe('/images/livrets/portes/fermee.webp');
    expect($biblio->urlSymbolePorte('fermee', MoteurPortes::VERROU_GRILLE))->toBe('/images/livrets/portes/verrou-grille.webp');
    // Ouverte, c'est un passage ouvert ordinaire (aucun livret ne dessine une porte ouverte).
    expect($biblio->urlSymbolePorte('ouverte', MoteurPortes::VERROU_GRILLE))->toBeNull();
});

// ---------------------------------------------------------------------------------------------------------------------
// Tests ajoutés après la revue des mutations (lecteurs qui ne faisaient tomber AUCUN test) : la salle de départ, le fil, le créneau.
// ---------------------------------------------------------------------------------------------------------------------

it("la salle de la clé n'est jamais la salle de départ, ni une salle que la grille sépare", function () {
    // La grille ferme la SORTIE EST de la salle 1 ; la salle 1 reste atteignable par le couloir (5,4), la salle 0 aussi. Les deux sont
    // exclues — la salle de départ, et la salle que la grille sépare : aucune salle ne reste, la clé n'a pas de salle (null).
    // Retirer l'exclusion de la salle 0 fait apparaître la salle 0 comme candidate : le test tombe.
    $salles = [
        ['x' => 1, 'y' => 3, 'largeur' => 4, 'hauteur' => 3],
        ['x' => 6, 'y' => 3, 'largeur' => 4, 'hauteur' => 3],
    ];
    $methode = new ReflectionMethod(AssembleurCarte::class, 'salleDeLaCle');

    $choisie = $methode->invoke(app(AssembleurCarte::class), [gfGrille(9, 4, 'e')], gfCases(), $salles, fn () => 0);

    expect($choisie)->toBeNull();
});

it('le fil nomme la clé trouvée à la première fouille du râtelier', function () {
    $lignes = (new JournalCombat)->depuisResultat([
        'type' => 'fouille_mobilier', 'issue' => 'rien', 'mobilier' => "Râtelier d'armes",
        'cle_de_laiton' => ['objet' => ['id' => 1, 'nom' => MoteurPortes::CLE_DE_LAITON]],
    ], 'Albrecht');

    expect(collect($lignes)->pluck('texte')->implode(' '))->toContain('trouve aussi une Clé de laiton');
});

it('le fil dit la grille forcée, ouverte ou échouée : le verrou, le total, et le tour qui s\'achève', function () {
    $ouverte = (new JournalCombat)->depuisResultat([
        'type' => 'forcer_grille', 'verrou' => 'grille de fer', 'faces' => [3, 2], 'total' => 5,
        'valeur' => 8, 'comparaison' => 'strict', 'reussi' => true,
    ], 'Albrecht');
    $echouee = (new JournalCombat)->depuisResultat([
        'type' => 'forcer_grille', 'verrou' => 'grille de fer', 'faces' => [5, 4], 'total' => 9,
        'valeur' => 8, 'comparaison' => 'strict', 'reussi' => false,
    ], 'Albrecht');

    expect(collect($ouverte)->pluck('texte')->implode(' '))->toContain('ouvre la grille de fer')->toContain('elle s\'ouvre');
    expect(collect($echouee)->pluck('texte')->implode(' '))->toContain('échoue devant la grille de fer')->toContain('le tour s\'achève');
});

it("la force de grille est une interaction libre, comme la porte à condition", function () {
    expect(ResolveurTour::creneauOption('forcer_grille'))->toBe('interaction');
    expect(ResolveurTour::creneauOption('forcer_porte_condition'))->toBe('interaction');
});
