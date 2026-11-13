<?php

declare(strict_types=1);

use App\Models\Evenement;
use App\Models\InstanceMonstre;
use App\Partie\JournalCombat;
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
 * UNE ACTIVATION DE MONSTRE PUBLIE TOUT CE QU'ELLE A FAIT (contrat « L'activation alternée », 2026-11-12), en campagne :
 * un monstre qui marche puis frappe rend `[deplacement_monstre, attaque_monstre]` ; un tireur collé rend
 * `[repli_tireur, attaque_monstre]` ; une figure déjà au contact ne rend AUCUNE marche. Le fil dit la marche
 * (« avance de N case »), une seule fois au journal.
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

/**
 * Une case LIBRE à deux pas du héros, dans une direction où la case du milieu est libre aussi : le monstre y marche
 * d'une case, puis frappe. Renvoie la case du monstre (à deux pas), ou `null` s'il n'y en a aucune.
 */
function caseADeuxPasDuHeros(App\Models\Quete $quete, int $hx, int $hy): ?array
{
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $milieu = ['x' => $hx + $dx, 'y' => $hy + $dy];
        $loin = ['x' => $hx + 2 * $dx, 'y' => $hy + 2 * $dy];

        if (caseQueteLibre($quete, $milieu['x'], $milieu['y']) && caseQueteLibre($quete, $loin['x'], $loin['y'])) {
            return $loin;
        }
    }

    return null;
}

it('CAMPAGNE : un monstre qui marche puis frappe rend les DEUX actions, dans l\'ordre, avec le trajet réel', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $hero = $ctx['etatHeros'];
    $loin = caseADeuxPasDuHeros($ctx['quete'], (int) $hero->position_x, (int) $hero->position_y);
    expect($loin)->not->toBeNull('la carte doit offrir deux cases libres en ligne droite autour du héros');

    $ctx['instance']->update(['position_x' => $loin['x'], 'position_y' => $loin['y']]);
    desFiges(array_fill(0, 40, 4));

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202)->json('resultat');

    $instance = $ctx['instance']->fresh();
    $actions = collect($resultat['tour_monstres']['actions'] ?? []);
    $indexMarche = $actions->search(fn ($a) => ($a['type'] ?? null) === 'deplacement_monstre' && (int) ($a['id'] ?? 0) === $instance->id);
    $indexFrappe = $actions->search(fn ($a) => ($a['type'] ?? null) === 'attaque_monstre' && (int) ($a['instance_id'] ?? 0) === $instance->id);

    expect($indexMarche)->not->toBeFalse('la marche doit être publiée, pas seulement la frappe')
        ->and($indexFrappe)->not->toBeFalse()
        ->and($indexMarche)->toBeLessThan($indexFrappe);

    $marche = $actions[$indexMarche];
    expect($marche['chemin'])->not->toBeEmpty()
        ->and([(int) $marche['vers']['x'], (int) $marche['vers']['y']])->toBe([(int) $instance->position_x, (int) $instance->position_y]);

    // Le fil dit la marche, en direct (résultat) comme au rejeu (journal) — et le journal ne la porte qu'une fois.
    $n = count($marche['chemin']);
    $texte = $n === 1 ? 'Gobelin avance de 1 case' : "Gobelin avance de {$n} cases";
    expect(collect(app(JournalCombat::class)->depuisResultat($resultat, 'Albrecht'))->pluck('texte')->all())->toContain($texte);

    $journalisees = Evenement::where('quete_id', $ctx['quete']->id)->where('type', 'action')->get()
        ->filter(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'deplacement_monstre' && (int) ($e->payload['id'] ?? 0) === $instance->id);
    expect($journalisees)->toHaveCount(1);
});

it('CAMPAGNE : un tireur collé RECULE puis TIRE, et la réponse publie les deux, dans l\'ordre', function () {
    $ctx = demarrerQueteAvecMonstre('Archer elfe');
    $depart = ['x' => (int) $ctx['instance']->position_x, 'y' => (int) $ctx['instance']->position_y];
    desFiges(array_fill(0, 40, 4));

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202)->json('resultat');

    $archer = $ctx['instance']->fresh();
    $arrivee = ['x' => (int) $archer->position_x, 'y' => (int) $archer->position_y];

    if ($arrivee === $depart) {
        // Encerclé : il frappe sur place, rien à publier de plus. Le cas n'est pas celui de ce test.
        expect(collect($resultat['tour_monstres']['actions'] ?? [])->contains(fn ($a) => ($a['type'] ?? null) === 'repli_tireur'))
            ->toBeFalse();

        return;
    }

    $actions = collect($resultat['tour_monstres']['actions'] ?? []);
    $indexRepli = $actions->search(fn ($a) => ($a['type'] ?? null) === 'repli_tireur' && (int) ($a['id'] ?? 0) === $archer->id);
    $indexTir = $actions->search(fn ($a) => ($a['type'] ?? null) === 'attaque_monstre' && (int) ($a['instance_id'] ?? 0) === $archer->id);

    expect($indexRepli)->not->toBeFalse('le repli doit être publié, pas seulement journalisé')
        ->and($indexTir)->not->toBeFalse('le tir doit suivre le repli')
        ->and($indexRepli)->toBeLessThan($indexTir)
        ->and($actions[$indexTir]['portee'])->toBe('distance');

    // Le repli est journalisé UNE fois (sa propre ligne), et le fil le dit.
    $journalises = Evenement::where('quete_id', $ctx['quete']->id)->where('type', 'action')->get()
        ->filter(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'repli_tireur');
    expect($journalises)->toHaveCount(1);
    expect(collect(app(JournalCombat::class)->depuisResultat($resultat, 'Albrecht'))->pluck('texte')->contains(
        fn (string $t) => str_contains($t, 'recule'),
    ))->toBeTrue();
});
