<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Snapshot;
use App\Partie\MoteurSorts;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Snapshots automatiques & reprise (contrat « Snapshots & reprise »,
 * doc 12 §4, doc 05 §6 TPK) : le moteur snapshotte l'état vivant complet
 * au démarrage de chaque quête (`debut_quete`) et après chaque phase des
 * monstres (`nouveau_tour`, seul le dernier conservé) ; POST reprise
 * restaure tout en transaction après un TPK — le journal, source de
 * vérité (doc 07), n'est JAMAIS tronqué.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, SortSeeder::class, ObjetSeeder::class,
    ]);
});

/**
 * Quête démarrée pour un magicien d'alice (sorts feu+eau attachés comme à
 * la création, un objet en inventaire) — l'état de départ est riche pour
 * vérifier la sérialisation complète du snapshot.
 *
 * @return array{0: JoueurAuthentifiable, 1: Groupe, 2: Personnage, 3: Quete}
 */
function demarrerQueteSauvegarde(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $mage = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);

    $moteur = app(MoteurSorts::class);
    $moteur->attacherElement($mage, 'feu');
    $moteur->attacherElement($mage, 'eau');

    Inventaire::create([
        'personnage_id' => $mage->id,
        'objet_id' => (int) Objet::query()->orderBy('id')->value('id'),
        'emplacement' => 'sac',
        'quantite' => 2,
    ]);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    return [$alice, $groupe, $mage, $quete];
}

/**
 * Fait jouer « attendre » au héros : tous les héros ont joué → phase des
 * monstres. Dés à 4 (boucliers blancs) = aucun crâne, zéro dégât subi.
 */
function attendreEtPhaseMonstres(array $des = []): void
{
    desFiges($des !== [] ? $des : array_fill(0, 60, 4));

    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
}

/**
 * TPK déterministe : le mage à 1 PV Body, un monstre actif au contact, dés
 * à 1 (crânes partout : l'attaque touche, la défense ne pare rien) → le
 * héros tombe pendant la phase des monstres → quête `echouee`, retour hub.
 */
function provoquerTpk(Groupe $groupe, Personnage $mage, Quete $quete): void
{
    $mage->update(['pv_body' => 1]);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $quete->instancesMonstres()->where('etat', 'actif')->orderBy('id')->firstOrFail()
        ->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    attendreEtPhaseMonstres(array_fill(0, 60, 1));

    expect($quete->fresh()->etat)->toBe('echouee')
        ->and($groupe->fresh()->phase)->toBe('hub');
}

it('snapshotte `debut_quete` au démarrage : état vivant complet (PV, positions, sorts, inventaire)', function () {
    [, $groupe, $mage, $quete] = demarrerQueteSauvegarde();

    $snapshots = Snapshot::where('groupe_id', $groupe->id)->get();
    expect($snapshots)->toHaveCount(1);

    $etat = $snapshots->first()->etat;
    expect($etat['etiquette'])->toBe('debut_quete')
        // Le snapshot marque le dernier événement inclus : celui du démarrage
        // (la narration arrive APRÈS, par job — chargement = snapshot + suite).
        ->and((int) $snapshots->first()->sequence_evenement)
        ->toBe((int) $groupe->evenements()->where('type', 'systeme')->get()
            ->first(fn ($e) => ($e->payload['action'] ?? null) === 'quete_demarree')->sequence)
        ->and($etat['groupe'])->toMatchArray(['phase' => 'quete', 'quete_courante_id' => $quete->id])
        ->and($etat['quete'])->toMatchArray(['id' => $quete->id, 'etat' => 'en_cours', 'position_arc' => 1])
        ->and($etat['carte']['grille']['cases'])->toBe($quete->carte->grille['cases'])
        ->and($etat['carte']['grille']['pieges'])->toBe($quete->carte->grille['pieges'])
        ->and($etat['instances_monstres'])->toHaveCount($quete->instancesMonstres()->count());

    // Positions de spawn et drapeaux de tour des héros.
    $epq = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail();
    expect($etat['etat_personnage_quete'][0])->toMatchArray([
        'personnage_id' => $mage->id,
        'position_x' => $epq->position_x,
        'position_y' => $epq->position_y,
        'a_joue' => false,
        'tombe' => false,
    ]);

    // Héros actif : PV (et max), 6 sorts tous disponibles, inventaire complet.
    $heros = collect($etat['heros'])->firstWhere('id', $mage->id);
    expect($heros)->toMatchArray(['pv_body' => 8, 'pv_body_max' => 8, 'pv_mind' => 2, 'pv_mind_max' => 2])
        ->and($heros['sorts'])->toHaveCount(6)
        ->and(collect($heros['sorts'])->every(fn ($s) => $s['disponible'] === true))->toBeTrue()
        ->and($heros['inventaire'])->toHaveCount(1)
        ->and($heros['inventaire'][0])->toMatchArray(['emplacement' => 'sac', 'quantite' => 2]);
});

it('snapshotte `nouveau_tour` après la phase des monstres et ne garde que le dernier (rétention)', function () {
    [, $groupe, , $quete] = demarrerQueteSauvegarde();

    attendreEtPhaseMonstres();

    $snapshots = Snapshot::where('groupe_id', $groupe->id)->orderBy('id')->get();
    expect($snapshots)->toHaveCount(2)
        ->and(data_get($snapshots[0]->etat, 'etiquette'))->toBe('debut_quete')
        ->and(data_get($snapshots[1]->etat, 'etiquette'))->toBe('nouveau_tour')
        ->and((int) data_get($snapshots[1]->etat, 'quete.id'))->toBe($quete->id);

    $premierTour = $snapshots[1];

    // Tour suivant : le `nouveau_tour` précédent est REMPLACÉ (un seul conservé).
    attendreEtPhaseMonstres();

    $snapshots = Snapshot::where('groupe_id', $groupe->id)->orderBy('id')->get();
    $nouveauxTours = $snapshots->filter(fn (Snapshot $s) => data_get($s->etat, 'etiquette') === 'nouveau_tour');

    expect($snapshots)->toHaveCount(2)
        ->and($nouveauxTours)->toHaveCount(1)
        ->and($nouveauxTours->first()->id)->toBeGreaterThan($premierTour->id)
        ->and((int) $nouveauxTours->first()->sequence_evenement)
        ->toBeGreaterThan((int) $premierTour->sequence_evenement)
        ->and($snapshots->contains(fn (Snapshot $s) => data_get($s->etat, 'etiquette') === 'debut_quete'))->toBeTrue();
});

it('reprend après un TPK : POST reprise restaure l\'état à l\'identique et la quête repasse en cours', function () {
    [, $groupe, $mage, $quete] = demarrerQueteSauvegarde();

    // Référence : l'état exact du départ de quête (celui du snapshot).
    $spawnHeros = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $mage->id)->firstOrFail()->only(['position_x', 'position_y']);
    $monstresDepart = $quete->instancesMonstres()->orderBy('id')->get()
        ->map(fn ($i) => $i->only(['id', 'pv_body', 'position_x', 'position_y', 'etat']))->all();

    // Pendant la quête : héros amoché, sorts épuisés, inventaire consommé,
    // un monstre tué — puis TPK (quête échouée, retour hub).
    DB::table('personnage_sorts')->where('personnage_id', $mage->id)->update(['disponible' => false]);
    Inventaire::where('personnage_id', $mage->id)->delete();
    $quete->instancesMonstres()->orderByDesc('id')->firstOrFail()->update(['etat' => 'vaincu', 'pv_body' => 0]);

    provoquerTpk($groupe, $mage, $quete);

    expect($mage->fresh()->pv_body)->toBe(0)
        ->and(EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail()->tombe)->toBeTrue();

    $evenementsAvant = (int) $groupe->evenements()->count();
    $snapshot = Snapshot::where('groupe_id', $groupe->id)->get()
        ->firstWhere(fn (Snapshot $s) => data_get($s->etat, 'etiquette') === 'debut_quete');

    // Reprise par défaut : le snapshot `debut_quete` de la quête échouée.
    $this->postJson('/api/groupes/table-1/reprise')
        ->assertOk()
        ->assertJsonPath('snapshot_id', $snapshot->id)
        ->assertJsonPath('etiquette', 'debut_quete')
        ->assertJsonPath('quete_id', $quete->id);

    // Groupe et quête : la partie reprend exactement où elle pouvait.
    $groupe->refresh();
    expect($groupe->phase)->toBe('quete')
        ->and($groupe->quete_courante_id)->toBe($quete->id)
        ->and($quete->fresh()->etat)->toBe('en_cours');

    // Héros : PV, position, drapeaux, sorts et inventaire restaurés.
    $mage->refresh();
    expect($mage->pv_body)->toBe(8)
        ->and($mage->sorts()->wherePivot('disponible', true)->count())->toBe(6)
        ->and($mage->inventaire()->count())->toBe(1);

    $epq = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $mage->id)->firstOrFail();
    expect($epq->tombe)->toBeFalse()
        ->and($epq->a_joue)->toBeFalse()
        ->and($epq->only(['position_x', 'position_y']))->toBe($spawnHeros);

    // Monstres : le tué revit, positions et PV de départ.
    $monstresApres = $quete->instancesMonstres()->orderBy('id')->get()
        ->map(fn ($i) => $i->only(['id', 'pv_body', 'position_x', 'position_y', 'etat']))->all();
    expect($monstresApres)->toBe($monstresDepart);

    // Journal : événement `reprise` ajouté, rien de tronqué (doc 07).
    $reprise = $groupe->evenements()->where('type', 'systeme')->get()
        ->first(fn ($e) => ($e->payload['action'] ?? null) === 'reprise');
    expect($reprise)->not->toBeNull()
        ->and($reprise->payload['snapshot_id'])->toBe($snapshot->id)
        ->and((int) $groupe->evenements()->count())->toBeGreaterThan($evenementsAvant)
        ->and($groupe->evenements()->where('type', 'systeme')->get()
            ->contains(fn ($e) => ($e->payload['action'] ?? null) === 'quete_echouee'))->toBeTrue();

    // L'état partagé reflète la reprise (et le joueur a reçu un menu rejouable).
    $etatPartage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect($etatPartage['groupe']['phase'])->toBe('quete')
        ->and($etatPartage['quete']['etat'])->toBe('en_cours')
        ->and(collect($etatPartage['entites'])->where('type', 'monstre')->where('etat', 'actif')->count())
        ->toBe(count($monstresDepart));
});

it('refuse la reprise (422) quand une quête est en cours et non échouée, ou sans snapshot', function () {
    demarrerQueteSauvegarde();

    // Quête en cours non échouée → on ne recharge pas en pleine partie.
    $this->postJson('/api/groupes/table-1/reprise')->assertStatus(422);

    // Au hub sans quête échouée (autre groupe vierge) → aucun snapshot.
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $groupe2 = creerGroupe('table-2');
    creerHeros($bob, $groupe2, 'Brunhilde', 1);
    $this->actingAs($bob, 'joueur');

    $this->postJson('/api/groupes/table-2/reprise')->assertStatus(422);
});

it('purge les snapshots de la quête à la fin de la quête (victoire)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    expect(Snapshot::where('groupe_id', $groupe->id)->count())->toBe(1);

    // Il ne reste qu'un monstre, affaibli, au contact du héros (cf. ResolutionTourTest).
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$proie->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.quete.etat', 'terminee');

    // Quête gagnée → les snapshots de la quête sont purgés.
    expect(Snapshot::where('groupe_id', $groupe->id)->count())->toBe(0);
});

it('liste les snapshots du groupe (GET) au format du contrat', function () {
    demarrerQueteSauvegarde();

    attendreEtPhaseMonstres();

    $reponse = $this->getJson('/api/groupes/table-1/snapshots')->assertOk()->json();

    expect($reponse)->toHaveCount(2)
        ->and(collect($reponse)->pluck('etiquette')->all())->toBe(['debut_quete', 'nouveau_tour']);

    foreach ($reponse as $entree) {
        expect($entree)->toHaveKeys(['id', 'etiquette', 'sequence_evenement', 'created_at'])
            ->and($entree['sequence_evenement'])->toBeGreaterThan(0)
            ->and($entree['created_at'])->not->toBeNull();
    }

    // Réservé aux membres du groupe (même règle que le reste de l'API).
    $intrus = JoueurAuthentifiable::create(['pseudo' => 'eve', 'identifiant' => 'eve', 'mot_de_passe' => 'secret']);
    $this->actingAs($intrus, 'joueur');
    $this->getJson('/api/groupes/table-1/snapshots')->assertStatus(422);
});
