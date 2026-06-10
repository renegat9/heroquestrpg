<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Engine\Des\LanceurDes;
use App\Engine\Des\LanceurDeterministe;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Résolution des tours via le moteur (POST /api/groupes/{identifiant}/choix) :
 * l'option est validée contre le DERNIER MENU PROPOSÉ, le moteur résout
 * (déplacement, attaque, jet), les monstres scriptés (C2) jouent quand tous
 * les héros ont joué, la fin de quête est détectée.
 *
 * Le hasard est figé par LanceurDeterministe (bind conteneur) — valeurs de
 * d6 : 1-3 = crâne, 4-5 = bouclier blanc, 6 = bouclier noir.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/**
 * Fige la file de dés servie au moteur.
 *
 * @param  list<int>  $valeurs
 */
function figerDes(array $valeurs): LanceurDeterministe
{
    $lanceur = new LanceurDeterministe($valeurs);
    app()->instance(LanceurDes::class, $lanceur);

    return $lanceur;
}

/**
 * Première case traversable libre adjacente à (x, y) sur la carte de la quête.
 *
 * @return array{x: int, y: int}
 */
function caseLibreAdjacente(Quete $quete, int $x, int $y): array
{
    $cases = $quete->carte->grille['cases'];

    $occupees = [];
    foreach ($quete->etatsPersonnages()->get() as $e) {
        $occupees["{$e->position_x},{$e->position_y}"] = true;
    }
    foreach ($quete->instancesMonstres()->where('etat', 'actif')->get() as $i) {
        $occupees["{$i->position_x},{$i->position_y}"] = true;
    }

    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        $cx = $x + $dx;
        $cy = $y + $dy;

        if (in_array($cases[$cy][$cx] ?? 'm', ['s', 'p'], true) && ! isset($occupees["{$cx},{$cy}"])) {
            return ['x' => $cx, 'y' => $cy];
        }
    }

    throw new RuntimeException('Aucune case libre adjacente — scénario de test invalide.');
}

it('résout un déplacement : base + 1d6, chemin sur la grille, a_joue marqué', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $cible = caseLibreAdjacente($quete, (int) $etat->position_x, (int) $etat->position_y);

    figerDes([3]); // 1d6 de déplacement → total = 4 (base) + 3 = 7

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => $cible,
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.type', 'deplacement')
        ->assertJsonPath('resultat.de', 3)
        ->assertJsonPath('resultat.deplacement_total', 7)
        ->assertJsonPath('resultat.distance', 1);

    $etat->refresh();
    expect($etat->position_x)->toBe($cible['x'])
        ->and($etat->position_y)->toBe($cible['y'])
        ->and($etat->a_joue)->toBeTrue();

    // L'événement est journalisé et l'état partagé reflète le tour.
    expect($groupe->evenements()->where('type', 'action')->exists())->toBeTrue();

    $etatPartage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect($etatPartage['initiative'][0])->toMatchArray(['id' => $heroA->id, 'a_joue' => true]);
});

it('refuse un déplacement hors de portée du jet de déplacement', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1, ['deplacement_base' => 1]);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Destination : la position d'un monstre au fond du donjon → bien au-delà
    // de 1 + 1d6 cases (et la case est occupée de toute façon).
    $monstre = $quete->instancesMonstres()->orderByDesc('id')->firstOrFail();

    figerDes([1]); // total = 1 + 1 = 2

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer',
        'parametres' => ['x' => (int) $monstre->position_x, 'y' => (int) $monstre->position_y],
    ])->assertStatus(422);
});

it('impose l\'ordre d\'initiative figé (C1) et la validation du menu proposé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    // Une option qui ne figure pas dans le dernier menu proposé → 422.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'sort_interdit'])
        ->assertStatus(422);

    // Bob (ordre 2) tente de jouer avant Albrecht (ordre 1) → 422.
    $this->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(422);
});

it('résout un jet de compétence (fouiller, jet de Mind) via le moteur', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1); // attribut_mind = 2 → 2 dés

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    figerDes([1, 4]); // crâne + bouclier blanc → 1 succès, difficulté 1 = réussite

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'jet')
        ->assertJsonPath('resultat.attribut', 'mind')
        ->assertJsonPath('resultat.des_lances', 2)
        ->assertJsonPath('resultat.succes', 1)
        ->assertJsonPath('resultat.issue', 'reussite');

    expect($groupe->evenements()->where('type', 'jet')->exists())->toBeTrue();
});

it('résout une attaque adjacente qui tue, et termine la quête (butin au pot commun)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1); // 3 dés d'attaque

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Scénario : il ne reste qu'un monstre, affaibli, au contact du héros.
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $contact = caseLibreAdjacente($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1]);

    // Le menu moteur est re-proposé après le repositionnement : il contient
    // maintenant l'option d'attaque du monstre adjacent.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);

    // Dés : 3 d'attaque (1 crâne, 2 boucliers blancs) puis la défense du
    // monstre (boucliers blancs — un monstre ne compte que les NOIRS) :
    // 1 touche − 0 bouclier = 1 dégât → 1 PV → vaincu.
    figerDes([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "attaquer_{$proie->id}",
    ])->assertStatus(202);

    $reponse->assertJsonPath('resultat.type', 'attaque')
        ->assertJsonPath('resultat.degats', 1)
        ->assertJsonPath('resultat.cible_vaincue', true)
        ->assertJsonPath('resultat.quete.etat', 'terminee')
        ->assertJsonPath('resultat.quete.or_butin', 50); // butin du gabarit « Exploration simple »

    // Fin de quête : tous les monstres vaincus → retour au hub, or au pot.
    expect($proie->fresh()->etat)->toBe('vaincu')
        ->and($quete->fresh()->etat)->toBe('terminee')
        ->and($groupe->fresh()->phase)->toBe('hub')
        ->and($groupe->fresh()->or)->toBe(50)
        ->and($groupe->evenements()->where('type', 'combat')->exists())->toBeTrue();

    $etatPartage = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect($etatPartage['groupe']['phase'])->toBe('hub')
        ->and($etatPartage['groupe']['or'])->toBe(50)
        ->and($etatPartage['quete'])->toBeNull()
        ->and($etatPartage['entites'])->toBe([]);
});

it('fait jouer les monstres scriptés (C2) quand tous les héros ont joué, puis ouvre un nouveau tour', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $positionsAvant = $quete->instancesMonstres()->where('etat', 'actif')->get()
        ->mapWithKeys(fn ($i) => [$i->id => [$i->position_x, $i->position_y]]);

    // Le héros attend → tous les héros ont joué → phase des monstres.
    // Réserve de dés « boucliers blancs » : les attaques éventuelles des
    // monstres ne sortent aucun crâne (0 dégât), le test reste déterministe.
    figerDes(array_fill(0, 60, 4));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'attente');

    // Les monstres se sont rapprochés du héros (script C2, moteur seul).
    $instances = $quete->instancesMonstres()->where('etat', 'actif')->get();
    $bouge = $instances->contains(
        fn ($i) => [$i->position_x, $i->position_y] !== $positionsAvant[$i->id]
    );
    expect($bouge)->toBeTrue();

    // Aucun dégât subi (boucliers) et nouveau tour : a_joue réinitialisé.
    expect($heroA->fresh()->pv_body)->toBe(8);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    expect($etat->a_joue)->toBeFalse()
        ->and($groupe->evenements()->where('type', 'systeme')->get()
            ->contains(fn ($e) => ($e->payload['action'] ?? null) === 'nouveau_tour'))->toBeTrue();
});
