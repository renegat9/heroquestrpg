<?php

declare(strict_types=1);

use App\Engine\ReactionEffet;
use App\Events\ReactionProposee;
use App\Models\Sort;
use App\Partie\MoteurDegats;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * Réaction HORS TOUR — la seule action du jeu qui arrive pendant le tour de
 * quelqu'un d'autre (*Dark Wings* du Warlock, *Twisting Torrent* du Moine).
 *
 * La phase des monstres se résout dans la requête d'un AUTRE joueur, à
 * l'intérieur d'une transaction : impossible de la suspendre pour interroger un
 * téléphone. Le coup est donc appliqué, puis la question posée — l'ordre même
 * de la table, où l'on annonce les dégâts avant que le joueur dise « j'annule ».
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/** Donne au héros un sort réactif disponible, façon *Twisting Torrent*. */
function armerReaction(App\Models\Personnage $heros): Sort
{
    $sort = Sort::query()->firstOrFail();
    $sort->update(['effet' => [...$sort->effet, 'reaction' => [
        'sur' => ReactionEffet::SUR_DEGATS_SUBIS,
        'action' => ReactionEffet::ANNULE_DEGATS,
    ]]]);
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => true]]);

    return $sort;
}

it('propose la réaction au joueur quand son héros encaisse un coup', function () {
    Event::fake([ReactionProposee::class]);

    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    armerReaction($heros);

    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    // Le coup a bien porté : on ne suspend rien, la résolution continue.
    expect((int) $heros->fresh()->pv_body)->toBe((int) $heros->pv_body_max - 2);

    // …et la proposition attend, en base ET sur le canal privé du joueur.
    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->not->toBeNull();
    Event::assertDispatched(ReactionProposee::class);
});

it('ne propose rien sur une source NON réactive : le rejeton n\'est pas un coup', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    armerReaction($heros);

    // Les cartes parlent d'un coup encaissé pendant le tour d'un ennemi. Les
    // jetons de rejeton sont une hémorragie automatique en fin de son PROPRE
    // tour : les laisser annuler viderait la mécanique du jeton.
    app(MoteurDegats::class)->infligerAHeros($heros, 1, MoteurDegats::SOURCE_REJETON);

    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();
});

it('ne propose rien si le sort réactif est ÉPUISÉ', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $sort = armerReaction($heros);
    $heros->sorts()->updateExistingPivot($sort->id, ['disponible' => false]);

    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();
});

it('rend les dégâts et dépense le sort quand le joueur ACCEPTE', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $sort = armerReaction($heros);
    $max = (int) $heros->pv_body_max;

    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id,
        'accepte' => true,
    ])->assertOk()->assertJsonPath('reaction.active', true);

    expect((int) $heros->fresh()->pv_body)->toBe($max)
        ->and($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull()
        // Dépensé : c'est ce qui empêche d'annuler tous les coups de la quête.
        ->and($heros->sorts()->wherePivot('disponible', false)->where('sorts.id', $sort->id)->exists())
        ->toBeTrue();
});

it('laisse le coup et GARDE le sort quand le joueur refuse', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $sort = armerReaction($heros);
    $max = (int) $heros->pv_body_max;

    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id,
        'accepte' => false,
    ])->assertOk()->assertJsonPath('reaction.active', false);

    expect((int) $heros->fresh()->pv_body)->toBe($max - 2)
        ->and($heros->sorts()->wherePivot('disponible', true)->where('sorts.id', $sort->id)->exists())
        ->toBeTrue();

    // La proposition est consommée dans les deux cas : la laisser en place
    // ferait ressortir la feuille au prochain rafraîchissement.
    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();
});

it('RELÈVE un héros tombé si la réaction annule le coup qui l\'a mis à terre', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    armerReaction($heros);

    $heros->update(['pv_body' => 1]);
    app(MoteurDegats::class)->infligerAHeros($heros, 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);
    $ctx['etatHeros']->update(['tombe' => true]);

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id,
        'accepte' => true,
    ])->assertOk();

    // Le coup n'a pas eu lieu : il ne doit pas rester à terre pour rien.
    expect((int) $heros->fresh()->pv_body)->toBe(1)
        ->and((bool) $ctx['etatHeros']->fresh()->tombe)->toBeFalse();
});

it('refuse de réagir pour le héros d\'un AUTRE joueur', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    armerReaction($heros);
    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    $bob = App\Auth\JoueurAuthentifiable::create([
        'pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret',
    ]);
    creerHeros($bob, $ctx['groupe'], 'Brunhilde', 2);
    test()->actingAs($bob, 'joueur');

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id,
        'accepte' => true,
    ])->assertStatus(422);
});

it('422 quand aucune réaction n\'attend', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $ctx['heros']->id,
        'accepte' => true,
    ])->assertStatus(422);
});

it('expose la proposition dans /etat — un rafraîchissement ne la perd pas', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    armerReaction($heros);
    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    $etat = app(App\Partie\EtatGroupe::class)->payload($ctx['groupe']->fresh());

    $entite = collect($etat['entites'])->firstWhere('id', $heros->id);

    expect($entite['reaction_en_attente'])->not->toBeNull()
        ->and($entite['reaction_en_attente']['degats'])->toBe(2);
});

it('INÉBRANLABLE ne se propose QUE sur un coup mortel, et pose un plancher à 1 PV', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    $noeud = App\Models\Competence::where('classe', 'chevalier')->where('nom', 'Inébranlable')->firstOrFail();
    $heros->competences()->syncWithoutDetaching([$noeud->id]);
    donnerBouclier($heros);

    // Coup NON mortel : rien ne doit être proposé. Une capacité « once per
    // quest » gaspillée sur une égratignure serait pire que pas de capacité.
    $heros->update(['pv_body' => 5]);
    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    expect($ctx['etatHeros']->fresh()->reaction_en_attente)->toBeNull();

    // Coup mortel : la proposition arrive.
    app(MoteurDegats::class)->infligerAHeros($heros->fresh(), 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    $attente = $ctx['etatHeros']->fresh()->reaction_en_attente;

    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe('plancher_pv');

    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $heros->id, 'accepte' => true,
    ])->assertOk();

    // ⚠ UN seul PV, pas la restitution du coup : « instead reduce them to 1 ».
    expect((int) $heros->fresh()->pv_body)->toBe(1)
        ->and((bool) $ctx['etatHeros']->fresh()->tombe)->toBeFalse();
});

it('PARADE AU BOUCLIER est proposée au VOISIN, pas à la victime', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $victime = $ctx['heros'];

    $bob = App\Auth\JoueurAuthentifiable::create([
        'pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret',
    ]);
    $chevalier = creerHeros($bob, $ctx['groupe'], 'Roland', 2, ['classe' => 'chevalier']);
    donnerBouclier($chevalier);

    // On le place AU CONTACT de la victime.
    $etatVictime = $ctx['etatHeros'];
    $etatChevalier = App\Models\EtatPersonnageQuete::create([
        'personnage_id' => $chevalier->id, 'quete_id' => $ctx['quete']->id,
        'position_x' => (int) $etatVictime->position_x + 1, 'position_y' => $etatVictime->position_y,
    ]);

    app(MoteurDegats::class)->infligerAHeros($victime, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    // ⚠ La proposition est déposée chez le PROTECTEUR, pas chez le blessé :
    // c'est lui qui décide de couvrir son compagnon.
    expect($etatVictime->fresh()->reaction_en_attente)->toBeNull();

    $attente = $etatChevalier->fresh()->reaction_en_attente;

    expect($attente)->not->toBeNull()
        ->and($attente['action'])->toBe('annule_degats_voisin')
        ->and($attente['victime_id'])->toBe($victime->id);

    $max = (int) $victime->pv_body_max;

    test()->actingAs($bob, 'joueur');
    $this->postJson('/api/groupes/table-1/reaction', [
        'personnage_id' => $chevalier->id, 'accepte' => true,
    ])->assertOk();

    // Les PV rendus vont à la VICTIME, pas au protecteur.
    expect((int) $victime->fresh()->pv_body)->toBe($max);
});

it('refuse la parade à un Chevalier SANS bouclier', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $victime = $ctx['heros'];

    $bob = App\Auth\JoueurAuthentifiable::create([
        'pseudo' => 'bob2', 'identifiant' => 'bob2', 'mot_de_passe' => 'secret',
    ]);
    $chevalier = creerHeros($bob, $ctx['groupe'], 'Sans-écu', 2, ['classe' => 'chevalier']);
    // Aucun bouclier : « Requires shield » sur la carte.

    $etatVictime = $ctx['etatHeros'];
    $etatChevalier = App\Models\EtatPersonnageQuete::create([
        'personnage_id' => $chevalier->id, 'quete_id' => $ctx['quete']->id,
        'position_x' => (int) $etatVictime->position_x + 1, 'position_y' => $etatVictime->position_y,
    ]);

    app(MoteurDegats::class)->infligerAHeros($victime, 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);

    expect($etatChevalier->fresh()->reaction_en_attente)->toBeNull();
});

/** Équipe un bouclier — deux capacités du Chevalier l'exigent. */
function donnerBouclier(App\Models\Personnage $heros): void
{
    $bouclier = App\Models\Objet::where('nom', 'Bouclier')->firstOrFail();

    App\Models\Inventaire::create([
        'personnage_id' => $heros->id, 'objet_id' => $bouclier->id,
        'quantite' => 1, 'emplacement' => 'arme_secondaire',
    ]);
}
