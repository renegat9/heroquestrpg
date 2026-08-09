<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Competence;
use App\Models\ForgeAmelioration;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\Equipement;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ForgeAmeliorationSeeder;
use Database\Seeders\ObjetSeeder;

/*
 * Forge du Nain (nœud d'arbre, doc 01 §6 + doc 04 §4) : améliore
 * DÉFINITIVEMENT un exemplaire d'équipement au hub, contre de l'or commun.
 * Périmètre MVP : seules Affûtée/Renforcée (bonus_des_attaque/defense) sont
 * applicables — les 4 autres améliorations du catalogue restent hors
 * périmètre (mécaniques de combat non implémentées).
 */

beforeEach(function () {
    $this->seed([ObjetSeeder::class, CompetenceSeeder::class, ForgeAmeliorationSeeder::class]);
});

function sacDeForge(Personnage $p, string $nomObjet, string $emplacement = 'sac'): Inventaire
{
    $objet = Objet::where('nom', $nomObjet)->firstOrFail();

    return Inventaire::create([
        'personnage_id' => $p->id,
        'objet_id' => $objet->id,
        'emplacement' => $emplacement,
        'quantite' => 1,
    ]);
}

function donneForge(Personnage $nain): void
{
    $nain->competences()->attach(Competence::where('classe', 'nain')->where('nom', 'Forge')->value('id'));
}

it('applique Affûtée (+1 dé d\'attaque) à une arme du sac, débite la bourse commune', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Épée courte'); // effet des_attaque: 2, dans le sac (non équipée)

    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id,
        'inventaire_id' => $ligne->id,
        'amelioration_id' => $affutee->id,
    ])->assertCreated()
        ->assertJsonPath('inventaire.ameliorations.0.nom', 'Affûtée')
        ->assertJsonPath('groupe.or', 500 - $affutee->prix);

    expect($groupe->fresh()->or)->toBe(500 - $affutee->prix)
        ->and($nain->fresh()->des_attaque)->toBe(3); // non équipée : pas d'effet immédiat sur les dés
});

it('applique immédiatement le bonus si la pièce est DÉJÀ équipée', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain', 'des_defense' => 2]);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Cotte de mailles', 'armure'); // équipée d'emblée, des_defense: 1

    $renforcee = ForgeAmelioration::where('nom', 'Renforcée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id,
        'inventaire_id' => $ligne->id,
        'amelioration_id' => $renforcee->id,
    ])->assertCreated();

    // 2 (base) + 1 (cotte de mailles) + 1 (Renforcée). L'ancienne attente était
    // 3 : le fixture pose la pièce directement dans le slot sans passer par
    // Equipement::equiper(), si bien que le +1 de la cotte elle-même n'était
    // jamais appliqué. Le recalcul complet (l'attaque venant désormais de
    // l'arme) compte toutes les pièces PORTÉES, ce qui corrige aussi ce trou.
    expect($nain->fresh()->des_defense)->toBe(4);
});

it('le bonus de Forge s\'applique à l\'équipement ultérieur d\'une pièce améliorée dans le sac', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain', 'des_attaque' => 2]);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Épée courte'); // effet des_attaque: 2, non équipée
    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertCreated();

    expect($nain->fresh()->des_attaque)->toBe(2); // toujours dans le sac, aucun effet

    (new Equipement)->equiper($nain, $ligne->fresh());

    // L'épée courte FIXE l'attaque à 2, puis Affûtée ajoute +1 → 3.
    // (Avant : 2 de classe + 2 d'objet + 1 = 5.) Le bonus de Forge suit bien
    // l'objet à l'équipement.
    expect($nain->fresh()->des_attaque)->toBe(3);
});

it('le Nain peut forger l\'équipement d\'un AUTRE héros actif du groupe', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 500]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $barbare = creerHeros($bob, $groupe, 'Albrecht', 2, ['des_attaque' => 3]);
    $ligne = sacDeForge($barbare, 'Épée large', 'arme_principale'); // effet des_attaque: 3

    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id,
        'inventaire_id' => $ligne->id,
        'amelioration_id' => $affutee->id,
    ])->assertCreated();

    expect($barbare->fresh()->des_attaque)->toBe(4); // 3 base + 1, immédiat (équipée)
});

it('refuse sans le nœud Forge, hors du hub, sur un objet déjà amélioré, ou une amélioration hors périmètre', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 5000]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    $ligne = sacDeForge($nain, 'Épée courte');
    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    // Sans le nœud.
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422);

    donneForge($nain);

    // Amélioration hors périmètre MVP (Cruelle : relance_de_attaque_rate, non câblée).
    $cruelle = ForgeAmelioration::where('nom', 'Cruelle')->firstOrFail();
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $cruelle->id,
    ])->assertStatus(422);

    // Une fois appliquée, refuse une seconde amélioration sur le même objet.
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertCreated();
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422);

    // Hors du hub : refusé.
    $groupe->update(['phase' => 'quete']);
    $autre = sacDeForge($nain, 'Épée large');
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $autre->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422);
});

it('refuse une bourse commune insuffisante et une catégorie incompatible (amélioration d\'armure sur une arme)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 10]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);
    $ligne = sacDeForge($nain, 'Épée courte');
    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    // Bourse commune insuffisante (10 < 120).
    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422);

    $groupe->update(['or' => 5000]);
    $renforcee = ForgeAmelioration::where('nom', 'Renforcée')->firstOrFail(); // cible : armure

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $renforcee->id,
    ])->assertStatus(422);
});

it('refuse d\'améliorer un ARTEFACT (rareté unique)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 5000]);
    $nain = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain']);
    donneForge($nain);

    // Un artefact est déjà au sommet de la courbe : la Forge n'y ajoute rien.
    $ligne = sacDeForge($nain, 'Lame des Esprits');
    $affutee = ForgeAmelioration::where('nom', 'Affûtée')->firstOrFail();

    $this->postJson('/api/groupes/table-1/forge', [
        'personnage_id' => $nain->id, 'inventaire_id' => $ligne->id, 'amelioration_id' => $affutee->id,
    ])->assertStatus(422)
        ->assertJsonValidationErrors('inventaire_id');

    expect($ligne->fresh()->ameliorations ?? [])->toBe([]);
});
