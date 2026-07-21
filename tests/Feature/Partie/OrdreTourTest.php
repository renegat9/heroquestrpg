<?php

declare(strict_types=1);

use App\Events\PretsMaj;
use App\Models\Groupe;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * Ordre du tour réordonnable ENTRE les quêtes (PUT /api/groupes/{id}/ordre) : au
 * HUB seulement (figé pendant une quête, C1), permutation EXACTE des héros
 * actifs, roster rediffusé (.prets.maj) dans le nouvel ordre.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Noms des héros actifs dans l'ordre du tour. */
function ordreDesHeros(Groupe $groupe): array
{
    return $groupe->personnages()->wherePivot('actif', true)
        ->orderBy('groupe_personnages.ordre_initiative')
        ->pluck('personnages.nom')->all();
}

it('réordonne l\'ordre du tour au hub et le reflète dans le roster (/etat)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $a = creerHeros($alice, $groupe, 'Albrecht', 1);
    $b = creerHeros($alice, $groupe, 'Brunhilde', 2);
    $c = creerHeros($alice, $groupe, 'Cedric', 3);

    expect(ordreDesHeros($groupe))->toBe(['Albrecht', 'Brunhilde', 'Cedric']);
    expect(collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.prets'))->pluck('nom')->all())
        ->toBe(['Albrecht', 'Brunhilde', 'Cedric']);

    // Réordonne : Cedric, Albrecht, Brunhilde.
    $this->actingAs($alice, 'joueur')
        ->putJson('/api/groupes/table-1/ordre', ['ordre' => [$c->id, $a->id, $b->id]])
        ->assertOk()
        ->assertJsonPath('ordre.0.nom', 'Cedric')
        ->assertJsonPath('ordre.2.nom', 'Brunhilde');

    expect(ordreDesHeros($groupe->fresh()))->toBe(['Cedric', 'Albrecht', 'Brunhilde']);
    expect(collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.prets'))->pluck('nom')->all())
        ->toBe(['Cedric', 'Albrecht', 'Brunhilde']);
});

it('rediffuse .prets.maj réordonné (statuts prêts conservés)', function () {
    Event::fake([PretsMaj::class]);
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $a = creerHeros($alice, $groupe, 'Albrecht', 1);
    $b = creerHeros($alice, $groupe, 'Brunhilde', 2);

    $this->actingAs($alice, 'joueur')
        ->putJson('/api/groupes/table-1/ordre', ['ordre' => [$b->id, $a->id]])->assertOk();

    Event::assertDispatched(PretsMaj::class, fn (PretsMaj $e) => $e->groupe->id === $groupe->id
        && collect($e->prets)->pluck('nom')->all() === ['Brunhilde', 'Albrecht']);
});

it('refuse une liste qui n\'est pas une permutation exacte des héros actifs (422)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $a = creerHeros($alice, $groupe, 'Albrecht', 1);
    $b = creerHeros($alice, $groupe, 'Brunhilde', 2);

    // Manque un membre.
    $this->actingAs($alice, 'joueur')
        ->putJson('/api/groupes/table-1/ordre', ['ordre' => [$a->id]])->assertStatus(422);
    // Intrus (id étranger).
    $this->actingAs($alice, 'joueur')
        ->putJson('/api/groupes/table-1/ordre', ['ordre' => [$a->id, $b->id, 999999]])->assertStatus(422);

    // L'ordre n'a pas bougé.
    expect(ordreDesHeros($groupe->fresh()))->toBe(['Albrecht', 'Brunhilde']);
});

it('refuse de réordonner PENDANT une quête (ordre figé — hub seulement)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $a = creerHeros($alice, $groupe, 'Albrecht', 1);
    $b = creerHeros($alice, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated(); // phase → quete

    $this->actingAs($alice, 'joueur')
        ->putJson('/api/groupes/table-1/ordre', ['ordre' => [$b->id, $a->id]])
        ->assertStatus(422);

    expect(ordreDesHeros($groupe->fresh()))->toBe(['Albrecht', 'Brunhilde']);
});
