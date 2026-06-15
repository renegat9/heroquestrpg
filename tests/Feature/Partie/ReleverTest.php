<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Auth\JoueurAuthentifiable;
use App\Models\Quete;
use App\Partie\MenuMoteur;
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

it('propose et résout « relever » un allié tombé adjacent (sacrifie le tour, +1 PV, debout)', function () {
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
        ->assertJsonPath('resultat.pv_body', 1);

    $eK = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $khazra->id)->firstOrFail();
    expect((bool) $eK->tombe)->toBeFalse()                 // debout
        ->and((int) $khazra->fresh()->pv_body)->toBe(1)   // 1 PV de Body
        ->and((bool) $eG->fresh()->a_joue)->toBeTrue();    // Grimnar a sacrifié son tour
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
