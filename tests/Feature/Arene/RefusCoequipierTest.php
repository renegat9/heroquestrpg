<?php

declare(strict_types=1);

use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/*
 * MODE ARÈNE, activation alternée (`OrdreDuTour`, lot T2) — le refus d'un joueur dont le héros n'a PAS la main doit NOMMER
 * le coéquipier qui a ENTAMÉ son activation et la tient seul (contrat, « Fenêtre de régénération », 2026-11-12). Sans
 * activation entamée, la main est libre : le refus reste celui du MJ, sans nom.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('Bob entame un déplacement : le choix d\'Alice est refusé EN NOMMANT Borin, qui tient seul la main', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);
    $id = $c['arene']->identifiant;

    $this->actingAs($c['bob'], 'joueur');
    $menu = $this->getJson("/api/groupes/{$id}/menu")->json('menu');
    $destination = collect(collect($menu['options'])->firstWhere('id', 'se_deplacer')['parametres']['destinations'])->first();
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => $destination['x'], 'y' => $destination['y']]])
        ->assertSuccessful();

    $this->actingAs($c['alice'], 'joueur');
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'attendre'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.option_id.0', 'Borin a pris la main : attends la fin de son activation.');
});

it('la même phrase sort pour l\'aperçu de trajet : c\'est le même point de refus', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);
    $id = $c['arene']->identifiant;

    $this->actingAs($c['bob'], 'joueur');
    $menu = $this->getJson("/api/groupes/{$id}/menu")->json('menu');
    $destination = collect(collect($menu['options'])->firstWhere('id', 'se_deplacer')['parametres']['destinations'])->first();
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => $destination['x'], 'y' => $destination['y']]])
        ->assertSuccessful();

    $this->actingAs($c['alice'], 'joueur');
    $this->postJson("/api/groupes/{$id}/deplacement/apercu", ['x' => 0, 'y' => 0])
        ->assertUnprocessable()
        ->assertJsonPath('errors.option_id.0', 'Borin a pris la main : attends la fin de son activation.');
});

it('la main est LIBRE (Bob n\'a pas commencé) : le refus d\'Alice ne nomme personne, c\'est le refus du MJ', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);
    $id = $c['arene']->identifiant;

    // Alice a joué son tour ; Bob a la main sans l'avoir entamée.
    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();

    $this->actingAs($c['alice'], 'joueur');
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'attendre'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.option_id.0', 'Aucun menu en attente pour ce joueur — attendez la proposition du MJ.');
});
