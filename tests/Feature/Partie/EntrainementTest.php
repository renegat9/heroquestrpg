<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Partie\Entrainement;
use Database\Seeders\ClasseHerosSeeder;
use Illuminate\Support\Facades\Http;

/*
 * L'ENTRAÎNEMENT au hub (René, 2026-11-07) : « seulement +1 par attribut par joueur avec un coût de 500 ». La décision
 * vit dans `App\Partie\Entrainement`, publiée par `/moi` et revérifiée par la route.
 */

beforeEach(function () {
    Http::fake();
    $this->seed([ClasseHerosSeeder::class]);
});

it('entraîne un attribut au hub : +1, 500 po de la bourse commune, une seule fois par attribut', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 1100]);
    $nain = creerHeros($alice, $groupe, 'petite barbe', 1, ['classe' => 'nain', 'attribut_body' => 3, 'attribut_mind' => 2]);

    $this->postJson('/api/groupes/table-1/entrainement', ['personnage_id' => $nain->id, 'attribut' => 'mind'])
        ->assertOk()
        ->assertJsonPath('personnage.attribut_mind', 3)
        ->assertJsonPath('or', 600);

    expect((int) $nain->fresh()->attribut_mind)->toBe(3)
        ->and($nain->fresh()->entraine_mind)->toBeTrue()
        ->and((int) $groupe->fresh()->or)->toBe(1100 - Entrainement::PRIX);

    // Une seule fois par attribut : le second Mind est refusé, et rien ne bouge.
    $this->postJson('/api/groupes/table-1/entrainement', ['personnage_id' => $nain->id, 'attribut' => 'mind'])->assertStatus(422);
    expect((int) $nain->fresh()->attribut_mind)->toBe(3)->and((int) $groupe->fresh()->or)->toBe(600);

    // L'autre attribut, lui, reste ouvert.
    $this->postJson('/api/groupes/table-1/entrainement', ['personnage_id' => $nain->id, 'attribut' => 'body'])->assertOk();
    expect((int) $nain->fresh()->attribut_body)->toBe(4)->and((int) $groupe->fresh()->or)->toBe(100);
});

it('refuse sans assez d\'or, hors du hub, et pour le héros d\'un autre joueur', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => Entrainement::PRIX - 1]);
    $nain = creerHeros($alice, $groupe, 'petite barbe', 1, ['classe' => 'nain']);

    $this->postJson('/api/groupes/table-1/entrainement', ['personnage_id' => $nain->id, 'attribut' => 'body'])->assertStatus(422);

    $groupe->update(['or' => 2000, 'phase' => 'quete']);
    $this->postJson('/api/groupes/table-1/entrainement', ['personnage_id' => $nain->id, 'attribut' => 'body'])->assertStatus(422);

    $groupe->update(['phase' => 'hub']);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $elfe = creerHeros($bob, $groupe, 'Sylvan', 2, ['classe' => 'elfe']);
    $this->postJson('/api/groupes/table-1/entrainement', ['personnage_id' => $elfe->id, 'attribut' => 'body'])->assertStatus(422);

    expect((int) $groupe->fresh()->or)->toBe(2000);
});

it('publie sur /moi la décision par attribut — possible, et la raison quand c\'est non', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 700]);
    $nain = creerHeros($alice, $groupe, 'petite barbe', 1, ['classe' => 'nain', 'attribut_body' => 3, 'attribut_mind' => 2]);
    $nain->update(['entraine_body' => true]);

    $perso = collect($this->getJson('/api/moi')->assertOk()->json('joueur.personnages'))->firstWhere('id', $nain->id);
    $parAttribut = collect($perso['entrainement'])->keyBy('attribut');

    expect($parAttribut['mind'])->toMatchArray(['libelle' => 'Mind', 'valeur' => 2, 'prix' => 500, 'possible' => true, 'raison' => null])
        ->and($parAttribut['body']['possible'])->toBeFalse()
        ->and($parAttribut['body']['raison'])->toContain('déjà entraîné');
});
