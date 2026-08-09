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

it('relève la jauge tombée à ZÉRO : Mind si c\'est le Mind qui est vide', function () {
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

    // Corps intact, ESPRIT vidé. ⚠ Aucun effet ne réduit `pv_mind` d'un héros
    // aujourd'hui : la branche est correcte mais dormante, et ce test est ce
    // qui la garde vivante le jour où un effet saura entamer l'esprit.
    $khazra->update(['pv_body' => 3, 'pv_mind' => 0]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $grimnar->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $relever = collect($menu['options'])->firstWhere('type', 'relever');

    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.jauges_relevees', ['pv_mind']);

    // Le Mind remonte à 1 ; le Body, qui n'était pas à zéro, n'est pas touché.
    expect((int) $khazra->fresh()->pv_mind)->toBe(1)
        ->and((int) $khazra->fresh()->pv_body)->toBe(3);
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
