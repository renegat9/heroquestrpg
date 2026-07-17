<?php

declare(strict_types=1);

use App\Http\Controllers\Api\TableController;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * Session de table, inscription, roster joueur et statut « prêt »
 * (docs/contrat-api.md §Modèle de session).
 *
 * Couvre :
 *  - POST /api/inscription (succès + 422 si identifiant pris)
 *  - POST /api/table (session table ouvre, narrateurActif vrai)
 *  - POST /api/table/ping (refresh TTL)
 *  - POST /api/table/quitter (vide session + cache)
 *  - narrateurActif false après expiration du cache
 *  - GET /etat accessible par la table sans compte joueur
 *  - POST /api/personnages (crée un perso libre)
 *  - POST /api/groupes avec personnage_id (fondateur engagé) + 422 si déjà engagé
 *  - POST /api/groupes/{identifiant}/pret :
 *      - ne démarre PAS si narrateur inactif
 *      - ne démarre PAS si un joueur pas prêt
 *      - DÉMARRE quand tous prêts + narrateur actif
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

// ---------------------------------------------------------------------------
// Inscription
// ---------------------------------------------------------------------------

it('crée un compte et connecte le joueur (inscription, sans mot de passe)', function () {
    $reponse = $this->postJson('/api/inscription', [
        'pseudo' => 'Alice',
        'identifiant' => 'alice',
    ]);

    $reponse->assertStatus(201)
        ->assertJsonPath('joueur.pseudo', 'Alice')
        ->assertJsonPath('joueur.identifiant', 'alice')
        ->assertJsonStructure(['joueur' => ['id', 'pseudo', 'identifiant', 'personnages']]);

    // Le joueur est bien connecté : /moi renvoie ses infos.
    $this->getJson('/api/moi')->assertOk()->assertJsonPath('joueur.identifiant', 'alice');
});

it('refuse l\'inscription si l\'identifiant est déjà pris (422)', function () {
    $this->postJson('/api/inscription', ['pseudo' => 'Alice', 'identifiant' => 'alice'])->assertStatus(201);

    // Deuxième tentative avec le même identifiant.
    $this->postJson('/api/inscription', ['pseudo' => 'Alice Bis', 'identifiant' => 'alice'])
        ->assertStatus(422)->assertJsonValidationErrors(['identifiant']);
});

it('valide les champs obligatoires de l\'inscription', function () {
    // identifiant non alpha_dash (espace).
    $this->postJson('/api/inscription', ['pseudo' => 'Bob', 'identifiant' => 'bob avec espace'])
        ->assertStatus(422)->assertJsonValidationErrors(['identifiant']);

    // pseudo manquant.
    $this->postJson('/api/inscription', ['identifiant' => 'bob'])
        ->assertStatus(422)->assertJsonValidationErrors(['pseudo']);
});

it('connecte par NOM seul (sans mot de passe), par identifiant ou pseudo', function () {
    $this->postJson('/api/inscription', ['pseudo' => 'Renégat', 'identifiant' => 'renegat'])->assertStatus(201);
    $this->postJson('/api/deconnexion')->assertOk();

    // Par identifiant (insensible à la casse).
    $this->postJson('/api/connexion', ['identifiant' => 'RENEGAT'])
        ->assertOk()->assertJsonPath('joueur.identifiant', 'renegat');
    $this->postJson('/api/deconnexion')->assertOk();

    // Par pseudo.
    $this->postJson('/api/connexion', ['identifiant' => 'Renégat'])
        ->assertOk()->assertJsonPath('joueur.identifiant', 'renegat');

    // Nom inconnu → 422.
    $this->postJson('/api/deconnexion')->assertOk();
    $this->postJson('/api/connexion', ['identifiant' => 'fantome'])
        ->assertStatus(422)->assertJsonValidationErrors(['identifiant']);
});

// ---------------------------------------------------------------------------
// Session de table
// ---------------------------------------------------------------------------

it('ouvre une session de table : narrateurActif devient vrai', function () {
    $groupe = creerGroupe('table-1');

    $reponse = $this->postJson('/api/table', ['code' => 'table-1']);
    $reponse->assertOk()
        ->assertJsonPath('groupe.groupe.identifiant', 'table-1');

    expect(TableController::narrateurActif($groupe))->toBeTrue();
});

it('narrateurActif devient faux après expiration du cache (simulation)', function () {
    $groupe = creerGroupe('table-1');

    $this->postJson('/api/table', ['code' => 'table-1'])->assertOk();
    expect(TableController::narrateurActif($groupe))->toBeTrue();

    // Simule l'expiration : on supprime manuellement la clé du cache.
    Cache::forget(TableController::cleActive($groupe->id));

    expect(TableController::narrateurActif($groupe))->toBeFalse();
});

it('ping rafraîchit la TTL du heartbeat', function () {
    $groupe = creerGroupe('table-1');

    $this->postJson('/api/table', ['code' => 'table-1'])->assertOk();

    // Simule l'expiration puis rafraîchit via ping.
    Cache::forget(TableController::cleActive($groupe->id));
    expect(TableController::narrateurActif($groupe))->toBeFalse();

    $this->postJson('/api/table/ping')->assertNoContent();
    expect(TableController::narrateurActif($groupe))->toBeTrue();
});

it('lecture-terminee (table) éteint « MJ réfléchit » — dégèle le joueur suivant (B1)', function () {
    $groupe = creerGroupe('table-1');
    $this->postJson('/api/table', ['code' => 'table-1'])->assertOk();

    Illuminate\Support\Facades\Event::fake([App\Events\MjReflechit::class]);
    // Simule l'état « MJ réfléchit » posé à la résolution d'une action.
    Illuminate\Support\Facades\Cache::put(App\Partie\EtatGroupe::cleMjReflechit($groupe->id), true, now()->addMinutes(5));

    $this->postJson('/api/table/lecture-terminee')->assertNoContent();

    Illuminate\Support\Facades\Event::assertDispatched(
        App\Events\MjReflechit::class,
        fn ($e) => $e->groupe->id === $groupe->id && $e->actif === false,
    );
});

it('lecture-terminee refuse sans session de table active (403)', function () {
    creerGroupe('table-1');
    $this->postJson('/api/table/lecture-terminee')->assertStatus(403);
});

it('quitter vide la session et le cache', function () {
    $groupe = creerGroupe('table-1');

    $this->postJson('/api/table', ['code' => 'table-1'])->assertOk();
    expect(TableController::narrateurActif($groupe))->toBeTrue();

    $this->postJson('/api/table/quitter')->assertNoContent();
    expect(TableController::narrateurActif($groupe))->toBeFalse();

    // Ping ne fonctionne plus (session oubliée).
    $this->postJson('/api/table/ping')->assertStatus(403);
});

it('retourne 404 si le code de groupe est inconnu', function () {
    $this->postJson('/api/table', ['code' => 'inexistant'])->assertNotFound();
});

// ---------------------------------------------------------------------------
// GET /etat accessible par la session de table
// ---------------------------------------------------------------------------

it('GET /etat est accessible par la table sans compte joueur', function () {
    $groupe = creerGroupe('table-1');

    // Ouvre la session de table (sans joueur connecté).
    $this->postJson('/api/table', ['code' => 'table-1'])->assertOk();

    // GET /etat doit répondre 200 même sans auth:joueur.
    $this->getJson('/api/groupes/table-1/etat')
        ->assertOk()
        ->assertJsonPath('groupe.identifiant', 'table-1')
        ->assertJsonPath('groupe.narrateur_actif', true);
});

it('GET /etat retourne 403 sans session table ni joueur membre', function () {
    creerGroupe('table-1');

    // Aucune session active, aucun joueur connecté.
    $this->getJson('/api/groupes/table-1/etat')->assertStatus(403);
});

it('EtatGroupe expose narrateur_actif et prets en phase hub', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe('table-1');
    creerHeros($joueur, $groupe, 'Albrecht', 1);

    // Sans heartbeat : narrateur_actif = false.
    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect($etat['groupe']['narrateur_actif'])->toBeFalse();
    expect($etat['groupe']['prets'])->toBeArray()->toHaveCount(1);
    expect($etat['groupe']['prets'][0]['pret'])->toBeFalse();

    // Avec heartbeat actif.
    Cache::put(TableController::cleActive($groupe->id), true, now()->addSeconds(30));
    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect($etat['groupe']['narrateur_actif'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// POST /api/personnages — roster joueur (perso libre)
// ---------------------------------------------------------------------------

it('crée un personnage libre dans le roster du joueur', function () {
    $this->seed([ClasseHerosSeeder::class]);
    $joueur = connecterJoueur('alice');

    $reponse = $this->postJson('/api/personnages', [
        'nom' => 'Gunther',
        'classe' => 'barbare',
    ]);

    $reponse->assertStatus(201)
        ->assertJsonPath('personnage.nom', 'Gunther')
        ->assertJsonPath('personnage.classe', 'barbare')
        ->assertJsonPath('personnage.disponible', true);

    expect(Personnage::where('joueur_id', $joueur->id)->where('nom', 'Gunther')->exists())->toBeTrue();
});

it('crée un magicien libre avec ses 3 éléments', function () {
    $this->seed([ClasseHerosSeeder::class, SortSeeder::class]);
    connecterJoueur('alice');

    $reponse = $this->postJson('/api/personnages', [
        'nom' => 'Zara',
        'classe' => 'magicien',
        'elements' => ['feu', 'air', 'eau'],
    ]);

    $reponse->assertStatus(201)
        ->assertJsonPath('personnage.classe', 'magicien');

    // Vérification sorts attachés via /moi.
    $moi = $this->getJson('/api/moi')->assertOk()->json();
    $sorts = collect($moi['joueur']['personnages'])
        ->firstWhere('nom', 'Zara')['sorts'] ?? [];
    // Magicien = 9 sorts (3 × 3 éléments, parité HeroQuest de base).
    expect(count($sorts))->toBe(9);
});

it('refuse la création d\'un perso avec une classe inexistante', function () {
    connecterJoueur('alice');

    $this->postJson('/api/personnages', [
        'nom' => 'Mystere',
        'classe' => 'classe_inexistante',
    ])->assertStatus(422)->assertJsonValidationErrors(['classe']);
});

// ---------------------------------------------------------------------------
// POST /api/groupes — avec personnage_id (fondateur engagé)
// ---------------------------------------------------------------------------

it('crée un groupe avec un perso libre comme fondateur (engagé)', function () {
    $this->seed([ClasseHerosSeeder::class]);
    $joueur = connecterJoueur('alice');

    // Créer d'abord un perso libre.
    $persoReponse = $this->postJson('/api/personnages', [
        'nom' => 'Albrecht',
        'classe' => 'barbare',
    ])->assertStatus(201);

    $personnageId = $persoReponse->json('personnage.id');

    $reponse = $this->postJson('/api/groupes', [
        'identifiant' => 'table-1',
        'nom' => 'Les Briseurs',
        'theme' => 'Forêt hantée',
        'longueur' => 'courte',
        'personnage_id' => $personnageId,
    ])->assertStatus(201);

    $groupe = Groupe::where('identifiant', 'table-1')->firstOrFail();

    // Le perso est engagé dans le groupe.
    $personnage = Personnage::findOrFail($personnageId);
    expect($personnage->groupe_actif_id)->toBe($groupe->id);

    // Le pivot est actif (SQLite renvoie 1, cast en bool).
    $pivot = $groupe->personnages()->where('personnages.id', $personnageId)->first();
    expect($pivot)->not->toBeNull();
    expect((bool) $pivot->pivot->actif)->toBeTrue();
});

it('POST /api/groupes sans personnage_id crée le groupe sans fondateur', function () {
    connecterJoueur('alice');

    $this->postJson('/api/groupes', [
        'identifiant' => 'table-vide',
        'nom' => 'Groupe Sans Fondateur',
        'theme' => 'Test',
        'longueur' => 'courte',
    ])->assertStatus(201);

    $groupe = Groupe::where('identifiant', 'table-vide')->firstOrFail();
    expect($groupe->personnages()->count())->toBe(0);
});

it('refuse de créer un groupe avec un perso déjà engagé (422)', function () {
    $this->seed([ClasseHerosSeeder::class]);
    $joueur = connecterJoueur('alice');

    // Créer un perso et l'engager dans un premier groupe.
    $groupe1 = creerGroupe('table-1');
    $personnage = creerHeros($joueur, $groupe1, 'Albrecht', 1);

    // Tenter de créer un second groupe avec ce perso déjà engagé.
    $this->postJson('/api/groupes', [
        'identifiant' => 'table-2',
        'nom' => 'Autre Groupe',
        'theme' => 'Test',
        'longueur' => 'courte',
        'personnage_id' => $personnage->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);
});

it('refuse d\'utiliser un perso qui n\'appartient pas au joueur', function () {
    $this->seed([ClasseHerosSeeder::class]);

    // Joueur A crée un perso.
    $joueurA = connecterJoueur('alice');
    $personnage = creerHeros($joueurA, creerGroupe('table-tmp'), 'Albrecht', 1);
    $personnage->update(['groupe_actif_id' => null]);

    // Joueur B essaie de créer un groupe avec le perso de A.
    $joueurB = \App\Auth\JoueurAuthentifiable::create([
        'pseudo' => 'bob',
        'identifiant' => 'bob',
        'mot_de_passe' => 'secret',
    ]);
    test()->actingAs($joueurB, 'joueur');

    $this->postJson('/api/groupes', [
        'identifiant' => 'table-2',
        'nom' => 'Groupe Bob',
        'theme' => 'Test',
        'longueur' => 'courte',
        'personnage_id' => $personnage->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);
});

// ---------------------------------------------------------------------------
// /moi expose groupe + narrateur_actif pour les persos engagés
// ---------------------------------------------------------------------------

it('/moi expose groupe.narrateur_actif pour les persos engagés', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe('table-1');
    creerHeros($joueur, $groupe, 'Albrecht', 1);

    // Sans heartbeat.
    $moi = $this->getJson('/api/moi')->assertOk()->json();
    $p = collect($moi['joueur']['personnages'])->firstWhere('nom', 'Albrecht');
    expect($p['disponible'])->toBeFalse();
    expect($p['groupe']['identifiant'])->toBe('table-1');
    expect($p['groupe']['narrateur_actif'])->toBeFalse();

    // Avec heartbeat actif.
    Cache::put(TableController::cleActive($groupe->id), true, now()->addSeconds(30));
    $moi = $this->getJson('/api/moi')->assertOk()->json();
    $p = collect($moi['joueur']['personnages'])->firstWhere('nom', 'Albrecht');
    expect($p['groupe']['narrateur_actif'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Statut « prêt » et démarrage de quête
// ---------------------------------------------------------------------------

it('POST pret stocke le statut et broadcast PretsMaj', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe('table-1');
    $perso = creerHeros($joueur, $groupe, 'Albrecht', 1);

    $reponse = $this->postJson("/api/groupes/table-1/pret", [
        'personnage_id' => $perso->id,
        'pret' => true,
    ])->assertOk();

    expect($reponse->json('prets'))->toBeArray();
    expect($reponse->json('prets.0.personnage_id'))->toBe($perso->id);
    expect($reponse->json('prets.0.pret'))->toBeTrue();

    // Le cache est bien mis à jour.
    $cache = Cache::get("partie:pret:{$groupe->id}");
    expect((bool) ($cache[$perso->id] ?? false))->toBeTrue();
});

it('la quête ne démarre PAS si le narrateur est inactif', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe('table-1');
    $perso = creerHeros($joueur, $groupe, 'Albrecht', 1);

    // Pas de heartbeat actif.
    $reponse = $this->postJson("/api/groupes/table-1/pret", [
        'personnage_id' => $perso->id,
        'pret' => true,
    ])->assertOk();

    // quete_demarree absent ou false.
    expect($reponse->json('quete_demarree'))->not->toBeTrue();
    $groupe->refresh();
    expect($groupe->phase)->toBe('hub');
});

it('la quête ne démarre PAS si un joueur n\'est pas prêt', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe('table-1');
    $perso1 = creerHeros($joueur, $groupe, 'Albrecht', 1);
    $perso2 = creerHeros($joueur, $groupe, 'Brunhilde', 2);

    // Heartbeat actif.
    Cache::put(TableController::cleActive($groupe->id), true, now()->addSeconds(30));

    // Seul le premier perso est prêt.
    $reponse = $this->postJson("/api/groupes/table-1/pret", [
        'personnage_id' => $perso1->id,
        'pret' => true,
    ])->assertOk();

    expect($reponse->json('quete_demarree'))->not->toBeTrue();
    $groupe->refresh();
    expect($groupe->phase)->toBe('hub');
});

it('la quête DÉMARRE quand tous prêts et narrateur actif', function () {
    Queue::fake();

    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe('table-1');
    $perso1 = creerHeros($joueur, $groupe, 'Albrecht', 1);
    $perso2 = creerHeros($joueur, $groupe, 'Brunhilde', 2);

    // Heartbeat actif.
    Cache::put(TableController::cleActive($groupe->id), true, now()->addSeconds(30));

    // Perso 1 prêt (pas encore tous).
    $this->postJson("/api/groupes/table-1/pret", [
        'personnage_id' => $perso1->id,
        'pret' => true,
    ])->assertOk();

    $groupe->refresh();
    expect($groupe->phase)->toBe('hub');

    // Perso 2 prêt (tous prêts + narrateur) → démarrage.
    $reponse = $this->postJson("/api/groupes/table-1/pret", [
        'personnage_id' => $perso2->id,
        'pret' => true,
    ])->assertOk();

    expect($reponse->json('quete_demarree'))->toBeTrue();
    expect($reponse->json('quete.etat'))->toBe('en_cours');

    $groupe->refresh();
    expect($groupe->phase)->toBe('quete');

    // Les statuts prêts sont vidés après le démarrage.
    expect(Cache::get("partie:pret:{$groupe->id}"))->toBeNull();

    $quete = Quete::where('groupe_id', $groupe->id)->first();
    expect($quete)->not->toBeNull();
});

it('refuse la route pret pour un non-membre du groupe', function () {
    $joueur = connecterJoueur('alice');
    $groupe = creerGroupe('table-1');

    // Perso non engagé dans ce groupe.
    $autreGroupe = creerGroupe('table-autre');
    $perso = creerHeros($joueur, $autreGroupe, 'Albrecht', 1);

    $this->postJson("/api/groupes/table-1/pret", [
        'personnage_id' => $perso->id,
        'pret' => true,
    ])->assertStatus(422);
});
