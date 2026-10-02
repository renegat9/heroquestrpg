<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Relever un allié tombé (doc 03 §48 : « tombé… relevable — soin/allié »).
 * Régression d'un softlock trouvé en partie multi : une figure tombée dans un
 * couloir d'une case bloquait héros ET monstres, sans aucun moyen de la relever.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
    Http::fake();
});

it('propose et résout « relever » un allié tombé adjacent (sacrifie le tour, relevé à 1 PV, debout)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $grimnar = creerHeros($alice, $groupe, 'Grimnar', 1, ['classe' => 'barbare']);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $khazra = creerHeros($bob, $groupe, 'Khazra', 2, ['classe' => 'nain']);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Khazra TOMBÉ, adjacent à Grimnar.
    $eG = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $grimnar->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $eG->position_x, (int) $eG->position_y);
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $khazra->id)
        ->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'tombe' => true]);
    $khazra->update(['pv_body' => 0]);

    // Le menu de Grimnar propose « relever_{khazra} ».
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $grimnar->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $relever = collect($menu['options'])->firstWhere('type', 'relever');
    expect($relever)->not->toBeNull()
        ->and($relever['id'])->toBe("relever_{$khazra->id}");

    // Résolution via POST choix.
    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'relever')
        // 1 POINT sur la jauge tombée à zéro (décision de René, 2026-08-06) —
        // on relevait auparavant à la moitié des PV max.
        ->assertJsonPath('resultat.pv_body', 1)
        ->assertJsonPath('resultat.jauges_relevees', ['pv_body']);

    $eK = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $khazra->id)->firstOrFail();
    expect((bool) $eK->tombe)->toBeFalse()                 // debout
        ->and((int) $khazra->fresh()->pv_body)->toBe(1)   // 1 point, pas une fraction des PV max
        ->and((bool) $eG->fresh()->a_joue)->toBeTrue();    // Grimnar a sacrifié son tour
});

it('« relever » soigne le BODY seulement — le Mind/l\'état de choc ne tombe plus, et ce geste ne le lève pas (René, 2026-10-01)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $grimnar = creerHeros($alice, $groupe, 'Grimnar', 1, ['classe' => 'barbare']);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $khazra = creerHeros($bob, $groupe, 'Khazra', 2, ['classe' => 'nain']);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $eG = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $grimnar->id)->firstOrFail();

    $contact = caseAdjacenteLibre($quete, (int) $eG->position_x, (int) $eG->position_y);
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $khazra->id)
        ->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'tombe' => true]);

    // Tombé au CORPS (0 Body) ET en ÉTAT DE CHOC (0 Mind) en même temps —
    // deux choses distinctes depuis le 2026-10-01. ⚠ La branche Mind de
    // `resoudreRelever()` a été RETIRÉE le même jour : elle n'existait que
    // parce que 0 Mind faisait tomber, et ce n'est plus vrai (*Against the
    // Ogre Horde* p. 9 — « they go into shock », pas une chute).
    $khazra->update(['pv_body' => 0, 'pv_mind' => 0]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $grimnar->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $relever = collect($menu['options'])->firstWhere('type', 'relever');

    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.jauges_relevees', ['pv_body']);

    // Le Body remonte à 1 point et Khazra se relève ; le Mind, lui, RESTE à
    // zéro — « relever » n'est plus un soin d'esprit, seul un vrai soin de
    // Mind (potion, sort) lève le choc.
    expect((int) $khazra->fresh()->pv_body)->toBe(1)
        ->and((int) $khazra->fresh()->pv_mind)->toBe(0)
        ->and($khazra->fresh()->estEnChoc())->toBeTrue()
        ->and((bool) EtatPersonnageQuete::where('quete_id', $quete->id)
            ->where('personnage_id', $khazra->id)->first()->tombe)->toBeFalse();
});

it('ne RETIRE jamais de PV à un tombé qui en a encore (potion bue à terre)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $grimnar = creerHeros($alice, $groupe, 'Grimnar', 1, ['classe' => 'barbare']);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $khazra = creerHeros($bob, $groupe, 'Khazra', 2, ['classe' => 'nain']);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $eG = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $grimnar->id)->firstOrFail();

    $contact = caseAdjacenteLibre($quete, (int) $eG->position_x, (int) $eG->position_y);
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $khazra->id)
        ->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'tombe' => true]);

    // À TERRE mais avec des PV : boire une potion soigne sans relever, donc cet
    // état existe réellement en jeu. Un repli aveugle à 1 PV lui coûterait 3 PV.
    $khazra->update(['pv_body' => 4, 'pv_mind' => 3]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $grimnar->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $relever = collect($menu['options'])->firstWhere('type', 'relever');

    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.jauges_relevees', []);

    expect((bool) EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $khazra->id)->first()->tombe)->toBeFalse()
        ->and((int) $khazra->fresh()->pv_body)->toBe(4)  // intacts
        ->and((int) $khazra->fresh()->pv_mind)->toBe(3);
});

it('ne propose pas « relever » si aucun allié tombé adjacent', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $grimnar = creerHeros($alice, $groupe, 'Grimnar', 1, ['classe' => 'barbare']);
    creerHeros($alice, $groupe, 'Solan', 1, ['classe' => 'elfe']); // vivant

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $groupe->refresh();

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $grimnar->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];

    expect(collect($menu['options'])->firstWhere('type', 'relever'))->toBeNull();
});
