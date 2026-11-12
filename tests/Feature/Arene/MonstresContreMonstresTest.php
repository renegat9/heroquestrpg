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

    // 4 de puissance, deux gobelins chacun : la sélection est close, le lancement attend les lots suivants.
    expect($salon['selection_terminee'])->toBeTrue()
        ->and($salon['tour_selection'])->toBeNull()
        ->and($salon['camps']['a']['monstres'])->toHaveCount(2)
        ->and($salon['camps']['b']['monstres'])->toHaveCount(2)
        ->and($salon['lancement_possible'])->toBeFalse()
        ->and($salon['lancement_motif'])->toBe(MonstresContreMonstres::MOTIF_A_VENIR);
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
