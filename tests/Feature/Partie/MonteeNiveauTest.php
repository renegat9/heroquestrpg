<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Events\EtatGroupeDiffuse;
use App\Events\NiveauMonte;
use App\Jobs\GenererMenu;
use App\Models\Competence;
use App\Models\Quete;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * Montée de niveau par jalons (doc 01 §5, contrat docs/contrat-api.md) :
 * victoire d'une quête sous_boss/boss_final → +1 niveau par héros actif
 * (+1 PV max au niveau PAIR), broadcast `.niveau.monte` ; les points de
 * compétence sont DÉRIVÉS ((niveau − 1) − nœuds acquis) et se dépensent via
 * POST /groupes/{identifiant}/competences (classe, prérequis, points).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, CompetenceSeeder::class]);
});

/** Id d'un nœud d'arbre du CompetenceSeeder. */
function idNoeud(string $classe, string $nom): int
{
    return (int) Competence::where('classe', $classe)->where('nom', $nom)->value('id');
}

it('monte chaque héros de +1 niveau à la victoire d\'une quête sous_boss (+1 PV max au niveau pair) et le diffuse', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1); // barbare niveau 1

    // Jalon sous_boss imposé par le squelette de campagne (doc 06 §4).
    $groupe->update(['plan_campagne' => ['jalons' => [['position' => 1, 'type' => 'sous_boss']]]]);

    $this->postJson('/api/groupes/table-1/quetes')
        ->assertCreated()
        ->assertJsonPath('quete.type_jalon', 'sous_boss');

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Scénario : il ne reste qu'un monstre, affaibli, au contact du héros.
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);

    $etat = $quete->etatsPersonnages()->where('personnage_id', $hero->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1, 'revele' => true]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Event::fake([NiveauMonte::class]);

    // 3 dés d'attaque (1 crâne) puis la défense du monstre (boucliers blancs,
    // ignorés par un monstre) : 1 dégât → vaincu → quête terminée.
    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    $reponse = $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => "attaquer_{$proie->id}",
    ])->assertStatus(202);

    // La montée est résolue par le moteur à la clôture victorieuse du jalon.
    $reponse->assertJsonPath('resultat.quete.etat', 'terminee')
        ->assertJsonPath('resultat.quete.niveaux.personnages.0.niveau', 2)
        ->assertJsonPath('resultat.quete.niveaux.personnages.0.points_competence', 1);

    // Niveau 2 = PAIR : +1 PV de Body max pour un barbare, le courant suit.
    $hero->refresh();
    expect((int) $hero->niveau)->toBe(2)
        ->and((int) $hero->pv_body_max)->toBe(9)
        ->and((int) $hero->pv_body)->toBe(9)
        ->and($hero->pointsCompetence())->toBe(1);

    // Broadcast `.niveau.monte` du contrat, avec les gains lisibles.
    Event::assertDispatched(NiveauMonte::class, function (NiveauMonte $evenement) use ($hero) {
        $ligne = $evenement->resultat['personnages'][0];

        return $ligne['id'] === $hero->id
            && $ligne['niveau'] === 2
            && $ligne['points_competence'] === 1
            && in_array('+1 PV de Body maximum', $ligne['gains'], true);
    });

    // Journal systeme + profil /api/moi enrichi (niveau, points, nœuds acquis).
    expect($groupe->evenements()->where('type', 'systeme')->get()
        ->contains(fn ($e) => ($e->payload['action'] ?? null) === 'niveau_monte'))->toBeTrue();

    $this->getJson('/api/moi')
        ->assertOk()
        ->assertJsonPath('joueur.personnages.0.niveau', 2)
        ->assertJsonPath('joueur.personnages.0.points_competence', 1)
        ->assertJsonPath('joueur.personnages.0.competences', []);
});

it('ne monte pas de niveau à la victoire d\'une quête normale', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')
        ->assertCreated()
        ->assertJsonPath('quete.type_jalon', 'normale');

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);

    $etat = $quete->etatsPersonnages()->where('personnage_id', $hero->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1, 'revele' => true]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Event::fake([NiveauMonte::class]);
    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$proie->id}"])
        ->assertStatus(202)
        ->assertJsonPath('resultat.quete.etat', 'terminee')
        ->assertJsonPath('resultat.quete.niveaux', null);

    expect((int) $hero->fresh()->niveau)->toBe(1);
    Event::assertNotDispatched(NiveauMonte::class);
});

it('acquiert un nœud d\'arbre et applique ses effets passifs chiffrés au personnage', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1, ['niveau' => 3]); // 2 points dérivés

    // Catalogue complet des arbres (contrat GET /api/competences).
    $catalogue = $this->getJson('/api/competences')->assertOk()->json('competences');
    expect(collect($catalogue)->pluck('classe')->unique()->sort()->values()->all())
        ->toBe(['barbare', 'elfe', 'magicien', 'nain']);

    // Chaque nœud porte une DESCRIPTION lisible (affichée à la sélection et sur
    // la fiche — doc 01 §6). Aucun talent muet.
    expect(collect($catalogue)->every(fn ($n) => is_string($n['description'] ?? null) && $n['description'] !== ''))
        ->toBeTrue();
    expect(collect($catalogue)->firstWhere('nom', 'Carrure')['description'])
        ->toBe('+1 Point de Body (PV Body max).');

    $carrure = idNoeud('barbare', 'Carrure'); // passif : bonus_pv_body_max +1

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id,
        'competence_id' => $carrure,
    ])->assertCreated()
        ->assertJsonPath('personnage.niveau', 3)
        ->assertJsonPath('personnage.points_competence', 1) // (3−1) − 1 acquis
        ->assertJsonPath('personnage.competences.0', $carrure)
        ->assertJsonPath('competence.nom', 'Carrure');

    // Effet passif chiffré appliqué : +1 PV de Body max, le courant suit.
    $hero->refresh();
    expect((int) $hero->pv_body_max)->toBe(9)
        ->and((int) $hero->pv_body)->toBe(9)
        ->and($hero->competences()->pluck('nom')->all())->toBe(['Carrure']);

    // Déjà acquis → 422 ; nœud d'une autre classe → 422.
    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id, 'competence_id' => $carrure,
    ])->assertStatus(422);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id, 'competence_id' => idNoeud('nain', 'Œil du mineur'),
    ])->assertStatus(422);

    // /api/moi reflète l'acquisition (ids des nœuds acquis).
    $this->getJson('/api/moi')
        ->assertOk()
        ->assertJsonPath('joueur.personnages.0.points_competence', 1)
        ->assertJsonPath('joueur.personnages.0.competences.0', $carrure);
});

it('exige le prérequis de l\'arbre puis refuse sans point disponible', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Aëlis', 1, ['classe' => 'elfe', 'niveau' => 2]); // 1 point

    // Second élément exige Première magie (prerequis_id du seeder).
    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id,
        'competence_id' => idNoeud('elfe', 'Second élément'),
    ])->assertStatus(422);

    // Première magie (deblocage) : enregistrée seulement, aucune colonne touchée.
    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id,
        'competence_id' => idNoeud('elfe', 'Première magie'),
    ])->assertCreated()
        ->assertJsonPath('personnage.points_competence', 0);

    expect((int) $hero->fresh()->deplacement_base)->toBe(4); // inchangé

    // Plus aucun point : le prérequis est rempli mais l'acquisition est refusée.
    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id,
        'competence_id' => idNoeud('elfe', 'Second élément'),
    ])->assertStatus(422);

    // Et un héros qui ne vous appartient pas est refusé.
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $heroBob = creerHeros($bob, $groupe, 'Brunhilde', 2, ['niveau' => 2]);

    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $heroBob->id,
        'competence_id' => idNoeud('barbare', 'Carrure'),
    ])->assertStatus(422);
});

it('rediffuse `.groupe.etat` quand un nœud est acquis pendant une quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Dorin', 1, ['classe' => 'nain', 'niveau' => 2]);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    Event::fake([EtatGroupeDiffuse::class]);

    // Œil du mineur : passif non chiffré (lu à la volée par MoteurPieges) —
    // seulement enregistré au pivot, aucune colonne modifiée.
    $this->postJson('/api/groupes/table-1/competences', [
        'personnage_id' => $hero->id,
        'competence_id' => idNoeud('nain', 'Œil du mineur'),
    ])->assertCreated();

    expect($hero->fresh()->competences()->pluck('nom')->all())->toBe(['Œil du mineur']);

    Event::assertDispatched(EtatGroupeDiffuse::class, function (EtatGroupeDiffuse $evenement) use ($hero) {
        $herosEtat = collect($evenement->etat['entites'])->firstWhere('id', $hero->id);

        return $herosEtat !== null && $herosEtat['niveau'] === 2;
    });
});
