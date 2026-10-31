<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\Groupe;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;
use App\Models\Quete;
use App\Partie\FaveursHopekins;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * FIN DE CAMPAGNE (docs/plan-wizards-of-morcar.md, « Exécution (2026-10-16, fin de campagne) »).
 *
 *  A. Après le boss final vaincu, AUCUN tour de Hopekins Rest n'est publié — ni pendant la fenêtre de clôture,
 *     ni après son annulation (`DELETE /cloture`) — et `PUT …/faveurs/lieu` refuse. Un seul prédicat :
 *     `Groupe::victoireFinaleAcquise()`, lu par `FaveursHopekins::tourDeHub()` (publication ET route).
 *  B. Une campagne gagnée ne démarre plus de quête : `POST /pret` refuse (422 `pret`, message du serveur) et la
 *     table (`POST /quetes`, 422 `groupe`) aussi ; l'annulation de la fenêtre laisse la CLÔTURE pour seule issue.
 *  C. Les FAVEURS DE HOPEKINS REST ne survivent pas à la campagne (décision de René, 2026-10-16) : la
 *     finalisation (`ClotureCampagne::purger()`, toutes les issues) les relâche, PV max compris ; aucune ligne
 *     ne reste, et une autre campagne en cours n'est pas touchée.
 *
 * Tout passe par les vraies routes. La victoire est gagnée au contact du boss (`POST /choix`), la fin de quête
 * par `acheverLaQuete()`, comme le vote de sortie qui la déclenche en jeu (ClotureTest, ClotureVictoireTest).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, MercenaireSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Le héros VISITE `$lieu` de Hopekins Rest, par la vraie route (`PUT …/faveurs/lieu`).
 */
function victoireFinaleLieu(Personnage $heros, string $lieu)
{
    return test()->putJson('/api/groupes/table-1/faveurs/lieu', [
        'personnage_id' => $heros->id,
        'lieu' => $lieu,
    ]);
}

/**
 * Le boss final de la quête suivante, lancé par la route puis gagné au contact (`POST /choix`) : le donjon est
 * nettoyé, le vote de sortie (`acheverLaQuete()`) termine la quête — victoire, fenêtre ouverte.
 */
function victoireFinaleCombattreBoss(JoueurAuthentifiable $alice, Groupe $groupe, Personnage $heros): void
{
    test()->postJson('/api/groupes/table-1/quetes')
        ->assertCreated()
        ->assertJsonPath('quete.type_jalon', 'boss_final');

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $proie = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($proie->id)->update(['etat' => 'vaincu']);
    // Bloc connu (le boss d'un pool d'archétypes change d'une exécution à l'autre) : le test porte sur l'arc.
    $proie->update(['monstre_id' => Monstre::where('nom_base', 'Seigneur Sorcier de Barak Tor')->value('id')]);
    $proie->refresh()->load('monstre');

    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    $proie->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'pv_body' => 1, 'revele' => true]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);
    // 3 dés d'attaque (1 crâne), puis la défense du monstre : 1 dégât → vaincu → donjon nettoyé.
    desFiges([1, 4, 4, ...array_fill(0, (int) $proie->monstre->defense, 4)]);

    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $proie->id]])
        ->assertStatus(202)
        ->assertJsonPath('resultat.donjon_nettoye', true);

    acheverLaQuete($groupe->fresh());
}

/**
 * Campagne WIZARDS OF MORCAR : `$fictives` quêtes fictives achevées (le Gardien), puis le BOSS FINAL, quête
 * `$nbQuetes`. `$lieu` : la faveur qu'Albrecht prend au hub, entre les deux (par la vraie route). Au retour :
 * hub, fenêtre de victoire ouverte.
 *
 * @return array{alice: JoueurAuthentifiable, groupe: Groupe, heros: Personnage}
 */
function victoireFinaleCampagne(int $nbQuetes = 2, int $fictives = 1, ?string $lieu = null): array
{
    $alice = connecterJoueur('alice');
    $groupe = jouerMorcar(creerGroupe('table-1', nbQuetes: $nbQuetes));
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe, $fictives);

    if ($lieu !== null) {
        victoireFinaleLieu($heros, $lieu)->assertOk();
    }

    victoireFinaleCombattreBoss($alice, $groupe, $heros);

    return ['alice' => $alice, 'groupe' => $groupe->fresh(), 'heros' => $heros];
}

/**
 * Une campagne Morcar à TROIS quêtes, deux fictives achevées : le hub ouvre le tour. Albrecht y prend *Dewdrop Inn*
 * (+2 PV de Body max) par la vraie route. Aucun boss : la fin se décide au hub (abandon) ou par l'arrêt d'urgence.
 *
 * @return array{alice: JoueurAuthentifiable, groupe: Groupe, heros: Personnage}
 */
function faveurCampagneAvecDewdrop(): array
{
    $alice = connecterJoueur('alice');
    $groupe = jouerMorcar(creerGroupe('table-1', nbQuetes: 3));
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    rendreGardien($groupe, 2);

    victoireFinaleLieu($heros, FaveursHopekins::DEWDROP_INN)->assertOk();
    expect(PersonnageFaveur::where('personnage_id', $heros->id)->where('cle', FaveursHopekins::DEWDROP_INN)->exists())
        ->toBeTrue();

    return ['alice' => $alice, 'groupe' => $groupe->fresh(), 'heros' => $heros];
}

/** La visite de Hopekins Rest que `/moi` publie à ce héros (`null` : rien à décider). */
function victoireFinaleVisite(Personnage $heros): ?array
{
    $personnages = test()->getJson('/api/moi')->assertOk()->json('joueur.personnages');

    return collect($personnages)->firstWhere('id', $heros->id)['visite_hopekins'];
}

/** Le narrateur ouvre la TABLE (session sans compte) : le heartbeat est actif. */
function victoireFinaleTable(): void
{
    test()->postJson('/api/table', ['code' => 'table-1'])->assertOk();
}

it('A : après la victoire finale, /moi et /etat ne publient AUCUN tour de Hopekins Rest — fenêtre ouverte', function () {
    ['heros' => $heros] = victoireFinaleCampagne();

    // La campagne SE CLÔT : la fenêtre de victoire est ouverte, c'est elle qui est affichée à la manette.
    $this->getJson('/api/groupes/table-1/cloture')->assertOk()->assertJsonPath('issue', 'victoire');

    expect(victoireFinaleVisite($heros))->toBeNull();
    $this->getJson('/api/groupes/table-1/etat')->assertOk()->assertJsonPath('groupe.hopekins', null);

    // Le choix n'est pas seulement caché : la route le refuse.
    victoireFinaleLieu($heros, FaveursHopekins::DEADEYE)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phase']);
    expect(PersonnageFaveur::count())->toBe(0);
});

it('A : annuler la fenêtre de victoire ne rouvre AUCUN tour — l\'arc est épuisé', function () {
    ['heros' => $heros] = victoireFinaleCampagne();

    $this->deleteJson('/api/groupes/table-1/cloture')->assertNoContent();

    expect(victoireFinaleVisite($heros))->toBeNull();
    $this->getJson('/api/groupes/table-1/etat')->assertOk()->assertJsonPath('groupe.hopekins', null);

    victoireFinaleLieu($heros, FaveursHopekins::DEADEYE)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['phase']);
    expect(PersonnageFaveur::count())->toBe(0);
});

it('B : après l\'annulation de la fenêtre, POST /pret refuse le départ — aucune quête 4 ne part', function () {
    ['heros' => $heros, 'groupe' => $groupe] = victoireFinaleCampagne();

    $this->deleteJson('/api/groupes/table-1/cloture')->assertNoContent();
    victoireFinaleTable();

    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $heros->id, 'pret' => true])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['pret'])
        ->assertJsonPath(
            'errors.pret.0',
            'La campagne est gagnée (boss final vaincu) : aucune nouvelle quête ne peut démarrer. Clôturez la campagne.',
        );

    // Rien n'a démarré et rien n'attend un départ impossible : ni quête, ni phase, ni « prêt » en cache.
    expect(Quete::where('groupe_id', $groupe->id)->count())->toBe(2)
        ->and($groupe->fresh()->phase)->toBe('hub')
        ->and($groupe->fresh()->quete_courante_id)->toBeNull()
        ->and(Cache::has("partie:pret:{$groupe->id}"))->toBeFalse();
});

it('B : ni le membre ni la TABLE ne lancent de quête (POST /quetes) après la victoire finale', function () {
    ['groupe' => $groupe] = victoireFinaleCampagne();

    // Fenêtre encore ouverte, puis annulée : le départ reste refusé dans les deux cas.
    $this->postJson('/api/groupes/table-1/quetes')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['groupe']);

    $this->deleteJson('/api/groupes/table-1/cloture')->assertNoContent();
    $this->postJson('/api/groupes/table-1/quetes')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['groupe']);

    $this->postJson('/api/deconnexion');
    victoireFinaleTable();
    $this->postJson('/api/groupes/table-1/quetes')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['groupe']);

    expect(Quete::where('groupe_id', $groupe->id)->count())->toBe(2);
});

it('B : la clôture reste l\'UNIQUE issue — rouverte après l\'annulation, elle se finalise en victoire', function () {
    ['groupe' => $groupe] = victoireFinaleCampagne();

    $this->deleteJson('/api/groupes/table-1/cloture')->assertNoContent();

    $this->postJson('/api/groupes/table-1/cloture')
        ->assertCreated()
        ->assertJsonPath('issue', 'victoire');

    $this->postJson('/api/groupes/table-1/cloture/confirmation')
        ->assertOk()
        ->assertJsonPath('finalise', true);

    // Campagne close et purgée : aucune quête 4 n'a jamais existé.
    expect(Groupe::where('id', $groupe->id)->exists())->toBeFalse();
});

it('contrôle : une quête NORMALE achevée n\'épuise pas l\'arc — le départ suivant reste permis', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe('table-1', nbQuetes: 3);
    $groupe->update(['plan_campagne' => ['jalons' => [['position' => 3, 'type' => 'boss_final']]]]);
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')
        ->assertCreated()
        ->assertJsonPath('quete.type_jalon', 'normale');
    acheverLaQuete($groupe);

    expect($groupe->fresh()->victoireFinaleAcquise())->toBeFalse();

    victoireFinaleTable();
    $this->postJson('/api/groupes/table-1/pret', ['personnage_id' => $heros->id, 'pret' => true])
        ->assertOk()
        ->assertJsonPath('quete_demarree', true);
});

it('C : la VICTOIRE finale relâche la faveur tenue — les PV max reviennent, aucune ligne ne reste', function () {
    ['heros' => $heros] = victoireFinaleCampagne(3, 2, FaveursHopekins::DEWDROP_INN);
    expect(PersonnageFaveur::count())->toBe(1);

    // Fenêtre ouverte : la faveur est encore tenue, son +2 aussi. C'est la valeur qu'on mesure.
    $pvAvant = (int) $heros->fresh()->pv_body_max;

    $this->getJson('/api/groupes/table-1/cloture')->assertOk()->assertJsonPath('issue', 'victoire');
    $this->postJson('/api/groupes/table-1/cloture/confirmation')->assertOk()->assertJsonPath('finalise', true);

    $apres = Personnage::findOrFail($heros->id);
    expect(PersonnageFaveur::count())->toBe(0)
        ->and((int) $apres->pv_body_max)->toBe($pvAvant - 2)
        ->and((int) $apres->pv_body)->toBe((int) $apres->pv_body_max)
        ->and($apres->groupe_actif_id)->toBeNull();
});

it('C : la clôture ABANDON (ouverte au hub) relâche la faveur tenue — PV max revenus', function () {
    ['heros' => $heros] = faveurCampagneAvecDewdrop();
    $pvAvant = (int) $heros->fresh()->pv_body_max;

    $this->postJson('/api/groupes/table-1/cloture')
        ->assertCreated()
        ->assertJsonPath('issue', 'abandon');
    $this->postJson('/api/groupes/table-1/cloture/confirmation')->assertOk()->assertJsonPath('finalise', true);

    expect(PersonnageFaveur::count())->toBe(0)
        ->and((int) Personnage::findOrFail($heros->id)->pv_body_max)->toBe($pvAvant - 2);
});

it('C : l\'ARRÊT D\'URGENCE relâche la faveur tenue, sans rituel de confirmation — PV max revenus', function () {
    ['heros' => $heros] = faveurCampagneAvecDewdrop();
    $pvAvant = (int) $heros->fresh()->pv_body_max;

    $this->postJson('/api/groupes/table-1/cloture/urgence')->assertNoContent();

    expect(PersonnageFaveur::count())->toBe(0)
        ->and((int) Personnage::findOrFail($heros->id)->pv_body_max)->toBe($pvAvant - 2);
});

it('C : la clôture d\'une campagne ne touche PAS aux faveurs d\'une AUTRE campagne en cours', function () {
    ['heros' => $heros] = faveurCampagneAvecDewdrop();

    // Une seconde campagne, toujours en cours, dont Brunhilde (joueuse Bob) tient une faveur.
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $autre = creerGroupe('table-2', nbQuetes: 3);
    $brunhilde = creerHeros($bob, $autre, 'Brunhilde', 1);
    PersonnageFaveur::create(['personnage_id' => $brunhilde->id, 'cle' => FaveursHopekins::DEWDROP_INN]);

    $this->postJson('/api/groupes/table-1/cloture')->assertCreated();
    $this->postJson('/api/groupes/table-1/cloture/confirmation')->assertOk()->assertJsonPath('finalise', true);

    expect(Groupe::where('identifiant', 'table-2')->exists())->toBeTrue()
        ->and(PersonnageFaveur::where('personnage_id', $brunhilde->id)->exists())->toBeTrue()
        ->and(PersonnageFaveur::where('personnage_id', $heros->id)->exists())->toBeFalse();
});

it('C : un héros PARTI en cours de campagne (pivot à actif = false) n\'emporte pas sa faveur dans une autre', function () {
    ['groupe' => $groupe] = faveurCampagneAvecDewdrop();

    // Brynn (joueur Bob) a pris la faveur, puis a quitté le groupe : le départ laisse le pivot à `actif: false`
    // (`VoteGroupe::detacherJoueur()`), le groupe continue sans elle.
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brynn = creerHeros($bob, $groupe, 'Brynn', 2);
    PersonnageFaveur::create(['personnage_id' => $brynn->id, 'cle' => FaveursHopekins::DEWDROP_INN]);
    $groupe->personnages()->updateExistingPivot($brynn->id, ['actif' => false]);
    $brynn->update(['groupe_actif_id' => null]);

    $this->postJson('/api/groupes/table-1/cloture')->assertCreated();
    $this->postJson('/api/groupes/table-1/cloture/confirmation')->assertOk()->assertJsonPath('finalise', true);

    expect(PersonnageFaveur::where('personnage_id', $brynn->id)->exists())->toBeFalse();
});

it('D : /etat publie la décision de départ — possible tant que l\'arc n\'est pas épuisé, absente en quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe('table-1', nbQuetes: 3);
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->getJson('/api/groupes/table-1/etat')
        ->assertOk()
        ->assertJsonPath('groupe.depart_possible', true)
        ->assertJsonPath('groupe.depart_motif', null);

    // En quête, la décision de départ n'a plus lieu d'être : le champ n'est pas publié.
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $this->getJson('/api/groupes/table-1/etat')
        ->assertOk()
        ->assertJsonMissingPath('groupe.depart_possible');
});

it('D : après la victoire finale, /etat publie le départ REFUSÉ avec son motif — fenêtre annulée comprise', function () {
    victoireFinaleCampagne();

    $this->getJson('/api/groupes/table-1/etat')
        ->assertOk()
        ->assertJsonPath('groupe.depart_possible', false)
        ->assertJsonPath(
            'groupe.depart_motif',
            'La campagne est gagnée (boss final vaincu) : aucune nouvelle quête ne peut démarrer. Clôturez la campagne.',
        );

    $this->deleteJson('/api/groupes/table-1/cloture')->assertNoContent();
    $this->getJson('/api/groupes/table-1/etat')
        ->assertOk()
        ->assertJsonPath('groupe.depart_possible', false);
});
