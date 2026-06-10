<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Votes de groupe & départs (doc 05 §5, contrat docs/contrat-api.md) :
 * un seul vote actif par groupe (cache + journal). En quête, retirer un
 * joueur exige la majorité des AUTRES joueurs (égalité = il reste) et verse
 * sa part de l'or d'avant la quête (or_initial ÷ membres) à son personnage.
 * Au hub, le départ est libre : part du pot commun ÷ membres présents.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('retire un joueur en quête à la majorité : la cible ne vote pas, part = or_initial ÷ membres', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 300]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroBob = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $carol = JoueurAuthentifiable::create(['pseudo' => 'carol', 'identifiant' => 'carol', 'mot_de_passe' => 'secret']);
    creerHeros($carol, $groupe, 'Cassandre', 3);

    // En quête : or_initial figé au pot du départ (300).
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $reponse = $this->postJson('/api/groupes/table-1/votes', [
        'type' => 'retrait_joueur',
        'cible_joueur_id' => $bob->id,
    ])->assertCreated();

    $reponse->assertJsonPath('vote.type', 'retrait_joueur')
        ->assertJsonPath('vote.cible_joueur_id', $bob->id)
        ->assertJsonPath('vote.attendus', 2); // alice + carol — bob ne vote pas

    // Le joueur visé NE VOTE PAS.
    $this->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'non'])->assertStatus(422);

    // Alice vote oui : vote toujours en cours (1/2).
    $this->actingAs($alice, 'joueur');
    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'oui'])
        ->assertOk()
        ->assertJsonPath('exprimes', 1)
        ->assertJsonPath('attendus', 2)
        ->assertJsonPath('resultat', null);

    $this->getJson('/api/groupes/table-1/votes')->assertOk()->assertJsonPath('vote.exprimes', 1);

    // Carol complète : majorité oui → retrait appliqué.
    $this->actingAs($carol, 'joueur');
    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'oui'])
        ->assertOk()
        ->assertJsonPath('resultat.option_id', 'oui')
        ->assertJsonPath('resultat.applique', true);

    // Part de l'or d'AVANT la quête : 300 ÷ 3 membres = 100, versée au
    // personnage du joueur retiré (bourse personnelle du roster).
    $heroBob->refresh();
    expect($heroBob->or)->toBe(100)
        ->and($heroBob->groupe_actif_id)->toBeNull()
        ->and($groupe->fresh()->or)->toBe(200);

    // Détaché du groupe (pivot actif=false) et sorti de la quête en cours.
    expect((bool) $groupe->personnages()->where('personnages.id', $heroBob->id)->first()->pivot->actif)->toBeFalse()
        ->and($groupe->fresh()->queteCourante->etatsPersonnages()->where('personnage_id', $heroBob->id)->exists())->toBeFalse();

    // Le vote est clos et journalisé.
    $this->getJson('/api/groupes/table-1/votes')->assertOk()->assertJsonPath('vote', null);
    expect($groupe->evenements()->where('type', 'systeme')->get()
        ->contains(fn ($e) => ($e->payload['action'] ?? null) === 'vote_resultat'))->toBeTrue();
});

it('garde le joueur en cas d\'égalité (le visé reste)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 300]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroBob = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $carol = JoueurAuthentifiable::create(['pseudo' => 'carol', 'identifiant' => 'carol', 'mot_de_passe' => 'secret']);
    creerHeros($carol, $groupe, 'Cassandre', 3);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $this->postJson('/api/groupes/table-1/votes', [
        'type' => 'retrait_joueur',
        'cible_joueur_id' => $bob->id,
    ])->assertCreated();

    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'oui'])->assertOk();

    $this->actingAs($carol, 'joueur');
    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'non'])
        ->assertOk()
        ->assertJsonPath('resultat.option_id', 'non')
        ->assertJsonPath('resultat.applique', false);

    // Égalité 1-1 → il reste : rien ne change.
    $heroBob->refresh();
    expect($heroBob->groupe_actif_id)->toBe($groupe->id)
        ->and($heroBob->or)->toBe(0)
        ->and($groupe->fresh()->or)->toBe(300);

    $this->getJson('/api/groupes/table-1/votes')->assertOk()->assertJsonPath('vote', null);
});

it('refuse le vote de retrait hors quête, et n\'autorise qu\'un seul vote actif', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    // Au hub, pas de vote de retrait : le départ est libre (POST depart).
    $this->postJson('/api/groupes/table-1/votes', [
        'type' => 'retrait_joueur',
        'cible_joueur_id' => $bob->id,
    ])->assertStatus(422);

    // Un vote actif à la fois.
    $options = [['id' => 'a', 'libelle' => 'Plan A'], ['id' => 'b', 'libelle' => 'Plan B']];
    $this->postJson('/api/groupes/table-1/votes', [
        'type' => 'choix_groupe', 'question' => 'Quel plan ?', 'options' => $options,
    ])->assertCreated();
    $this->postJson('/api/groupes/table-1/votes', [
        'type' => 'choix_groupe', 'question' => 'Encore ?', 'options' => $options,
    ])->assertStatus(422);
});

it('résout un choix de groupe à la majorité simple, à complétude des votants', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $carol = JoueurAuthentifiable::create(['pseudo' => 'carol', 'identifiant' => 'carol', 'mot_de_passe' => 'secret']);
    creerHeros($carol, $groupe, 'Cassandre', 3);

    $this->postJson('/api/groupes/table-1/votes', [
        'type' => 'choix_groupe',
        'question' => 'Prendre la porte de gauche ou de droite ?',
        'options' => [['id' => 'gauche', 'libelle' => 'Gauche'], ['id' => 'droite', 'libelle' => 'Droite']],
    ])->assertCreated()->assertJsonPath('vote.attendus', 3);

    // Option inconnue → 422.
    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'milieu'])->assertStatus(422);

    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'gauche'])
        ->assertOk()->assertJsonPath('resultat', null);

    $this->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'droite'])->assertOk();

    $this->actingAs($carol, 'joueur');
    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'gauche'])
        ->assertOk()
        ->assertJsonPath('decompte.gauche', 2)
        ->assertJsonPath('decompte.droite', 1)
        ->assertJsonPath('resultat.option_id', 'gauche')
        ->assertJsonPath('resultat.applique', true);

    $this->getJson('/api/groupes/table-1/votes')->assertOk()->assertJsonPath('vote', null);
});

it('tranche l\'égalité d\'un choix de groupe par la première option (décompte stable)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/votes', [
        'type' => 'choix_groupe',
        'question' => 'On campe ici ?',
        'options' => [['id' => 'oui', 'libelle' => 'Oui'], ['id' => 'non', 'libelle' => 'Non']],
    ])->assertCreated();

    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'non'])->assertOk();

    // 1-1 : la PREMIÈRE option déclarée gagne (choix documenté au contrat).
    $this->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/votes/bulletin', ['option_id' => 'oui'])
        ->assertOk()
        ->assertJsonPath('resultat.option_id', 'oui')
        ->assertJsonPath('resultat.applique', true);
});

it('laisse partir librement au hub avec sa part du pot commun', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 300]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroBob = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $carol = JoueurAuthentifiable::create(['pseudo' => 'carol', 'identifiant' => 'carol', 'mot_de_passe' => 'secret']);
    creerHeros($carol, $groupe, 'Cassandre', 3);

    // Bob part librement : 300 ÷ 3 présents = 100 vers son personnage.
    $this->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/depart')
        ->assertOk()
        ->assertJsonPath('part', 100);

    $heroBob->refresh();
    expect($heroBob->or)->toBe(100)
        ->and($heroBob->groupe_actif_id)->toBeNull()
        ->and((bool) $groupe->personnages()->where('personnages.id', $heroBob->id)->first()->pivot->actif)->toBeFalse()
        ->and($groupe->fresh()->or)->toBe(200);

    // Parti = plus membre : il ne peut plus agir sur ce groupe.
    $this->postJson('/api/groupes/table-1/depart')->assertStatus(422);

    // L'état partagé ne liste plus son héros.
    $this->actingAs($alice, 'joueur');
    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($etat['entites'])->pluck('id'))->not->toContain($heroBob->id);
});

it('refuse le départ libre pendant une quête (vote requis)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $this->postJson('/api/groupes/table-1/depart')->assertStatus(422);
});
