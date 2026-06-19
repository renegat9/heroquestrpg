<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Prologue de campagne exposé dans EtatGroupe (écran d'histoire au lancement) :
 * prémisse + menace, `auto` tant qu'aucune quête n'a eu lieu.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([GabaritQueteSeeder::class]);
});

it('expose le prologue (prémisse + menace) au hub, auto avant la première quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $groupe->update(['plan_campagne' => [
        'premisse' => 'Un mal ancien s\'éveille sous la cité d\'ambre, et l\'appel résonne.',
        'menace' => ['nom' => 'Le Roi Liche', 'description' => 'Un seigneur mort-vivant assoiffé d\'âmes.'],
        'jalons' => [],
    ]]);

    $prologue = $this->getJson('/api/groupes/table-1/etat')
        ->assertOk()
        ->json('groupe.prologue');

    expect($prologue['texte'])->toContain('mal ancien')
        ->and($prologue['menace']['nom'])->toBe('Le Roi Liche')
        ->and($prologue['auto'])->toBeTrue()      // aucune quête encore
        ->and($prologue['url'])->toBeNull();       // audio non généré (sans clé)
});

it('passe le prologue en non-auto dès qu\'une quête a eu lieu', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['plan_campagne' => ['premisse' => 'Une longue histoire commence ici, sous la pierre.']]);

    Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => GabaritQuete::query()->value('id'),
        'titre' => 'Quête 1',
        'position_arc' => 1,
        'type_jalon' => 'normale',
        'etat' => 'terminee',
        'or_initial' => 0,
    ]);

    $prologue = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.prologue');

    expect($prologue['texte'])->toContain('longue histoire')
        ->and($prologue['auto'])->toBeFalse(); // une quête existe → plus d'ouverture auto
});

it('pas de prologue si aucun squelette de campagne', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    expect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.prologue'))->toBeNull();
});
