<?php

declare(strict_types=1);

use App\Engine\MotsClesTerrain;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\DeplacementHeros;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\Sauvegarde;
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
 * LOT H DES RESTES — LE SOL MEUBLE (Spirit Queen's Torment, quête 10 « The Tower of Earth », livret G0053 p. 13 note B) :
 *
 *   « if the result of the movement dice is odd, they become stuck in the first square of their movement and take 1 Body
 *     Point of damage. Once stuck, a hero may only roll 1 die for movement, and they only move if the result of the die is
 *     an even number. »
 *
 * Trois propriétés : (1) la marche s'arrête sur la PREMIÈRE case de la salle sur un dé impair, et la salle ne se traverse
 * pas ; (2) l'enlisement est DURABLE (`etat_personnage_quete.enlise_sol`, jamais le cache) et ne se libère qu'à un dé pair ;
 * (3) le monstre et l'allié n'y sont jamais arrêtés (« When a HERO moves »). Routes réelles : GET /menu, POST /choix.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

function idSolMeuble(): int
{
    return (int) Terrain::where('nom', 'Sol meuble')->firstOrFail()->id;
}

/**
 * Une rangée de 8 cases : la salle A (x 0..2, départ), la salle B (x 3..5) de SOL MEUBLE, la salle C (x 6..7). Un héros, le
 * groupe 'table-1', la quête en cours. Le héros part de (1,0), base 4 : sa portée est 4 + le d6 du tour.
 *
 * @return array{groupe: App\Models\Groupe, quete: Quete, heros: Personnage}
 */
function solMeubleScene(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — sol meuble',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $terrain = [];
    foreach ([3, 4, 5] as $x) {
        $terrain[] = ['x' => $x, 'y' => 0, 'terrain_id' => idSolMeuble(), 'groupe_id' => 'salle-1'];
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 8, 'hauteur' => 1,
        'grille' => [
            'largeur' => 8, 'hauteur' => 1,
            'cases' => [array_fill(0, 8, 's')],
            'salles' => [
                ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 1, 'mediane_y' => 0],
                ['x' => 3, 'y' => 0, 'largeur' => 3, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 4, 'mediane_y' => 0],
                ['x' => 6, 'y' => 0, 'largeur' => 2, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 6, 'mediane_y' => 0],
            ],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => $terrain,
            'spawn_heros' => [['x' => 1, 'y' => 0]], 'spawn_monstres' => [],
        ],
    ]);

    // Le brouillard masque les salles NON DÉCOUVERTES : la salle B et la salle C sont connues, sinon leurs cases sont des murs.
    $quete->update(['salles_decouvertes' => [1, 2]]);

    // Une menace active et révélée : sans elle, Unthreatened Movement (FL-Q p. 7) compte le d6 comme 4 SANS le lancer.
    menacerAvecMonstreFactice($quete->fresh());

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id, 'position_x' => 1, 'position_y' => 0,
    ]);

    return ['groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'heros' => $heros];
}

function solMeubleEtat(array $ctx): EtatPersonnageQuete
{
    return EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $ctx['heros']->id)->firstOrFail();
}

/** Le menu publié par la VRAIE route de rattrapage (il lance le d6 du tour au premier appel). */
function solMeubleMenu(): array
{
    return test()->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu') ?? [];
}

function solMeubleOption(array $menu, string $id): ?array
{
    return collect($menu['options'] ?? [])->firstWhere('id', $id);
}

/** Les cases atteignables publiées par le serveur, sous forme « x,y ». */
function solMeubleCases(array $menu): array
{
    return collect(solMeubleOption($menu, 'se_deplacer')['parametres']['destinations'] ?? [])
        ->map(fn (array $d) => "{$d['x']},{$d['y']}")->values()->all();
}

function solMeubleChoisir(string $option, array $parametres = [])
{
    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => $option, 'parametres' => $parametres]);
}

/** Le d6 du tour (la première face tirée), puis des 4 : aucune autre face n'est jouée par hasard. */
function solMeubleDe(int $valeur): void
{
    solMeubleDes([$valeur]);
}

/** Les d6 SUCCESSIFS (un par tour : le tour courant, puis les suivants), puis des 4. */
function solMeubleDes(array $valeurs): void
{
    desFiges([...$valeurs, ...array_fill(0, 80, 4)]);
}

// =====================================================================
// 1. LA CASE D'ARRÊT — un dé impair s'arrête à la première case de sol meuble
// =====================================================================

it('déclare le sol meuble : coût 1, boîte spirit_queen, pose SALLE, et la clé arret_sur_impair lue par deux coutures', function () {
    $sol = Terrain::where('nom', 'Sol meuble')->firstOrFail();

    expect($sol->boite)->toBe('spirit_queen')
        ->and($sol->pose)->toBe('salle')
        ->and($sol->cout_deplacement)->toBe(1)
        ->and($sol->effet)->toBe(['arret_sur_impair' => 1])
        ->and(MotsClesTerrain::connue('arret_sur_impair'))->toBeTrue();
});

it('un dé IMPAIR : la marche s\'arrête sur la PREMIÈRE case de la salle — la salle ne se traverse pas', function () {
    $ctx = solMeubleScene();
    solMeubleDe(3);

    $cases = solMeubleCases(solMeubleMenu());

    // La première case de la salle est une destination (on y entre, on s'y enlise) ; la sortie n'en est pas une.
    expect($cases)->toContain('3,0')
        ->and($cases)->not->toContain('4,0')
        ->and($cases)->not->toContain('5,0')
        ->and($cases)->not->toContain('6,0');
});

it('un dé PAIR : la salle se traverse, la sortie est atteignable', function () {
    $ctx = solMeubleScene();
    solMeubleDe(4);

    $cases = solMeubleCases(solMeubleMenu());

    // Sur un dé pair, rien n'arrête la marche dans la salle : la sortie est atteignable.
    expect($cases)->toContain('3,0')
        ->and($cases)->toContain('4,0')
        ->and($cases)->toContain('6,0');
});

// =====================================================================
// 2. L'ENLISEMENT — durable, 1 PV, annoncé
// =====================================================================

it('entrer sur un dé impair enlise le héros sur la case : 1 PV de Body, état DURABLE, et la marche prend fin', function () {
    $ctx = solMeubleScene();
    solMeubleDe(3);
    solMeubleMenu();

    solMeubleChoisir('se_deplacer', ['x' => 3, 'y' => 0])->assertStatus(202);

    // 1 PV de Body perdu à l'enlisement ; l'état est DURABLE ; la case est celle d'arrivée.
    $etat = solMeubleEtat($ctx)->fresh();
    expect((bool) $etat->enlise_sol)->toBeTrue()
        ->and([(int) $etat->position_x, (int) $etat->position_y])->toBe([3, 0])
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(7);

    // L'enlisement s'ANNONCE dans le fil : un effet automatique que rien n'annonce est injouable.
    $fil = collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['journal_combat'] ?? [])->pluck('texte')->all();
    expect(implode(' | ', $fil))->toContain("s'enlise dans Sol meuble");
});

it('un héros enlisé dont le dé est IMPAIR ne bouge pas : aucune destination, et le résolveur refuse la sortie', function () {
    // Enlisé en (3,0) par un tour précédent (état DURABLE) ; le d6 de CE tour tire 5 (impair).
    $ctx = solMeubleScene();
    solMeubleEtat($ctx)->update(['position_x' => 3, 'enlise_sol' => true]);
    solMeubleDe(5);

    $menu = solMeubleMenu();

    expect(solMeubleOption($menu, 'se_deplacer'))->toBeNull('un enlisé au dé impair n\'a aucune marche à proposer')
        ->and(solMeubleEtat($ctx)->fresh()->enlise_sol)->toBeTrue();

    solMeubleChoisir('se_deplacer', ['x' => 4, 'y' => 0])->assertStatus(422);
    expect([(int) solMeubleEtat($ctx)->fresh()->position_x])->toBe([3]);
});

it('un héros enlisé dont le dé est PAIR se dégage, marche (base + d6), et l\'état est effacé', function () {
    // Enlisé en (3,0) par un tour précédent ; le d6 de CE tour tire 4 (pair) : il se dégage et la salle se traverse.
    $ctx = solMeubleScene();
    solMeubleEtat($ctx)->update(['position_x' => 3, 'enlise_sol' => true]);
    solMeubleDe(4);

    $cases = solMeubleCases(solMeubleMenu());

    expect($cases)->toContain('6,0');

    solMeubleChoisir('se_deplacer', ['x' => 6, 'y' => 0])->assertStatus(202);

    $etat = solMeubleEtat($ctx)->fresh();
    expect((bool) $etat->enlise_sol)->toBeFalse('le dé pair libère le héros : l\'état est effacé par le déplacement')
        ->and([(int) $etat->position_x, (int) $etat->position_y])->toBe([6, 0])
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe(8, 'se dégager ne coûte aucun PV : le héros reste à son maximum');
});

it('l\'enlisement est porté par le snapshot : une reprise ne fait pas marcher un héros enlisé sans qu\'il ait tiré un dé pair', function () {
    $ctx = solMeubleScene();
    solMeubleEtat($ctx)->update(['position_x' => 3, 'position_y' => 0, 'enlise_sol' => true]);

    $snapshot = app(Sauvegarde::class)->snapshotter($ctx['groupe']->fresh(), 'nouveau_tour');
    solMeubleEtat($ctx)->update(['enlise_sol' => false]);

    app(Sauvegarde::class)->restaurer($ctx['groupe']->fresh(), $snapshot);

    expect((bool) solMeubleEtat($ctx)->fresh()->enlise_sol)->toBeTrue();
});

// =====================================================================
// 3. LA PARITÉ — le d6 qui compte, les cas où il ne compte pas
// =====================================================================

it('la parité porte sur le d6 du tour : un dé annulé (armure) ou un mouvement fixe ne fige ni ne libère personne', function () {
    expect(DeplacementHeros::solMeubleArrete(new EtatPersonnageQuete(
        ['detail_deplacement_tour' => ['des' => [3], 'de_annule' => true]],
    )))->toBeFalse('un dé annulé ne compte plus : il ne peut pas être impair')
        ->and(DeplacementHeros::solMeubleArrete(new EtatPersonnageQuete(
            ['detail_deplacement_tour' => ['des' => []]],
        )))->toBeFalse('un mouvement fixe n\'a aucun dé')
        ->and(DeplacementHeros::solMeubleArrete(new EtatPersonnageQuete(
            ['detail_deplacement_tour' => ['des' => [3, 6]]],
        )))->toBeTrue('le premier d6 décide, les bottes elfiques n\'y changent rien')
        ->and(DeplacementHeros::solMeubleArrete(new EtatPersonnageQuete(
            ['detail_deplacement_tour' => ['des' => [4]]],
        )))->toBeFalse('le jet « sans menace » (4) est pair');
});

// =====================================================================
// 4. LE MONSTRE ET L'ALLIÉ — jamais arrêtés (« When a HERO moves »)
// =====================================================================

it('la grille d\'un monstre ou d\'un allié n\'est PAS arrêtée par le sol meuble, même sur un dé impair', function () {
    $ctx = solMeubleScene();
    $quete = $ctx['quete']->fresh()->load('carte');

    // Sans activation (monstre, allié, grille générique) : la salle entière se traverse.
    $monstre = FabriqueGrille::pour($quete, exceptInstanceId: 999991);
    expect($monstre->arretSurImpairActif())->toBeFalse()
        ->and($monstre->chemin(1, 0, 6, 0))->not->toBeNull()
        ->and($monstre->estArretSurImpair(3, 0))->toBeTrue('la case est connue, mais rien ne l\'active pour un monstre');
});

// =====================================================================
// 5. PLACEMENT — sous le thème seulement, jamais dans la salle de départ
// =====================================================================

it('le sol meuble se pose sous le thème spirit_queen, pas ailleurs, jamais dans la salle de départ', function () {
    $idSol = idSolMeuble();
    $posees = 0;

    foreach (range(1, 40) as $graine) {
        $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
        $gabarit->structure = [...$gabarit->structure, 'terrains' => ['min' => 0, 'max' => 0, 'sol_meuble' => ['min' => 1, 'max' => 1]]];

        foreach ([null, 'horreur_des_glaces'] as $theme) {
            $carte = app(\App\Partie\AssembleurCarte::class)->assembler(
                $gabarit, $graine, bestiaire: $theme === null ? null : \App\Partie\BestiaireGroupe::auto($theme),
            );

            expect(collect($carte['terrain'])->contains(fn (array $t) => $t['terrain_id'] === $idSol))
                ->toBeFalse("thème « {$theme} » : sol meuble posé");
        }

        $carte = app(\App\Partie\AssembleurCarte::class)->assembler(
            $gabarit, $graine, bestiaire: \App\Partie\BestiaireGroupe::auto('spirit_queen'),
        );

        foreach (collect($carte['terrain'])->where('terrain_id', $idSol) as $t) {
            $posees++;
            expect(\App\Partie\Salles::indexDe($carte['salles'], $t['x'], $t['y']))->not->toBe(0, "graine {$graine} : sol meuble dans la salle de départ");
        }
    }

    expect($posees)->toBeGreaterThan(0, 'le sol meuble n\'a jamais été posé : le test ne prouve rien');
});
