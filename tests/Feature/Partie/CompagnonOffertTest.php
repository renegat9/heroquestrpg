<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Partie\CompagnonOffert;
use App\Partie\FaveursHopekins;
use App\Partie\Sauvegarde;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * COMPAGNON ANIMAL OFFERT (*Against the Ogre Horde*, livret F9528 p. 8-9, « at no cost »).
 *
 * Décision de René (Q2, 2026-10-02 ; interprétation confirmée le 2026-10-15) : proposé UNE
 * fois, GRATUITEMENT, aux groupes de moins de 4 héros, et il accompagne le groupe toute la
 * campagne. Le moment est le lancement de la PREMIÈRE quête (à la création du groupe, le
 * fondateur est seul : la taille n'est pas connue) ; le premier joueur qui prend l'animal en
 * devient le maître. Tout passe par les vraies routes.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, MercenaireSeeder::class,
        SortDreadSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Ce que le hub publie au groupe (`GET /etat`, lisible par tout membre). */
function offreDuHub(): ?array
{
    return test()->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.compagnon_offert');
}

/** Un second joueur, sans changer l'utilisateur connecté. */
function secondJoueur(string $pseudo = 'bob'): JoueurAuthentifiable
{
    return JoueurAuthentifiable::create(['pseudo' => $pseudo, 'identifiant' => $pseudo, 'mot_de_passe' => 'secret']);
}

function mercenaireNomme(string $nom): Mercenaire
{
    return Mercenaire::where('nom', $nom)->firstOrFail();
}

it('PROPOSÉ à un groupe de moins de 4 héros : consigne et animaux lus dans le catalogue', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $offre = offreDuHub();

    expect($offre['nb_heros'])->toBe(1)
        ->and($offre['seuil'])->toBe(4)
        ->and($offre['consigne'])->toContain('1 héros')->toContain('gratuitement')->toContain('toute la campagne')
        // Les animaux du catalogue, ni plus ni moins — jamais une liste recopiée.
        ->and(collect($offre['animaux'])->pluck('mercenaire_id')->sort()->values()->all())
        ->toBe(Mercenaire::where('animal', true)->where('octroi_seul', false)->pluck('id')->sort()->values()->all())
        ->and(collect($offre['animaux'])->pluck('nom')->all())->toContain('Loup', 'Croc-sabre', 'Raptor apprivoisé')
        ->and($offre['animaux'][0])->toHaveKeys(['mercenaire_id', 'nom', 'deplacement', 'attaque', 'defense', 'pv_body', 'description', 'image_url']);
});

it('le NOMBRE DE HÉROS décide, au moment où il est connu : 3 héros oui, un quatrième qui arrive la retire', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    creerHeros($alice, $groupe, 'Brynn', 2);
    creerHeros($alice, $groupe, 'Cyra', 3);

    expect(offreDuHub())->not->toBeNull()->and(offreDuHub()['nb_heros'])->toBe(3);

    // « fewer than four hero players » : à quatre, il n'y a plus d'offre — et un animal refusé par POST.
    creerHeros($alice, $groupe, 'Dorn', 4);
    expect(offreDuHub())->toBeNull();

    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['groupe']);
    expect(GroupeMercenaire::count())->toBe(0);
});

it('PRENDRE : gratuit, l\'or du groupe ne bouge pas, le héros qui le prend en devient le maître, et l\'offre se referme pour tous', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 40]);
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    $brynn = creerHeros($alice, $groupe, 'Brynn', 2);
    $loup = mercenaireNomme('Loup');

    $this->postJson('/api/groupes/table-1/compagnon-offert', [
        'mercenaire_id' => $loup->id, 'personnage_id' => $brynn->id,
    ])->assertCreated()
        ->assertJsonPath('recrue.nom', 'Loup')
        ->assertJsonPath('recrue.offert', true)
        ->assertJsonPath('recrue.recruteur_personnage_id', $brynn->id)
        ->assertJsonPath('or', 40); // « at no cost ».

    $ligne = GroupeMercenaire::firstOrFail();
    expect((int) $groupe->fresh()->or)->toBe(40)
        ->and($groupe->fresh()->compagnon_offert)->toBe('pris')          // EN BASE
        ->and($ligne->mercenaire_id)->toBe($loup->id)
        ->and((int) $ligne->recruteur_personnage_id)->toBe($brynn->id)  // le maître : Brynn
        ->and($ligne->etat)->toBe('actif')
        ->and((int) $ligne->pv_body)->toBe((int) $loup->pv_body)
        ->and($ligne->offert)->toBeTrue();

    // L'offre est close et l'animal est publié comme tel (« offert »).
    $hub = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe');
    expect($hub['compagnon_offert'])->toBeNull()
        ->and($hub['mercenaires'][0])->toMatchArray(['nom' => 'Loup', 'animal' => true, 'offert' => true]);

    // Une seule fois : ni un second animal, ni un refus après coup.
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Croc-sabre')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['groupe']);
    $this->deleteJson('/api/groupes/table-1/compagnon-offert')->assertStatus(422);
    expect(GroupeMercenaire::count())->toBe(1);

    // Le journal le dit.
    $journal = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()->pluck('payload.action')->all();
    expect($journal)->toContain('compagnon_offert_pris');
});

it('PRENDRE sans personnage_id : c\'est le premier héros du joueur qui le mène', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    creerHeros($alice, $groupe, 'Brynn', 2);

    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Raptor apprivoisé')->id])->assertCreated();

    expect((int) GroupeMercenaire::firstOrFail()->recruteur_personnage_id)->toBe($albrecht->id);
});

it('la liste publiée EST la liste blanche : un mercenaire payant, un captif, un animal inconnu — refusés', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    foreach ([mercenaireNomme('Fauchard')->id, mercenaireNomme('Gothar')->id, mercenaireNomme('Squelette Hearthkin')->id, 999999] as $id) {
        $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => $id])
            ->assertStatus(422)->assertJsonValidationErrors(['mercenaire_id']);
    }

    expect(GroupeMercenaire::count())->toBe(0)->and($groupe->fresh()->compagnon_offert)->toBeNull();
});

it('QUI DÉCIDE : le premier joueur qui touche — le second trouve l\'offre close ; un étranger au groupe ne touche à rien', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = secondJoueur('bob');
    $brynn = creerHeros($bob, $groupe, 'Brynn', 2);
    $eve = secondJoueur('eve'); // sans héros dans ce groupe

    // Le héros d'un AUTRE joueur ne peut pas être désigné comme maître.
    $this->postJson('/api/groupes/table-1/compagnon-offert', [
        'mercenaire_id' => mercenaireNomme('Loup')->id, 'personnage_id' => $brynn->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);

    $this->actingAs($eve, 'joueur');
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['personnage_id']);
    expect(GroupeMercenaire::count())->toBe(0);

    $this->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Croc-sabre')->id])->assertCreated();

    $this->actingAs($alice, 'joueur');
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['groupe']);

    expect(GroupeMercenaire::count())->toBe(1)
        ->and((int) GroupeMercenaire::firstOrFail()->recruteur_personnage_id)->toBe($brynn->id);
});

it('REFUSER : définitif — aucun animal, l\'offre ne revient pas, et le journal le dit', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->deleteJson('/api/groupes/table-1/compagnon-offert')->assertOk()->assertJsonPath('compagnon_offert', 'refuse');

    expect($groupe->fresh()->compagnon_offert)->toBe('refuse')
        ->and(GroupeMercenaire::count())->toBe(0)
        ->and(offreDuHub())->toBeNull();

    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['groupe']);

    $journal = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()->pluck('payload.action')->all();
    expect($journal)->toContain('compagnon_offert_refuse');
});

it('UNE SEULE FOIS : une quête déjà lancée (même en cours de campagne) ferme l\'offre — les groupes existants n\'ont rien à recevoir', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe); // deux quêtes achevées : campagne entamée

    expect(offreDuHub())->toBeNull();
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['groupe']);
});

it('PAS EN QUÊTE : l\'offre est une affaire du hub', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['phase' => 'quete']);

    expect(app(CompagnonOffert::class)->ouvert($groupe->fresh()))->toBeFalse();
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])->assertStatus(422);
});

it('PRÊT : tant que l\'offre est ouverte, un joueur ne part pas sans l\'avoir vue — un seul geste (prendre OU refuser) lève le refus', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = secondJoueur('bob');
    $brynn = creerHeros($bob, $groupe, 'Brynn', 2);

    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $albrecht->id, 'pret' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['pret'])
        ->assertJsonPath('errors.pret.0', fn (string $m) => str_contains($m, 'compagnon animal'));

    // Se retirer des prêts n'est jamais bloqué.
    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $albrecht->id, 'pret' => false])->assertOk();

    // Bob refuse l'offre POUR TOUS : Alice (qui n'a rien fait) peut partir.
    $this->actingAs($bob, 'joueur');
    $this->deleteJson('/api/groupes/table-1/compagnon-offert')->assertOk();
    $this->actingAs($alice, 'joueur');
    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $albrecht->id, 'pret' => true])->assertOk();
});

it('TOUTE LA CAMPAGNE, SANS ENTRETIEN : à sec, l\'animal offert reste après la quête, là où un mercenaire payé part', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['or' => 0]);
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])->assertCreated();

    // Un suivant ENGAGÉ (entretien dû) à côté, pour comparer.
    $engage = GroupeMercenaire::create([
        'groupe_id' => $groupe->id, 'mercenaire_id' => mercenaireNomme('Fauchard')->id,
        'recruteur_personnage_id' => $albrecht->id, 'pv_body' => 2, 'etat' => 'actif',
    ]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    // L'animal est de la quête : posé sur la carte, mené par Albrecht.
    $loup = GroupeMercenaire::where('offert', true)->firstOrFail();
    expect($loup->position_x)->not->toBeNull()
        ->and((int) $loup->recruteur_personnage_id)->toBe($albrecht->id);

    // Victoire SANS butin : la bourse reste à 0 au moment de l'entretien.
    $quete = $groupe->fresh()->queteCourante;
    $quete->gabarit->update(['structure' => [...(array) $quete->gabarit->structure, 'butin' => ['or_base' => 0]]]);
    $fin = acheverLaQuete($groupe);

    // Bourse à 0 : le Fauchard (10 po) part, le Loup offert reste et n'est pas facturé.
    expect($fin['mercenaires_entretien']['partis'][0]['nom'] ?? null)->toBe('Fauchard')
        ->and($fin['mercenaires_entretien']['payes'])->toBe([])
        ->and(GroupeMercenaire::find($engage->id))->toBeNull()
        ->and(GroupeMercenaire::find($loup->id))->not->toBeNull()
        ->and(GroupeMercenaire::find($loup->id)->etat)->toBe('actif');

    // Une fois : l'offre ne revient pas pour la quête suivante, même à un seul héros.
    expect(offreDuHub())->toBeNull();

    // Et il repart avec le groupe à la quête suivante.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    expect(GroupeMercenaire::find($loup->id)->position_x)->not->toBeNull();
});

it('ENTRETIEN : sans le moindre suivant ordinaire, l\'animal offert ne déclenche aucune annonce d\'entretien', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])->assertCreated();

    expect(app(FaveursHopekins::class)->reglerEntretien($groupe->fresh(), 1))->toBeNull();
});

it('UN SEUL ANIMAL PAR GROUPE : l\'offert compte — impossible d\'en recruter un second contre de l\'or', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])->assertCreated();

    rendreGardien($groupe);
    $groupe->update(['or' => 5000]);

    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => mercenaireNomme('Croc-sabre')->id])
        ->assertStatus(422)->assertJsonValidationErrors(['mercenaire_id']);
    // Un mercenaire HUMAIN reste recrutable : seule la règle de l'animal unique s'applique.
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => mercenaireNomme('Fauchard')->id])->assertCreated();
});

it('LA REPRISE rend l\'animal offert avec son drapeau : un TPK rechargé ne lui rend pas l\'entretien', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/table-1/compagnon-offert', ['mercenaire_id' => mercenaireNomme('Loup')->id])->assertCreated();
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $groupe = $groupe->fresh();
    $snapshot = app(Sauvegarde::class)->snapshotter($groupe, 'nouveau_tour');
    GroupeMercenaire::query()->delete(); // purgé à l'échec
    app(Sauvegarde::class)->restaurer($groupe->fresh(), $snapshot);

    expect(GroupeMercenaire::count())->toBe(1)
        ->and(GroupeMercenaire::firstOrFail()->offert)->toBeTrue();
});

it('un groupe sans aucun animal au catalogue n\'a rien à proposer (pas d\'offre vide)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    Mercenaire::where('animal', true)->update(['octroi_seul' => true]);

    expect(offreDuHub())->toBeNull();
});
