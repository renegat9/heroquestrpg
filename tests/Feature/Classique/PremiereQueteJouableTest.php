<?php

declare(strict_types=1);

use App\Jobs\GenererRecitsQuete;
use App\Jobs\GenererSqueletteCampagne;
use App\Jobs\HabillerMonstres;
use App\Models\Carte;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\DemarreurQueteConstruite;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Classique\DocumentExemple;

/*
 * MODE CLASSIQUE — LA PREMIÈRE QUÊTE JOUABLE (`docs/plan-mode-classique.md` §7), jouée par les VRAIES routes.
 *
 * Ce que ces tests défendent, de bout en bout :
 *  1. un groupe classique s'ouvre sur une campagne construite, SANS aucun travail d'IA ;
 *  2. sa quête démarre sur la carte de l'auteur : mêmes cases, mêmes monstres à leur place, valeurs du catalogue ;
 *  3. le moteur la joue : le héros reçoit un menu ;
 *  4. l'objectif est celui de l'auteur — vaincre les figures DÉSIGNÉES — et sa consigne est la sienne ;
 *  5. la quête gagnée se termine SANS erreur (la montée de niveau est fermée au classique), fait avancer la campagne
 *     et verse la récompense aux bourses des héros, annoncée.
 *
 * La campagne est `DocumentExemple` : un plateau, des quêtes et des textes INVENTÉS.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
    app(CampagnesConstruites::class)->enregistrer(DocumentExemple::campagne());
});

/** Un héros LIBRE (engagé dans aucun groupe), comme en sort la création de personnage. */
function herosLibre(\App\Models\Joueur $joueur, string $nom, string $classe = 'barbare', int $or = 0): Personnage
{
    return Personnage::create([
        'joueur_id' => $joueur->id, 'nom' => $nom, 'classe' => $classe, 'niveau' => 1, 'or' => $or,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 5, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
}

/** Ouvre un groupe classique par la vraie route et rend le modèle. */
function groupeClassique(Personnage $fondateur, string $campagne = 'campagne-d-essai'): Groupe
{
    $reponse = test()->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => $campagne, 'personnage_id' => $fondateur->id])
        ->assertCreated();

    return Groupe::where('identifiant', $reponse->json('groupe.identifiant'))->firstOrFail();
}

it('ouvre un groupe classique sur une campagne construite, sans aucun travail d\'IA', function () {
    Queue::fake();
    $alice = connecterJoueur('alice');
    $heros = herosLibre($alice, 'Albrecht', or: 40);

    $reponse = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()
        ->assertJsonPath('groupe.mode', 'classique')
        ->assertJsonPath('groupe.campagne', 'campagne-d-essai')
        ->assertJsonPath('groupe.rang_quete', 1)
        ->assertJsonPath('groupe.nb_quetes', 2);

    $groupe = Groupe::where('identifiant', $reponse->json('groupe.identifiant'))->firstOrFail();

    expect($groupe->estClassique())->toBeTrue()
        ->and($groupe->phase)->toBe('hub')
        ->and($heros->refresh()->groupe_actif_id)->toBe($groupe->id)
        // BOURSE PAR HÉROS : l'or du fondateur reste sur lui, le pot commun reste vide.
        ->and($heros->or)->toBe(40)
        ->and((int) $groupe->or)->toBe(0);

    Queue::assertNotPushed(GenererSqueletteCampagne::class);
});

it('refuse une campagne inconnue, et un héros déjà engagé ailleurs', function () {
    $alice = connecterJoueur('alice');
    $heros = herosLibre($alice, 'Albrecht');

    $this->postJson('/api/classique', ['nom' => 'X', 'campagne' => 'absente', 'personnage_id' => $heros->id])
        ->assertStatus(422)->assertJsonValidationErrors('campagne');

    groupeClassique($heros);
    $this->postJson('/api/classique', ['nom' => 'Y', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertStatus(422)->assertJsonValidationErrors('personnage_id');
});

it('démarre la quête sur la carte de l\'auteur : mêmes cases, mêmes monstres, valeurs du catalogue, aucune IA', function () {
    Queue::fake();
    $alice = connecterJoueur('alice');
    $heros = herosLibre($alice, 'Albrecht');
    $groupe = groupeClassique($heros);

    $this->postJson("/api/groupes/{$groupe->identifiant}/quetes")
        ->assertCreated()
        ->assertJsonPath('quete.titre', 'La première marche')
        ->assertJsonPath('quete.position_arc', 1);

    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    $campagne = DocumentExemple::campagne();
    $attendu = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0]);

    // La carte en base EST celle que produit le constructeur — rien n'a été tiré ni ajouté.
    expect($quete->gabarit_id)->toBeNull()
        ->and($groupe->phase)->toBe('quete')
        ->and(Carte::where('quete_id', $quete->id)->firstOrFail()->grille)->toEqual($attendu['carte']);

    // Le gobelin de l'auteur, à SA case, avec les valeurs du catalogue ; il est la cible de l'objectif.
    $gobelin = Monstre::where('nom_base', 'Gobelin')->firstOrFail();
    $instances = InstanceMonstre::where('quete_id', $quete->id)->get();
    expect($instances)->toHaveCount(1)
        ->and($instances[0]->monstre_id)->toBe($gobelin->id)
        ->and([$instances[0]->position_x, $instances[0]->position_y])->toBe([5, 2])
        ->and($instances[0]->pv_body)->toBe((int) $gobelin->pv_body)
        ->and($instances[0]->elite)->toBeFalse()
        ->and($instances[0]->objectif)->toBeTrue()
        // Il est dans la salle de départ : vu d'emblée.
        ->and($instances[0]->revele)->toBeTrue();

    // Le héros part de l'escalier, soigné (il entrait à 5 PV sur 8).
    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    expect([$etat->position_x, $etat->position_y])->toBe([2, 4])
        ->and($heros->refresh()->pv_body)->toBe(8);

    // Aucun travail d'IA n'est parti.
    Queue::assertNotPushed(HabillerMonstres::class);
    Queue::assertNotPushed(GenererRecitsQuete::class);
    Queue::assertNotPushed(GenererSqueletteCampagne::class);
});

it('le moteur joue la quête construite : le héros reçoit son menu, et l\'objectif est celui de l\'auteur', function () {
    $alice = connecterJoueur('alice');
    $heros = herosLibre($alice, 'Albrecht');
    $groupe = groupeClassique($heros);
    $this->postJson("/api/groupes/{$groupe->identifiant}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);

    $menu = $this->getJson("/api/groupes/{$groupe->identifiant}/menu")->assertOk()->json();
    expect($menu['personnage_id'])->toBe($heros->id)
        ->and(collect($menu['menu']['options'])->pluck('id'))->toContain('se_deplacer');

    expect($quete->objectif())->toBe(Quete::OBJECTIF_VAINCRE_CIBLES)
        ->and($quete->objectifLibelle())->toBe('Vaincre le gobelin qui garde l\'entrée.')
        ->and($quete->objectifAccompli())->toBeFalse();

    // La cible tombe : l'objectif est accompli.
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);
    expect($quete->refresh()->objectifAccompli())->toBeTrue();
});

it('termine la quête gagnée sans erreur, fait avancer la campagne et verse la récompense aux bourses', function () {
    $alice = connecterJoueur('alice');
    $bob = \App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $albrecht = herosLibre($alice, 'Albrecht', or: 5);
    $groupe = groupeClassique($albrecht);

    // Un second héros rejoint : son or reste sur lui (bourse par héros).
    $brunehaut = herosLibre($bob, 'Brunehaut', 'nain', or: 7);
    $this->actingAs($bob, 'joueur');
    $this->postJson("/api/groupes/{$groupe->identifiant}/joueurs", ['personnage_id' => $brunehaut->id])->assertOk();
    expect($brunehaut->refresh()->or)->toBe(7)->and((int) $groupe->refresh()->or)->toBe(0);

    $this->postJson("/api/groupes/{$groupe->identifiant}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    InstanceMonstre::where('quete_id', $quete->id)->update(['etat' => 'vaincu']);

    // La fin de quête du moteur, son point de passage unique. La montée de niveau est FERMÉE au classique : elle n'est
    // pas appelée, et rien ne lève.
    $resultat = app(ResolveurTour::class)->terminerQuete($groupe, $quete);

    expect($resultat['etat'])->toBe('terminee')
        ->and($resultat['niveaux'])->toBeNull()
        ->and($resultat['classique']['rang_suivant'])->toBe(2)
        // 10 pièces à partager entre deux héros : 5 chacun.
        ->and(array_column($resultat['classique']['recompense'], 'or'))->toBe([5, 5]);

    expect($groupe->refresh()->phase)->toBe('hub')
        ->and((int) $groupe->rang_quete)->toBe(2)
        ->and((int) $groupe->or)->toBe(0)
        ->and($albrecht->refresh()->or)->toBe(10)
        ->and($brunehaut->refresh()->or)->toBe(12)
        // Ni niveau gagné, ni groupe dissous.
        ->and($albrecht->niveau)->toBe(1)
        ->and($groupe->estClassique())->toBeTrue();

    // La récompense est ANNONCÉE : une ligne au journal, avec sa phrase.
    $annonce = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()
        ->first(fn ($e) => ($e->payload['type'] ?? null) === 'recompense_quete');
    expect($annonce)->not->toBeNull()
        ->and(app(\App\Partie\JournalCombat::class)->depuisResultat($annonce->payload, 'Le maître du jeu')[0]['texte'])
        ->toBe('Récompense de la quête : 10 pièces d\'or (Albrecht +5, Brunehaut +5)');

    // La quête SUIVANTE de la campagne démarre ensuite : la seconde de l'auteur.
    $this->postJson("/api/groupes/{$groupe->identifiant}/quetes")->assertCreated()->assertJsonPath('quete.titre', 'La crypte');
});

it('ne publie aucune boîte de bestiaire pour un groupe classique, et publie la consigne de l\'auteur', function () {
    $alice = connecterJoueur('alice');
    $groupe = groupeClassique(herosLibre($alice, 'Albrecht'));

    // Au hub comme en quête : la table affichait « First Light » à côté d'une campagne construite (vu à l'écran).
    $etat = $this->getJson("/api/groupes/{$groupe->identifiant}/etat")->assertOk()->json('groupe');
    expect($etat['mode'])->toBe('classique')
        ->and($etat['theme_bestiaire'])->toBeNull()
        ->and($etat['theme_bestiaire_libelle'])->toBeNull()
        ->and($etat['bestiaire_mode'])->toBeNull()
        ->and($etat['bestiaire_boites'])->toBe([]);

    $this->postJson("/api/groupes/{$groupe->identifiant}/quetes")->assertCreated();
    $etat = $this->getJson("/api/groupes/{$groupe->identifiant}/etat")->assertOk()->json();
    expect($etat['groupe']['theme_bestiaire_libelle'])->toBeNull()
        ->and(json_encode($etat, JSON_UNESCAPED_UNICODE))->toContain('Vaincre le gobelin qui garde l\'entrée.');
});

it('refuse le départ tant que le groupe n\'a pas la classe que la campagne exige, puis quand la campagne est finie', function () {
    $alice = connecterJoueur('alice');
    $nain = herosLibre($alice, 'Brunehaut', 'nain');
    $groupe = groupeClassique($nain);
    $demarreur = app(DemarreurQueteConstruite::class);

    // La campagne d'essai exige un barbare ; le groupe n'a qu'un nain.
    expect($demarreur->refusDeDepart($groupe))->toContain('exige un héros de classe')->toContain('arbare');

    // Un barbare rejoint : plus de refus.
    $barbare = herosLibre($alice, 'Albrecht');
    $this->postJson("/api/groupes/{$groupe->identifiant}/joueurs", ['personnage_ids' => [$barbare->id]])->assertOk();
    expect($demarreur->refusDeDepart($groupe->refresh()))->toBeNull();

    // Toutes les quêtes jouées : la campagne est finie, et le groupe N'EST PAS dissous.
    $groupe->update(['rang_quete' => 3]);
    expect($demarreur->refusDeDepart($groupe->refresh()))->toContain('ont été jouées')
        ->and($groupe->estClassique())->toBeTrue();
});
