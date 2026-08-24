<?php

declare(strict_types=1);

use App\Models\Evenement;
use App\Partie\Grille;
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
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Stratégie du monstre à DISTANCE (René, 2026-08-23).
 *
 * ⚠ Décision de PORTAGE, pas une règle : aucun livret ne décrit d'IA. Ce qui la
 * motive est dans les fiches — l'Archer elfe attaque à 4 dés, **1 au contact**.
 * Le moteur le poussait pourtant au corps-à-corps dans les deux cas : collé il
 * frappait sans décrocher, et sans ligne de mire il visait une case ADJACENTE
 * au héros comme un corps-à-corps. Il se privait de son arme tout seul.
 *
 * ⚠ Le recul est borné à la SALLE (René) : sans borne, l'archer devient un
 * kiteur qu'un héros de mêlée ne rattrape jamais dans un couloir.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

/** Le monstre est-il au contact (Manhattan 1) d'un héros debout ? */
function auContactDUnHeros(App\Models\Quete $quete, int $x, int $y): bool
{
    return $quete->etatsPersonnages()->where('tombe', false)->get()->contains(
        fn ($h) => abs((int) $h->position_x - $x) + abs((int) $h->position_y - $y) === 1,
    );
}

it('collé à un héros, l’archer RECULE puis tire, au lieu de frapper à 1 dé', function () {
    $ctx = demarrerQueteAvecMonstre('Archer elfe');
    $depart = ['x' => (int) $ctx['instance']->position_x, 'y' => (int) $ctx['instance']->position_y];

    expect(auContactDUnHeros($ctx['quete'], $depart['x'], $depart['y']))
        ->toBeTrue('la scène doit partir AU CONTACT, sinon le test ne prouve rien');

    desFiges(array_fill(0, 40, 4)); // boucliers : personne ne tombe, la scène tient
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $archer = $ctx['instance']->fresh();
    $arrivee = ['x' => (int) $archer->position_x, 'y' => (int) $archer->position_y];

    // A-t-il eu une échappatoire ? On le vérifie plutôt que de l'admettre —
    // sinon le test passerait même si le repli ne tournait pas du tout.
    $grille = Grille::depuisCarte($ctx['quete']->carte);
    $echappatoire = false;

    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $x = $depart['x'] + $dx;
        $y = $depart['y'] + $dy;

        if ($grille->estTraversable($x, $y) && ! auContactDUnHeros($ctx['quete'], $x, $y)) {
            $echappatoire = true;
            break;
        }
    }

    if (! $echappatoire) {
        expect($arrivee)->toBe($depart, 'encerclé : il frappe sur place, refus silencieux');

        return;
    }

    expect($arrivee)->not->toBe($depart, 'il avait une case de repli et n’a pas bougé')
        ->and(auContactDUnHeros($ctx['quete'], $arrivee['x'], $arrivee['y']))
        ->toBeFalse('reculer pour rester au contact n’est pas reculer');

    // …et il TIRE : c'est tout l'objet du repli.
    $tir = Evenement::where('quete_id', $ctx['quete']->id)->where('type', 'combat')
        ->get()->first(fn (Evenement $e) => ($e->payload['portee'] ?? null) === 'distance');

    expect($tir)->not->toBeNull('après le repli, l’archer doit tirer à distance');
});

it('trace le repli pour que la table l’anime et que le journal le dise', function () {
    $ctx = demarrerQueteAvecMonstre('Archer elfe');
    $depart = ['x' => (int) $ctx['instance']->position_x, 'y' => (int) $ctx['instance']->position_y];

    desFiges(array_fill(0, 40, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    if ((int) $ctx['instance']->fresh()->position_x === $depart['x']
        && (int) $ctx['instance']->fresh()->position_y === $depart['y']) {
        expect(true)->toBeTrue(); // encerclé : rien à tracer

        return;
    }

    $repli = Evenement::where('quete_id', $ctx['quete']->id)->where('type', 'action')
        ->get()->first(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'repli_tireur');

    // Un effet automatique que rien n'annonce est injouable : la figurine
    // glisserait puis tirerait sans qu'aucune ligne n'explique pourquoi.
    expect($repli)->not->toBeNull('le repli doit avoir sa propre ligne de journal')
        ->and($repli->payload['depart'])->toBe($depart)
        ->and($repli->payload['chemin'])->not->toBeEmpty()
        ->and($repli->payload['vers'])->not->toBe($depart);
});

it('ne sort JAMAIS de sa salle en reculant', function () {
    $ctx = demarrerQueteAvecMonstre('Archer elfe');
    $depart = ['x' => (int) $ctx['instance']->position_x, 'y' => (int) $ctx['instance']->position_y];

    $salles = (array) data_get($ctx['quete']->carte->grille, 'salles', []);
    $dans = fn (array $s, int $x, int $y) => $x >= (int) $s['x'] && $x < (int) $s['x'] + (int) $s['largeur']
        && $y >= (int) $s['y'] && $y < (int) $s['y'] + (int) $s['hauteur'];
    $salleDepart = collect($salles)->first(fn ($s) => $dans($s, $depart['x'], $depart['y']));

    desFiges(array_fill(0, 40, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $archer = $ctx['instance']->fresh();
    $arrivee = ['x' => (int) $archer->position_x, 'y' => (int) $archer->position_y];

    if ($salleDepart === null) {
        // En couloir : pas de recul du tout, c'est la borne dans l'autre sens.
        expect($arrivee)->toBe($depart, 'hors salle, il ne recule pas');

        return;
    }

    expect($dans($salleDepart, $arrivee['x'], $arrivee['y']))
        ->toBeTrue('le repli est borné à la salle courante — sinon l’archer kite');
});

it('casesAtteignables borne le parcours et rend un chemin par case', function () {
    // Salle 5×5 de sol, murée : 'm' = mur, 's' = sol.
    $cases = [];
    for ($y = 0; $y < 7; $y++) {
        for ($x = 0; $x < 7; $x++) {
            $cases[$y][$x] = ($x === 0 || $y === 0 || $x === 6 || $y === 6) ? 'm' : 's';
        }
    }

    $grille = new Grille($cases);
    $a2 = $grille->casesAtteignables(3, 3, 2);
    $a1 = $grille->casesAtteignables(3, 3, 1);

    expect($a1)->toHaveCount(4)                       // les 4 voisins orthogonaux
        ->and(count($a2))->toBeGreaterThan(count($a1)) // 2 pas portent plus loin
        ->and($a2['3,1'])->toHaveCount(2)              // chemin de 2 étapes
        ->and($a2['3,1'][1])->toBe(['x' => 3, 'y' => 1])
        ->and($a2)->not->toHaveKey('0,3')              // le mur n'est pas franchi
        ->and($grille->casesAtteignables(3, 3, 0))->toBe([]);
});
