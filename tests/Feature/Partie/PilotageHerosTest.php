<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\PilotageHeros;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
 * MANETTE À PLUSIEURS HÉROS, lot M1 (`docs/plan-mode-classique.md` §5) — CONFIER un héros à un autre joueur du groupe.
 *
 * Le propriétaire (`personnages.joueur_id`) garde son héros ; le pilote (`groupe_personnages.pilote_joueur_id`) le joue
 * dans ce groupe. Ce fichier tient les règles de `App\Partie\PilotageHeros` et leur reprise par les routes du hub.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([
        ClasseHerosSeeder::class, ObjetSeeder::class, CompetenceSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Un compte de joueur SANS l'authentifier : le test décide qui agit, au moment où il agit. */
function compteDePilotage(string $pseudo): JoueurAuthentifiable
{
    return JoueurAuthentifiable::create([
        'pseudo' => $pseudo,
        'identifiant' => $pseudo,
        'mot_de_passe' => 'secret',
    ]);
}

/** Confie (ou rend, `null`) un héros via la vraie route, en tant que `$demandeur`. */
function confierPilotage(JoueurAuthentifiable $demandeur, Personnage $heros, ?int $piloteJoueurId): TestResponse
{
    test()->actingAs($demandeur, 'joueur');

    return test()->putJson('/api/groupes/table-1/pilote', [
        'personnage_id' => $heros->id,
        'pilote_joueur_id' => $piloteJoueurId,
    ]);
}

function piloteEnBase(Personnage $heros, Groupe $groupe): ?int
{
    $valeur = DB::table('groupe_personnages')
        ->where('groupe_id', $groupe->id)
        ->where('personnage_id', $heros->id)
        ->value('pilote_joueur_id');

    return $valeur === null ? null : (int) $valeur;
}

it('confie un héros à un autre joueur du groupe, puis le lui reprend', function () {
    $bob = connecterJoueur('bob');
    $alice = compteDePilotage('alice');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1);
    creerHeros($alice, $groupe, 'Albrecht', 2);

    // Bob, propriétaire, confie son héros à Alice.
    confierPilotage($bob, $deBob, (int) $alice->id)
        ->assertOk()
        ->assertJson(['personnage_id' => $deBob->id, 'pilote_joueur_id' => (int) $alice->id]);

    expect(piloteEnBase($deBob, $groupe))->toBe((int) $alice->id)
        ->and($groupe->fresh()->piloteDe($deBob))->toBe((int) $alice->id);

    // Journalisé : la table et le fil savent qui a confié quoi à qui.
    $journal = Evenement::query()->where('groupe_id', $groupe->id)->where('type', 'systeme')->get()
        ->first(fn (Evenement $e) => ($e->payload['action'] ?? null) === 'pilote_change');
    expect($journal)->not->toBeNull()
        ->and($journal->payload['vers_joueur_id'])->toBe((int) $alice->id)
        ->and($journal->payload['par_joueur_id'])->toBe((int) $bob->id);

    // Alice, pilote actuel, le rend à son propriétaire : la colonne reprend null, et la réponse nomme le propriétaire.
    confierPilotage($alice, $deBob, (int) $bob->id)
        ->assertOk()
        ->assertJson(['personnage_id' => $deBob->id, 'pilote_joueur_id' => (int) $bob->id]);

    expect(piloteEnBase($deBob, $groupe))->toBeNull();
});

it('refuse de confier un héros en quête', function () {
    $bob = connecterJoueur('bob');
    $alice = compteDePilotage('alice');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1);
    creerHeros($alice, $groupe, 'Albrecht', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    confierPilotage($bob, $deBob, (int) $alice->id)->assertStatus(422)->assertJsonValidationErrors(['phase']);
    expect(piloteEnBase($deBob, $groupe))->toBeNull();
});

it('refuse à un tiers qui ni ne possède ni ne pilote le héros', function () {
    $bob = connecterJoueur('bob');
    $alice = compteDePilotage('alice');
    $carol = compteDePilotage('carol');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1);
    creerHeros($alice, $groupe, 'Albrecht', 2);
    creerHeros($carol, $groupe, 'Clotilde', 3);

    // Carol n'est ni propriétaire de Bertrand ni son pilote : elle ne peut ni le confier à Alice, ni le reprendre.
    confierPilotage($carol, $deBob, (int) $alice->id)->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);
    expect(piloteEnBase($deBob, $groupe))->toBeNull();
});

it('refuse de confier à un joueur qui n\'a aucun héros actif dans ce groupe', function () {
    $bob = connecterJoueur('bob');
    $dave = compteDePilotage('dave');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1);

    // Dave a un compte, mais aucun héros dans CE groupe : un joueur étranger.
    confierPilotage($bob, $deBob, (int) $dave->id)->assertStatus(422)->assertJsonValidationErrors(['pilote_joueur_id']);
    expect(piloteEnBase($deBob, $groupe))->toBeNull();
});

it('refuse un héros qui n\'est pas actif dans ce groupe', function () {
    $bob = connecterJoueur('bob');
    $alice = compteDePilotage('alice');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1);
    creerHeros($alice, $groupe, 'Albrecht', 2);
    $groupe->personnages()->updateExistingPivot($deBob->id, ['actif' => false]);

    confierPilotage($bob, $deBob, (int) $alice->id)->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);
});

it('le pilote équipe le héros confié par la vraie route, le propriétaire ne le peut plus', function () {
    $bob = connecterJoueur('bob');
    $alice = compteDePilotage('alice');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    creerHeros($alice, $groupe, 'Albrecht', 2);

    $ligne = Inventaire::create([
        'personnage_id' => $deBob->id,
        'objet_id' => Objet::where('nom', 'Épée courte')->value('id'),
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);

    confierPilotage($bob, $deBob, (int) $alice->id)->assertOk();

    // Le propriétaire, absent, ne commande plus le héros qu'il a confié.
    test()->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/equipement', [
        'personnage_id' => $deBob->id,
        'inventaire_id' => $ligne->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);
    expect($ligne->fresh()->emplacement)->toBe('sac');

    // Le pilote, lui, l'équipe.
    test()->actingAs($alice, 'joueur');
    $this->postJson('/api/groupes/table-1/equipement', [
        'personnage_id' => $deBob->id,
        'inventaire_id' => $ligne->id,
    ])->assertOk();
    expect($ligne->fresh()->emplacement)->toBe('arme_principale');
});

it('publie le pilote de chaque héros dans GET /etat', function () {
    $bob = connecterJoueur('bob');
    $alice = compteDePilotage('alice');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1);
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 2);

    confierPilotage($bob, $deBob, (int) $alice->id)->assertOk();

    // La quête lui donne des entités ; Alice, qui pilote les deux, les lit.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    test()->actingAs($alice, 'joueur');

    $heros = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('entites'))
        ->where('type', 'heros')
        ->keyBy('id');

    expect($heros)->toHaveCount(2)
        ->and($heros[$deBob->id]['pilote_joueur_id'])->toBe((int) $alice->id)
        ->and($heros[$albrecht->id]['pilote_joueur_id'])->toBe((int) $alice->id);
});

it('lit le groupe à un pilote qui n\'a plus de héros à lui, et au propriétaire dont le seul héros est confié', function () {
    $bob = connecterJoueur('bob');
    $alice = compteDePilotage('alice');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1);
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 2);

    // Un compte sans héros dans ce groupe ne lit rien.
    $ines = compteDePilotage('ines');
    test()->actingAs($ines, 'joueur');
    $this->getJson('/api/groupes/table-1/etat')->assertForbidden();

    // Bob confie son héros à Alice (elle a un héros dans le groupe : c'est la règle du confier).
    confierPilotage($bob, $deBob, (int) $alice->id)->assertOk();

    // Le héros d'Alice quitte le groupe ensuite : elle ne pilote plus que celui de Bob, et elle le lit toujours.
    $groupe->personnages()->updateExistingPivot($albrecht->id, ['actif' => false]);

    test()->actingAs($alice, 'joueur');
    $this->getJson('/api/groupes/table-1/etat')->assertOk();

    // Bob n'a plus que ce héros-là, confié : il le possède, il lit donc toujours le groupe.
    test()->actingAs($bob, 'joueur');
    $this->getJson('/api/groupes/table-1/etat')->assertOk();

    // Le prédicat d'appartenance, à la source : un inconnu n'est pas membre.
    expect(app(PilotageHeros::class)->estMembre($groupe->fresh(), (int) $alice->id))->toBeTrue()
        ->and(app(PilotageHeros::class)->estMembre($groupe->fresh(), (int) $bob->id))->toBeTrue()
        ->and(app(PilotageHeros::class)->estMembre($groupe->fresh(), (int) $ines->id))->toBeFalse();
});

it('le pilote déclare « prêt » le héros qu\'on lui a confié, le propriétaire ne le peut plus', function () {
    $bob = connecterJoueur('bob');
    $alice = compteDePilotage('alice');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1);
    creerHeros($alice, $groupe, 'Albrecht', 2);

    confierPilotage($bob, $deBob, (int) $alice->id)->assertOk();

    // Bob, absent, ne peut plus déclarer prêt un héros qu'il ne pilote plus.
    test()->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $deBob->id, 'pret' => true])
        ->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);

    // Alice le déclare prêt : c'est elle qui le joue, et c'est ce « prêt » qui permet à la quête de partir sans Bob.
    test()->actingAs($alice, 'joueur');
    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $deBob->id, 'pret' => true])
        ->assertOk()
        ->assertJsonFragment(['personnage_id' => $deBob->id, 'pret' => true]);
});

it('rend à leur propriétaire les héros que pilotait un joueur qui quitte le groupe', function () {
    $alice = compteDePilotage('alice');
    $bob = compteDePilotage('bob');
    $carol = compteDePilotage('carol');
    $groupe = creerGroupe();
    $deAlice = creerHeros($alice, $groupe, 'Albrecht', 1);
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 2);
    creerHeros($carol, $groupe, 'Cassandre', 3);

    // Alice est absente : son héros est confié à Bob. Puis Bob quitte le groupe.
    confierPilotage($alice, $deAlice, (int) $bob->id)->assertOk();
    expect($groupe->piloteDe($deAlice))->toBe((int) $bob->id);

    $this->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/depart')->assertOk();

    // Le héros d'Alice lui revient : Bob, parti, ne pilote plus rien ici.
    expect(piloteEnBase($deAlice, $groupe))->toBeNull()
        ->and($groupe->piloteDe($deAlice))->toBe((int) $alice->id)
        ->and($groupe->herosPilotesPar((int) $bob->id)->count())->toBe(0)
        // Et le héros de Bob, sorti du groupe, n'y garde aucun pilote.
        ->and(piloteEnBase($deBob, $groupe))->toBeNull();
});
