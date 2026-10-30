<?php

declare(strict_types=1);

use App\Engine\MotsClesTerrain;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\EtatGroupe;
use App\Partie\Sauvegarde;
use App\Partie\Salles;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * LOT H DES RESTES — LES VENTS DE COULOIR, EN JEU (*Spirit Queen's Torment* q. 12, livret G0053 p. 15). Un héros qui TERMINE son
 * mouvement sur un couloir lance un dé de combat : bouclier (blanc ou noir) = il CHOISIT la direction de sa poussée de 2 cases ;
 * crâne = ZARGON la choisit, immédiatement. Routes réelles (GET /menu, POST /choix) et dés injectés.
 *
 * Scène : une rangée de 9 cases. Salle A (x 0..2, départ), couloir (x 3..8) — chaque case de couloir porte le vent. Le héros part
 * de (1,0) avec un budget de 5 (base 4 + d6 = 1) et va en (4,0) : l'arrivée est sur le vent. Le dé de combat suit le d6 :
 * 4 = bouclier blanc, 1 = crâne (face = (dé − 1) / 2, cf. FaceDeCombat::depuisD6).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

function idVent(): int
{
    return (int) Terrain::where('nom', 'Vent de couloir')->firstOrFail()->id;
}

/**
 * @param  list<array{x: int, y: int}>  $monstres  monstres ACTIFS et posés (vivants, visibles) : des figures qui arrêtent une poussée
 * @return array{groupe: App\Models\Groupe, quete: Quete, heros: Personnage}
 */
function ventScene(array $monstres = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — vents de couloir',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $terrain = [];
    foreach (range(3, 8) as $x) {
        $terrain[] = ['x' => $x, 'y' => 0, 'terrain_id' => idVent()];
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 9, 'hauteur' => 1,
        'grille' => [
            'largeur' => 9, 'hauteur' => 1,
            'cases' => [array_fill(0, 9, 's')],
            'salles' => [
                ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 1, 'mediane_y' => 0],
            ],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => $terrain,
            'spawn_heros' => [['x' => 1, 'y' => 0]], 'spawn_monstres' => [],
        ],
    ]);

    $quete->update(['salles_decouvertes' => [1]]);
    menacerAvecMonstreFactice($quete->fresh());

    foreach ($monstres as $m) {
        InstanceMonstre::create([
            'quete_id' => $quete->id,
            'monstre_id' => Monstre::query()->firstOrFail()->id,
            'pv_body' => 1, 'pv_mind' => 1,
            'etat' => 'actif', 'revele' => true,
            'position_x' => $m['x'], 'position_y' => $m['y'],
        ]);
    }

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id, 'position_x' => 1, 'position_y' => 0,
    ]);

    return ['groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'heros' => $heros];
}

function ventEtat(array $ctx): EtatPersonnageQuete
{
    return EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $ctx['heros']->id)->firstOrFail();
}

function ventMenu(): array
{
    return test()->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu') ?? [];
}

function ventChoisir(string $option, array $parametres = [])
{
    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => $option, 'parametres' => $parametres]);
}

/** Le d6 du mouvement (1 : budget 5), puis le dé de combat (4 = bouclier blanc, 1 = crâne), puis des 4. */
function ventDes(int $deCombat): void
{
    desFiges([1, $deCombat, ...array_fill(0, 80, 4)]);
}

/** Le héros marche jusqu'à (4,0) : le couloir venté. */
function ventMarcher(): void
{
    ventChoisir('se_deplacer', ['x' => 4, 'y' => 0])->assertStatus(202);
}

// =====================================================================
// 1. LE CATALOGUE ET LA POSE
// =====================================================================

it('déclare le vent : pose COULOIRS, boîte spirit_queen, et les deux clés de poussée lues par le résolveur', function () {
    $vent = Terrain::where('nom', 'Vent de couloir')->firstOrFail();

    expect($vent->pose)->toBe('couloirs')
        ->and($vent->boite)->toBe('spirit_queen')
        ->and($vent->effet)->toBe(['sur' => [
            'bouclier_blanc' => ['pousse_heros' => 2],
            'bouclier_noir' => ['pousse_heros' => 2],
            'crane' => ['pousse_zargon' => 2],
        ]])
        ->and(MotsClesTerrain::connue('pousse_heros'))->toBeTrue()
        ->and(MotsClesTerrain::connue('pousse_zargon'))->toBeTrue();
});

it('pose le vent sur TOUTES les cases de couloir libres, jamais dans une salle, et seulement sous le thème', function () {
    $id = idVent();
    $vu = 0;

    foreach (range(1, 30) as $graine) {
        $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
        $gabarit->structure = [...$gabarit->structure, 'terrains' => ['min' => 0, 'max' => 0, 'vents' => ['min' => 1, 'max' => 1]]];

        foreach ([null, 'horreur_des_glaces'] as $theme) {
            $carte = app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: $theme === null ? null : BestiaireGroupe::auto($theme));

            expect(collect($carte['terrain'])->contains(fn (array $t) => $t['terrain_id'] === $id))->toBeFalse("thème « {$theme} » : vent posé");
        }

        $carte = app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: BestiaireGroupe::auto('spirit_queen'));

        foreach (collect($carte['terrain'])->where('terrain_id', $id) as $t) {
            $vu++;
            expect(Salles::indexDe($carte['salles'], $t['x'], $t['y']))->toBeNull("graine {$graine} : vent dans une salle")
                ->and(array_key_exists('groupe_id', $t))->toBeFalse("graine {$graine} : un couloir est une case isolée, sans groupe");
        }
    }

    expect($vu)->toBeGreaterThan(0);
});

// =====================================================================
// 2. LE BOUCLIER — un choix EN ATTENTE, forcé au menu
// =====================================================================

it('un bouclier à l\'arrivée ouvre le choix : le menu n\'offre QUE les directions légales, dans l\'ordre E, O', function () {
    $ctx = ventScene();
    ventDes(4); // le d6 du mouvement (1), puis le dé de combat (4 = bouclier blanc)
    ventMenu();
    ventMarcher();

    $etat = ventEtat($ctx)->fresh();
    expect($etat->vent_choix)->toMatchArray(['x' => 4, 'y' => 0, 'cases' => 2, 'directions' => ['e', 'o']]);

    $ids = collect(ventMenu()['options'] ?? [])->pluck('id')->all();
    expect($ids)->toBe(['pousser_vent_e', 'pousser_vent_o'], 'un choix forcé : aucune autre action ne se propose');
});

it('choisir la direction pousse de deux cases, et efface le choix', function () {
    $ctx = ventScene();
    ventDes(4);
    ventMenu();
    ventMarcher();

    ventChoisir('pousser_vent_e', ['direction' => 'e'])->assertStatus(202);

    $etat = ventEtat($ctx)->fresh();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([6, 0])
        ->and($etat->vent_choix)->toBeNull();

    $fil = collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['journal_combat'] ?? [])->pluck('texte')->all();
    expect(implode(' | ', $fil))->toContain('se laisse porter vers l\'est');
});

it('une direction que le menu n\'a PAS offerte est refusée par le résolveur (422), même avec une option qui la nomme', function () {
    $ctx = ventScene();
    ventDes(4);
    ventMenu();
    ventMarcher();

    ventChoisir('pousser_vent_n', ['direction' => 'n'])->assertStatus(422);

    expect([(int) ventEtat($ctx)->fresh()->position_x, (int) ventEtat($ctx)->fresh()->position_y])->toBe([4, 0])
        ->and(ventEtat($ctx)->fresh()->vent_choix)->not->toBeNull('le choix reste en attente après un refus');
});

it('une FIGURE sur la case voisine retire la direction : le choix ne propose que l\'ouest', function () {
    $ctx = ventScene([['x' => 5, 'y' => 0]]);
    ventDes(4);
    ventMenu();
    ventMarcher();

    expect(ventEtat($ctx)->fresh()->vent_choix['directions'])->toBe(['o'])
        ->and(collect(ventMenu()['options'] ?? [])->pluck('id')->all())->toBe(['pousser_vent_o']);
});

// =====================================================================
// 3. LE CRÂNE — Zargon choisit, immédiatement
// =====================================================================

it('un crâne : Zargon pousse le héros tout de suite, sans choix — sans monstre vivant, la première direction légale (E)', function () {
    $ctx = ventScene();
    ventDes(1); // le dé de combat : crâne
    ventMenu();
    ventMarcher();

    $etat = ventEtat($ctx)->fresh();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([6, 0])
        ->and($etat->vent_choix)->toBeNull();
});

it('un crâne : Zargon pousse vers le MONSTRE VIVANT le plus proche de l\'arrivée', function () {
    // Monstre en (0,0) : la poussée vers l'OUEST finit en (2,0), à deux cases de lui ; vers l'est, en (6,0), à six.
    $ctx = ventScene([['x' => 0, 'y' => 0]]);
    ventDes(1);
    ventMenu();
    ventMarcher();

    expect([(int) ventEtat($ctx)->fresh()->position_x, (int) ventEtat($ctx)->fresh()->position_y])->toBe([2, 0]);
});

// =====================================================================
// 4. LA PLACE — sans le vent, rien ; sur une case de salle, rien
// =====================================================================

it('une arrivée dans une SALLE ne lance aucun dé de vent : ni choix, ni poussée, ni trace', function () {
    $ctx = ventScene();
    desFiges([1, ...array_fill(0, 80, 4)]);
    ventMenu();

    ventChoisir('se_deplacer', ['x' => 2, 'y' => 0])->assertStatus(202);

    expect(ventEtat($ctx)->fresh()->vent_choix)->toBeNull()
        ->and([(int) ventEtat($ctx)->fresh()->position_x])->toBe([2]);
});

it('le choix du vent est porté par le snapshot : une reprise retrouve la poussée à choisir', function () {
    $ctx = ventScene();
    ventDes(4);
    ventMenu();
    ventMarcher();

    $snapshot = app(Sauvegarde::class)->snapshotter($ctx['groupe']->fresh(), 'nouveau_tour');
    ventEtat($ctx)->update(['vent_choix' => null]);

    app(Sauvegarde::class)->restaurer($ctx['groupe']->fresh(), $snapshot);

    expect(ventEtat($ctx)->fresh()->vent_choix['directions'])->toBe(['e', 'o']);
});

it('le bouclier NOIR compte comme un bouclier : il ouvre le même choix que le blanc (le livret dit « a shield »)', function () {
    $ctx = ventScene();
    ventDes(6); // 6 = bouclier noir (FaceDeCombat::depuisD6)
    ventMenu();
    ventMarcher();

    expect(ventEtat($ctx)->fresh()->vent_choix['directions'])->toBe(['e', 'o']);
});

it('une figure qui entre sur la trajectoire ENTRE le dé et le choix annule la direction : le résolveur la revalide', function () {
    $ctx = ventScene();
    ventDes(4);
    ventMenu();
    ventMarcher();

    // Un monstre se pose en (5,0), sur la case de la poussée vers l'est, pendant que le choix attend.
    InstanceMonstre::query()->create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::query()->firstOrFail()->id,
        'pv_body' => 1, 'pv_mind' => 1, 'etat' => 'actif', 'revele' => true,
        'position_x' => 5, 'position_y' => 0,
    ]);

    ventChoisir('pousser_vent_e', ['direction' => 'e'])->assertStatus(422);
    expect([(int) ventEtat($ctx)->fresh()->position_x])->toBe([4]);
});
