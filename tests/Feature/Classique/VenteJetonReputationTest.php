<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Marche\PhaseMarche;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LA VENTE D'UN JETON DE RÉPUTATION AU MARCHÉ (lot K6 — *Rise of the Dread Moon* p. 5) : « Reputation tokens can be sold
 * between quests for 250 gold coins each, which must immediately be used to purchase something from the Underground
 * Market. Any excess gold from the sale of the reputation token is lost. This exchange can be done even if a hero cannot
 * visit shops between quests. » Par les VRAIES routes ; `tableAJetons()` et `articleDeLEtal()` viennent de
 * `BoursesTest`. Campagne `DocumentExemple`, INVENTÉE.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

/** Alice (Albrecht) fonde un groupe classique, Bob (Brunhild) le rejoint ; Alice reste connectée. */
function tableAJetons(int $orAlbrecht, int $orBrunhild): array
{
    $fiche = fn ($joueur, string $nom, int $or) => \App\Models\Personnage::create([
        'joueur_id' => $joueur->id, 'nom' => $nom, 'classe' => 'barbare', 'niveau' => 1, 'or' => $or,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $alice = connecterJoueur('alice');
    $albrecht = $fiche($alice, 'Albrecht', $orAlbrecht);
    $code = test()->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $albrecht->id])
        ->assertCreated()->json('groupe.identifiant');
    $bob = connecterJoueur('bob');
    $brunhild = $fiche($bob, 'Brunhild', $orBrunhild);
    test()->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $brunhild->id])->assertSuccessful();
    test()->actingAs($alice, 'joueur');

    return [Groupe::where('identifiant', $code)->firstOrFail(), $alice, $albrecht, $bob, $brunhild];
}

/** Un article de l'étal dont le prix tient sous `$plafond` et dépasse `$plancher`. */
function articleEntre(array $marche, int $plancher, int $plafond): array
{
    $article = collect($marche['inventaire'])->first(fn ($a) => $a['prix'] > $plancher && $a['prix'] <= $plafond && ($a['stock'] ?? 1) > 0);
    expect($article)->not->toBeNull();

    return [(int) $article['objet_id'], (int) $article['prix']];
}

it('un jeton vendu paie 250 po d\'achats avant la bourse ; le reste est perdu ; le jeton quitte le groupe à l\'application', function () {
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    [$groupe, $alice, $albrecht, $bob] = tableAJetons(orAlbrecht: 100, orBrunhild: 0);
    $groupe->update(['jetons_reputation' => 2]);
    $code = $groupe->identifiant;

    $marche = $this->postJson("/api/groupes/{$code}/marche")->assertCreated()
        ->assertJsonPath('reputation.jetons', 2)->assertJsonPath('reputation.valeur', 250)->assertJsonPath('reputation.restreint', false)
        ->json();
    [$objetId, $prix] = articleEntre($marche, 0, 250);

    // Un jeton pour Albrecht, un article sous 250 : sa bourse ne bouge pas, et l'écart est perdu — publié, déjà décidé.
    $etat = $this->putJson("/api/groupes/{$code}/marche/panier", [
        'achats' => [['objet_id' => $objetId, 'quantite' => 1]], 'ventes' => [],
        'jetons' => [['personnage_id' => $albrecht->id, 'nombre' => 1]],
    ])->assertOk()->json();
    expect($etat['bourses'][0])->toMatchArray(['projete' => 100, 'jetons' => 1, 'or_perdu' => 250 - $prix]);

    $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertOk();
    $this->actingAs($bob, 'joueur');
    $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertOk()->assertJsonPath('applique', true);

    expect((int) $albrecht->fresh()->or)->toBe(100)
        ->and(Inventaire::where('personnage_id', $albrecht->id)->where('objet_id', $objetId)->exists())->toBeTrue()
        ->and((int) $groupe->fresh()->jetons_reputation)->toBe(1);
});

it('au-delà du crédit, la bourse paie le reste ; un jeton qui ne sert pas est refusé à la confirmation', function () {
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    [$groupe, , $albrecht] = tableAJetons(orAlbrecht: 1000, orBrunhild: 0);
    $groupe->update(['jetons_reputation' => 2]);
    $code = $groupe->identifiant;
    $marche = $this->postJson("/api/groupes/{$code}/marche")->assertCreated()->json();
    [$cherId, $prixCher] = articleEntre($marche, 250, 1000);

    $this->putJson("/api/groupes/{$code}/marche/panier", [
        'achats' => [['objet_id' => $cherId, 'quantite' => 1]], 'ventes' => [],
        'jetons' => [['personnage_id' => $albrecht->id, 'nombre' => 1]],
    ])->assertOk()->assertJsonPath('bourses.0.projete', 1000 - ($prixCher - 250))->assertJsonPath('bourses.0.or_perdu', 0);

    // Deux jetons pour un seul article sous 250 : le second ne servirait à rien (« must immediately be used »).
    [$petitId, $petitPrix] = articleEntre($marche, 0, 250);
    $this->putJson("/api/groupes/{$code}/marche/panier", [
        'achats' => [['objet_id' => $petitId, 'quantite' => 1]], 'ventes' => [],
        'jetons' => [['personnage_id' => $albrecht->id, 'nombre' => 2]],
    ])->assertOk();
    expect($this->postJson("/api/groupes/{$code}/marche/confirmation")->assertStatus(422)->json('errors.jetons.0'))
        ->toContain('doit servir aussitôt');

    // Plus de jetons que le groupe n'en a : refusé dès le panier.
    $this->putJson("/api/groupes/{$code}/marche/panier", [
        'achats' => [], 'ventes' => [], 'jetons' => [['personnage_id' => $albrecht->id, 'nombre' => 3]],
    ])->assertStatus(422)->assertJsonValidationErrors('jetons');
    expect((int) $groupe->fresh()->jetons_reputation)->toBe(2);
});

it('deux joueurs ne vendent pas le même jeton : l\'application revérifie le total du groupe', function () {
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
    [$groupe, , $albrecht, $bob, $brunhild] = tableAJetons(orAlbrecht: 0, orBrunhild: 0);
    $groupe->update(['jetons_reputation' => 1]);
    $code = $groupe->identifiant;
    $marche = $this->postJson("/api/groupes/{$code}/marche")->assertCreated()->json();
    [$objetId] = articleEntre($marche, 0, 250);
    $panier = fn ($heros) => ['achats' => [['objet_id' => $objetId, 'quantite' => 1]], 'ventes' => [], 'jetons' => [['personnage_id' => $heros->id, 'nombre' => 1]]];

    $this->putJson("/api/groupes/{$code}/marche/panier", $panier($albrecht))->assertOk();
    $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertOk();
    $this->actingAs($bob, 'joueur');
    $this->putJson("/api/groupes/{$code}/marche/panier", $panier($brunhild))->assertOk();
    expect($this->postJson("/api/groupes/{$code}/marche/confirmation")->assertStatus(422)->json('errors.jetons.0'))
        ->toContain('tous paniers confondus');
    expect((int) $groupe->fresh()->jetons_reputation)->toBe(1)
        ->and(Inventaire::where('objet_id', $objetId)->exists())->toBeFalse();
});

it('marché fermé : avec un jeton, la phase s\'ouvre RESTREINTE — ni vente, ni achat hors du crédit', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['regles'] = ['marche_ferme' => true];
    app(CampagnesConstruites::class)->enregistrer($campagne);
    [$groupe, , $albrecht] = tableAJetons(orAlbrecht: 1000, orBrunhild: 0);
    $code = $groupe->identifiant;

    // Sans jeton : fermé, comme avant.
    expect($this->getJson("/api/groupes/{$code}/etat")->json('groupe.marche_ouvert'))->toBeFalse();
    $this->postJson("/api/groupes/{$code}/marche")->assertStatus(422);

    $groupe->update(['jetons_reputation' => 1]);
    $hub = $this->getJson("/api/groupes/{$code}/etat")->json('groupe');
    expect($hub['marche_ouvert'])->toBeTrue()->and($hub['marche_motif'])->toContain('Seul l\'échange d\'un jeton de réputation est possible.');

    $marche = $this->postJson("/api/groupes/{$code}/marche")->assertCreated()->assertJsonPath('reputation.restreint', true)->json();
    [$cherId] = articleEntre($marche, 250, 1000);
    [$petitId] = articleEntre($marche, 0, 250);

    $ligne = Inventaire::create(['personnage_id' => $albrecht->id, 'objet_id' => Objet::where('nom', 'Épée courte')->firstOrFail()->id, 'emplacement' => 'sac', 'quantite' => 1]);
    $this->putJson("/api/groupes/{$code}/marche/panier", ['achats' => [], 'ventes' => [['inventaire_id' => $ligne->id]]])
        ->assertStatus(422)->assertJsonValidationErrors('ventes');

    // Au-delà du jeton, même avec 1000 po en bourse : refusé.
    $this->putJson("/api/groupes/{$code}/marche/panier", [
        'achats' => [['objet_id' => $cherId, 'quantite' => 1]], 'ventes' => [], 'jetons' => [['personnage_id' => $albrecht->id]],
    ])->assertOk();
    expect($this->postJson("/api/groupes/{$code}/marche/confirmation")->assertStatus(422)->json('errors.jetons.0'))->toContain('marché est fermé');

    // Sans jeton, rien ne s'achète.
    $this->putJson("/api/groupes/{$code}/marche/panier", ['achats' => [['objet_id' => $petitId, 'quantite' => 1]], 'ventes' => []])->assertOk();
    $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertStatus(422);

    $this->putJson("/api/groupes/{$code}/marche/panier", [
        'achats' => [['objet_id' => $petitId, 'quantite' => 1]], 'ventes' => [], 'jetons' => [['personnage_id' => $albrecht->id]],
    ])->assertOk();
    $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertOk();
});

it('un groupe RPG n\'a ni jetons au panier ni bloc `reputation` au marché', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe('table-rpg');
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $marche = $this->postJson('/api/groupes/table-rpg/marche')->assertCreated()->json();
    expect($marche)->not->toHaveKey('reputation');

    $this->putJson('/api/groupes/table-rpg/marche/panier', ['achats' => [], 'ventes' => [], 'jetons' => [['personnage_id' => $heros->id]]])
        ->assertStatus(422)->assertJsonValidationErrors('jetons');
    $panier = $this->putJson('/api/groupes/table-rpg/marche/panier', ['achats' => [], 'ventes' => []])->assertOk()->json('paniers.0');
    expect($panier)->not->toHaveKey('jetons')
        ->and(PhaseMarche::VALEUR_JETON_REPUTATION)->toBe(250);
});
