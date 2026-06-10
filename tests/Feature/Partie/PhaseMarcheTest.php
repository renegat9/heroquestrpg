<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\Inventaire;
use App\Models\Objet;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Phase marché (doc 04 §5, contrat docs/contrat-api.md) — au hub uniquement.
 * La phase vit en cache serveur : paniers par joueur, total projeté sur la
 * bourse commune (M3), application ATOMIQUE quand tous ont confirmé, avec
 * tous les garde-fous (or, stock, possession des ventes, capacité de sac).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Ligne d'inventaire au sac d'un héros (objet du catalogue, par nom). */
function donnerObjet(int $personnageId, string $nomObjet, string $emplacement = 'sac', int $quantite = 1): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $personnageId,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => $emplacement,
        'quantite' => $quantite,
    ]);
}

it('ouvre la phase au hub : profil bourg par défaut, raretés et stocks du profil, prix du catalogue', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 1000]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $reponse = $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    $reponse->assertJsonPath('profil', 'bourg')
        ->assertJsonPath('or_courant', 1000)
        ->assertJsonPath('total_projete', 1000);

    expect((float) $reponse->json('multiplicateur'))->toBe(1.0);

    $inventaire = collect($reponse->json('inventaire'));

    // Bourg : commun + peu commun seulement — jamais de rare ni d'unique.
    expect($inventaire->pluck('rarete')->unique()->sort()->values()->all())
        ->toBe(['commun', 'peu_commun']);

    // Prix = prix_base × 1,0 ; stocks playtest : commun illimité, peu_commun 3.
    $epee = $inventaire->firstWhere('nom', 'Épée courte');
    $lance = $inventaire->firstWhere('nom', 'Lance');
    expect($epee['prix'])->toBe(150)
        ->and($epee['stock'])->toBeNull()
        ->and($lance['prix'])->toBe(250)
        ->and($lance['stock'])->toBe(3);

    // Panier vide initialisé pour chaque joueur membre.
    $reponse->assertJsonPath('paniers.0.joueur_id', $alice->id)
        ->assertJsonPath('paniers.0.confirme', false);

    // Déjà ouverte → 422 ; GET rend le même état.
    $this->postJson('/api/groupes/table-1/marche')->assertStatus(422);
    $this->getJson('/api/groupes/table-1/marche')->assertOk()->assertJsonPath('profil', 'bourg');
});

it('applique le profil de lieu : village = commun seul à ×1,2', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $reponse = $this->postJson('/api/groupes/table-1/marche', ['profil' => 'village'])->assertCreated();

    $inventaire = collect($reponse->json('inventaire'));
    expect($inventaire->pluck('rarete')->unique()->all())->toBe(['commun'])
        ->and($inventaire->firstWhere('nom', 'Dague')['prix'])->toBe(30); // 25 × 1,2
});

it('refuse d\'ouvrir le marché pendant une quête (hub uniquement)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $this->postJson('/api/groupes/table-1/marche')->assertStatus(422);
});

it('calcule le total projeté en direct sur l\'ensemble des paniers (achats + reventes à 50 %)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 1000]);
    $heroAlice = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroBob = creerHeros($bob, $groupe, 'Brunhilde', 2);
    $dague = donnerObjet($heroBob->id, 'Dague');

    $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    // Alice achète une épée courte (150) et 2 potions de soin (2 × 100).
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [
            ['objet_id' => Objet::where('nom', 'Épée courte')->first()->id],
            ['objet_id' => Objet::where('nom', 'Potion de soin')->first()->id, 'quantite' => 2],
        ],
        'ventes' => [],
    ])->assertOk()->assertJsonPath('total_projete', 1000 - 350);

    // Bob revend sa dague : 50 % du prix marchand courant (M1) = 25 ÷ 2 = 12.
    $this->actingAs($bob, 'joueur');
    $etat = $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [],
        'ventes' => [['inventaire_id' => $dague->id]],
    ])->assertOk()->json();

    expect($etat['total_projete'])->toBe(1000 - 350 + 12)
        ->and($etat['or_courant'])->toBe(1000);

    // Panier consolidé : chaque ligne est étiquetée de son joueur.
    $paniers = collect($etat['paniers'])->keyBy('joueur_id');
    expect($paniers[$alice->id]['achats'])->toHaveCount(2)
        ->and($paniers[$alice->id]['achats'][0]['personnage_id'])->toBe($heroAlice->id)
        ->and($paniers[$bob->id]['ventes'][0]['nom'])->toBe('Dague')
        ->and($paniers[$bob->id]['ventes'][0]['prix_revente'])->toBe(12);

    // Modifier son panier annule SA confirmation (et seulement la sienne).
    $this->postJson('/api/groupes/table-1/marche/confirmation')->assertOk()->assertJsonPath('applique', null);
    $maj = $this->putJson('/api/groupes/table-1/marche/panier', ['achats' => [], 'ventes' => []])
        ->assertOk()->json();
    expect(collect($maj['paniers'])->firstWhere('joueur_id', $bob->id)['confirme'])->toBeFalse();
});

it('refuse la vente d\'un objet que le joueur ne possède pas', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroBob = creerHeros($bob, $groupe, 'Brunhilde', 2);
    $dagueDeBob = donnerObjet($heroBob->id, 'Dague');

    $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    // Alice tente de vendre la dague de Bob → 422.
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [],
        'ventes' => [['inventaire_id' => $dagueDeBob->id]],
    ])->assertStatus(422);

    expect($dagueDeBob->fresh())->not->toBeNull();
});

it('refuse un achat hors profil ou au-delà du stock', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 5000]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/marche')->assertCreated(); // bourg

    // La hache de bataille est rare : absente de l'étal d'un bourg.
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [['objet_id' => Objet::where('nom', 'Hache de bataille')->first()->id]],
        'ventes' => [],
    ])->assertStatus(422);

    // Lance : peu commun, stock 3 — en demander 4 dépasse la boutique.
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [['objet_id' => Objet::where('nom', 'Lance')->first()->id, 'quantite' => 4]],
        'ventes' => [],
    ])->assertStatus(422);
});

it('refuse la finalisation si la bourse commune ne couvre pas le total (total projeté < 0)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 100]);
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [['objet_id' => Objet::where('nom', 'Épée courte')->first()->id]],
        'ventes' => [],
    ])->assertOk()->assertJsonPath('total_projete', -50);

    // Seul membre → sa confirmation déclencherait l'application : refusée.
    $this->postJson('/api/groupes/table-1/marche/confirmation')->assertStatus(422);

    // Rien n'est appliqué, la phase reste ouverte (panier à corriger).
    expect($groupe->fresh()->or)->toBe(100)
        ->and(Inventaire::where('personnage_id', $hero->id)->exists())->toBeFalse();
    $this->getJson('/api/groupes/table-1/marche')->assertOk();
});

it('refuse la finalisation si le sac d\'un personnage déborde (capacité = PV Body max ÷ 2 + bonus de classe)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 5000]);
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1); // barbare : ⌈8 ÷ 2⌉ + 0 = 4 places

    // Sac déjà bien rempli : 3 objets.
    donnerObjet($hero->id, 'Bâton');
    donnerObjet($hero->id, 'Casque');
    donnerObjet($hero->id, 'Bouclier');

    $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    // 2 dagues de plus = 5 objets au sac pour 4 places → 422 à l'application.
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [['objet_id' => Objet::where('nom', 'Dague')->first()->id, 'quantite' => 2]],
        'ventes' => [],
    ])->assertOk();

    $this->postJson('/api/groupes/table-1/marche/confirmation')->assertStatus(422);

    expect($groupe->fresh()->or)->toBe(5000)
        ->and(Inventaire::where('personnage_id', $hero->id)->count())->toBe(3);

    // Les consommables ne comptent pas dans le sac : 2 potions passent.
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [
            ['objet_id' => Objet::where('nom', 'Dague')->first()->id], // 4ᵉ et dernière place
            ['objet_id' => Objet::where('nom', 'Potion de soin')->first()->id, 'quantite' => 2],
        ],
        'ventes' => [],
    ])->assertOk();

    $this->postJson('/api/groupes/table-1/marche/confirmation')->assertOk()->assertJsonPath('applique', true);
});

it('refuse la finalisation quand les paniers cumulés dépassent le stock de la boutique', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 5000]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    // 2 lances chacun : légal panier par panier (stock 3), pas en cumulé (4).
    $lance = Objet::where('nom', 'Lance')->first();

    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [['objet_id' => $lance->id, 'quantite' => 2]], 'ventes' => [],
    ])->assertOk();
    $this->postJson('/api/groupes/table-1/marche/confirmation')->assertOk();

    $this->actingAs($bob, 'joueur');
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [['objet_id' => $lance->id, 'quantite' => 2]], 'ventes' => [],
    ])->assertOk();
    $this->postJson('/api/groupes/table-1/marche/confirmation')->assertStatus(422);

    expect($groupe->fresh()->or)->toBe(5000)
        ->and(Inventaire::count())->toBe(0);
});

it('finalise atomiquement quand tous les joueurs ont confirmé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 1000]);
    $heroAlice = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroBob = creerHeros($bob, $groupe, 'Brunhilde', 2);
    $dague = donnerObjet($heroBob->id, 'Dague');

    $this->postJson('/api/groupes/table-1/marche')->assertCreated();

    // Alice : épée courte (150, vers le sac) + 2 potions (200, consommables).
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [
            ['objet_id' => Objet::where('nom', 'Épée courte')->first()->id],
            ['objet_id' => Objet::where('nom', 'Potion de soin')->first()->id, 'quantite' => 2],
        ],
        'ventes' => [],
    ])->assertOk();

    // Première confirmation : rien n'est appliqué tant que Bob n'a pas confirmé.
    $this->postJson('/api/groupes/table-1/marche/confirmation')
        ->assertOk()->assertJsonPath('applique', null);
    expect($groupe->fresh()->or)->toBe(1000);

    // Bob vend sa dague (+12) et confirme → application atomique + clôture.
    $this->actingAs($bob, 'joueur');
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [],
        'ventes' => [['inventaire_id' => $dague->id]],
    ])->assertOk();

    $this->postJson('/api/groupes/table-1/marche/confirmation')
        ->assertOk()->assertJsonPath('applique', true);

    // Or débité/crédité sur la bourse commune : 1000 − 350 + 12.
    expect($groupe->fresh()->or)->toBe(662);

    // Achats rangés : l'épée au SAC d'Albrecht, les potions empilées hors sac.
    $sac = Inventaire::where('personnage_id', $heroAlice->id)->get();
    $epee = $sac->firstWhere('emplacement', 'sac');
    $potions = $sac->firstWhere('emplacement', 'consommable');
    expect($epee->objet->nom)->toBe('Épée courte')
        ->and($potions->objet->nom)->toBe('Potion de soin')
        ->and($potions->quantite)->toBe(2);

    // La vente a retiré la ligne de l'inventaire de Brunhilde.
    expect($dague->fresh())->toBeNull();

    // Phase close (journalisée) : GET → 404, plus rien à confirmer.
    $this->getJson('/api/groupes/table-1/marche')->assertNotFound();
    expect($groupe->evenements()->where('type', 'systeme')->get()
        ->contains(fn ($e) => ($e->payload['action'] ?? null) === 'marche_finalise'))->toBeTrue();
});

it('annule la phase sans rien appliquer', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 1000]);
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/marche')->assertCreated();
    $this->putJson('/api/groupes/table-1/marche/panier', [
        'achats' => [['objet_id' => Objet::where('nom', 'Épée courte')->first()->id]],
        'ventes' => [],
    ])->assertOk();

    $this->deleteJson('/api/groupes/table-1/marche')->assertNoContent();

    expect($groupe->fresh()->or)->toBe(1000)
        ->and(Inventaire::where('personnage_id', $hero->id)->exists())->toBeFalse();
    $this->getJson('/api/groupes/table-1/marche')->assertNotFound();
});
