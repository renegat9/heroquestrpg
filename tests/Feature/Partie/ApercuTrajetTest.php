<?php

declare(strict_types=1);

use App\Models\Piege;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * APERÇU DU TRAJET (René, 2026-09-17 : « que la figure utilise le vrai chemin »)
 * et MUR DE GLACE publié.
 *
 * Le trajet n'est pas décoratif : `MoteurPieges::controlerChemin()` contrôle les
 * pièges CASE PAR CASE dessus, et le joueur ne désignait qu'une destination — il
 * découvrait la route à l'animation. `POST deplacement/apercu` la lui montre
 * d'abord, et ces tests verrouillent les deux propriétés qui la rendent honnête :
 * c'est le MÊME chemin que celui parcouru, et il ne révèle RIEN.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, CompetenceSeeder::class, ClasseHerosSeeder::class]);
});

/** Une case de sol libre à `$pas` cases du héros, sur un axe dégagé. */
function caseDeDestination(App\Models\Quete $quete, int $hx, int $hy, int $pas = 2): array
{
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $ok = true;
        for ($i = 1; $i <= $pas; $i++) {
            if (! caseQueteLibre($quete, $hx + $i * $dx, $hy + $i * $dy)) {
                $ok = false;
                break;
            }
        }
        if ($ok) {
            return ['x' => $hx + $pas * $dx, 'y' => $hy + $pas * $dy];
        }
    }

    throw new RuntimeException('Aucun axe dégagé autour du héros — scénario de test invalide.');
}

it('rend le chemin EXACT que le héros parcourra ensuite', function () {
    ['alice' => $alice, 'quete' => $quete, 'instance' => $gobelin, 'etatHeros' => $etat] = demarrerQueteAvecMonstre('Gobelin');
    $gobelin->update(['etat' => 'vaincu']); // il ne doit ni bloquer ni dérouter

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $cible = caseDeDestination($quete->fresh(), $hx, $hy, 2);
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => null, 'a_deplace' => false]);

    $apercu = $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/deplacement/apercu', $cible)
        ->assertOk()
        ->json();

    expect($apercu['atteignable'])->toBeTrue()
        ->and($apercu['chemin'])->toHaveCount(2)
        ->and(end($apercu['chemin']))->toBe($cible)
        ->and($apercu['cout'])->toBe(2)
        ->and($apercu['restant_apres'])->toBe(4);

    // ⚠ LA propriété : le chemin ANNONCÉ est celui qui sera PARCOURU. Deux
    // routes de même coût n'exposent pas aux mêmes pièges — un aperçu qui en
    // montre une autre est pire que pas d'aperçu du tout.
    $resultat = $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $cible,
    ])->assertStatus(202)->json('resultat');

    expect($resultat['chemin'])->toBe($apercu['chemin']);
});

it('refuse une destination hors de portée en le DISANT, sans rien dépenser', function () {
    ['alice' => $alice, 'quete' => $quete, 'instance' => $gobelin, 'etatHeros' => $etat] = demarrerQueteAvecMonstre('Gobelin');
    $gobelin->update(['etat' => 'vaincu']);

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $cible = caseDeDestination($quete->fresh(), $hx, $hy, 2);
    $etat->update(['deplacement_tour' => 1, 'deplacement_restant' => 1, 'a_deplace' => false]);

    $apercu = $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/deplacement/apercu', $cible)
        ->assertOk()
        ->json();

    expect($apercu['atteignable'])->toBeFalse()
        ->and($apercu['raison'])->toContain('hors de portée')
        ->and($apercu['restant_apres'])->toBe(1);

    // Regarder ne coûte rien : le héros n'a pas bougé, ses points sont intacts.
    $frais = $etat->fresh();
    expect([(int) $frais->position_x, (int) $frais->position_y])->toBe([$hx, $hy])
        ->and((int) $frais->deplacement_restant)->toBe(1);
});

it('ne révèle AUCUN piège caché — seulement ceux que la carte montre déjà', function () {
    ['alice' => $alice, 'quete' => $quete, 'instance' => $gobelin, 'etatHeros' => $etat] = demarrerQueteAvecMonstre('Gobelin');
    $gobelin->update(['etat' => 'vaincu']);

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $cible = caseDeDestination($quete->fresh(), $hx, $hy, 2);
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => null, 'a_deplace' => false]);

    $fosse = Piege::where('nom', 'Fosse')->firstOrFail();
    $carte = $quete->fresh()->carte;
    $grille = $carte->grille;
    // Un piège CACHÉ pile sur la case visée : l'aperçu ne doit pas le nommer,
    // sinon il devient un détecteur de pièges gratuit.
    $grille['pieges'] = [['x' => $cible['x'], 'y' => $cible['y'], 'piege_id' => $fosse->id, 'etat' => 'cache']];
    $carte->update(['grille' => $grille]);

    $apercu = $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/deplacement/apercu', $cible)
        ->assertOk()->json();

    expect($apercu['pieges'])->toBe([]);

    // Le MÊME piège, une fois DÉTECTÉ, est annoncé : il est déjà sur la carte,
    // le trajet ne fait que rappeler qu'il passe dessus.
    $grille['pieges'][0]['etat'] = 'detecte';
    $carte->update(['grille' => $grille]);

    $apercu = $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/deplacement/apercu', $cible)
        ->assertOk()->json();

    expect($apercu['pieges'])->toHaveCount(1)
        ->and($apercu['pieges'][0]['nom'])->toBe('Fosse')
        ->and($apercu['pieges'][0]['etat'])->toBe('detecte');
});

it('publie les MURS DE GLACE et refuse de marcher dessus', function () {
    ['alice' => $alice, 'quete' => $quete, 'instance' => $gobelin, 'etatHeros' => $etat] = demarrerQueteAvecMonstre('Gobelin');
    $gobelin->update(['etat' => 'vaincu']);

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $cible = caseDeDestination($quete->fresh(), $hx, $hy, 2);
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => null, 'a_deplace' => false]);

    // Mur de glace SUR la case visée (couche dédiée `grille['glace']`, posée en
    // jeu par le sort du boss — jamais le catalogue `terrains`).
    $carte = $quete->fresh()->carte;
    $grille = $carte->grille;
    $grille['glace'] = [['x' => $cible['x'], 'y' => $cible['y'], 'source_instance_id' => $gobelin->id, 'cranes' => 2]];
    $carte->update(['grille' => $grille]);

    // 1. Il est PUBLIÉ — c'était tout le défaut : le moteur le lisait, les deux
    //    écrans l'ignoraient (« une couche publiée nulle part », cf. leviers).
    $publie = $this->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/etat')
        ->assertOk()->json('carte.glace');

    expect($publie)->toHaveCount(1)
        ->and($publie[0]['x'])->toBe($cible['x'])
        ->and($publie[0]['y'])->toBe($cible['y'])
        ->and($publie[0]['cranes'])->toBe(2)
        // L'entretien du sort n'est pas une information de jeu.
        ->and($publie[0])->not->toHaveKey('source_instance_id');

    // 2. L'aperçu refuse d'y mener, comme le résolveur.
    $apercu = $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/deplacement/apercu', $cible)
        ->assertOk()->json();

    expect($apercu['atteignable'])->toBeFalse();

    $this->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $cible,
    ])->assertStatus(422);

    expect([(int) $etat->fresh()->position_x, (int) $etat->fresh()->position_y])->toBe([$hx, $hy]);
});

it('ne publie pas un mur de glace resté dans le brouillard', function () {
    ['alice' => $alice, 'quete' => $quete] = demarrerQueteAvecMonstre('Gobelin');

    $cases = $this->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/etat')
        ->assertOk()->json('carte.cases');

    $brouillee = null;
    foreach ($cases as $y => $ligne) {
        foreach ($ligne as $x => $valeur) {
            if ($valeur === 'b') {
                $brouillee = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }

    expect($brouillee)->not->toBeNull('carte sans brouillard — scénario de test invalide');

    $carte = $quete->fresh()->carte;
    $grille = $carte->grille;
    $grille['glace'] = [[...$brouillee, 'source_instance_id' => 1, 'cranes' => 0]];
    $carte->update(['grille' => $grille]);

    // Même critère que les leviers et le terrain : publier une case brouillée
    // contournerait le brouillard par la porte de derrière.
    expect($this->actingAs($alice, 'joueur')->getJson('/api/groupes/table-1/etat')
        ->assertOk()->json('carte.glace'))->toBe([]);
});
