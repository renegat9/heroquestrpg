<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Partie\Arene\GroupesArene;
use App\Partie\Arene\MonstresContreMonstres;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MonstreSeeder;

/**
 * MODE ARÈNE, lot A9a — le SALON Monster vs Monster (livret F9528 p. 16) : deux camps de joueurs sans héros, une
 * puissance convenue, un tirage au sort du Challenger, puis une sélection alternée — le Defender d'abord — jusqu'à ce
 * que chaque camp ait atteint la puissance convenue. Joué par les vraies routes.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class]);
});

function idMonstreMvm(string $nom): int
{
    return (int) Monstre::where('nom_base', $nom)->where('boite', 'base')->value('id');
}

/** Alice ouvre (camp a), Bob rejoint le camp b ; renvoie [alice, bob, code]. */
function salonMvm(): array
{
    $alice = connecterJoueur('alice');
    $code = test()->postJson('/api/arene/monstres')->assertCreated()->json('groupe.identifiant');
    $bob = connecterJoueur('bob');
    test()->postJson("/api/arene/{$code}/camp", ['camp' => 'b'])->assertOk();

    return [$alice, $bob, $code];
}

it('ouvre un combat sans héros : deux camps de joueurs, lisibles par leurs membres', function () {
    [$alice, $bob, $code] = salonMvm();

    $salon = $this->getJson("/api/groupes/{$code}/etat")->assertOk()->json('groupe.arene');

    expect($salon['variante'])->toBe('monstres')
        ->and(collect($salon['joueurs'])->pluck('camp', 'pseudo')->all())->toBe(['alice' => 'a', 'bob' => 'b'])
        ->and($salon['tirage_fait'])->toBeFalse()
        ->and($salon['tirage_possible'])->toBeFalse()
        ->and($salon['lancement_possible'])->toBeFalse()
        ->and(Personnage::count())->toBe(0);

    // Un inconnu ne lit pas le combat.
    connecterJoueur('carole');
    $this->getJson("/api/groupes/{$code}/etat")->assertForbidden();
});

it('le tirage exige la puissance convenue ; puis le Defender choisit le premier et les camps alternent', function () {
    [$alice, $bob, $code] = salonMvm();

    $this->actingAs($alice, 'joueur');
    $this->postJson("/api/arene/{$code}/tirage")->assertUnprocessable();
    $this->putJson("/api/arene/{$code}/puissance", ['puissance' => 4])->assertOk();
    $salon = $this->postJson("/api/arene/{$code}/tirage")->assertOk()->json('groupe.arene');

    $defender = collect($salon['camps'])->search(fn ($c) => $c['role'] === 'defender');
    $challenger = $defender === 'a' ? 'b' : 'a';
    expect($salon['tirage_fait'])->toBeTrue()
        ->and($salon['tour_selection'])->toBe($defender)
        ->and($salon['camps'][$challenger]['role'])->toBe('challenger');

    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;
    $gobelin = idMonstreMvm('Gobelin');

    // Le Challenger ne choisit pas hors de son tour.
    $this->actingAs($joueurDe($challenger), 'joueur');
    $this->postJson("/api/arene/{$code}/selection", ['monstre_id' => $gobelin])->assertUnprocessable();

    // Le Defender choisit ; la main passe au Challenger.
    $this->actingAs($joueurDe($defender), 'joueur');
    $salon = $this->postJson("/api/arene/{$code}/selection", ['monstre_id' => $gobelin])->assertOk()->json('groupe.arene');
    expect($salon['tour_selection'])->toBe($challenger)
        ->and($salon['camps'][$defender]['restant'])->toBe(4 - (int) Monstre::find($gobelin)->attaque);

    $this->actingAs($joueurDe($challenger), 'joueur');
    $this->postJson("/api/arene/{$code}/selection", ['monstre_id' => $gobelin])->assertOk();
    $this->actingAs($joueurDe($defender), 'joueur');
    $this->postJson("/api/arene/{$code}/selection", ['monstre_id' => $gobelin])->assertOk();
    $this->actingAs($joueurDe($challenger), 'joueur');
    $salon = $this->postJson("/api/arene/{$code}/selection", ['monstre_id' => $gobelin])->assertOk()->json('groupe.arene');

    // 4 de puissance, deux gobelins chacun : la sélection est close, le combat peut être lancé.
    expect($salon['selection_terminee'])->toBeTrue()
        ->and($salon['tour_selection'])->toBeNull()
        ->and($salon['camps']['a']['monstres'])->toHaveCount(2)
        ->and($salon['camps']['b']['monstres'])->toHaveCount(2)
        ->and($salon['lancement_possible'])->toBeTrue()
        ->and($salon['lancement_motif'])->toBeNull();
});

it('refuse un monstre trop cher pour ce qui reste, et marque le roster en conséquence', function () {
    [$alice, $bob, $code] = salonMvm();
    $this->actingAs($alice, 'joueur');
    $this->putJson("/api/arene/{$code}/puissance", ['puissance' => 2])->assertOk();
    $salon = $this->postJson("/api/arene/{$code}/tirage")->assertOk()->json('groupe.arene');

    $defender = $salon['tour_selection'];
    $this->actingAs($defender === 'a' ? $alice : $bob, 'joueur');

    $orque = collect($salon['monstres'])->firstWhere('nom_base', 'Orque');
    expect($orque['abordable'])->toBe($orque['puissance'] <= 2);
    $this->postJson("/api/arene/{$code}/selection", ['monstre_id' => idMonstreMvm('Guerrier du Chaos')])->assertUnprocessable();
});

it('après le tirage, camps et puissance sont figés', function () {
    [$alice, $bob, $code] = salonMvm();
    $this->actingAs($alice, 'joueur');
    $this->putJson("/api/arene/{$code}/puissance", ['puissance' => 6])->assertOk();
    $this->postJson("/api/arene/{$code}/tirage")->assertOk();

    $this->putJson("/api/arene/{$code}/puissance", ['puissance' => 8])->assertUnprocessable();
    $this->postJson("/api/arene/{$code}/camp", ['camp' => 'b'])->assertUnprocessable();
});

it('un Monster vs Monster refuse les gestes du Coop : héros, Defender composé, allié', function () {
    [$alice, , $code] = salonMvm();
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();

    $this->actingAs($alice, 'joueur');
    $heros = $this->postJson('/api/personnages', ['nom' => 'Brakka', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id');
    $this->postJson("/api/arene/{$code}/combattants", ['personnage_id' => $heros])->assertUnprocessable();
    $this->putJson("/api/arene/{$code}/defender", ['monstre_ids' => [idMonstreMvm('Gobelin')]])->assertUnprocessable();
    $this->postJson("/api/arene/{$code}/lancer")->assertUnprocessable();

    expect(Personnage::where('groupe_actif_id', $groupe->id)->count())->toBe(0);
});

it('la purge d\'arène emporte un Monster vs Monster et ses membres, sans toucher aux joueurs', function () {
    [, , $code] = salonMvm();
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();

    app(GroupesArene::class)->purger($groupe);

    expect(Groupe::whereKey($groupe->id)->exists())->toBeFalse()
        ->and(Illuminate\Support\Facades\DB::table('arene_joueurs')->count())->toBe(0)
        ->and(App\Models\Joueur::count())->toBe(2);
});

/** Un combat lancé : puissance 4, deux gobelins par camp ; renvoie [alice, bob, code, camp défenseur]. */
function combatMvmLance(): array
{
    [$alice, $bob, $code] = salonMvm();
    test()->actingAs($alice, 'joueur');
    test()->putJson("/api/arene/{$code}/puissance", ['puissance' => 4])->assertOk();
    $salon = test()->postJson("/api/arene/{$code}/tirage")->assertOk()->json('groupe.arene');
    $defender = $salon['tour_selection'];
    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;
    $gobelin = idMonstreMvm('Gobelin');

    foreach ([$defender, $defender === 'a' ? 'b' : 'a', $defender, $defender === 'a' ? 'b' : 'a'] as $camp) {
        test()->actingAs($joueurDe($camp), 'joueur');
        test()->postJson("/api/arene/{$code}/selection", ['monstre_id' => $gobelin])->assertOk();
    }

    test()->postJson("/api/arene/{$code}/lancer")->assertCreated();

    return [$alice, $bob, $code, $defender];
}

it('COMBAT : chaque camp sur sa tuile, le Defender a la main, l\'autre camp ne peut rien', function () {
    [$alice, $bob, $code, $defender] = combatMvmLance();
    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $hall = app(App\Partie\Arene\HallArene::class);
    $est = collect($hall->tuileDeDepart(ouest: false))->map(fn ($c) => "{$c['x']},{$c['y']}")->all();

    $instances = App\Models\InstanceMonstre::where('quete_id', $groupe->quete_courante_id)->get();
    expect($instances)->toHaveCount(4)
        ->and($instances->where('camp_arene', $defender)->every(fn ($i) => in_array("{$i->position_x},{$i->position_y}", $est, true)))->toBeTrue()
        ->and($this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene.main'))->toBe($defender);

    $this->actingAs($joueurDe($defender === 'a' ? 'b' : 'a'), 'joueur');
    expect($this->getJson("/api/arene/{$code}/figures")->assertOk()->json('figures'))->toBe([]);
    $autre = $instances->firstWhere('camp_arene', '!=', $defender);
    $this->postJson("/api/arene/{$code}/geste", ['instance_id' => $autre->id, 'geste' => 'terminer'])->assertUnprocessable();

    $this->actingAs($joueurDe($defender), 'joueur');
    $figures = $this->getJson("/api/arene/{$code}/figures")->json('figures');
    expect($figures)->toHaveCount(2)
        ->and($figures[0]['peut_deplacer'])->toBeTrue()
        ->and($figures[0]['peut_attaquer'])->toBeFalse();
});

it('COMBAT : une figure entamée garde la main ; terminée, la main passe à l\'autre camp', function () {
    [$alice, $bob, $code, $defender] = combatMvmLance();
    $challenger = $defender === 'a' ? 'b' : 'a';
    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;

    $this->actingAs($joueurDe($defender), 'joueur');
    $figure = $this->getJson("/api/arene/{$code}/figures")->json('figures.0');
    $case = $figure['destinations'][0];
    $menu = $this->postJson("/api/arene/{$code}/geste", ['instance_id' => $figure['instance_id'], 'geste' => 'deplacer', 'parametres' => $case])
        ->assertOk()->json('menu');

    $instance = App\Models\InstanceMonstre::find($figure['instance_id']);
    expect([$instance->position_x, $instance->position_y])->toBe([$case['x'], $case['y']])
        ->and($menu['figures'])->toHaveCount(1)
        ->and($menu['figures'][0]['peut_deplacer'])->toBeFalse();

    $this->postJson("/api/arene/{$code}/geste", ['instance_id' => $figure['instance_id'], 'geste' => 'terminer'])->assertOk();
    expect($instance->refresh()->active_ce_round)->toBeTrue()
        ->and($this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene.main'))->toBe($challenger);
});

it('COMBAT : une frappe au contact blesse, et le camp éteint perd — sans aucune statistique', function () {
    [$alice, $bob, $code, $defender] = combatMvmLance();
    $challenger = $defender === 'a' ? 'b' : 'a';
    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $quete = $groupe->queteCourante;

    // Un seul gobelin adverse, à 1 PV, au contact du premier gobelin du Defender.
    $mien = App\Models\InstanceMonstre::where('quete_id', $quete->id)->where('camp_arene', $defender)->orderBy('id')->first();
    $adverses = App\Models\InstanceMonstre::where('quete_id', $quete->id)->where('camp_arene', $challenger)->orderBy('id')->get();
    $adverses->last()->update(['etat' => 'vaincu']);
    $cible = $adverses->first();
    $cible->update(['position_x' => $mien->position_x - 1, 'position_y' => $mien->position_y, 'pv_body' => 1]);
    desFiges(array_fill(0, 50, 1)); // crânes partout : la défense ne pare rien

    $this->actingAs($joueurDe($defender), 'joueur');
    $figure = collect($this->getJson("/api/arene/{$code}/figures")->json('figures'))->firstWhere('instance_id', $mien->id);
    expect(collect($figure['cibles'])->pluck('id')->all())->toBe([$cible->id]);

    $resultat = $this->postJson("/api/arene/{$code}/geste", ['instance_id' => $mien->id, 'geste' => 'attaquer', 'parametres' => ['cible_id' => $cible->id]])
        ->assertOk()->json('resultat');

    expect($resultat['type'])->toBe('attaque_mvm')
        ->and($resultat['cible_vaincue'])->toBeTrue()
        ->and($quete->refresh()->etat)->toBe('terminee')
        ->and($groupe->refresh()->phase)->toBe('hub')
        ->and($this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene.resultat.gagnant'))->toBe($defender)
        ->and(Illuminate\Support\Facades\DB::table('arene_combats')->count())->toBe(0);
});

it('COMBAT : quand tout a joué, le round se referme et le Defender rouvre le suivant', function () {
    [$alice, $bob, $code, $defender] = combatMvmLance();
    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();

    foreach ([$defender, $defender === 'a' ? 'b' : 'a', $defender, $defender === 'a' ? 'b' : 'a'] as $camp) {
        $this->actingAs($joueurDe($camp), 'joueur');
        $figure = $this->getJson("/api/arene/{$code}/figures")->json('figures.0');
        $this->postJson("/api/arene/{$code}/geste", ['instance_id' => $figure['instance_id'], 'geste' => 'terminer'])->assertOk();
    }

    expect(App\Models\InstanceMonstre::where('quete_id', $groupe->quete_courante_id)->where('active_ce_round', true)->count())->toBe(0)
        ->and($this->getJson("/api/groupes/{$code}/etat")->json('groupe.arene.main'))->toBe($defender);
});
