<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Partie\CapacitesInnees;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Les dernières capacités de carte du ROGUE et du CHEVALIER.
 *
 * *Ambidextrie* passe par le canal de la Potion d'héroïsme (une frappe de plus
 * au-delà du créneau) ; *Défi du chevalier* est le seul déclencheur de réaction
 * du jeu qui ne soit pas un coup encaissé — une bête qui surgit.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/** Met une arme en main principale (les dés d'attaque ne changent pas ici). */
function armerLaMainDe(App\Models\Personnage $heros, string $nom): void
{
    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'arme_principale',
    ]);
}

it('AMBIDEXTRIE offre une frappe de plus après un coup de dague', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'rogue']);
    armerLaMainDe($ctx['heros'], 'Dague');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 40, 4)); // boucliers : la cible survit, on veut le tour d'après

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.ambidextrie', true)
        ->assertJsonPath('resultat.attaque_supplementaire', true);

    // Le créneau d'action est pris, mais la frappe offerte est PROPOSÉE — c'est
    // exactement le trou par lequel la Potion d'héroïsme était injouable.
    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu'];

    expect(collect($menu['options'])->pluck('id'))->toContain('attaquer');
});

it('AMBIDEXTRIE reconnaît une DAGUE artefact, sans Bandoulière', function () {
    // Errata 2021 B2 (2026-10-01) : la *Lame Fantôme* est une dague (Avalon
    // Hill). L'Ambidextrie comparait le NOM de l'arme en main — « Dague » — et
    // l'artefact lui restait invisible tant qu'aucune Bandoulière ne traînait
    // dans le sac.
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'rogue']);
    armerLaMainDe($ctx['heros'], 'Lame Fantôme');

    expect($ctx['heros']->inventaire()->whereHas('objet', fn ($q) => $q->where('nom', 'Bandoulière'))->exists())
        ->toBeFalse('le scénario exige un Rogue SANS bandoulière');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 40, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonPath('resultat.ambidextrie', true);
});

it('AMBIDEXTRIE ne se déclenche pas à la hache, ni deux fois dans le tour', function () {
    $ctx = demarrerQueteAvecMonstre('Gargouille', ['classe' => 'rogue']);
    armerLaMainDe($ctx['heros'], 'Hachette'); // ni dague ni épée courte

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 40, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)->assertJsonMissingPath('resultat.ambidextrie');

    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse();

    // « Once per turn » : le compteur du TOUR, pas celui de la quête.
    $capacites = app(CapacitesInnees::class);
    $capacites->consommer($ctx['heros'], $ctx['etatHeros']->fresh(), 'attaque_supplementaire_arme');

    expect($capacites->disponible($ctx['heros'], $ctx['etatHeros']->fresh(), 'attaque_supplementaire_arme'))
        ->toBeFalse()
        ->and($ctx['etatHeros']->fresh()->capacites_utilisees)->toBeNull(); // pas la quête
});

it('DÉFI DU CHEVALIER est proposé quand un errant surgit dans sa salle', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $fouilleur = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $chevalier = creerHeros($bob, $groupe, 'Roland', 2, ['classe' => 'chevalier']);
    Inventaire::create([
        'personnage_id' => $chevalier->id,
        'objet_id' => Objet::where('nom', 'Bouclier')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = App\Models\Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatFouilleur = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $fouilleur->id)->firstOrFail();
    $etatChevalier = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $chevalier->id)->firstOrFail();

    // Les deux héros démarrent dans la salle 0 : c'est bien « la même salle ».
    empilerCarteFouille($quete, ['issue' => 'errant']);
    desFiges(array_fill(0, 40, 4)); // le coup du défi ne doit pas tuer le Chevalier

    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json('resultat');

    expect($resultat['issue'])->toBe('errant');

    // ⚠ La proposition va au CHEVALIER, pas au fouilleur : c'est lui qui décide
    // de prendre la bête sur lui.
    expect($etatFouilleur->fresh()->reaction_en_attente)->toBeNull();

    $attente = $etatChevalier->fresh()->reaction_en_attente;

    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe('defi_errant')
        ->and($attente['instance_id'])->toBe($resultat['monstre']['instance_id']);

    $errant = App\Models\InstanceMonstre::findOrFail($resultat['monstre']['instance_id']);

    $this->actingAs($bob, 'joueur')
        ->postJson('/api/groupes/table-1/reaction', [
            'personnage_id' => $chevalier->id, 'accepte' => true,
        ])->assertOk()->assertJsonPath('reaction.active', true);

    // Placé AU CONTACT du Chevalier, et il a frappé : le prix du défi.
    $errant->refresh();
    $distance = abs((int) $errant->position_x - (int) $etatChevalier->position_x)
        + abs((int) $errant->position_y - (int) $etatChevalier->position_y);

    expect($distance)->toBe(1)
        ->and(app(CapacitesInnees::class)
            ->disponible($chevalier->fresh(), $etatChevalier->fresh(), 'defi_errant'))->toBeFalse();
});

it('ne propose aucun défi au fouilleur lui-même', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $chevalier = creerHeros($alice, $groupe, 'Roland', 1, ['classe' => 'chevalier']);
    Inventaire::create([
        'personnage_id' => $chevalier->id,
        'objet_id' => Objet::where('nom', 'Bouclier')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = App\Models\Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $chevalier->id)->firstOrFail();

    empilerCarteFouille($quete, ['issue' => 'errant']);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])->assertStatus(202);

    // L'errant vient DÉJÀ le chercher : se défier soi-même ne changerait rien
    // et gaspillerait une capacité « once per quest ».
    expect($etat->fresh()->reaction_en_attente)->toBeNull();
});

/**
 * Fouilleur (Albrecht, alice) et Chevalier (Roland, bob) dans la salle de départ. Pose une carte
 * « errant » au sommet du deck et renvoie le contexte (sans encore fouiller).
 *
 * @return array{fouilleur: Personnage, chevalier: Personnage, groupe: App\Models\Groupe, quete: App\Models\Quete, etatFouilleur: EtatPersonnageQuete, etatChevalier: EtatPersonnageQuete, bob: App\Auth\JoueurAuthentifiable}
 */
function demarrerDefiDuSurgissement(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $fouilleur = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $chevalier = creerHeros($bob, $groupe, 'Roland', 2, ['classe' => 'chevalier']);
    Inventaire::create([
        'personnage_id' => $chevalier->id,
        'objet_id' => Objet::where('nom', 'Bouclier')->firstOrFail()->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = App\Models\Quete::findOrFail($groupe->fresh()->quete_courante_id);
    empilerCarteFouille($quete, ['issue' => 'errant']);

    return [
        'fouilleur' => $fouilleur,
        'chevalier' => $chevalier,
        'groupe' => $groupe,
        'quete' => $quete,
        'etatFouilleur' => EtatPersonnageQuete::where('quete_id', $quete->id)
            ->where('personnage_id', $fouilleur->id)->firstOrFail(),
        'etatChevalier' => EtatPersonnageQuete::where('quete_id', $quete->id)
            ->where('personnage_id', $chevalier->id)->firstOrFail(),
        'bob' => $bob,
    ];
}

/** Fouille le trésor de la salle de départ ; rend le `resultat`. */
function fouilleDuDefi(): array
{
    return test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller_tresor'])
        ->assertStatus(202)->json('resultat');
}

it('un Défi OUVERT retient la frappe du surgissement : le fouilleur n\'est pas frappé avant la réponse', function () {
    $ctx = demarrerDefiDuSurgissement();
    desFiges(array_fill(0, 40, 1));
    $pvAvant = (int) $ctx['fouilleur']->fresh()->pv_body;

    $resultat = fouilleDuDefi();

    expect($resultat['defi_en_attente'] ?? false)->toBeTrue('le fil dit que la bête attend')
        ->and($resultat)->not->toHaveKey('frappe_errant')
        ->and((int) $ctx['fouilleur']->fresh()->pv_body)->toBe($pvAvant, 'rien ne frappe avant la réponse')
        ->and($ctx['etatChevalier']->fresh()->reaction_en_attente['frappe_fouilleur'] ?? null)->toBeTrue();
});

it('un Défi REFUSÉ fait frapper le FOUILLEUR, à la réponse, et la réponse le dit (apres_defi: refuse)', function () {
    $ctx = demarrerDefiDuSurgissement();
    fouilleDuDefi();

    desFiges(array_fill(0, 40, 1));
    $pvAvant = (int) $ctx['fouilleur']->fresh()->pv_body;

    $this->actingAs($ctx['bob'], 'joueur')
        ->postJson('/api/groupes/table-1/reaction', ['personnage_id' => $ctx['chevalier']->id, 'accepte' => false])
        ->assertOk()
        ->assertJsonPath('reaction.active', false)
        ->assertJsonPath('reaction.frappe.type', 'attaque_monstre')
        ->assertJsonPath('reaction.frappe.cible.personnage_id', $ctx['fouilleur']->id)
        ->assertJsonPath('reaction.frappe.apres_defi', 'refuse');

    expect((int) $ctx['fouilleur']->fresh()->pv_body)->toBeLessThan($pvAvant, 'le coup a porté')
        ->and($ctx['etatChevalier']->fresh()->reaction_en_attente)->toBeNull();
});

it('un Défi ACCEPTÉ fait frapper le CHEVALIER, jamais le fouilleur', function () {
    $ctx = demarrerDefiDuSurgissement();
    fouilleDuDefi();

    desFiges(array_fill(0, 40, 1));
    $pvFouilleur = (int) $ctx['fouilleur']->fresh()->pv_body;

    $this->actingAs($ctx['bob'], 'joueur')
        ->postJson('/api/groupes/table-1/reaction', ['personnage_id' => $ctx['chevalier']->id, 'accepte' => true])
        ->assertOk()
        ->assertJsonPath('reaction.active', true)
        ->assertJsonPath('reaction.frappe.cible.personnage_id', $ctx['chevalier']->id)
        ->assertJsonMissingPath('reaction.frappe.apres_defi');

    expect((int) $ctx['fouilleur']->fresh()->pv_body)->toBe($pvFouilleur, 'le fouilleur est sauf');
});

it('un Défi EXPIRÉ est rattrapé à la lecture d\'état : la bête frappe le fouilleur, et le journal le dit', function () {
    $ctx = demarrerDefiDuSurgissement();
    fouilleDuDefi();

    // La fenêtre est passée sans réponse : on la rend expirée, comme le temps le ferait.
    $attente = $ctx['etatChevalier']->fresh()->reaction_en_attente;
    $ctx['etatChevalier']->fresh()->update([
        'reaction_en_attente' => [...$attente, 'expire_a' => now()->subMinute()->toIso8601String()],
    ]);

    desFiges(array_fill(0, 40, 1));
    $pvAvant = (int) $ctx['fouilleur']->fresh()->pv_body;

    // Une lecture d'état fait purger l'expiration (GroupeController::etat).
    test()->getJson('/api/groupes/table-1/etat')->assertOk();

    expect((int) $ctx['fouilleur']->fresh()->pv_body)->toBeLessThan($pvAvant, 'le coup a porté')
        ->and($ctx['etatChevalier']->fresh()->reaction_en_attente)->toBeNull();

    $frappes = App\Models\Evenement::where('groupe_id', $ctx['groupe']->id)->get()
        ->map(fn ($e) => is_string($e->payload) ? json_decode($e->payload, true) : $e->payload)
        ->filter(fn ($p) => ($p['apres_defi'] ?? null) === 'expire');

    expect($frappes)->toHaveCount(1, 'la frappe est journalisée avec sa raison');
});

it('un errant dont la frappe attend le Chevalier ne joue PAS au tour des monstres', function () {
    $ctx = demarrerDefiDuSurgissement();
    $resultat = fouilleDuDefi();

    $errant = App\Models\InstanceMonstre::findOrFail($resultat['monstre']['instance_id']);
    $etatF = $ctx['etatFouilleur']->fresh();

    // Au contact ORTHOGONAL : c'est le seul contact qu'un monstre ordinaire frappe depuis son tour.
    // Sinon le test ne prouverait rien — la bête se contenterait de marcher.
    expect(abs((int) $errant->position_x - (int) $etatF->position_x) + abs((int) $errant->position_y - (int) $etatF->position_y))
        ->toBe(1, 'précondition : la bête est orthogonalement au contact du fouilleur');

    $pvFouilleur = (int) $ctx['fouilleur']->fresh()->pv_body;

    // Les deux héros passent : le round se termine, et la phase des monstres joue.
    desFiges(array_fill(0, 60, 1));
    test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $this->actingAs($ctx['bob'], 'joueur');
    desFiges(array_fill(0, 60, 1));
    $reponse = test()->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $frappesDeLErrant = collect($reponse->json('resultat.tour_monstres.actions') ?? [])
        ->where('type', 'attaque_monstre')
        ->where('instance_id', $errant->id);

    expect($frappesDeLErrant)->toHaveCount(0, 'sa frappe est DUE, et sera donnée à la réponse — pas deux fois')
        ->and((int) $ctx['fouilleur']->fresh()->pv_body)->toBe($pvFouilleur);
});

it('le FIL dit que la bête attend le Chevalier, puis pourquoi elle frappe le fouilleur après son refus', function () {
    $ctx = demarrerDefiDuSurgissement();
    fouilleDuDefi();

    $textes = fn () => array_column(app(App\Partie\EtatGroupe::class)->payload($ctx['groupe']->fresh())['journal_combat'], 'texte');

    expect(implode(' | ', $textes()))->toContain('attend la réponse du Chevalier');

    desFiges(array_fill(0, 40, 1));
    $this->actingAs($ctx['bob'], 'joueur')
        ->postJson('/api/groupes/table-1/reaction', ['personnage_id' => $ctx['chevalier']->id, 'accepte' => false])
        ->assertOk();

    // Relu depuis la base, comme une manette qui se reconnecte : la frappe est au fil, avec sa cause.
    expect(implode(' | ', $textes()))->toContain('Le Chevalier refuse le défi : la bête se retourne contre Albrecht');
});

it('la TABLE voit la frappe d\'un défi REFUSÉ : une scène d\'attaque, même si la réponse n\'est pas active', function () {
    $ctx = demarrerDefiDuSurgissement();
    fouilleDuDefi();

    desFiges(array_fill(0, 40, 1));
    $reponse = $this->actingAs($ctx['bob'], 'joueur')
        ->postJson('/api/groupes/table-1/reaction', ['personnage_id' => $ctx['chevalier']->id, 'accepte' => false])
        ->assertOk()->json('reaction');

    expect($reponse['active'])->toBeFalse();

    $scenes = app(App\Partie\SceneDeTable::class)->depuisReaction($reponse, $ctx['chevalier']);

    expect($scenes)->not->toBeEmpty('une réaction refusée qui frappe a sa scène')
        ->and($scenes[0]['genre'])->toBe('attaque');
});
