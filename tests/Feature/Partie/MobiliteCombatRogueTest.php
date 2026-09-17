<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\EtatPersonnageQuete;
use App\Models\Mobilier;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\MenuMoteur;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * MOBILITÉ DE COMBAT (Rogue) — René, 2026-09-11, en partie réelle : « la
 * mobilité de combat du voleur ne permet pas de se déplacer à travers les
 * ennemis ». Le moteur avait toujours raison (`ResolveurTour::resoudreDeplacer()`
 * levait déjà les figures pour un porteur de `franchit_figures`) : c'était le
 * miroir client (`DeplacementSheet.vue`) qui traitait tout monstre comme un mur
 * SANS CONDITION, faute d'un moyen de savoir si CE héros porte le talent.
 *
 * `MoteurSorts::mobiliteCombatDisponible()` est désormais le seul point de
 * passage de cette question (`ResolveurTour`, `EtatGroupe`,
 * `MenuMoteur::peutSeDeplacer()`) — ces tests le verrouillent EN JEU, sur une
 * quête réelle, jamais au catalogue.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, CompetenceSeeder::class, ClasseHerosSeeder::class]);
});

/**
 * Force un couloir DROIT d'une case de large, à sens unique, autour de trois
 * cases alignées H (départ) → M (le monstre) → B (au-delà) : les deux flancs
 * perpendiculaires ET la case derrière H sont murés, de sorte qu'AUCUNE autre
 * route n'existe entre H et B — sans ce verrouillage, un round-trip HTTP sur
 * une carte ouverte contournerait le monstre et ne prouverait rien (c'est
 * exactement la mise en garde de `DeplacementTest::« ne franchit PAS un
 * monstre »`, qui teste la grille directement pour cette raison).
 *
 * @return array{m: array{x:int,y:int}, b: array{x:int,y:int}}
 */
function forcerCouloirVersMonstre(Quete $quete, int $hx, int $hy): array
{
    $carte = $quete->carte;
    $largeur = $carte->largeur;
    $hauteur = $carte->hauteur;

    $axe = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $ok = true;
        foreach ([-1, 1, 2] as $pas) {
            $x = $hx + $pas * $dx;
            $y = $hy + $pas * $dy;
            if ($x < 0 || $y < 0 || $x >= $largeur || $y >= $hauteur) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            $axe = [$dx, $dy];
            break;
        }
    }

    expect($axe)->not->toBeNull('aucun axe avec assez de marge autour du héros');
    [$dx, $dy] = $axe;
    [$pdx, $pdy] = $dx !== 0 ? [0, 1] : [1, 0];

    $m = ['x' => $hx + $dx, 'y' => $hy + $dy];
    $b = ['x' => $hx + 2 * $dx, 'y' => $hy + 2 * $dy];
    $derriere = ['x' => $hx - $dx, 'y' => $hy - $dy];

    $grille = $carte->grille;
    foreach ([[$hx, $hy], [$m['x'], $m['y']], [$b['x'], $b['y']]] as [$cx, $cy]) {
        $grille['cases'][$cy][$cx] = 's';
        foreach ([[$pdx, $pdy], [-$pdx, -$pdy]] as [$ddx, $ddy]) {
            $nx = $cx + $ddx;
            $ny = $cy + $ddy;
            if ($nx >= 0 && $ny >= 0 && $nx < $largeur && $ny < $hauteur) {
                $grille['cases'][$ny][$nx] = 'm';
            }
        }
    }
    $grille['cases'][$derriere['y']][$derriere['x']] = 'm';

    $carte->update(['grille' => $grille]);
    $quete->refresh();

    return ['m' => $m, 'b' => $b];
}

it('un ROGUE franchit un monstre pour atteindre une case au-delà : la destination est acceptée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'rogue']);
    ['alice' => $alice, 'quete' => $quete, 'instance' => $gobelin, 'etatHeros' => $etat] = $ctx;

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    ['m' => $m, 'b' => $b] = forcerCouloirVersMonstre($quete, $hx, $hy);

    $gobelin->update(['position_x' => $m['x'], 'position_y' => $m['y'], 'revele' => true]);
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => null, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $b,
    ])->assertStatus(202)->assertJsonPath('resultat.vers', $b);

    expect([(int) $etat->fresh()->position_x, (int) $etat->fresh()->position_y])->toBe([$b['x'], $b['y']]);
});

it('un héros SANS le talent ne peut pas franchir le même monstre pour l\'atteindre', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin'); // barbare par défaut, pas de franchit_figures
    ['alice' => $alice, 'quete' => $quete, 'instance' => $gobelin, 'etatHeros' => $etat] = $ctx;

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    ['m' => $m, 'b' => $b] = forcerCouloirVersMonstre($quete, $hx, $hy);

    $gobelin->update(['position_x' => $m['x'], 'position_y' => $m['y'], 'revele' => true]);
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => null, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $b,
    ])->assertStatus(422)->assertJsonPath(
        'errors.parametres.0',
        'Destination inaccessible (mur, case occupée ou sur place).',
    );

    expect([(int) $etat->fresh()->position_x, (int) $etat->fresh()->position_y])->toBe([$hx, $hy]);
});

it('EtatGroupe publie franchit_figures : vrai pour un Rogue, faux pour un héros sans le talent', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $rogue = creerHeros($alice, $groupe, 'Voleuse', 1, ['classe' => 'rogue']);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $barbare = creerHeros($bob, $groupe, 'Grondin', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $entites = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites');

    $entRogue = collect($entites)->firstWhere('id', $rogue->id);
    $entBarbare = collect($entites)->firstWhere('id', $barbare->id);

    expect($entRogue)->not->toBeNull()->and($entRogue['franchit_figures'])->toBeTrue()
        ->and($entBarbare)->not->toBeNull()->and($entBarbare['franchit_figures'])->toBeFalse();
});

it('MenuMoteur::peutSeDeplacer garde « Se déplacer » pour un Rogue encerclé de MONSTRES', function () {
    // Même trou que le miroir client, côté MENU cette fois : un Rogue dont les
    // 4 voisins sont des monstres n'a, à s'en tenir à un simple test de
    // voisinage, AUCUN pas immédiat — pourtant le résolveur le laisserait
    // avancer en les traversant.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $rogue = creerHeros($alice, $groupe, 'Voleuse', 1, ['classe' => 'rogue']);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $rogue->id)->firstOrFail();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $voisins = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    $carte = $quete->carte;
    $grille = $carte->grille;
    foreach ($voisins as [$dx, $dy]) {
        $grille['cases'][$hy + $dy][$hx + $dx] = 's';
    }
    // Échappée à 2 pas, à l'est — seulement atteignable EN TRAVERSANT le
    // monstre planté sur (hx+1, hy).
    $grille['cases'][$hy][$hx + 2] = 's';
    $carte->update(['grille' => $grille]);
    $quete->refresh();

    $gobelinCatalogue = Monstre::where('nom_base', 'Gobelin')->firstOrFail(); // 1×1, pas de grande emprise

    foreach ($voisins as [$dx, $dy]) {
        $quete->instancesMonstres()->create([
            'monstre_id' => $gobelinCatalogue->id,
            'position_x' => $hx + $dx, 'position_y' => $hy + $dy,
            'pv_body' => 5, 'pv_body_max' => 5, 'pv_mind' => 1,
            'etat' => 'actif', 'revele' => true,
        ]);
    }

    desFiges(array_fill(0, 20, 4));
    $menu = app(MenuMoteur::class)->generer($groupe->fresh(), $rogue->fresh());

    expect(collect($menu['options'])->firstWhere('type', 'deplacement'))->not->toBeNull();
});

/*
 * « On se retrouve parfois à se déplacer à travers les mobiliers » (René,
 * 2026-09-17). La mobilité de combat passait par `Grille::autoriserFranchissement()`,
 * le mode AGILE des monstres, qui efface AUSSI le mobilier et le terrain
 * bloquant. La carte du Rogue ne parle que des cases OCCUPÉES par des monstres :
 * une table reste une table.
 *
 * `MobilierSeeder` n'est semé qu'ICI, APRÈS le départ de la quête : semé dans le
 * `beforeEach`, il meublerait les cartes générées et les couloirs forcés
 * ci-dessus pourraient buter sur un meuble posé par l'assembleur.
 */

/** Remplace le mobilier de la carte par une Table 1×1 sur chacune des cases. */
function poserTables(Quete $quete, array $cases): void
{
    test()->seed(MobilierSeeder::class);
    $table = Mobilier::where('nom', 'Table')->firstOrFail();

    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['mobilier'] = array_map(fn (array $c) => [
        'mobilier_id' => $table->id, 'x' => $c['x'], 'y' => $c['y'], 'l' => 1, 'h' => 1,
    ], $cases);
    $carte->update(['grille' => $grille]);
    $quete->refresh();
}

it('un ROGUE franchit un monstre mais JAMAIS une table : la case au-delà du meuble est refusée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'rogue']);
    ['alice' => $alice, 'quete' => $quete, 'instance' => $gobelin, 'etatHeros' => $etat] = $ctx;

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    ['m' => $m, 'b' => $b] = forcerCouloirVersMonstre($quete, $hx, $hy);

    // Le seul chemin vers B passe par la table. Le gobelin est retiré du
    // couloir : c'est le MEUBLE seul qui doit barrer.
    poserTables($quete, [$m]);
    $gobelin->update(['etat' => 'vaincu']);
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => null, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    expect(app(\App\Partie\MoteurSorts::class)->mobiliteCombatDisponible($ctx['heros']->fresh()))->toBeTrue();

    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $b,
    ])->assertStatus(422)->assertJsonPath(
        'errors.parametres.0',
        'Destination inaccessible (mur, case occupée ou sur place).',
    );

    expect([(int) $etat->fresh()->position_x, (int) $etat->fresh()->position_y])->toBe([$hx, $hy]);
});

it('MenuMoteur::peutSeDeplacer retire « Se déplacer » à un Rogue cerné de TABLES', function () {
    // Pendant du test « encerclé de MONSTRES » ci-dessus : la même échappée à
    // deux pas, mais derrière quatre meubles. Le résolveur la refuse désormais,
    // le menu ne doit donc plus la promettre.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $rogue = creerHeros($alice, $groupe, 'Voleuse', 1, ['classe' => 'rogue']);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $rogue->id)->firstOrFail();

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $voisins = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    $carte = $quete->carte;
    $grille = $carte->grille;
    foreach ($voisins as [$dx, $dy]) {
        $grille['cases'][$hy + $dy][$hx + $dx] = 's';
    }
    $grille['cases'][$hy][$hx + 2] = 's';
    $carte->update(['grille' => $grille]);
    $quete->refresh();

    poserTables($quete, array_map(fn (array $d) => ['x' => $hx + $d[0], 'y' => $hy + $d[1]], $voisins));

    desFiges(array_fill(0, 20, 4));
    $menu = app(MenuMoteur::class)->generer($groupe->fresh(), $rogue->fresh());

    expect(collect($menu['options'])->firstWhere('type', 'deplacement'))->toBeNull();
});
