<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Quete;
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
 * Chantier 3a (2026-10-04, décision de René : « un allié est TOUJOURS joué
 * par son joueur ») — l'allié joue DANS le tour du héros qui le contrôle,
 * juste après lui, depuis SA manette (second menu, `allie_id` non-null).
 * Le cœur mécanique (déplacement/attaque de l'allié) est couvert par
 * `MercenairesTest.php` ; ce fichier couvre le POINT DE PASSAGE
 * (`OrdreDuTour::acteurActif()`), le contrôleur choisi au recrutement, et la
 * décision nommée « contrôleur tombé ».
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

it('assigne le CONTRÔLEUR choisi au recrutement, pas toujours le premier héros', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $branwen = creerHeros($alice, $groupe, 'Branwen', 2);
    $groupe->update(['or' => 500]);

    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $reponse = $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => $merc->id,
        'personnage_id' => $branwen->id,
    ])->assertStatus(201);

    expect($reponse->json('recrue.recruteur_personnage_id'))->toBe($branwen->id);

    $allie = $groupe->fresh()->mercenaires()->first();
    expect($allie->recruteur_personnage_id)->toBe($branwen->id);
});

it('refuse un personnage_id qui n\'est pas un héros actif de CE joueur', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $bob = \App\Models\Joueur::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $intrus = \App\Models\Personnage::create([
        'joueur_id' => $bob->id, 'groupe_actif_id' => $groupe->id, 'nom' => 'Intrus',
        'classe' => 'barbare', 'niveau' => 1, 'attribut_body' => 4, 'attribut_mind' => 2,
        'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    // Non attaché au groupe : ni pivot ni héros actif de ce groupe. La
    // session reste CELLE D'ALICE (`connecterJoueur` au tout début du test) :
    // c'est bien elle qui tente de recruter, en visant le personnage de bob.
    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();

    $this->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => $merc->id,
        'personnage_id' => $intrus->id,
    ])->assertStatus(422);
});

/**
 * Démarre une quête à DEUX héros de DEUX JOUEURS distincts (le cas réaliste :
 * chaque héros a sa propre manette), un seul monstre actif, et un allié
 * recruté pour le premier héros (Albrecht, contrôlé par alice).
 *
 * ⚠ La session active À LA FIN de cet appel est celle d'ALICE — pas celle de
 * bob : chaque test bascule explicitement (`actingAs`) pour jouer le tour de
 * Branwen, exactement comme deux téléphones distincts le feraient.
 *
 * @return array{0: \App\Models\Groupe, 1: Quete, 2: \App\Models\Personnage, 3: \App\Models\Personnage, 4: GroupeMercenaire, 5: \App\Auth\JoueurAuthentifiable, 6: \App\Auth\JoueurAuthentifiable}
 */
function queteADeuxHerosEtUnAllie(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = \App\Auth\JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $branwen = creerHeros($bob, $groupe, 'Branwen', 2);

    $groupe->update(['or' => 500]);

    // Toujours alice ici (le dernier `actingAs` est celui de `connecterJoueur`
    // ci-dessus) : c'est elle qui recrute, pour Albrecht.
    $merc = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    test()->postJson('/api/groupes/table-1/mercenaires', [
        'mercenaire_id' => $merc->id,
        'personnage_id' => $albrecht->id,
    ])->assertStatus(201);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update(['revele' => true]);

    return [$groupe, $quete, $albrecht, $branwen, $groupe->fresh()->mercenaires()->first(), $alice, $bob];
}

it('un contrôleur TOMBÉ ne bloque jamais le tour : son allié attend, l\'initiative passe au héros suivant', function () {
    [$groupe, $quete, $albrecht, $branwen, $allie, , $bob] = queteADeuxHerosEtUnAllie();

    // Albrecht (contrôleur de l'allié) est tombé AVANT son tour — un héros à
    // terre n'a aucun tour, ni lui ni l'allié qu'il contrôle.
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $albrecht->id)
        ->update(['tombe' => true]);

    $ordreDuTour = app(App\Partie\OrdreDuTour::class);
    $acteur = $ordreDuTour->acteurActif($groupe->fresh());

    // C'est Branwen qui a la main — ni Albrecht (tombé), ni son allié
    // (jamais un transfert de contrôle à un autre joueur).
    expect($acteur)->not->toBeNull()
        ->and($acteur['type'])->toBe('heros')
        ->and($acteur['personnage_id'])->toBe($branwen->id);

    // Branwen peut jouer son tour normalement jusqu'au bout, depuis SA
    // PROPRE manette (bob) — rien ne gèle.
    $this->actingAs($bob, 'joueur');
    desFiges(array_fill(0, 40, 4));
    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Plus personne en attente : ni Branwen (a joué), ni Albrecht (tombé,
    // sauté), ni son allié (jamais atteint tant qu'Albrecht est à terre) —
    // le round s'est bouclé jusqu'à la phase des monstres.
    expect($reponse->json('resultat.tour_monstres'))->not->toBeNull();

    // L'allié n'a pas joué (toujours en attente), et n'a pas bloqué le round.
    expect($allie->fresh()->a_joue)->toBeFalse();
});

it('dès que son héros se relève, l\'allié reprend son tour normal au round suivant', function () {
    [$groupe, $quete, $albrecht, $branwen, $allie, $alice, $bob] = queteADeuxHerosEtUnAllie();

    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $albrecht->id)
        ->update(['tombe' => true]);

    $this->actingAs($bob, 'joueur'); // le tour de Branwen, depuis SA manette
    desFiges(array_fill(0, 80, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Albrecht se relève (hors du flux testé ici : un soin, un autre héros).
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $albrecht->id)
        ->update(['tombe' => false]);

    // Nouveau round : Albrecht a de nouveau la main.
    $acteur = app(App\Partie\OrdreDuTour::class)->acteurActif($groupe->fresh());
    expect($acteur['type'])->toBe('heros')->and($acteur['personnage_id'])->toBe($albrecht->id);

    $this->actingAs($alice, 'joueur'); // retour sur la manette d'Albrecht
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Son allié a maintenant la main, juste après lui.
    $acteur = app(App\Partie\OrdreDuTour::class)->acteurActif($groupe->fresh());
    expect($acteur['type'])->toBe('allie')
        ->and($acteur['allie_id'])->toBe($allie->id)
        ->and($acteur['personnage_id'])->toBe($albrecht->id);
});

it('deux alliés du MÊME héros jouent l\'un après l\'autre, jamais ensemble', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 1000]);

    $fauchard = Mercenaire::where('nom', 'Fauchard')->firstOrFail();
    $eclaireur = Mercenaire::where('nom', 'Éclaireur')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $fauchard->id, 'personnage_id' => $albrecht->id])->assertStatus(201);
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $eclaireur->id, 'personnage_id' => $albrecht->id])->assertStatus(201);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $groupe->refresh();
    $allies = $groupe->mercenaires()->orderBy('id')->get();
    expect($allies)->toHaveCount(2);

    desFiges(array_fill(0, 40, 4));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Le PREMIER allié (ordre d'id) a la main — jamais les deux en même temps.
    $acteur = app(App\Partie\OrdreDuTour::class)->acteurActif($groupe->fresh());
    expect($acteur['type'])->toBe('allie')->and($acteur['allie_id'])->toBe($allies[0]->id);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre_allie'])->assertStatus(202);

    // Puis le SECOND.
    $acteur = app(App\Partie\OrdreDuTour::class)->acteurActif($groupe->fresh());
    expect($acteur['type'])->toBe('allie')->and($acteur['allie_id'])->toBe($allies[1]->id);
});
