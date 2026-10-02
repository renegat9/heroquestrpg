<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Partie\JalonsCampagne;

/*
 * JALONS DE L'ARC — sans plan de campagne (pas de clé d'API), le repli plaçait
 * le seul boss final : une campagne de vingt quêtes ne croisait AUCUN
 * sous-boss (René, 2026-10-02). Il applique désormais la cadence exigée de
 * l'IA.
 */

it('place sans plan autant de sous-boss que l\'IA doit en placer, régulièrement avant le final', function (int $quetes, array $attendues) {
    $groupe = new Groupe(['nb_quetes_total' => $quetes, 'plan_campagne' => null]);
    $jalons = app(JalonsCampagne::class);

    $sousBoss = array_values(array_filter(
        range(1, $quetes),
        fn (int $p) => $jalons->type($groupe, $p) === JalonsCampagne::SOUS_BOSS,
    ));

    expect($sousBoss)->toBe($attendues)
        ->and($sousBoss)->toHaveCount(JalonsCampagne::nbSousBossAttendu($quetes))
        ->and($jalons->type($groupe, $quetes))->toBe(JalonsCampagne::BOSS_FINAL);
})->with([
    'très courte' => [1, []],
    'courte (3)' => [3, [2]],
    'courte (5)' => [5, [3]],
    'normale (10)' => [10, [3, 7]],
    'longue (15)' => [15, [4, 8, 11]],
    'très longue (20)' => [20, [4, 8, 12, 16]],
]);

it('suit le plan de campagne quand il existe, et lui seul', function () {
    $groupe = new Groupe(['nb_quetes_total' => 10, 'plan_campagne' => ['jalons' => [
        ['position' => 5, 'type' => 'sous_boss'],
        ['position' => 10, 'type' => 'boss_final'],
    ]]]);
    $jalons = app(JalonsCampagne::class);

    // Le repli placerait 3 et 7 : avec un plan, ils n'existent pas.
    expect($jalons->type($groupe, 5))->toBe(JalonsCampagne::SOUS_BOSS)
        ->and($jalons->type($groupe, 3))->toBe(JalonsCampagne::NORMALE)
        ->and($jalons->type($groupe, 7))->toBe(JalonsCampagne::NORMALE)
        ->and($jalons->positions($groupe))->toBe([5, 10]);
});
