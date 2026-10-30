<?php

declare(strict_types=1);

use App\Engine\MotsClesTerrain;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use App\Partie\EtatGroupe;
use App\Partie\FabriqueGrille;
use App\Partie\ResolveurTour;
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
 * LOT H DES RESTES — LE COURANT PORTEUR, EN JEU (Spirit Queen's Torment, quête 11, livret G0053 p. 14 note C) : un héros qui
 * ENTRE dans le courant gagne 2 cases de déplacement, une fois par déplacement. Routes réelles (GET /menu, POST /choix) et
 * le même calcul que l'aperçu. Les tests purs du parcours sont dans `tests/Unit/Partie/CourantPorteurGrilleTest.php`.
 *
 * Scène : une rangée de 10 cases. Salle A (x 0..2, départ), salle B (x 3..5, courant porteur), salle C (x 6..9, sol). Le héros
 * part de (1,0) avec un budget de 5 (base 4 + d6 = 1). Sans le courant, (6,0) est le dernier pas ; avec lui, (8,0) l'est.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

function idCourantPorteur(): int
{
    return (int) Terrain::where('nom', 'Courant porteur')->firstOrFail()->id;
}

/**
 * @return array{groupe: App\Models\Groupe, quete: Quete, heros: Personnage}
 */
function courantPorteurScene(bool $avecCourant = true): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $quete = Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::where('type_jalon', 'normale')->firstOrFail()->id,
        'titre' => 'Quête de test — courant porteur',
        'position_arc' => 1, 'type_jalon' => 'normale', 'etat' => 'en_cours', 'or_initial' => 0,
    ]);

    $terrain = [];
    if ($avecCourant) {
        foreach ([3, 4, 5] as $x) {
            $terrain[] = ['x' => $x, 'y' => 0, 'terrain_id' => idCourantPorteur(), 'groupe_id' => 'salle-1'];
        }
    }

    Carte::create([
        'quete_id' => $quete->id, 'largeur' => 10, 'hauteur' => 1,
        'grille' => [
            'largeur' => 10, 'hauteur' => 1,
            'cases' => [array_fill(0, 10, 's')],
            'salles' => [
                ['x' => 0, 'y' => 0, 'largeur' => 3, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 1, 'mediane_y' => 0],
                ['x' => 3, 'y' => 0, 'largeur' => 3, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 4, 'mediane_y' => 0],
                ['x' => 6, 'y' => 0, 'largeur' => 4, 'hauteur' => 1, 'theme' => 'generique', 'mediane_x' => 7, 'mediane_y' => 0],
            ],
            'portes' => [], 'leviers' => [], 'epreuves' => [], 'aretes' => [],
            'pieges' => [], 'mobilier' => [], 'terrain' => $terrain,
            'spawn_heros' => [['x' => 1, 'y' => 0]], 'spawn_monstres' => [],
        ],
    ]);

    $quete->update(['salles_decouvertes' => [1, 2]]);
    menacerAvecMonstreFactice($quete->fresh());

    $groupe->update(['phase' => 'quete', 'quete_courante_id' => $quete->id]);

    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $heros->id, 'position_x' => 1, 'position_y' => 0,
    ]);

    return ['groupe' => $groupe, 'quete' => $quete->fresh()->load('carte'), 'heros' => $heros];
}

function courantPorteurEtat(array $ctx): EtatPersonnageQuete
{
    return EtatPersonnageQuete::where('quete_id', $ctx['quete']->id)->where('personnage_id', $ctx['heros']->id)->firstOrFail();
}

function courantPorteurMenu(): array
{
    return test()->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu') ?? [];
}

function courantPorteurCases(array $menu): array
{
    $option = collect($menu['options'] ?? [])->firstWhere('id', 'se_deplacer');

    return collect($option['parametres']['destinations'] ?? [])->map(fn (array $d) => "{$d['x']},{$d['y']}")->values()->all();
}

function courantPorteurChoisir(string $option, array $parametres = [])
{
    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => $option, 'parametres' => $parametres]);
}

/** Le d6 du tour (base 4 + 1 = budget 5), puis des 4. */
function courantPorteurDe(int $valeur): void
{
    desFiges([$valeur, ...array_fill(0, 80, 4)]);
}

// =====================================================================
// 1. LE CATALOGUE — la tuile, sa clé, son gabarit
// =====================================================================

it('déclare le courant porteur : coût 1, boîte spirit_queen, pose SALLE, et la clé bonus_entree (valeur = gain)', function () {
    $courant = Terrain::where('nom', 'Courant porteur')->firstOrFail();

    expect($courant->boite)->toBe('spirit_queen')
        ->and($courant->pose)->toBe('salle')
        ->and($courant->cout_deplacement)->toBe(1)
        ->and($courant->effet)->toBe(['bonus_entree' => 2])
        ->and(MotsClesTerrain::connue('bonus_entree'))->toBeTrue();
});

it('pose le courant porteur sous le thème spirit_queen seulement, jamais dans la salle de départ', function () {
    $id = idCourantPorteur();
    $posees = 0;

    foreach (range(1, 40) as $graine) {
        $gabarit = GabaritQuete::query()->where('type_jalon', 'boss_final')->firstOrFail()->replicate();
        $gabarit->structure = [...$gabarit->structure, 'terrains' => ['min' => 0, 'max' => 0, 'courants_porteurs' => ['min' => 1, 'max' => 1]]];

        foreach ([null, 'horreur_des_glaces'] as $theme) {
            $carte = app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: $theme === null ? null : BestiaireGroupe::auto($theme));

            expect(collect($carte['terrain'])->contains(fn (array $t) => $t['terrain_id'] === $id))->toBeFalse("thème « {$theme} » : courant porteur posé");
        }

        $carte = app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: BestiaireGroupe::auto('spirit_queen'));

        foreach (collect($carte['terrain'])->where('terrain_id', $id) as $t) {
            $posees++;
            expect(Salles::indexDe($carte['salles'], $t['x'], $t['y']))->not->toBe(0, "graine {$graine} : courant porteur dans la salle de départ");
        }
    }

    expect($posees)->toBeGreaterThan(0, 'le courant porteur n\'a jamais été posé : le test ne prouve rien');
});

// =====================================================================
// 2. LE DÉPLACEMENT — la marche, le gain, le reste du tour
// =====================================================================

it('avec le courant, (8,0) devient une destination : entrer dans le courant donne 2 points, le budget est 5 + 2', function () {
    courantPorteurScene();
    courantPorteurDe(1);
    $cases = courantPorteurCases(courantPorteurMenu());

    expect($cases)->toContain('8,0')
        ->and($cases)->not->toContain('9,0');
});

it('sans courant porteur sur la carte, le dernier pas à cinq points est (6,0) : (7,0) coûte six, hors de portée', function () {
    courantPorteurScene(avecCourant: false);
    courantPorteurDe(1);
    $cases = courantPorteurCases(courantPorteurMenu());

    expect($cases)->toContain('6,0')
        ->and($cases)->not->toContain('7,0');
});

it('un héros qui va jusqu\'au bout du courant y gagne 2 points : il lui reste 1 point, et le fil dit « +2 cases »', function () {
    $ctx = courantPorteurScene();
    courantPorteurDe(1);
    courantPorteurMenu();

    // (7,0) : six points dépensés, sur un budget de cinq plus les deux du courant — il reste un point.
    courantPorteurChoisir('se_deplacer', ['x' => 7, 'y' => 0])->assertStatus(202);

    $etat = courantPorteurEtat($ctx)->fresh();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([7, 0])
        ->and((int) $etat->deplacement_restant)->toBe(1, '5 + 2 − 6 = 1 point restant ce tour');

    $fil = collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['journal_combat'] ?? [])->pluck('texte')->all();
    expect(implode(' | ', $fil))->toContain('profite du courant porteur : +2 cases');
});

it('l\'aperçu annonce le MÊME trajet et le MÊME reste que la résolution : (7,0) atteignable, 1 point restant, gain 2', function () {
    $ctx = courantPorteurScene();
    courantPorteurDe(1);
    courantPorteurMenu();

    $apercu = app(ResolveurTour::class)->apercuDeplacement(
        $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh(), courantPorteurEtat($ctx)->fresh(), 7, 0,
    );

    expect($apercu['atteignable'])->toBeTrue()
        ->and($apercu['cout'])->toBe(6)
        ->and($apercu['bonus_courant'])->toBe(2)
        ->and($apercu['restant_apres'])->toBe(1)
        ->and(array_map(fn (array $c) => $c['x'], $apercu['chemin']))->toBe([2, 3, 4, 5, 6, 7]);
});

it('un héros qui PART dans le courant n\'y gagne rien : avec un budget de 1, (5,0) reste hors d\'atteinte', function () {
    $ctx = courantPorteurScene();
    // Le tour est déjà tiré (budget 1) : le héros est en (3,0), DANS le courant.
    courantPorteurEtat($ctx)->update([
        'position_x' => 3, 'position_y' => 0,
        'deplacement_tour' => 1,
        'detail_deplacement_tour' => ['base' => 0, 'des' => [1], 'de_annule' => false, 'de_annule_par' => null, 'sans_menace' => false],
    ]);

    $cases = courantPorteurCases(courantPorteurMenu());

    expect($cases)->toContain('4,0')
        ->and($cases)->not->toContain('5,0');
});

it('un monstre ne reçoit JAMAIS le gain : sa grille ne voit pas (7,0) à cinq points', function () {
    $ctx = courantPorteurScene();

    $grilleMonstre = FabriqueGrille::pour($ctx['quete']->fresh()->load('carte'), exceptInstanceId: 999991);

    expect(array_keys($grilleMonstre->casesAtteignables(1, 0, 5)))->not->toContain('7,0')
        ->and($grilleMonstre->gainDuTrajet(1, 0, [['x' => 3, 'y' => 0]]))->toBe(0);
});

it('le courant porteur ne se prend qu\'UNE fois : le gain d\'un déplacement est celui d\'une seule entrée', function () {
    $ctx = courantPorteurScene();
    courantPorteurDe(1);
    courantPorteurMenu();

    // (8,0) à sept points : atteignable (5 + 2) ; si le gain se cumulait, (9,0) — huit points — le serait aussi.
    $apercu = app(ResolveurTour::class)->apercuDeplacement(
        $ctx['quete']->fresh()->load('carte'), $ctx['heros']->fresh(), courantPorteurEtat($ctx)->fresh(), 8, 0,
    );

    expect($apercu['atteignable'])->toBeTrue()
        ->and($apercu['bonus_courant'])->toBe(2)
        ->and($apercu['restant_apres'])->toBe(0);
});
