<?php

declare(strict_types=1);

use App\Models\Quete;
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
use Illuminate\Support\Facades\Http;

/**
 * `attaque_diagonale` CÔTÉ MONSTRE (Assassin, Rise of the Dread Moon, carte
 * scannée le 2026-10-05 : « Each Assassin may attack diagonally. »). Le
 * mot-clé avait déjà deux lecteurs — les armes longues
 * (`effet['attaque_diagonale']`, `ResolveurTour::frapper()`) et les
 * mercenaires (`merc->capacites`, `resoudreAttaqueDAllie()`) — jamais un
 * monstre. Un trait déclaré au catalogue ne prouve rien (`BestiaireSourceTest`
 * ne vérifie que ça) : ce fichier vérifie qu'il AGIT, en jeu, sur une vraie
 * quête — un Assassin en diagonale d'un héros l'attaque, un Gobelin en
 * diagonale ne l'attaque pas.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Isole une case DIAGONALE au héros (hx, hy) dans une poche à deux cases
 * entièrement murée — AUCUNE route orthogonale, même longue, ne relie les
 * deux cases. Sans ce verrouillage, un monstre au large déplacement
 * (Gobelin : 10) contournerait en un pas jusqu'à une case orthogonalement
 * adjacente et attaquerait quand même CE tour-ci, ce qui ne prouverait rien
 * sur la diagonale elle-même — même mise en garde que
 * `MobiliteCombatRogueTest::forcerCouloirVersMonstre()`, qui mure un couloir
 * pour la même raison, sur une autre mécanique.
 *
 * @return array{x: int, y: int} la case du monstre
 */
function isolerPocheDiagonale(Quete $quete, int $hx, int $hy): array
{
    $carte = $quete->carte;
    $largeur = $carte->largeur;
    $hauteur = $carte->hauteur;

    $diagonale = null;
    foreach ([[1, 1], [1, -1], [-1, 1], [-1, -1]] as [$dx, $dy]) {
        $xs = [$hx - $dx, $hx, $hx + $dx, $hx + 2 * $dx];
        $ys = [$hy - $dy, $hy, $hy + $dy, $hy + 2 * $dy];
        if (min($xs) >= 0 && max($xs) < $largeur && min($ys) >= 0 && max($ys) < $hauteur) {
            $diagonale = [$dx, $dy];
            break;
        }
    }

    expect($diagonale)->not->toBeNull('aucune diagonale avec assez de marge autour du héros');
    [$dx, $dy] = $diagonale;
    $mx = $hx + $dx;
    $my = $hy + $dy;

    $grille = $carte->grille;
    $grille['cases'][$hy][$hx] = 's';
    $grille['cases'][$my][$mx] = 's';

    // Les 6 cases du pourtour orthogonal de la poche à 2 cases (H et M) :
    // les 2 « raccourcis » partagés par H et M, les 2 autres flancs de H, et
    // les 2 flancs plus loin de M. Murer les 8 cases (2 sol + 6 murs) isole
    // totalement la poche — aucun chemin orthogonal n'existe plus vers M.
    foreach ([
        [$hx + $dx, $hy], [$hx, $hy + $dy],
        [$hx - $dx, $hy], [$hx, $hy - $dy],
        [$mx + $dx, $my], [$mx, $my + $dy],
    ] as [$wx, $wy]) {
        $grille['cases'][$wy][$wx] = 'm';
    }

    $carte->update(['grille' => $grille]);
    $quete->refresh();

    return ['x' => $mx, 'y' => $my];
}

it('fait attaquer un Assassin en diagonale d\'un héros, sans aucun chemin orthogonal possible', function () {
    $ctx = demarrerQueteAvecMonstre('Assassin');
    $hx = (int) $ctx['etatHeros']->position_x;
    $hy = (int) $ctx['etatHeros']->position_y;

    $poche = isolerPocheDiagonale($ctx['quete'], $hx, $hy);
    $ctx['instance']->update(['position_x' => $poche['x'], 'position_y' => $poche['y']]);

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $actions = collect($reponse->json('resultat.tour_monstres.actions'));
    $attaque = $actions->firstWhere('type', 'attaque_monstre');

    expect($attaque)->not->toBeNull('l\'Assassin en diagonale aurait dû attaquer ce tour-ci')
        ->and($attaque['monstre'])->toBe('Assassin')
        ->and($attaque['cible']['personnage_id'])->toBe($ctx['heros']->id);
});

it('ne fait PAS attaquer un Gobelin en diagonale d\'un héros, à la même position bloquée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $hx = (int) $ctx['etatHeros']->position_x;
    $hy = (int) $ctx['etatHeros']->position_y;

    $poche = isolerPocheDiagonale($ctx['quete'], $hx, $hy);
    $ctx['instance']->update(['position_x' => $poche['x'], 'position_y' => $poche['y']]);

    desFiges(array_fill(0, 200, 4));

    $reponse = test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);

    $actions = collect($reponse->json('resultat.tour_monstres.actions'));
    $attaque = $actions->firstWhere('type', 'attaque_monstre');

    expect($attaque)->toBeNull('un Gobelin sans `attaque_diagonale` ne doit pas toucher le héros depuis une case diagonale sans chemin orthogonal');

    // Trappé par la poche murée, il ne peut même pas bouger : il reste
    // immobile plutôt que de téléporter ou de forcer un mur.
    $gobelin = $ctx['instance']->fresh();
    expect((int) $gobelin->position_x)->toBe($poche['x'])
        ->and((int) $gobelin->position_y)->toBe($poche['y']);
});
