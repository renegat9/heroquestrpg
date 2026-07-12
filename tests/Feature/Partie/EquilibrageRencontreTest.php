<?php

declare(strict_types=1);

use App\Partie\DemarreurQuete;
use Database\Seeders\MonstreSeeder;

/*
 * Composition des rencontres (correctifs §3, config jeu.rencontres) : le budget
 * achète « BEAUCOUP de faibles + QUELQUES forts » au lieu de se remplir avec les
 * monstres de base les plus chers. On teste directement acheterMonstres (méthode
 * privée) pour un résultat déterministe, indépendant de la carte.
 */

beforeEach(function () {
    $this->seed(MonstreSeeder::class);
});

/** @return list<App\Models\Monstre> */
function acheter(int $budget, int $maxSpawns, int $positionArc, array $structure = []): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    return $methode->invoke($demarreur, $structure, $budget, $maxSpawns, $positionArc);
}

$forts = fn (array $a) => array_values(array_filter($a, fn ($m) => $m->tier === 'base' && (int) $m->cout > 3));
$faibles = fn (array $a) => array_values(array_filter($a, fn ($m) => $m->tier === 'base' && (int) $m->cout <= 3));

it('sème beaucoup de faibles et peu de forts (quête normale, arc 1)', function () use ($forts, $faibles) {
    $achats = acheter(budget: 20, maxSpawns: 20, positionArc: 1);

    expect(count($faibles($achats)))->toBeGreaterThan(count($forts($achats)))
        ->and(count($forts($achats)))->toBeLessThanOrEqual(1) // forts_par_quete = 1 à l'arc 1
        // Beaucoup plus d'ennemis que l'ancien glouton (qui prenait ~3 Gargouilles).
        ->and(count($achats))->toBeGreaterThanOrEqual(6)
        // Le budget n'est jamais dépassé.
        ->and(array_sum(array_map(fn ($m) => (int) $m->cout, $achats)))->toBeLessThanOrEqual(20);
});

it('escalade le nombre de forts avec la progression d\'arc', function () use ($forts) {
    $arc1 = count($forts(acheter(budget: 30, maxSpawns: 20, positionArc: 1)));
    $arc5 = count($forts(acheter(budget: 30, maxSpawns: 20, positionArc: 5)));

    // forts_escalade_arc = 2 → +1 fort tous les 2 crans : l'arc 5 en a plus que l'arc 1.
    expect($arc5)->toBeGreaterThan($arc1);
});

it('garde la rencontre finale en tête et n\'empêche pas la masse de faibles', function () use ($faibles) {
    $achats = acheter(budget: 30, maxSpawns: 20, positionArc: 1, structure: [
        'rencontre_finale' => ['tier' => 'boss'],
    ]);

    expect($achats[0]->tier)->toBe('boss')            // le boss d'abord (inchangé)
        ->and(count($faibles($achats)))->toBeGreaterThan(0); // et de la piétaille autour
});

it('ne bloque jamais : budget serré → au moins un ennemi faible', function () {
    $achats = acheter(budget: 1, maxSpawns: 5, positionArc: 1);

    expect($achats)->not->toBeEmpty()
        ->and((int) $achats[0]->cout)->toBeLessThanOrEqual(3);
});
