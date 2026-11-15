<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Bourses;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\MoteurOracle;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * MODE CLASSIQUE — BOURSE PAR HÉROS (décision de René, 2026-11-13 ; `docs/plan-mode-classique.md` Q3), par les VRAIES
 * routes.
 *
 * Ce que ces tests défendent :
 *  1. le marché d'un groupe classique publie la bourse de chaque héros, et ne publie plus d'or commun à dépenser ;
 *  2. un achat est payé par le héros qui le reçoit, une vente crédite celui qui possédait la pièce ; `groupes.or`
 *     n'est jamais écrit ;
 *  3. la bourse d'un autre ne couvre pas la mienne : la confirmation est refusée, sur place ;
 *  4. le joueur qui pilote deux héros paie chaque achat avec la bourse du héros qui le reçoit ;
 *  5. l'or trouvé en quête va à celui qui le trouve ; un gain que personne ne fait en particulier est partagé ;
 *  6. l'état publié porte la bourse du héros — et `null` pour un groupe à pot commun, où rien n'a changé.
 *
 * La campagne est `DocumentExemple` : un plateau, des quêtes et des textes INVENTÉS.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
});

function herosABourse(JoueurAuthentifiable $joueur, string $nom, int $or): Personnage
{
    return Personnage::create([
        'joueur_id' => $joueur->id, 'nom' => $nom, 'classe' => 'barbare', 'niveau' => 1, 'or' => $or,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
}

/**
 * Un groupe classique à deux joueurs : Alice (Albrecht) le fonde, Bob (Brunhild) le rejoint. Rend le groupe, les deux
 * joueurs et leurs héros ; Alice reste connectée.
 *
 * @return array{0: Groupe, 1: JoueurAuthentifiable, 2: Personnage, 3: JoueurAuthentifiable, 4: Personnage}
 */
function tableClassiqueADeux(int $orAlbrecht, int $orBrunhild): array
{
    $alice = connecterJoueur('alice');
    $albrecht = herosABourse($alice, 'Albrecht', $orAlbrecht);
    $code = test()->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $albrecht->id])
        ->assertCreated()->json('groupe.identifiant');

    $bob = connecterJoueur('bob');
    $brunhild = herosABourse($bob, 'Brunhild', $orBrunhild);
    test()->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $brunhild->id])->assertSuccessful();

    test()->actingAs($alice, 'joueur');

    return [Groupe::where('identifiant', $code)->firstOrFail(), $alice, $albrecht, $bob, $brunhild];
}

/** Un article de l'étal ouvert, par son nom : `[objet_id, prix]`. */
function articleDeLEtal(array $marche, string $nom): array
{
    $article = collect($marche['inventaire'])->firstWhere('nom', $nom);
    expect($article)->not->toBeNull();

    return [(int) $article['objet_id'], (int) $article['prix']];
}

it('le marché classique publie la bourse de chaque héros, et chacun paie avec la sienne', function () {
    [$groupe, $alice, $albrecht, $bob, $brunhild] = tableClassiqueADeux(orAlbrecht: 400, orBrunhild: 30);
    $code = $groupe->identifiant;

    // Brunhild a une pièce à vendre.
    $ligne = Inventaire::create([
        'personnage_id' => $brunhild->id, 'objet_id' => Objet::where('nom', 'Épée courte')->firstOrFail()->id,
        'emplacement' => 'sac', 'quantite' => 1,
    ]);

    $marche = $this->postJson("/api/groupes/{$code}/marche")->assertCreated()
        // L'or reste sur les héros : le pot commun est vide, et ce sont les bourses qui sont publiées.
        ->assertJsonPath('or_courant', 0)
        ->assertJsonPath('bourses.0.nom', 'Albrecht')->assertJsonPath('bourses.0.or', 400)->assertJsonPath('bourses.0.projete', 400)
        ->assertJsonPath('bourses.1.nom', 'Brunhild')->assertJsonPath('bourses.1.or', 30)->assertJsonPath('bourses.1.projete', 30)
        ->json();

    [$bouclierId, $prixBouclier] = articleDeLEtal($marche, 'Bouclier');
    expect($prixBouclier)->toBeGreaterThan(30)->toBeLessThanOrEqual(400);

    // Alice achète pour Albrecht : la projection de SA bourse baisse, celle de Brunhild ne bouge pas.
    $this->putJson("/api/groupes/{$code}/marche/panier", ['achats' => [['objet_id' => $bouclierId, 'quantite' => 1]], 'ventes' => []])
        ->assertOk()
        ->assertJsonPath('bourses.0.projete', 400 - $prixBouclier)
        ->assertJsonPath('bourses.1.projete', 30);
    $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertOk()->assertJsonPath('applique', null);

    // Bob vend l'épée de Brunhild : c'est SA bourse qui est créditée.
    $this->actingAs($bob, 'joueur');
    $etat = $this->putJson("/api/groupes/{$code}/marche/panier", ['achats' => [], 'ventes' => [['inventaire_id' => $ligne->id]]])
        ->assertOk()->json();
    $revente = (int) collect($etat['paniers'])->firstWhere('joueur_id', $bob->id)['ventes'][0]['total'];
    expect($revente)->toBeGreaterThan(0)
        ->and($etat['bourses'][1]['projete'])->toBe(30 + $revente);

    $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertOk()->assertJsonPath('applique', true);

    expect((int) $albrecht->fresh()->or)->toBe(400 - $prixBouclier)
        ->and((int) $brunhild->fresh()->or)->toBe(30 + $revente)
        // Le pot commun d'un groupe classique n'est jamais écrit.
        ->and((int) $groupe->fresh()->or)->toBe(0)
        ->and(Inventaire::where('personnage_id', $albrecht->id)->where('objet_id', $bouclierId)->exists())->toBeTrue()
        ->and(Inventaire::whereKey($ligne->id)->exists())->toBeFalse();
});

it('la bourse d\'un autre ne couvre pas la mienne : la confirmation est refusée sur place, et rien ne bouge', function () {
    // Au pot commun, 30 + 400 couvriraient l'achat. En classique, Brunhild n'a que ses 30 pièces.
    [$groupe, , $albrecht, $bob, $brunhild] = tableClassiqueADeux(orAlbrecht: 400, orBrunhild: 30);
    $code = $groupe->identifiant;

    $marche = $this->postJson("/api/groupes/{$code}/marche")->assertCreated()->json();
    [$bouclierId, $prixBouclier] = articleDeLEtal($marche, 'Bouclier');

    $this->actingAs($bob, 'joueur');
    $this->putJson("/api/groupes/{$code}/marche/panier", ['achats' => [['objet_id' => $bouclierId, 'quantite' => 1]], 'ventes' => []])
        ->assertOk()
        // Le découvert est PUBLIÉ : l'écran n'a rien à recalculer pour griser la confirmation.
        ->assertJsonPath('bourses.1.projete', 30 - $prixBouclier);

    $refus = $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertStatus(422);
    expect($refus->json('errors.achats.0'))->toContain('bourse de Brunhild')
        ->toContain((string) ($prixBouclier - 30));

    expect((int) $brunhild->fresh()->or)->toBe(30)
        ->and((int) $albrecht->fresh()->or)->toBe(400)
        ->and(Inventaire::where('personnage_id', $brunhild->id)->exists())->toBeFalse();

    // Son panier n'est pas confirmé : le marché attend toujours.
    $etat = $this->getJson("/api/groupes/{$code}/marche")->assertOk()->json();
    expect(collect($etat['paniers'])->firstWhere('joueur_id', $bob->id)['confirme'])->toBeFalse();
});

it('le joueur qui pilote deux héros paie chaque achat avec la bourse du héros qui le reçoit', function () {
    $alice = connecterJoueur('alice');
    $albrecht = herosABourse($alice, 'Albrecht', 400);
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $albrecht->id])
        ->assertCreated()->json('groupe.identifiant');
    $conrad = herosABourse($alice, 'Conrad', 20);
    $this->postJson("/api/groupes/{$code}/joueurs", ['personnage_id' => $conrad->id])->assertSuccessful();

    $marche = $this->postJson("/api/groupes/{$code}/marche")->assertCreated()->json();
    [$bouclierId, $prixBouclier] = articleDeLEtal($marche, 'Bouclier');

    // Le bouclier pour Conrad : sa bourse ne suffit pas, celle d'Albrecht — même joueur — n'y change rien.
    $this->putJson("/api/groupes/{$code}/marche/panier", [
        'achats' => [['objet_id' => $bouclierId, 'quantite' => 1, 'personnage_id' => $conrad->id]], 'ventes' => [],
    ])->assertOk()->assertJsonPath('bourses.0.projete', 400)->assertJsonPath('bourses.1.projete', 20 - $prixBouclier);
    $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertStatus(422);

    // Le même bouclier pour Albrecht : c'est lui qui paie, et lui qui le reçoit.
    $this->putJson("/api/groupes/{$code}/marche/panier", [
        'achats' => [['objet_id' => $bouclierId, 'quantite' => 1, 'personnage_id' => $albrecht->id]], 'ventes' => [],
    ])->assertOk();
    $this->postJson("/api/groupes/{$code}/marche/confirmation")->assertOk()->assertJsonPath('applique', true);

    expect((int) $albrecht->fresh()->or)->toBe(400 - $prixBouclier)
        ->and((int) $conrad->fresh()->or)->toBe(20)
        ->and(Inventaire::where('personnage_id', $albrecht->id)->where('objet_id', $bouclierId)->exists())->toBeTrue();
});

it('lever la Malédiction de l\'Oracle au marché classique débite la bourse du héros maudit', function () {
    [$groupe, , $albrecht, , $brunhild] = tableClassiqueADeux(orAlbrecht: 900, orBrunhild: 900);
    $code = $groupe->identifiant;
    app(MoteurOracle::class)->accorderMalediction($albrecht);

    $this->postJson("/api/groupes/{$code}/marche")->assertCreated();
    $this->postJson("/api/groupes/{$code}/marche/lever-malediction", ['personnage_id' => $albrecht->id])
        ->assertOk()->assertJsonPath('leve', true)->assertJsonPath('or', 100);

    expect((int) $albrecht->fresh()->or)->toBe(100)
        ->and((int) $brunhild->fresh()->or)->toBe(900)
        ->and((int) $groupe->fresh()->or)->toBe(0);
});

it('l\'or trouvé en fouillant va à la bourse de celui qui fouille', function () {
    [$groupe, , $albrecht, , $brunhild] = tableClassiqueADeux(orAlbrecht: 10, orBrunhild: 10);
    $code = $groupe->identifiant;
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    // Le gobelin de la salle de départ est écarté (dans la galerie voisine, hors de vue) : une salle occupée ne se
    // fouille pas. Le paquet de trésor du classique est vide tant que les notes de l'auteur n'existent pas (lot K3) :
    // on y pose la carte que ce test veut voir tirer.
    $quete->instancesMonstres()->update(['position_x' => 9, 'position_y' => 3, 'revele' => false]);
    empilerCarteFouille($quete, ['issue' => 'tresor', 'or' => 30]);
    Cache::forget(GenererMenu::cleDuHeros($groupe, $albrecht));
    $this->getJson("/api/groupes/{$code}/menu")->assertOk();

    $this->postJson("/api/groupes/{$code}/choix", ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'tresor')
        ->assertJsonPath('resultat.or', 30);

    $etat = $this->getJson("/api/groupes/{$code}/etat")->assertOk()->json();
    $entites = collect($etat['entites'])->where('type', 'heros')->keyBy('nom');

    expect((int) $albrecht->fresh()->or)->toBe(40)
        ->and((int) $brunhild->fresh()->or)->toBe(10)
        ->and((int) $groupe->fresh()->or)->toBe(0)
        // La bourse est publiée sur le héros, en quête : la manette l'affiche sans rien recalculer.
        ->and($entites['Albrecht']['bourse'])->toBe(40)
        ->and($entites['Brunhild']['bourse'])->toBe(10);
});

it('un gain que personne ne fait en particulier est partagé, le reste aux premiers dans l\'ordre du tour', function () {
    [$groupe, , $albrecht, , $brunhild] = tableClassiqueADeux(orAlbrecht: 0, orBrunhild: 0);

    $parts = app(Bourses::class)->verser($groupe, 25);

    expect($parts)->toBe([
        ['personnage_id' => $albrecht->id, 'nom' => 'Albrecht', 'or' => 13],
        ['personnage_id' => $brunhild->id, 'nom' => 'Brunhild', 'or' => 12],
    ])
        ->and((int) $albrecht->fresh()->or)->toBe(13)
        ->and((int) $brunhild->fresh()->or)->toBe(12)
        ->and((int) $groupe->fresh()->or)->toBe(0);
});

it('un paiement que la bourse ne couvre pas est refusé sans rien débiter', function () {
    [$groupe, , $albrecht] = tableClassiqueADeux(orAlbrecht: 50, orBrunhild: 500);

    expect(fn () => app(Bourses::class)->payer($groupe, 80, $albrecht))
        ->toThrow(\Illuminate\Validation\ValidationException::class, 'Il manque 30 pièces d\'or à la bourse de Albrecht.');

    expect((int) $albrecht->fresh()->or)->toBe(50);

    app(Bourses::class)->payer($groupe, 50, $albrecht);
    expect((int) $albrecht->fresh()->or)->toBe(0);
});

it('publie la bourse de chaque héros au hub d\'un groupe classique, et null pour un groupe à pot commun', function () {
    [$groupe] = tableClassiqueADeux(orAlbrecht: 400, orBrunhild: 30);

    $prets = collect($this->getJson("/api/groupes/{$groupe->identifiant}/etat")->assertOk()->json('groupe.prets'))->keyBy('nom');
    expect($prets['Albrecht']['bourse'])->toBe(400)
        ->and($prets['Brunhild']['bourse'])->toBe(30);

    // CAMPAGNE : rien n'a changé. Le pot commun, aucune bourse publiée, et l'or gagné va au pot.
    $campagne = creerGroupe('table-1');
    $campagne->update(['or' => 100]);
    $hero = creerHeros(JoueurAuthentifiable::where('pseudo', 'alice')->firstOrFail(), $campagne, 'Dagmar', 1);

    $pretsCampagne = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.prets');
    expect($pretsCampagne[0]['bourse'])->toBeNull()
        ->and($this->postJson('/api/groupes/table-1/marche')->assertCreated()->json('bourses'))->toBeNull();

    expect(app(Bourses::class)->verser($campagne, 40, $hero))->toBe([])
        ->and((int) $campagne->fresh()->or)->toBe(140)
        ->and((int) $hero->fresh()->or)->toBe(0);

    app(Bourses::class)->payer($campagne, 60, $hero);
    expect((int) $campagne->fresh()->or)->toBe(80);
});
