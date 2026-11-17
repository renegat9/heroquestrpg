<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\Classique\CampagnesConstruites;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LE CHOIX DE CAMPAGNE AU HUB (fin du lot S1, `docs/plan-mode-classique.md` §6 et §7). Un groupe classique n'est jamais
 * dissous : finir une campagne ramène au choix, et une campagne dont le groupe n'a pas une classe exigée est GRISÉE
 * avec sa raison — la liste et la route lisent la même règle (`ChoixCampagne`).
 *
 * Campagnes INVENTÉES : celle de `DocumentExemple` (2 quêtes, exige un barbare), une copie qui exige un nain, une
 * copie sans prérequis. Groupe : Brunehaut (naine), seule.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);

    $enregistrer = app(CampagnesConstruites::class);
    $enregistrer->enregistrer(DocumentExemple::campagne());
    foreach ([['campagne-des-nains', 'Campagne des nains', ['nain']], ['campagne-ouverte', 'Campagne ouverte', []]] as [$slug, $titre, $prerequis]) {
        $campagne = DocumentExemple::campagne();
        $campagne['slug'] = $slug;
        $campagne['titre'] = $titre;
        $campagne['prerequis_heros'] = $prerequis;
        $enregistrer->enregistrer($campagne);
    }
});

function naineAuHub(object $test, string $campagne = 'campagne-des-nains'): array
{
    $alice = connecterJoueur('alice');
    $naine = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Brunehaut', 'classe' => 'nain', 'niveau' => 1, 'or' => 30,
        'attribut_body' => 3, 'attribut_mind' => 3, 'pv_body_max' => 7, 'pv_body' => 7, 'pv_mind_max' => 3, 'pv_mind' => 3,
        'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => $campagne, 'personnage_id' => $naine->id])
        ->assertCreated()->json('groupe.identifiant');

    return [Groupe::where('identifiant', $code)->firstOrFail(), $naine, $alice];
}

it('grise la campagne qui exige une classe absente, avec sa raison, et la route la refuse', function () {
    [$groupe] = naineAuHub($this);

    $reponse = $this->getJson("/api/groupes/{$groupe->identifiant}/campagnes")->assertOk();
    expect($reponse->json('choix_ouvert'))->toBeTrue(); // rang 1 : pas commencée
    $campagnes = collect($reponse->json('campagnes'))->keyBy('slug');

    expect($campagnes['campagne-d-essai']['choisissable'])->toBeFalse()
        ->and($campagnes['campagne-d-essai']['motif'])->toBe('Exige un héros de classe : Barbare.')
        ->and($campagnes['campagne-des-nains']['choisissable'])->toBeTrue()
        ->and($campagnes['campagne-des-nains']['actuelle'])->toBeTrue()
        ->and($campagnes['campagne-ouverte']['motif'])->toBeNull();

    // La route lit la même règle : la campagne grisée est refusée, avec la même raison.
    $this->putJson("/api/groupes/{$groupe->identifiant}/campagne", ['campagne' => 'campagne-d-essai'])
        ->assertStatus(422)->assertJsonPath('errors.campagne.0', '« Campagne d\'essai » : Exige un héros de classe : Barbare.');
    expect($groupe->refresh()->campagneConstruite->slug)->toBe('campagne-des-nains');
});

it('change de campagne avant la première quête : rang 1, titre au hub, « prêt » remis à zéro', function () {
    [$groupe, $naine] = naineAuHub($this);
    Cache::put("partie:pret:{$groupe->id}", [$naine->id => true], now()->addHour());

    $this->putJson("/api/groupes/{$groupe->identifiant}/campagne", ['campagne' => 'campagne-ouverte'])->assertOk()
        ->assertJsonPath('groupe.campagne', 'campagne-ouverte')
        ->assertJsonPath('groupe.rang_quete', 1)
        ->assertJsonPath('groupe.nb_quetes', 2);

    $groupe->refresh();
    expect($groupe->theme)->toBe('Campagne ouverte')
        ->and(Cache::get("partie:pret:{$groupe->id}"))->toBeNull()
        ->and((int) $naine->fresh()->or)->toBe(30)
        ->and(\App\Models\Evenement::where('groupe_id', $groupe->id)->where('type', 'systeme')->get()->contains(fn ($e) => ($e->payload['action'] ?? null) === 'campagne_choisie'))->toBeTrue();

    $hub = $this->getJson("/api/groupes/{$groupe->identifiant}/etat")->assertOk()->json('groupe.classique');
    expect($hub['campagne'])->toBe(['slug' => 'campagne-ouverte', 'titre' => 'Campagne ouverte'])
        ->and($hub['rang_quete'])->toBe(1)
        ->and($hub['nb_quetes'])->toBe(2)
        ->and($hub['prochaine_quete'])->toBe('La première marche')
        ->and($hub['terminee'])->toBeFalse()
        ->and($hub['choix_ouvert'])->toBeTrue();
});

it('ferme le choix d\'une campagne en cours, et le rouvre quand elle est finie — la rejouer repart de la quête 1', function () {
    [$groupe] = naineAuHub($this);
    $groupe->update(['rang_quete' => 2]);

    $hub = $this->getJson("/api/groupes/{$groupe->identifiant}/etat")->assertOk()->json('groupe.classique');
    expect($hub['choix_ouvert'])->toBeFalse()
        ->and($hub['choix_motif'])->toContain('est en cours');
    $this->putJson("/api/groupes/{$groupe->identifiant}/campagne", ['campagne' => 'campagne-ouverte'])
        ->assertStatus(422)->assertJsonPath('errors.campagne.0', fn (string $m) => str_contains($m, 'est en cours'));

    // Toutes les quêtes jouées : la campagne est finie, le départ refusé, le choix rouvert.
    $groupe->update(['rang_quete' => 3]);
    $etat = $this->getJson("/api/groupes/{$groupe->identifiant}/etat")->assertOk()->json('groupe');
    expect($etat['classique']['terminee'])->toBeTrue()
        ->and($etat['classique']['choix_ouvert'])->toBeTrue()
        ->and($etat['depart_possible'])->toBeFalse()
        ->and($etat['depart_motif'])->toContain('ont été jouées');

    $this->putJson("/api/groupes/{$groupe->identifiant}/campagne", ['campagne' => 'campagne-des-nains'])->assertOk()
        ->assertJsonPath('groupe.rang_quete', 1);
    expect($this->getJson("/api/groupes/{$groupe->identifiant}/etat")->json('groupe.depart_possible'))->toBeTrue();
});

it('publie au hub le refus de départ d\'un groupe classique sans la classe exigée, et la table ne passe pas outre', function () {
    [$groupe, $naine] = naineAuHub($this, 'campagne-ouverte');
    // La campagne exige désormais un barbare : le refus est publié, « Prêt » ne sera pas offert.
    $groupe->update(['campagne_construite_id' => \App\Models\CampagneConstruite::where('slug', 'campagne-d-essai')->value('id')]);

    $etat = $this->getJson("/api/groupes/{$groupe->identifiant}/etat")->assertOk()->json('groupe');
    expect($etat['depart_possible'])->toBeFalse()
        ->and($etat['depart_motif'])->toBe('« Campagne d\'essai » exige un héros de classe : Barbare.');

    $this->postJson("/api/groupes/{$groupe->identifiant}/pret", ['personnage_id' => $naine->id, 'pret' => true])->assertStatus(422);
    // « Lancer la quête » (table) passe outre les « prêt », jamais les prérequis.
    $this->postJson("/api/groupes/{$groupe->identifiant}/quetes")->assertStatus(422);
    expect($groupe->refresh()->phase)->toBe('hub');
});

it('refuse la liste et le choix à qui ne pilote aucun héros du groupe, et à un groupe qui n\'est pas classique', function () {
    [$groupe] = naineAuHub($this);

    connecterJoueur('bruno');
    $this->getJson("/api/groupes/{$groupe->identifiant}/campagnes")->assertForbidden();
    $this->putJson("/api/groupes/{$groupe->identifiant}/campagne", ['campagne' => 'campagne-ouverte'])->assertForbidden();

    $bruno = \App\Models\Joueur::where('pseudo', 'bruno')->firstOrFail();
    $campagne = creerGroupe('table-campagne');
    creerHeros($bruno, $campagne, 'Bertrand', 1);
    $this->putJson('/api/groupes/table-campagne/campagne', ['campagne' => 'campagne-ouverte'])
        ->assertStatus(422)->assertJsonPath('errors.campagne.0', 'Seul un groupe classique choisit une campagne construite.');
    expect($this->getJson('/api/groupes/table-campagne/etat')->json('groupe'))->not->toHaveKey('classique');
});

/*
 * MARCHÉ FERMÉ AVANT UNE QUÊTE (lot K5c, R8) : `regles.marche_ferme` sur la quête 1 de la campagne ouverte. Au hub, tant
 * qu'elle est la prochaine, le marché ne s'ouvre pas — publié et refusé ; la quête jouée (rang 2), il rouvre.
 */
it('ferme le marché avant la quête qui l\'interdit, le publie au hub, et le rouvre ensuite', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['slug'] = 'campagne-ouverte';
    $campagne['titre'] = 'Campagne ouverte';
    $campagne['prerequis_heros'] = [];
    $campagne['quetes'][0]['regles'] = ['marche_ferme' => true];
    expect(app(\App\Partie\Classique\ValidationCampagne::class)->valider($campagne))->toBe([]);
    app(CampagnesConstruites::class)->enregistrer($campagne);
    [$groupe] = naineAuHub($this, 'campagne-ouverte');

    $etat = $this->getJson("/api/groupes/{$groupe->identifiant}/etat")->assertOk()->json('groupe');
    expect($etat['marche_ouvert'])->toBeFalse()
        ->and($etat['marche_motif'])->toBe('Le marché est fermé avant « La première marche ».');
    $this->postJson("/api/groupes/{$groupe->identifiant}/marche")->assertStatus(422)
        ->assertJsonPath('errors.groupe.0', 'Le marché est fermé avant « La première marche ».');

    $groupe->update(['rang_quete' => 2]);
    expect($this->getJson("/api/groupes/{$groupe->identifiant}/etat")->json('groupe.marche_ouvert'))->toBeTrue();
    $this->postJson("/api/groupes/{$groupe->identifiant}/marche")->assertCreated();

    $campagne['quetes'][0]['regles'] = ['marche_ferme' => 'oui'];
    expect(collect(app(\App\Partie\Classique\ValidationCampagne::class)->valider($campagne))->pluck('chemin'))
        ->toContain('quetes.0.regles.marche_ferme');
});

it('publie au hub que la clôture est fermée à un groupe classique, et ouverte à une campagne', function () {
    [$groupe] = naineAuHub($this);
    expect($this->getJson("/api/groupes/{$groupe->identifiant}/etat")->json('groupe.cloture_ouverte'))->toBeFalse();

    $bruno = connecterJoueur('bruno');
    $campagne = creerGroupe('table-campagne');
    creerHeros($bruno, $campagne, 'Bertrand', 1);
    expect($this->getJson('/api/groupes/table-campagne/etat')->json('groupe.cloture_ouverte'))->toBeTrue();
});

/*
 * DRAPEAUX DE CAMPAGNE (lot K5h, C4). Quête 1 : sa note de fouille de trésor pose `cle_trouvee` DE CAMPAGNE. Quête 2 : une
 * note lit `si_drapeau: cle_trouvee` — validée parce qu'une quête PRÉCÉDENTE le pose ; en jeu, le drapeau survit à la quête 1.
 */
function campagneADrapeau(bool $deCampagne): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['slug'] = 'campagne-ouverte';
    $campagne['prerequis_heros'] = [];
    $campagne['quetes'][0]['notes'] = [
        ['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
            'alors' => [['effet' => 'drapeau', 'nom' => 'cle_trouvee', ...($deCampagne ? ['campagne' => true] : [])]]],
    ];
    $campagne['quetes'][1]['notes'] = [
        ['repere' => 'B', 'quand' => ['declencheur' => 'entree', 'cible' => ['salle' => 'crypte'], 'si_drapeau' => 'cle_trouvee'],
            'alors' => [['effet' => 'or', 'or' => 20]]],
    ];

    return $campagne;
}

it('valide un drapeau lu par une quête suivante seulement s\'il est posé « de campagne »', function () {
    expect(app(\App\Partie\Classique\ValidationCampagne::class)->valider(campagneADrapeau(true)))->toBe([]);

    $refus = app(\App\Partie\Classique\ValidationCampagne::class)->valider(campagneADrapeau(false));
    expect(collect($refus)->pluck('code', 'chemin')->all())
        ->toMatchArray(['quetes.1.notes.0.quand.si_drapeau' => \App\Partie\Classique\ValidationCampagne::DRAPEAU_JAMAIS_POSE]);

    $campagne = campagneADrapeau(true);
    $campagne['quetes'][0]['notes'][0]['alors'][0]['campagne'] = 'oui';
    expect(collect(app(\App\Partie\Classique\ValidationCampagne::class)->valider($campagne))->pluck('chemin'))
        ->toContain('quetes.0.notes.0.alors.0.campagne');
});

it('EN JEU : le drapeau de campagne survit à la quête, se lit dans la suivante, et s\'efface au changement de campagne', function () {
    app(CampagnesConstruites::class)->enregistrer(campagneADrapeau(true));
    [$groupe] = naineAuHub($this, 'campagne-ouverte');
    $this->postJson("/api/groupes/{$groupe->identifiant}/quetes")->assertCreated();
    $quete1 = \App\Models\Quete::findOrFail($groupe->refresh()->quete_courante_id);

    app(\App\Partie\Classique\NotesDeQuete::class)->signaler($quete1, \App\Engine\MotsClesQuete::FOUILLE_TRESOR, ['salle' => 0]);
    expect($groupe->refresh()->drapeaux_campagne)->toBe(['cle_trouvee' => true])
        ->and($quete1->refresh()->drapeaux)->toBeEmpty()
        ->and($quete1->aDrapeau('cle_trouvee'))->toBeTrue();

    // Une quête suivante du même groupe lit le drapeau de campagne.
    $quete2 = $quete1->replicate();
    $quete2->drapeaux = null;
    $quete2->save();
    expect($quete2->aDrapeau('cle_trouvee'))->toBeTrue();

    // Changer de campagne efface les drapeaux de campagne.
    $quete1->update(['etat' => 'terminee']);
    $quete2->delete();
    $groupe->update(['phase' => 'hub', 'quete_courante_id' => null, 'rang_quete' => 3]);
    $this->putJson("/api/groupes/{$groupe->identifiant}/campagne", ['campagne' => 'campagne-des-nains'])->assertOk();
    expect($groupe->refresh()->drapeaux_campagne)->toBeNull();
});
