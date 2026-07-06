<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Events\ClotureOuverte;
use App\Events\ClotureTerminee;
use App\Jobs\GenererMenu;
use App\Models\Carte;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Condition;
use App\Models\Personnage;
use App\Models\PersonnageHistorique;
use App\Models\Quete;
use App\Models\Snapshot;
use App\Models\Sort;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * Clôture de campagne (doc 05 §6, doc 12 §8, contrat docs/contrat-api.md) :
 * fenêtre en cache ouverte à la victoire du boss final (auto), au hub (fin
 * décidée) ou par abandon après une quête échouée (or = or_initial plafonné).
 * Répartition : parts égales + reste unité par unité aux premiers, équipement
 * réassignable entre héros actifs. Quand TOUS confirment, la finalisation
 * part en job : or réparti, historique écrit, personnages détachés, puis
 * purge COMPLÈTE (groupe inclus). Le groupe vidé par les départs subit la
 * même purge, silencieuse.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class, ConditionSeeder::class]);
});

/** Ligne d'inventaire au sac d'un héros (objet du catalogue, par nom). */
function equiperHeros(int $personnageId, string $nomObjet): Inventaire
{
    return Inventaire::create([
        'personnage_id' => $personnageId,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
}

/**
 * Gagne la quête courante avec le héros donné (premier de l'initiative) :
 * un seul monstre restant, affaibli, amené au contact, puis attaque fatale.
 */
function gagnerQueteCourante(JoueurAuthentifiable $joueur, Groupe $groupe, Personnage $hero): void
{
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);

    $etat = $quete->etatsPersonnages()->where('personnage_id', $hero->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1]);

    GenererMenu::dispatchSync($groupe->id, (int) $joueur->id, (int) $hero->id);

    // 3 dés d'attaque (1 crâne) puis la défense du monstre (boucliers blancs,
    // ignorés par un monstre) : 1 dégât → vaincu → quête terminée.
    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    test()->postJson("/api/groupes/{$groupe->identifiant}/choix", ['option_id' => "attaquer_{$proie->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.quete.etat', 'terminee');
}

it('ouvre automatiquement la fenêtre de clôture à la victoire du boss final', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe(nbQuetes: 1);
    $groupe->update(['or' => 100, 'plan_campagne' => ['jalons' => [['position' => 1, 'type' => 'boss_final']]]]);
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')
        ->assertCreated()
        ->assertJsonPath('quete.type_jalon', 'boss_final');

    Event::fake([ClotureOuverte::class]);

    gagnerQueteCourante($alice, $groupe, $hero);

    // Broadcast `.cloture.ouverte` du contrat, EtatCloture en payload.
    Event::assertDispatched(ClotureOuverte::class, fn (ClotureOuverte $e) => $e->etatCloture['issue'] === 'victoire');

    // L'or à partager inclut le butin du boss, déjà versé au pot commun.
    $orPot = (int) $groupe->fresh()->or;
    expect($orPot)->toBeGreaterThan(100);

    $this->getJson('/api/groupes/table-1/cloture')
        ->assertOk()
        ->assertJsonPath('issue', 'victoire')
        ->assertJsonPath('or_a_partager', $orPot)
        ->assertJsonPath('parts.0.personnage_id', $hero->id)
        ->assertJsonPath('parts.0.montant', $orPot)
        ->assertJsonPath('confirmations.0.joueur_id', $alice->id)
        ->assertJsonPath('confirmations.0.confirme', false);
});

it('ouvre manuellement au hub (fin décidée) et refuse l\'abandon sans quête échouée', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 90]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    // Abandon sans quête échouée → 422 (TPK requis, doc 05 §6).
    $this->postJson('/api/groupes/table-1/cloture', ['abandon' => true])->assertStatus(422);

    // Fin décidée sans victoire du boss final : issue `abandon`, pot complet.
    $this->postJson('/api/groupes/table-1/cloture')
        ->assertCreated()
        ->assertJsonPath('issue', 'abandon')
        ->assertJsonPath('or_a_partager', 90)
        ->assertJsonPath('parts.0.montant', 45)
        ->assertJsonPath('parts.1.montant', 45);

    // Déjà ouverte → 422 ; GET rend le même état.
    $this->postJson('/api/groupes/table-1/cloture')->assertStatus(422);
    $this->getJson('/api/groupes/table-1/cloture')->assertOk()->assertJsonPath('issue', 'abandon');
});

it('ouvre par abandon après une quête échouée : or = or_initial plafonné à l\'or restant', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 200]);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    // En quête, or_initial figé à 200.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    expect((int) $quete->or_initial)->toBe(200);

    // TPK simulé : quête échouée, retour au hub, pot entamé (80 restants).
    $quete->update(['etat' => 'echouee']);
    $groupe->refresh()->update(['phase' => 'hub', 'quete_courante_id' => null, 'or' => 80]);

    // or_initial (200) plafonné à l'or restant (80) ; issue `echec`.
    $this->postJson('/api/groupes/table-1/cloture', ['abandon' => true])
        ->assertCreated()
        ->assertJsonPath('issue', 'echec')
        ->assertJsonPath('or_a_partager', 80);
});

it('refuse d\'ouvrir la clôture pendant une quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $this->postJson('/api/groupes/table-1/cloture')->assertStatus(422);
    $this->postJson('/api/groupes/table-1/cloture', ['abandon' => true])->assertStatus(422);
    $this->getJson('/api/groupes/table-1/cloture')->assertNotFound();
});

it('répartit l\'or en parts égales (reste aux premiers) et réassigne l\'équipement en annulant les confirmations', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 101]);
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroB = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $epee = equiperHeros($heroA->id, 'Épée courte');

    // 101 ÷ 2 = 50, reste 1 → unité au PREMIER de l'initiative (Albrecht).
    $reponse = $this->postJson('/api/groupes/table-1/cloture')->assertCreated();
    $reponse->assertJsonPath('parts.0.personnage_id', $heroA->id)
        ->assertJsonPath('parts.0.montant', 51)
        ->assertJsonPath('parts.1.personnage_id', $heroB->id)
        ->assertJsonPath('parts.1.montant', 50)
        ->assertJsonPath('equipements.0.inventaire_id', $epee->id)
        ->assertJsonPath('equipements.0.personnage_id', $heroA->id);

    // Alice confirme…
    $this->postJson('/api/groupes/table-1/cloture/confirmation')
        ->assertOk()
        ->assertJsonPath('finalise', false)
        ->assertJsonPath('cloture.confirmations.0.confirme', true);

    // …puis Bob réassigne l'épée vers son héros : confirmations ANNULÉES.
    $this->actingAs($bob, 'joueur');
    $this->putJson('/api/groupes/table-1/cloture/repartition', [
        'inventaire_id' => $epee->id,
        'personnage_id' => $heroB->id,
    ])->assertOk()
        ->assertJsonPath('equipements.0.personnage_id', $heroB->id)
        ->assertJsonPath('confirmations.0.confirme', false)
        ->assertJsonPath('confirmations.1.confirme', false);

    // Destinataire hors du groupe → 422 (héros actif du groupe uniquement).
    $carol = JoueurAuthentifiable::create(['pseudo' => 'carol', 'identifiant' => 'carol', 'mot_de_passe' => 'secret']);
    $autreGroupe = creerGroupe('table-2');
    $heroCarol = creerHeros($carol, $autreGroupe, 'Cassandre', 1);

    $this->putJson('/api/groupes/table-1/cloture/repartition', [
        'inventaire_id' => $epee->id,
        'personnage_id' => $heroCarol->id,
    ])->assertStatus(422);

    // Équipement d'un héros hors du groupe → 422.
    $lance = equiperHeros($heroCarol->id, 'Lance');
    $this->putJson('/api/groupes/table-1/cloture/repartition', [
        'inventaire_id' => $lance->id,
        'personnage_id' => $heroB->id,
    ])->assertStatus(422);

    // Rien n'est appliqué tant que tous n'ont pas confirmé.
    expect($epee->fresh()->personnage_id)->toBe($heroA->id)
        ->and((int) $heroA->fresh()->or)->toBe(0);
});

it('finalise quand tous confirment : or réparti, équipement réassigné, historique écrit, tout purgé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe(nbQuetes: 1);
    $groupe->update(['or' => 101, 'plan_campagne' => ['jalons' => [['position' => 1, 'type' => 'boss_final']]]]);
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroB = creerHeros($bob, $groupe, 'Brunhilde', 2);

    $epee = equiperHeros($heroA->id, 'Épée courte');
    Snapshot::create(['groupe_id' => $groupe->id, 'sequence_evenement' => 1, 'etat' => []]);

    // Victoire du boss final → fenêtre ouverte automatiquement.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    gagnerQueteCourante($alice, $groupe, $heroA);

    $orPot = (int) $groupe->fresh()->or; // 101 + butin du gabarit
    $partA = intdiv($orPot, 2) + ($orPot % 2);
    $partB = intdiv($orPot, 2);

    // L'épée d'Albrecht ira à Brunhilde.
    $this->putJson('/api/groupes/table-1/cloture/repartition', [
        'inventaire_id' => $epee->id,
        'personnage_id' => $heroB->id,
    ])->assertOk();

    Event::fake([ClotureTerminee::class]);

    $this->postJson('/api/groupes/table-1/cloture/confirmation')
        ->assertOk()
        ->assertJsonPath('finalise', false);

    // Bob complète → finalisation (job sync) : tout est appliqué puis purgé.
    $this->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/cloture/confirmation')
        ->assertOk()
        ->assertJsonPath('finalise', true);

    // Or commun réparti vers les bourses personnelles (reste au premier).
    $heroA->refresh();
    $heroB->refresh();
    expect((int) $heroA->or)->toBe($partA)
        ->and((int) $heroB->or)->toBe($partB);

    // Équipement réassigné, conservé après la purge (les personnages survivent).
    expect($epee->fresh()->personnage_id)->toBe($heroB->id);

    // Historique : une ligne par héros, résumé de repli factuel (sans LLM),
    // niveau atteint après la montée du boss final (2).
    $histoA = PersonnageHistorique::where('personnage_id', $heroA->id)->firstOrFail();
    expect($histoA->groupe_nom)->toBe('Les Lames du Crépuscule')
        ->and($histoA->theme)->toBe('Cryptes maudites sous la cité')
        ->and($histoA->issue)->toBe('victoire')
        ->and((int) $histoA->niveau_atteint)->toBe(2)
        ->and($histoA->termine_le)->not->toBeNull()
        ->and($histoA->resume)->toContain('Campagne Les Lames du Crépuscule')
        ->and($histoA->resume)->toContain('victoire')
        ->and($histoA->resume)->toContain((string) $orPot);
    expect(PersonnageHistorique::where('personnage_id', $heroB->id)->exists())->toBeTrue();

    // Personnages détachés, retour au roster.
    expect($heroA->groupe_actif_id)->toBeNull()
        ->and($heroB->groupe_actif_id)->toBeNull();

    // Purge COMPLÈTE : groupe, quêtes, cartes, instances, états, journal,
    // snapshots — plus rien (doc 12 §8).
    expect(Groupe::where('identifiant', 'table-1')->exists())->toBeFalse()
        ->and(Quete::count())->toBe(0)
        ->and(Carte::count())->toBe(0)
        ->and(InstanceMonstre::count())->toBe(0)
        ->and(EtatPersonnageQuete::count())->toBe(0)
        ->and(Evenement::count())->toBe(0)
        ->and(Snapshot::count())->toBe(0);

    // `.cloture.terminee` émis AVANT la suppression, un résumé par héros.
    Event::assertDispatched(ClotureTerminee::class, function (ClotureTerminee $e) use ($heroA, $heroB) {
        $ids = collect($e->resumes)->pluck('personnage_id');

        return $ids->contains($heroA->id) && $ids->contains($heroB->id)
            && str_contains($e->resumes[0]['resume'], 'Campagne');
    });

    // La fenêtre n'existe plus.
    $this->actingAs($alice, 'joueur');
    $this->getJson('/api/groupes/table-1/cloture')->assertNotFound();
});

it('annule la fenêtre : rien n\'est appliqué, la clôture peut rouvrir', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 60]);
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/cloture')->assertCreated();
    $this->postJson('/api/groupes/table-1/cloture/confirmation')
        ->assertOk()
        ->assertJsonPath('finalise', false);

    $this->deleteJson('/api/groupes/table-1/cloture')->assertNoContent();

    // Fenêtre fermée, rien appliqué : or intact, pas d'historique, groupe vivant.
    $this->getJson('/api/groupes/table-1/cloture')->assertNotFound();
    expect((int) $groupe->fresh()->or)->toBe(60)
        ->and((int) $hero->fresh()->or)->toBe(0)
        ->and(PersonnageHistorique::count())->toBe(0)
        ->and($hero->fresh()->groupe_actif_id)->toBe($groupe->id);

    // Annuler sans fenêtre → 422 ; et la clôture peut rouvrir.
    $this->deleteJson('/api/groupes/table-1/cloture')->assertStatus(422);
    $this->postJson('/api/groupes/table-1/cloture')->assertCreated();
});

it('purge silencieusement le groupe vidé par le départ du dernier joueur', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 100]);
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroB = creerHeros($bob, $groupe, 'Brunhilde', 2);

    // Des données vivantes à purger : une quête échouée (TPK simulé) + snapshot.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    Quete::findOrFail($groupe->fresh()->quete_courante_id)->update(['etat' => 'echouee']);
    $groupe->refresh()->update(['phase' => 'hub', 'quete_courante_id' => null]);
    Snapshot::create(['groupe_id' => $groupe->id, 'sequence_evenement' => 1, 'etat' => []]);

    // Bob part : le groupe vit encore (alice reste membre).
    $this->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/depart')->assertOk()->assertJsonPath('part', 50);
    expect(Groupe::where('identifiant', 'table-1')->exists())->toBeTrue();

    // Alice, dernier joueur, part : purge complète SILENCIEUSE (doc 05 §6) —
    // sans résumé ni historique, les personnages déjà revenus au roster.
    $this->actingAs($alice, 'joueur');
    $this->postJson('/api/groupes/table-1/depart')->assertOk()->assertJsonPath('part', 50);

    expect(Groupe::where('identifiant', 'table-1')->exists())->toBeFalse()
        ->and(Quete::count())->toBe(0)
        ->and(Carte::count())->toBe(0)
        ->and(InstanceMonstre::count())->toBe(0)
        ->and(EtatPersonnageQuete::count())->toBe(0)
        ->and(Evenement::count())->toBe(0)
        ->and(Snapshot::count())->toBe(0)
        ->and(PersonnageHistorique::count())->toBe(0);

    // Les personnages survivent, détachés, avec leur part d'or.
    $heroA->refresh();
    $heroB->refresh();
    expect((int) $heroA->or)->toBe(50)
        ->and((int) $heroB->or)->toBe(50)
        ->and($heroA->groupe_actif_id)->toBeNull()
        ->and($heroB->groupe_actif_id)->toBeNull();
});

it('remet les héros à plein (PV, sorts, conditions) à la clôture — victoire, échec ou abandon', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, [
        'pv_body' => 3, 'pv_body_max' => 8,
        'pv_mind' => 0, 'pv_mind_max' => 2,
    ]);

    // Quête éprouvante simulée : un sort épuisé, un buff porté (doc 05 §6 :
    // une campagne close referme l'ardoise, comme reinitialiserQuete entre
    // deux quêtes de la MÊME campagne).
    $sortId = Sort::query()->value('id');
    DB::table('personnage_sorts')->insert([
        'personnage_id' => $hero->id, 'sort_id' => $sortId, 'disponible' => false,
    ]);
    $hero->conditions()->attach(Condition::query()->value('id'), ['duree' => 2, 'source' => 'sort:Peau de Pierre']);

    // Fin décidée (abandon sans TPK) → issue `abandon`.
    $this->postJson('/api/groupes/table-1/cloture')->assertCreated()->assertJsonPath('issue', 'abandon');
    $this->postJson('/api/groupes/table-1/cloture/confirmation')
        ->assertOk()
        ->assertJsonPath('finalise', true);

    $hero->refresh();
    expect((int) $hero->pv_body)->toBe(8)
        ->and((int) $hero->pv_mind)->toBe(2)
        ->and($hero->groupe_actif_id)->toBeNull()
        ->and(DB::table('personnage_sorts')->where('personnage_id', $hero->id)->where('sort_id', $sortId)->value('disponible'))->toBeTruthy()
        ->and($hero->conditions()->count())->toBe(0);
});
