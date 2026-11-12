<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\HallArene;
use App\Partie\Arene\TropheesArene;
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

/**
 * MODE ARÈNE, lot T4 (première étape) — les TUILES TROPHÉES (F9528 p. 13-15) : le lot de la p. 14 posé face cachée sur
 * les six cases du plan, le ramassage en fin d'activation, les réserves d'équipe, la potion et l'élan du Challenger, la
 * potion du Defender (décision de René, 2026-11-09).
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
    $this->joueur = connecterJoueur();
});

/** Un combat lancé : un barbare d'arène contre `$defender`. */
function combatAvecTrophees(array $defender): array
{
    $heros = Personnage::findOrFail(test()->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();
    $doublure = $arenes->engager($arene, $heros);
    $quete = app(DemarreurArene::class)->demarrer($arene, array_map(fn ($n) => (int) Monstre::where('nom_base', $n)->value('id'), $defender));
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $doublure->id)->firstOrFail();

    return [$arene->refresh(), $quete->refresh(), $doublure, $etat];
}

function reserveTropheesDe(Quete $quete, string $camp): array
{
    return app(TropheesArene::class)->reserve($quete->fresh(), $camp);
}

/**
 * Les options du menu courant. Le menu en cache est jeté d'abord : les tests posent la réserve À LA MAIN, hors de toute
 * résolution — en jeu, c'est la résolution qui a rempli la réserve qui fait recalculer le menu.
 */
function optionsMenuArene(string $code): Illuminate\Support\Collection
{
    $groupe = App\Models\Groupe::where('identifiant', $code)->firstOrFail();
    foreach ($groupe->personnages()->pluck('joueur_id')->unique() as $joueurId) {
        Illuminate\Support\Facades\Cache::forget(App\Jobs\GenererMenu::cleMenu($groupe->id, (int) $joueurId));
    }

    return collect(test()->getJson("/api/groupes/{$code}/menu")->assertOk()->json('menu.options') ?? []);
}

it('pose six tuiles du lot de la p. 14, face cachée, sur les six cases du plan', function () {
    [$arene, $quete] = combatAvecTrophees(['Gobelin']);
    $hall = app(HallArene::class);

    $cases = collect($quete->trophees_arene['cases']);
    $attendues = collect(TropheesArene::CASES)->map(fn ($c) => implode(',', $hall->caseDeLaSalle($c[0], $c[1])))->sort()->values();

    expect($cases->map(fn ($c) => "{$c['x']},{$c['y']}")->sort()->values()->all())->toBe($attendues->all());

    // Un sous-ensemble du lot : chaque tuile posée existe au lot, avec ses exemplaires.
    $lot = array_count_values(TropheesArene::LOT);
    foreach (array_count_values($cases->pluck('tuile')->all()) as $tuile => $n) {
        expect($n)->toBeLessThanOrEqual($lot[$tuile] ?? 0);
    }

    // Publiées FACE CACHÉE : la case, jamais la tuile.
    $publie = $this->getJson("/api/groupes/{$arene->identifiant}/etat")->json('quete.trophees');
    expect($publie['cases'])->toHaveCount(6)
        ->and($publie['cases'][0])->toHaveKeys(['x', 'y'])
        ->and($publie['cases'][0])->not->toHaveKey('tuile')
        ->and($publie['challenger'])->toBe([]);
});

it('un héros qui FINIT son activation sur une case ramasse la tuile, révélée, pour la réserve du Challenger', function () {
    [$arene, $quete, , $etat] = combatAvecTrophees(['Gobelin']);
    $case = $quete->trophees_arene['cases'][0];
    $etat->update(['position_x' => $case['x'], 'position_y' => $case['y']]);

    $this->getJson("/api/groupes/{$arene->identifiant}/menu")->assertOk();
    $resultat = $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => 'attendre'])->assertSuccessful()->json();

    expect(reserveTropheesDe($quete, TropheesArene::CHALLENGER))->toBe([$case['tuile']])
        ->and(collect($quete->fresh()->trophees_arene['cases'])->contains(fn ($c) => $c['x'] === $case['x'] && $c['y'] === $case['y']))->toBeFalse();

    $annonce = collect($resultat['resultat']['annonces_automatiques'] ?? [])->firstWhere('type', 'trophee_ramasse');
    expect($annonce['tuiles'])->toBe([TropheesArene::TUILES[$case['tuile']]['libelle']]);

    $publie = $this->getJson("/api/groupes/{$arene->identifiant}/etat")->json('quete.trophees.challenger');
    expect($publie[0]['tuile'])->toBe($case['tuile']);
});

it('un monstre ramasse sous toute son emprise, pour la réserve du Defender', function () {
    [$arene, $quete] = combatAvecTrophees(['Gobelin']);
    $case = $quete->trophees_arene['cases'][4];
    $gobelin = InstanceMonstre::where('quete_id', $quete->id)->first();
    $gobelin->update(['position_x' => $case['x'], 'position_y' => $case['y']]);

    app(TropheesArene::class)->ramasserMonstre($arene, $quete, $gobelin->fresh()->load('monstre'));

    expect(reserveTropheesDe($quete, TropheesArene::DEFENDER))->toBe([$case['tuile']])
        ->and(reserveTropheesDe($quete, TropheesArene::CHALLENGER))->toBe([]);
});

it('la POTION du Challenger : +2 PV jusqu\'au maximum, sans créneau, puis la tuile est défaussée', function () {
    [$arene, $quete, $doublure] = combatAvecTrophees(['Gobelin']);
    $quete->update(['trophees_arene' => [...$quete->trophees_arene, 'challenger' => ['potion']]]);
    $doublure->update(['pv_body' => (int) $doublure->pv_body_max - 1]);

    expect(optionsMenuArene($arene->identifiant)->pluck('id')->all())->toContain('trophee_potion');
    $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => 'trophee_potion'])->assertSuccessful();

    expect((int) $doublure->fresh()->pv_body)->toBe((int) $doublure->pv_body_max)
        ->and(reserveTropheesDe($quete, TropheesArene::CHALLENGER))->toBe([])
        ->and(optionsMenuArene($arene->identifiant)->pluck('id')->all())->not->toContain('trophee_potion');
});

it('l\'ÉLAN : n\'importe quelle tuile défaussée donne +2 cases au déplacement du tour', function () {
    [$arene, $quete, , $etat] = combatAvecTrophees(['Gobelin']);
    $quete->update(['trophees_arene' => [...$quete->trophees_arene, 'challenger' => ['crane']]]);

    $elan = optionsMenuArene($arene->identifiant)->firstWhere('id', 'trophee_elan');
    expect($elan['parametres']['tuiles'])->toBe([['cle' => 'crane', 'nom' => 'Crâne blanc']]);
    $avant = (int) $etat->fresh()->deplacement_tour;

    $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => 'trophee_elan', 'parametres' => ['cle' => 'crane']])
        ->assertSuccessful();

    expect((int) $etat->fresh()->deplacement_restant)->toBe($avant + 2)
        ->and(reserveTropheesDe($quete, TropheesArene::CHALLENGER))->toBe([]);
});

it('la POTION du Defender : une figure blessée qui s\'active la boit (René, 2026-11-09)', function () {
    [$arene, $quete] = combatAvecTrophees(['Momie']);
    $momie = InstanceMonstre::where('quete_id', $quete->id)->with('monstre')->first();
    $momie->update(['pv_body' => 1, 'active_ce_round' => false]);
    $quete->update(['trophees_arene' => [...$quete->trophees_arene, 'defender' => ['potion']]]);

    // Fin d'activation du héros : le Defender répond avec sa seule figure, blessée.
    $this->getJson("/api/groupes/{$arene->identifiant}/menu")->assertOk();
    $this->postJson("/api/groupes/{$arene->identifiant}/choix", ['option_id' => 'attendre'])->assertSuccessful();

    expect((int) $momie->fresh()->pv_body)->toBe(min(1 + 2, $momie->pvBodyMax()))
        ->and(reserveTropheesDe($quete, TropheesArene::DEFENDER))->toBe([]);
});
