<?php

declare(strict_types=1);

use App\Models\Condition;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Potions / consommables (doc 01 §8) — CANON : buvables à TOUT MOMENT, même hors
 * de son tour (pendant le tour d'un monstre), action gratuite. POST /potions ne
 * passe donc PAS par le menu courant ni l'initiative.
 */

beforeEach(function () {
    Http::fake();
    $this->seed([ClasseHerosSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class]);
});

function donnerConsommable(\App\Models\Personnage $perso, string $nom, int $quantite = 1): Inventaire
{
    $objet = Objet::where('nom', $nom)->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $perso->id,
        'objet_id' => $objet->id,
        'emplacement' => 'consommable',
        'quantite' => $quantite,
    ]);
}

it('soigne le héros sans menu ni tour (action gratuite, à tout moment)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['pv_body' => 2, 'pv_body_max' => 8]);
    $ligne = donnerConsommable($heros, 'Potion de soin'); // soin_pv_body 4

    // Aucun menu proposé, pas son tour : la potion passe quand même.
    $this->postJson('/api/groupes/table-1/potions', ['inventaire_id' => $ligne->id])
        ->assertOk()
        ->assertJsonPath('resultat.objet', 'Potion de soin')
        ->assertJsonPath('resultat.pv_body', 6);

    expect((int) $heros->fresh()->pv_body)->toBe(6)
        ->and(Inventaire::find($ligne->id))->toBeNull(); // exemplaire consommé
});

it('plafonne le soin au maximum de PV', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['pv_body' => 7, 'pv_body_max' => 8]);
    $ligne = donnerConsommable($heros, 'Potion de soin');

    $this->postJson('/api/groupes/table-1/potions', ['inventaire_id' => $ligne->id])
        ->assertOk()
        ->assertJsonPath('resultat.pv_body', 8);
});

it('décrémente la pile et garde la ligne s\'il en reste', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['pv_body' => 1, 'pv_body_max' => 8]);
    $ligne = donnerConsommable($heros, 'Potion de soin', 2);

    $this->postJson('/api/groupes/table-1/potions', ['inventaire_id' => $ligne->id])->assertOk();

    expect((int) Inventaire::find($ligne->id)->quantite)->toBe(1);
});

it('retire la condition ciblée (antidote)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $empoisonne = Condition::where('nom', 'Empoisonné')->firstOrFail();
    $heros->conditions()->attach($empoisonne->id, ['duree' => 3, 'source' => 'piege:test']);
    $ligne = donnerConsommable($heros, 'Antidote');

    $this->postJson('/api/groupes/table-1/potions', ['inventaire_id' => $ligne->id])->assertOk();

    expect($heros->fresh()->conditions()->where('nom', 'Empoisonné')->exists())->toBeFalse();
});

it('applique le buff de la Potion de rage (bonus de dés d\'attaque)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $ligne = donnerConsommable($heros, 'Potion de rage'); // bonus_des_attaque 1

    $this->postJson('/api/groupes/table-1/potions', ['inventaire_id' => $ligne->id])->assertOk();

    // Le bonus est relu depuis l'effet de l'objet via le système de buffs.
    expect(app(MoteurSorts::class)->bonusDes($heros->fresh(), 'bonus_des_attaque'))->toBe(1);
});

it('refuse la potion d\'un héros qui n\'est pas à soi', function () {
    $alice = connecterJoueur('alice');
    $bob = \App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $persoBob = creerHeros($bob, $groupe, 'Brunhilde', 2);
    $ligneBob = donnerConsommable($persoBob, 'Potion de soin');

    // Alice connectée tente de boire la potion du héros de Bob.
    $this->postJson('/api/groupes/table-1/potions', ['inventaire_id' => $ligneBob->id])
        ->assertStatus(422);
});
