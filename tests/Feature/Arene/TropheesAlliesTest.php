<?php

declare(strict_types=1);

use App\Models\GroupeMercenaire;
use App\Models\Groupe;
use App\Models\Mercenaire;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\TropheesArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/**
 * MODE ARÈNE — les TUILES TROPHÉES ramassées par les ALLIÉS (livret F9528 p. 12 : « Heroes and allies make up the
 * Challenger team » ; décision de René, 2026-11-12). Un allié qui FINIT son activation sur une case trophée la ramasse,
 * comme un héros : la tuile rejoint la réserve du Challenger, et l'annonce passe par le tampon générique.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
        MercenaireSeeder::class,
    ]);
});

it('un ALLIÉ qui FINIT son activation sur une case trophée la ramasse : réserve du Challenger, annonce, case révélée', function () {
    $alice = connecterJoueur('alice');
    $id = test()->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->assertCreated()->json('personnage.id');
    $code = test()->postJson('/api/arene', ['personnage_id' => $id])->assertCreated()->json('groupe.identifiant');
    $eclaireur = Mercenaire::where('nom', 'Éclaireur')->firstOrFail();
    test()->putJson("/api/arene/{$code}/allie", ['mercenaire_id' => $eclaireur->id])->assertOk();

    $arene = Groupe::where('identifiant', $code)->firstOrFail();
    $gobelin = (int) Monstre::where('nom_base', 'Gobelin')->value('id');
    $quete = app(DemarreurArene::class)->demarrer($arene->refresh(), [$gobelin, $gobelin]);

    // L'allié se pose sur une case trophée du plan, face cachée.
    $case = $quete->trophees_arene['cases'][0];
    $allie = GroupeMercenaire::where('groupe_id', $arene->id)->firstOrFail();
    $allie->update(['position_x' => $case['x'], 'position_y' => $case['y']]);

    // Le héros joue son activation : le Defender répond, puis c'est à l'allié de jouer (sa propre activation).
    jouerEnArene($alice, $code, 'attendre')->assertSuccessful();

    test()->actingAs($alice, 'joueur');
    $menu = test()->getJson("/api/groupes/{$code}/menu")->assertOk()->json();
    expect($menu['allie_id'] ?? null)->toBe($allie->id);

    $attente = collect($menu['menu']['options'])->firstWhere('type', 'attente_allie');
    $resultat = test()->postJson("/api/groupes/{$code}/choix", ['option_id' => $attente['id']])->assertSuccessful()->json();

    // La tuile rejoint la réserve du Challenger, et la case n'est plus couverte.
    expect(app(TropheesArene::class)->reserve($quete->fresh(), TropheesArene::CHALLENGER))->toBe([$case['tuile']])
        ->and(collect($quete->fresh()->trophees_arene['cases'])->contains(fn ($c) => $c['x'] === $case['x'] && $c['y'] === $case['y']))->toBeFalse();

    // L'annonce existe, nommée d'après l'allié, et le fil la reçoit dans le résultat — comme pour un héros.
    $annonce = collect($resultat['resultat']['annonces_automatiques'] ?? [])->firstWhere('type', 'trophee_ramasse');
    expect($annonce)->not->toBeNull()
        ->and($annonce['camp'])->toBe(TropheesArene::CHALLENGER)
        ->and($annonce['nom'])->toBe('Éclaireur')
        ->and($annonce['tuiles'])->toBe([TropheesArene::TUILES[$case['tuile']]['libelle']]);

    // La publication : la case n'est plus dans les cases couvertes du plateau.
    $couvertes = test()->getJson("/api/groupes/{$code}/etat")->assertOk()->json('carte.trophees');
    expect(collect($couvertes)->contains(fn ($c) => $c['x'] === $case['x'] && $c['y'] === $case['y']))->toBeFalse();
});
