<?php

declare(strict_types=1);

use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\Equipement;
use Database\Seeders\ObjetSeeder;

/*
 * Équiper / déséquiper (doc 01 §7) : les deltas de combat de l'objet
 * s'appliquent aux colonnes du héros à l'équipement et sont révoqués au
 * déséquipement (même patron que les nœuds de compétence).
 */

beforeEach(function () {
    $this->seed(ObjetSeeder::class);
});

function sacDe(App\Models\Personnage $p, string $nomObjet): Inventaire
{
    $objet = Objet::where('nom', $nomObjet)->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => $objet->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
}

it('équipe une arme : +dés d\'attaque et passage en slot', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    $ligne = sacDe($heros, 'Épée large'); // effet des_attaque: 3

    (new Equipement())->equiper($heros, $ligne);

    expect($heros->refresh()->des_attaque)->toBe(5) // 2 + 3
        ->and($ligne->fresh()->emplacement)->toBe('arme_principale');
});

it('déséquipe une arme : les dés reviennent à la base, l\'objet retourne au sac', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    $ligne = sacDe($heros, 'Épée courte'); // des_attaque: 2

    $svc = new Equipement();
    $svc->equiper($heros, $ligne);
    expect($heros->refresh()->des_attaque)->toBe(4);

    $svc->desequiper($heros, $ligne->fresh());
    expect($heros->refresh()->des_attaque)->toBe(2)
        ->and($ligne->fresh()->emplacement)->toBe('sac');
});

it('auto-swap : équiper une seconde arme remet la première au sac (capacité neutre)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    $courte = sacDe($heros, 'Épée courte'); // 2
    $large = sacDe($heros, 'Épée large');   // 3

    $svc = new Equipement();
    $svc->equiper($heros, $courte);
    $svc->equiper($heros, $large);

    expect($heros->refresh()->des_attaque)->toBe(5) // base 2 + large 3, la courte est révoquée
        ->and($courte->fresh()->emplacement)->toBe('sac')
        ->and($large->fresh()->emplacement)->toBe('arme_principale');
});

it('applique les dés de défense d\'une armure', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['des_defense' => 2]);
    $ligne = sacDe($heros, 'Cotte de mailles'); // des_defense: 1

    (new Equipement())->equiper($heros, $ligne);
    expect($heros->refresh()->des_defense)->toBe(3);
});

it('refuse un bouclier quand une arme à deux mains est équipée', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $hache = sacDe($heros, 'Hache de bataille'); // deux_mains
    $bouclier = sacDe($heros, 'Bouclier');       // incompatible_deux_mains

    $svc = new Equipement();
    $svc->equiper($heros, $hache);

    expect(fn () => $svc->equiper($heros, $bouclier))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('refuse d\'équiper un objet du sac non montable (potion)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    // Une potion est en emplacement consommable, pas sac — on force une ligne sac
    // d'un objet-outil (Trousse à outils, emplacement sac) : non équipable.
    $ligne = sacDe($heros, 'Trousse à outils');

    expect(fn () => (new Equipement())->equiper($heros, $ligne))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('POST /equipement équipe au hub et renvoie les dés à jour ; refuse en quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    $ligne = sacDe($heros, 'Épée large');

    $this->postJson('/api/groupes/table-1/equipement', [
        'personnage_id' => $heros->id,
        'inventaire_id' => $ligne->id,
    ])->assertOk()->assertJsonPath('personnage.des_attaque', 5);

    // En quête : refus (MVP hub-only).
    $groupe->update(['phase' => 'quete']);
    $this->deleteJson('/api/groupes/table-1/equipement', [
        'personnage_id' => $heros->id,
        'inventaire_id' => $ligne->id,
    ])->assertStatus(422);
});
