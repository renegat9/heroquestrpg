<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\MotsClesSort;
use App\Engine\MotsClesTerrain;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\Sort;
use App\Models\Terrain;
use App\Partie\FabriqueGrille;
use App\Partie\JournalCombat;
use App\Partie\MoteurPieges;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * SPELL SCROLL — ICE BRIDGE (The Frozen Horror, F5815 p. 4 — `reference/18_extensions.md` §3), PORTÉE le 2026-11-13 (plan
 * `docs/plan-parchemins-glace.md`, lot 4) : « a permanent bridge of Magic Ice tiles that enables heroes to cross over any
 * pit, trap, chasm, crevasse, or icy square ».
 *
 * Décisions de René (lot 0) : le pont est fait d'autant de tuiles qu'il en faut pour couvrir L'ÉLÉMENT choisi (une fosse :
 * 1 ; une fosse longue : 2) ; portée = la ligne de vue ; les monstres peuvent l'emprunter ; difficulté de lecture 2.
 *
 * Le parchemin se lit par la VRAIE route (`/menu`, `/choix`) : une entrée PAR élément, `cle` = `parchemin:{id}:pont:{nature}:{x},{y}`
 * (la première case), et seules les entrées publiées sont acceptées. Les fonctions sont préfixées `pont` : ce fichier ne
 * dépend d'aucune fonction d'un autre fichier de test.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder et TerrainSeeder AVANT ObjetSeeder : celui-ci dérive un parchemin par sort.
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, TerrainSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Une quête sur un plateau 7×7, UNE salle, sans portes. Un magicien LANCEUR (pas de jet de Mind à la lecture) en (3,3).
 *
 * `$cfg` :
 *  - `figures`  : `[nom_base, x, y, pv]` — monstres révélés et actifs ;
 *  - `pieges`   : `[nom, x, y, etat]` ou `[nom, x, y, etat, groupe]` — pièges posés sur la grille ;
 *  - `terrain`  : `[nom, x, y]` ou `[nom, x, y, groupe]` — tuiles de terrain posées sur la grille ;
 *  - `murs`     : `[x, y]` — cases de roche DANS la salle (coupent la ligne de vue).
 *
 * @param  array<string, mixed>  $cfg
 * @return array{alice: JoueurAuthentifiable, groupe: \App\Models\Groupe, heros: Personnage, quete: Quete, etat: EtatPersonnageQuete, figures: list<InstanceMonstre>}
 */
function pontScene(array $cfg = []): array
{
    $cfg += ['figures' => [], 'pieges' => [], 'terrain' => [], 'murs' => []];

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();

    $cases = array_fill(0, 7, array_fill(0, 7, 's'));
    foreach ($cfg['murs'] as [$mx, $my]) {
        $cases[$my][$mx] = 'm';
    }

    $pieges = array_map(fn (array $p) => array_filter([
        'x' => $p[1], 'y' => $p[2], 'piege_id' => Piege::where('nom', $p[0])->value('id'), 'etat' => $p[3],
        'groupe_id' => $p[4] ?? null,
    ], fn ($v) => $v !== null), $cfg['pieges']);

    $terrains = array_map(fn (array $t) => array_filter([
        'x' => $t[1], 'y' => $t[2], 'terrain_id' => Terrain::where('nom', $t[0])->value('id'),
        'groupe_id' => $t[3] ?? null,
    ], fn ($v) => $v !== null), $cfg['terrain']);

    $quete->carte->update(['grille' => [
        'largeur' => 7, 'hauteur' => 7, 'cases' => $cases,
        'salles' => [['x' => 0, 'y' => 0, 'largeur' => 7, 'hauteur' => 7, 'theme' => 'generique', 'mediane_x' => 0, 'mediane_y' => 0]],
        'portes' => [], 'pieges' => array_values($pieges), 'mobilier' => [], 'terrain' => array_values($terrains),
        'leviers' => [], 'epreuves' => [], 'spawn_heros' => [['x' => 3, 'y' => 3]], 'spawn_monstres' => [], 'aretes' => [],
    ]]);
    $quete->update(['salles_decouvertes' => [0]]);

    $quete->instancesMonstres()->update(['etat' => 'vaincu', 'revele' => false]);

    $instances = [];
    foreach ($cfg['figures'] as [$nomBase, $x, $y, $pv]) {
        $instances[] = InstanceMonstre::create([
            'quete_id' => $quete->id,
            'monstre_id' => Monstre::where('nom_base', $nomBase)->firstOrFail()->id,
            'pv_body' => $pv, 'pv_body_max' => $pv, 'pv_mind' => 0,
            'etat' => 'actif', 'revele' => true,
            'position_x' => $x, 'position_y' => $y,
        ]);
    }

    $etat->update([
        'position_x' => 3, 'position_y' => 3,
        'a_joue' => false, 'a_deplace' => false, 'a_agi' => false, 'tombe' => false,
        'deplacement_restant' => null, 'capacites_tour' => null,
        // Allonge fixée (comme PiegesTest) : la marche est déterministe, et le d6 du tour est le seul dé demandé.
        'deplacement_tour' => 6,
    ]);

    Cache::forget(GenererMenu::cleMenu($groupe->id, $alice->id));

    return ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'quete' => $quete->fresh()->load('carte'),
        'etat' => $etat->fresh(), 'figures' => $instances];
}

/** Donne au héros le parchemin de Pont de Glace, au sac. */
function pontDonner(Personnage $heros): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Parchemin : Pont de Glace')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);
}

/** Le menu RÉEL du joueur. @return list<array<string, mixed>> */
function pontMenu(JoueurAuthentifiable $alice): array
{
    Cache::forget(GenererMenu::cleMenu(\App\Models\Groupe::where('identifiant', 'table-1')->value('id'), $alice->id));

    return test()->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu.options');
}

/**
 * Les entrées de PONT du menu pour CE sac (`parchemin:{id}:pont:…`), dans l'ordre publié.
 *
 * @return list<array<string, mixed>>
 */
function pontEntrees(JoueurAuthentifiable $alice, int $inventaireId): array
{
    $parchemins = collect(pontMenu($alice))->firstWhere('id', 'lire_parchemin')['parametres']['parchemins'] ?? [];

    return collect($parchemins)
        ->filter(fn (array $entree) => str_starts_with((string) $entree['cle'], "parchemin:{$inventaireId}:pont:"))
        ->values()
        ->all();
}

/** Lit le parchemin par la route de choix, sur l'élément désigné par `$cle`. */
function pontLire(string $cle)
{
    return test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => $cle],
    ]);
}

/** Les pièges de la grille de la quête, lus à neuf. @return list<array<string, mixed>> */
function pontPieges(Quete $quete): array
{
    return array_values((array) ($quete->fresh()->carte->grille['pieges'] ?? []));
}

/**
 * Rouvre le tour du héros : lire un parchemin prend l'action (`a_agi`) et termine le tour (`a_joue`). Sans cela, la seconde lecture du même tour n'est
 * pas offerte — et un test croirait voir une règle de pont là où il n'y a que la fin de tour.
 */
function pontNouveauTour(array $s): void
{
    EtatPersonnageQuete::where('quete_id', $s['quete']->id)->where('personnage_id', $s['heros']->id)
        ->update(['a_joue' => false, 'a_deplace' => false, 'a_agi' => false, 'deplacement_restant' => null]);
    Cache::forget(GenererMenu::cleMenu($s['groupe']->id, $s['alice']->id));
}

/**
 * Un COULOIR : la colonne x = 4 est roche, sauf (4,3) — le seul passage de (3,3) à (5,3). Sans cela, la marche
 * contourne la case piégée (le déplacement évite les pièges connus) et le test ne touche jamais l'élément.
 *
 * @return list<array{0: int, 1: int}>
 */
function pontCouloir(): array
{
    return [[4, 0], [4, 1], [4, 2], [4, 4], [4, 5], [4, 6]];
}

// ==================================================================
// LE CATALOGUE ET LES REGISTRES
// ==================================================================

it('seed le Pont de Glace : parchemin utilitaire, difficulté 2, pose la tuile Glace magique', function () {
    $sort = Sort::where('nom', 'Pont de Glace')->firstOrFail();

    expect($sort->element)->toBe('parchemin')
        ->and($sort->type)->toBe('utilitaire')
        ->and($sort->difficulte_parchemin)->toBe(2)
        ->and($sort->effet)->toMatchArray(['pose_pont' => 'Glace magique']);
});

it('dérive un « Parchemin : Pont de Glace » au sac, rangé dans la boîte horreur_des_glaces', function () {
    $objet = Objet::where('nom', 'Parchemin : Pont de Glace')->firstOrFail();

    expect($objet->categorie)->toBe('parchemin')
        ->and($objet->effet['sort_nom'])->toBe('Pont de Glace')
        ->and(ObjetSeeder::boitesDesParchemins()['Pont de Glace'] ?? null)->toBe('horreur_des_glaces');
});

it('porte la carte Spell Scroll — Ice Bridge : elle désigne le sort, et n\'est plus une dette', function () {
    $carte = collect(config('cartes.parchemins.cartes'))->firstWhere('carte', 'Spell Scroll — Ice Bridge');

    expect($carte['sort'] ?? null)->toBe('Pont de Glace')
        ->and(array_key_exists('manque', $carte))->toBeFalse();
});

it('déclare le juge dans les DEUX SENS : les pièges et plaques pontables semés sont exactement ceux qui sont déclarés', function () {
    $pieges = Piege::query()->get()
        ->filter(fn (Piege $p) => ! empty(data_get($p->effet, 'pontable')))
        ->pluck('nom')->sort()->values()->all();

    expect($pieges)->toBe(collect([
        'Fosse', 'Fosse des ténèbres', 'Fosse longue', 'Piège à lances', 'Chute de blocs', 'Lame balançoire',
        "Mare d'acide", 'Piège de lianes',
    ])->sort()->values()->all());

    $terrains = Terrain::query()->get()
        ->filter(fn (Terrain $t) => ! empty(data_get($t->effet, 'pontable')))
        ->pluck('nom')->sort()->values()->all();

    expect($terrains)->toBe(collect([
        'Glace glissante', 'Glissière de glace', 'Rivière gelée', 'Sables mouvants', 'Sol meuble', 'Mare', 'Trappe de téléportation',
    ])->sort()->values()->all());

    expect(MotsClesTerrain::connue('pontable'))->toBeTrue()
        ->and(MotsClesTerrain::connue('pont'))->toBeTrue()
        ->and(MotsClesTerrain::estNonImplementee('support_sort'))->toBeFalse()
        ->and(data_get(Terrain::where('nom', 'Glace magique')->firstOrFail()->effet, 'pont'))->toBeTrue()
        ->and(array_key_exists('support_sort', (array) Terrain::where('nom', 'Glace magique')->firstOrFail()->effet))->toBeFalse();
});

it('déclare la POSE du parchemin (MotsClesSort::POSES) avec un lecteur qui existe et nomme la clé', function () {
    expect(MotsClesSort::POSES)->toHaveKey('pose_pont');

    [$classe, $methode] = explode('::', MotsClesSort::POSES['pose_pont']['lecteur']);

    expect(method_exists($classe, $methode))->toBeTrue()
        ->and(str_contains((string) file_get_contents((new ReflectionClass($classe))->getFileName()), 'pose_pont'))->toBeTrue();
});

// ==================================================================
// LE MENU : une entrée PAR élément, et jamais un parchemin sans effet
// ==================================================================

it('offre UNE entrée par élément visible : une Fosse détectée — cases et nom exacts', function () {
    $s = pontScene(['pieges' => [['Fosse', 5, 3, 'detecte']]]);
    pontDonner($s['heros']);

    $entrees = pontEntrees($s['alice'], Inventaire::where('personnage_id', $s['heros']->id)->value('id'));

    expect($entrees)->toHaveCount(1)
        ->and($entrees[0]['cle'])->toEndWith(':pont:piege:5,3')
        ->and($entrees[0]['mode'])->toBe('pose_pont')
        ->and($entrees[0]['cases'])->toBe([['x' => 5, 'y' => 3]])
        ->and($entrees[0]['nom'])->toBe('Pont de Glace — Fosse en 5,3')
        ->and($entrees[0]['element_pont'])->toBe(['nature' => 'piege', 'nom' => 'Fosse']);
});

it('une Fosse longue est UNE entrée de ses deux cases (même groupe_id)', function () {
    $s = pontScene(['pieges' => [['Fosse longue', 4, 3, 'fosse_ouverte', 'fosse-1'], ['Fosse longue', 5, 3, 'fosse_ouverte', 'fosse-1']]]);
    $inventaire = pontDonner($s['heros']);

    $entrees = pontEntrees($s['alice'], $inventaire->id);

    expect($entrees)->toHaveCount(1)
        ->and($entrees[0]['cases'])->toBe([['x' => 4, 'y' => 3], ['x' => 5, 'y' => 3]])
        ->and($entrees[0]['nom'])->toBe('Pont de Glace — Fosse longue en 4,3 (2 cases)');
});

it('une plaque de Rivière gelée (ses trois cases groupées) est UNE entrée de ses trois cases', function () {
    $s = pontScene(['terrain' => [
        ['Rivière gelée', 1, 5, 'riviere-1'], ['Rivière gelée', 2, 5, 'riviere-1'], ['Rivière gelée', 3, 5, 'riviere-1'],
    ]]);
    $inventaire = pontDonner($s['heros']);

    $entrees = pontEntrees($s['alice'], $inventaire->id);

    expect($entrees)->toHaveCount(1)
        ->and($entrees[0]['cases'])->toBe([['x' => 1, 'y' => 5], ['x' => 2, 'y' => 5], ['x' => 3, 'y' => 5]])
        ->and($entrees[0]['element_pont'])->toBe(['nature' => 'terrain', 'nom' => 'Rivière gelée']);
});

it('n\'offre PAS un piège caché : personne ne sait qu\'il est là', function () {
    $s = pontScene(['pieges' => [['Fosse', 5, 3, 'cache']]]);
    $inventaire = pontDonner($s['heros']);

    expect(pontEntrees($s['alice'], $inventaire->id))->toBe([]);
});

it('n\'offre PAS une Chute de blocs tombée : un bloc de pierre n\'est plus un trou', function () {
    $s = pontScene(['pieges' => [['Chute de blocs', 5, 3, 'bloc']]]);
    $inventaire = pontDonner($s['heros']);

    expect(pontEntrees($s['alice'], $inventaire->id))->toBe([]);
});

it('n\'offre PAS un élément hors de la ligne de vue : deux roches interposées le cachent', function () {
    $s = pontScene([
        'pieges' => [['Fosse', 3, 0, 'detecte']],
        'murs' => [[3, 1], [3, 2]],
    ]);
    $inventaire = pontDonner($s['heros']);

    expect(pontEntrees($s['alice'], $inventaire->id))->toBe([]);
});

it('n\'offre PAS un élément qu\'une figure occupe : un Gobelin est debout sur la fosse', function () {
    $s = pontScene([
        'pieges' => [['Fosse', 5, 3, 'detecte']],
        'figures' => [['Gobelin', 5, 3, 3]],
    ]);
    $inventaire = pontDonner($s['heros']);

    expect(pontEntrees($s['alice'], $inventaire->id))->toBe([]);
});

it('sans AUCUN élément pontable, le parchemin n\'est pas offert : aucune option lire_parchemin pour ce sac', function () {
    $s = pontScene([]);
    $inventaire = pontDonner($s['heros']);

    expect(pontEntrees($s['alice'], $inventaire->id))->toBe([])
        ->and(collect(pontMenu($s['alice']))->firstWhere('id', 'lire_parchemin'))->toBeNull();
});

// ==================================================================
// LA RÉSOLUTION : par la vraie route
// ==================================================================

it('lire le parchemin par /choix pose le pont, consomme le parchemin, et publie la décision', function () {
    $s = pontScene(['pieges' => [['Fosse', 5, 3, 'detecte']]]);
    $inventaire = pontDonner($s['heros']);
    $cle = pontEntrees($s['alice'], $inventaire->id)[0]['cle'];

    pontLire($cle)->assertStatus(202)
        ->assertJsonPath('resultat.mode', 'pose_pont')
        ->assertJsonPath('resultat.pont', true)
        ->assertJsonPath('resultat.cases.0.x', 5)
        ->assertJsonPath('resultat.element.nom', 'Fosse');

    $quete = $s['quete']->fresh();

    expect(Inventaire::whereKey($inventaire->id)->exists())->toBeFalse()
        ->and(pontPieges($quete)[0]['etat'])->toBe(MoteurPieges::ETAT_SOUS_PONT);

    $tuile = collect($quete->carte->grille['terrain'])->firstWhere('x', 5);

    expect($tuile['terrain_id'])->toBe(Terrain::where('nom', 'Glace magique')->value('id'))
        ->and($tuile['groupe_id'])->toBe('pont-1');
});

it('la fosse SOUS le pont ne se déclenche plus quand un héros marche dessus : aucun dégât, tour non fini, plus de saut proposé', function () {
    $s = pontScene(['pieges' => [['Fosse', 4, 3, 'detecte']], 'murs' => pontCouloir()]);
    $inventaire = pontDonner($s['heros']);
    pontLire(pontEntrees($s['alice'], $inventaire->id)[0]['cle'])->assertStatus(202);
    pontNouveauTour($s);

    desFiges([3]);

    test()->actingAs($s['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => 5, 'y' => 3],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.interrompu', false);

    $etat = EtatPersonnageQuete::where('quete_id', $s['quete']->id)->where('personnage_id', $s['heros']->id)->firstOrFail();

    expect($s['heros']->fresh()->pv_body)->toBe(8)
        ->and((int) $etat->position_x)->toBe(5)
        ->and((bool) $etat->a_joue)->toBeFalse()
        ->and(pontPieges($s['quete']->fresh())[0]['etat'])->toBe(MoteurPieges::ETAT_SOUS_PONT);

    // Le menu ne propose plus de sauter une fosse que le pont couvre.
    expect(collect(pontMenu($s['alice']))->filter(fn (array $o) => str_starts_with((string) $o['id'], 'franchir_'))->all())->toBe([]);
});

it('CONTRÔLE : sans pont, la même marche tombe dans la fosse — le test ne passe pas par hasard', function () {
    $s = pontScene(['pieges' => [['Fosse', 4, 3, 'detecte']], 'murs' => pontCouloir()]);

    desFiges([3]);

    test()->actingAs($s['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => 5, 'y' => 3],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.interrompu', true);

    expect($s['heros']->fresh()->pv_body)->toBe(7);
});

it('une Chute de blocs DÉTECTÉE sous un pont ne tombe pas', function () {
    $s = pontScene(['pieges' => [['Chute de blocs', 4, 3, 'detecte']], 'murs' => pontCouloir()]);
    $inventaire = pontDonner($s['heros']);
    pontLire(pontEntrees($s['alice'], $inventaire->id)[0]['cle'])->assertStatus(202);
    pontNouveauTour($s);

    desFiges([3]);

    test()->actingAs($s['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => 5, 'y' => 3],
    ])->assertStatus(202);

    $piege = pontPieges($s['quete']->fresh())[0];

    expect($piege['etat'])->toBe(MoteurPieges::ETAT_SOUS_PONT)
        ->and($s['heros']->fresh()->pv_body)->toBe(8);
});

it('la Glace glissante sous un pont ne fait plus glisser : la case est un pont, sans jet ni fin de tour', function () {
    $s = pontScene(['terrain' => [['Glace glissante', 4, 3]], 'murs' => pontCouloir()]);
    $inventaire = pontDonner($s['heros']);
    pontLire(pontEntrees($s['alice'], $inventaire->id)[0]['cle'])->assertStatus(202);
    pontNouveauTour($s);

    desFiges([3]);

    test()->actingAs($s['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => 5, 'y' => 3],
    ])->assertStatus(202);

    $etat = EtatPersonnageQuete::where('quete_id', $s['quete']->id)->where('personnage_id', $s['heros']->id)->firstOrFail();

    expect((int) $etat->position_x)->toBe(5)
        ->and((bool) $etat->a_joue)->toBeFalse()
        ->and($s['heros']->fresh()->pv_body)->toBe(8);
});

it('un monstre peut EMPRUNTER une Glissière de glace pontée : la case n\'est plus un obstacle pour lui', function () {
    $s = pontScene(['terrain' => [['Glissière de glace', 4, 3]], 'figures' => [['Gobelin', 1, 1, 3]]]);
    $monstre = $s['figures'][0];

    $avant = FabriqueGrille::pour($s['quete']->fresh(), exceptInstanceId: $monstre->id);
    expect($avant->estTraversable(4, 3))->toBeFalse();

    $inventaire = pontDonner($s['heros']);
    pontLire(pontEntrees($s['alice'], $inventaire->id)[0]['cle'])->assertStatus(202);

    $apres = FabriqueGrille::pour($s['quete']->fresh(), exceptInstanceId: $monstre->id);
    expect($apres->estTraversable(4, 3))->toBeTrue();
});

it('un piège de sol déjà couvert n\'est plus offert une seconde fois', function () {
    $s = pontScene(['pieges' => [['Fosse', 5, 3, 'detecte']]]);
    $deux = pontDonner($s['heros']);
    $cle = pontEntrees($s['alice'], $deux->id)[0]['cle'];

    pontLire($cle)->assertStatus(202);
    pontNouveauTour($s);

    // Un second parchemin, le tour rouvert : la fosse est sous glace, elle n'est plus un élément. Le menu ne propose
    // donc RIEN pour ce sac — et ce n'est pas parce que le héros a agi (le tour est rouvert juste avant).
    $autre = pontDonner($s['heros']);
    expect(pontEntrees($s['alice'], $autre->id))->toBe([]);
});

it('cle hors liste refusée (422) : le résolveur ne pose que ce que le menu a offert', function () {
    $s = pontScene(['pieges' => [['Fosse', 5, 3, 'detecte']]]);
    $inventaire = pontDonner($s['heros']);

    pontLire("parchemin:{$inventaire->id}:pont:0,0")->assertStatus(422);

    expect(pontPieges($s['quete']->fresh())[0]['etat'])->toBe('detecte')
        ->and(Inventaire::whereKey($inventaire->id)->exists())->toBeTrue();
});

it('la phrase au fil nomme l\'élément et son repère, sans article', function () {
    $s = pontScene(['pieges' => [['Fosse', 5, 3, 'detecte']]]);
    $inventaire = pontDonner($s['heros']);
    $payload = pontLire(pontEntrees($s['alice'], $inventaire->id)[0]['cle'])->assertStatus(202)->json('resultat');

    $lignes = app(JournalCombat::class)->depuisResultat($payload, 'Aldric');
    $textes = collect($lignes)->pluck('texte')->implode(' | ');

    expect($textes)->toContain('lit Pont de Glace')
        ->and($textes)->toContain('un pont de glace est posé — Fosse en 5,3');
});

it('publie le pont : carte.terrain[].pont vrai sur la case, et le piège couvert n\'est pas publié', function () {
    $s = pontScene(['pieges' => [['Fosse', 5, 3, 'detecte']]]);
    $inventaire = pontDonner($s['heros']);
    pontLire(pontEntrees($s['alice'], $inventaire->id)[0]['cle'])->assertStatus(202);

    $carte = test()->actingAs($s['alice'], 'joueur')->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte');

    $tuile = collect($carte['terrain'])->firstWhere('x', 5);

    expect($tuile['pont'])->toBeTrue()
        ->and($tuile['nom'])->toBe('Glace magique')
        ->and(collect($carte['pieges'])->where('x', 5)->where('y', 3)->all())->toBe([]);
});

it('le pont survit à une reprise : il vit dans cartes.grille, donc dans la base', function () {
    $s = pontScene(['pieges' => [['Fosse', 5, 3, 'detecte']]]);
    $inventaire = pontDonner($s['heros']);
    pontLire(pontEntrees($s['alice'], $inventaire->id)[0]['cle'])->assertStatus(202);

    $relu = Quete::findOrFail($s['quete']->id)->carte->fresh()->grille;

    expect(collect($relu['terrain'])->firstWhere('x', 5)['groupe_id'] ?? null)->toBe('pont-1')
        ->and($relu['pieges'][0]['etat'])->toBe(MoteurPieges::ETAT_SOUS_PONT);
});

it('une seconde lecture sur un autre élément prend le numéro suivant : N unique dans la carte', function () {
    $s = pontScene(['pieges' => [['Fosse', 5, 3, 'detecte'], ['Fosse', 3, 5, 'detecte']]]);
    $deux = pontDonner($s['heros']);
    $troisieme = pontDonner($s['heros']);

    $cles = collect(pontEntrees($s['alice'], $deux->id))->pluck('cle')->all();
    pontLire($cles[0])->assertStatus(202);
    pontNouveauTour($s);
    pontLire(pontEntrees($s['alice'], $troisieme->id)[0]['cle'])->assertStatus(202);

    $groupes = collect($s['quete']->fresh()->carte->grille['terrain'])->pluck('groupe_id')->unique()->sort()->values()->all();

    expect($groupes)->toBe(['pont-1', 'pont-2']);
});
