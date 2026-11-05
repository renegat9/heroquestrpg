<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\CartesDread;
use App\Partie\MoteurDread;
use App\Partie\Sauvegarde;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * UNE FOIS PAR QUÊTE, POUR TOUS LES LANCEURS (René, 2026-10-31).
 *
 * « Each monster may cast each spell they know once per quest, regardless of if another monster has already cast
 * that spell » (F9907 p. 4 ; F6646 p. 4 ; FL p. 6). Avant, seuls les sorciers nommés (`sorts_uniques`) la suivaient :
 * un sous-boss générique relançait son Tourmente à chaque tour, dans le budget de son palier.
 *
 *  1. Un sous-boss à UN sort (Tourmente) la lance une fois dans la quête, jamais deux — son budget reste à un.
 *  2. Un sous-boss à deux sorts les lance chacun une fois, puis n'en lance plus.
 *  3. Le sort « à volonté » (`sort_a_volonte`) se répète sans rien dépenser, et n'entre jamais au compteur.
 *  4. La réserve de cartes (Nexrael) n'est pas bridée par le compteur : une carte par Explosion mentale.
 *  5. Une reprise de partie (snapshot) garde les sorts déjà lancés.
 *  6. REGISTRE : « à volonté » et réserve ne sont portés que par les figures dont le texte l'écrit.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MobilierSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/**
 * Une quête à DEUX héros (Albrecht, Brunhild) : le monstre, réduit à `$repertoire` (sans archétype, sans capacité
 * autre que `$capacites`), est posé au contact d'Albrecht. Deux héros pour qu'un Tourmente ne fasse jamais rejouer
 * la phase des monstres (`RoundSansHerosTest`).
 *
 * @param  list<string>  $repertoire
 * @param  array<string, mixed>  $capacites
 * @return array<string, mixed>
 */
function sufDeuxHeros(string $nomMonstre, array $repertoire, array $capacites = []): array
{
    $alice = connecterJoueur('alice');
    $bob = connecterJoueur('bob');
    $groupe = creerGroupe();
    $h1 = creerHeros($alice, $groupe, 'Albrecht', 1);
    $h2 = creerHeros($bob, $groupe, 'Brunhild', 2);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $catalogue = Monstre::where('nom_base', $nomMonstre)->firstOrFail();
    $catalogue->update(['sorts_dread' => $repertoire, 'archetype_lanceur' => null, 'capacites' => $capacites]);

    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update([
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_body_max' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'etat' => 'actif',
        'elite' => false,
    ]);
    $instance->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($instance, $quete);

    $etat1 = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h1->id)->firstOrFail();
    $etat2 = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $h2->id)->firstOrFail();

    $contact = caseAdjacenteLibre($quete, (int) $etat1->position_x, (int) $etat1->position_y);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);

    $voisine = caseAdjacenteLibre($quete, (int) $etat1->position_x, (int) $etat1->position_y);
    $etat2->update(['position_x' => $voisine['x'], 'position_y' => $voisine['y']]);

    return [
        'alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'quete' => $quete->refresh(),
        'instance' => $instance->refresh()->load('monstre'), 'heros1' => $h1, 'etat1' => $etat1->refresh(),
    ];
}

/**
 * Une RONDE des deux héros sur les vraies routes : chacun qui a encore un tour passe (`attendre`) ; celui qui clôt la
 * ronde déclenche la phase des monstres. Les dés sont figés à 4 (bouclier blanc : ni crâne, ni rupture). Un héros sans
 * menu (422) est étourdi : il est sauté. Rend les actions des monstres de la ronde.
 *
 * @param  array<string, mixed>  $ctx
 * @return list<array<string, mixed>>
 */
function sufRonde(array $ctx): array
{
    $actions = [];

    foreach (['alice', 'bob'] as $qui) {
        desFiges(array_fill(0, 300, 4));

        $reponse = test()->actingAs($ctx[$qui], 'joueur')
            ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre']);

        if ($reponse->status() === 422) {
            continue;
        }

        $reponse->assertStatus(202);
        $actions = [...$actions, ...collect($reponse->json('resultat.tour_monstres.actions'))->all()];
    }

    return $actions;
}

/** Les noms des sorts de Dread lancés dans une liste d'actions. @return list<string> */
function sufSortsJoues(array $actions): array
{
    return collect($actions)->pluck('sort')->filter()->values()->all();
}

// ==================================================================
// 1. UN SOUS-BOSS, UN SORT — Tourmente une fois, jamais deux
// ==================================================================

it('un sous-boss générique à Tourmente la lance UNE fois dans la quête, jamais deux — et le tour d\'après, il fait autre chose', function () {
    // Garde-mage (sous-boss, budget 2) réduit à son seul Tourmente : avant la règle, deux Tourmentes sur deux tours.
    $ctx = sufDeuxHeros('Garde-mage', ['Tourmente']);
    expect((int) $ctx['instance']->usages_dread)->toBe(2);

    $premier = sufRonde($ctx);
    expect(sufSortsJoues($premier))->toBe(['Tourmente']);

    $lances = sufSortsJoues($premier);

    for ($tour = 0; $tour < 3; $tour++) {
        $actions = sufRonde($ctx);
        $lances = [...$lances, ...sufSortsJoues($actions)];

        // Le tour d'après, il n'a plus de sort : il attaque ou se déplace, comme une créature ordinaire.
        expect(collect($actions)->whereIn('type', ['attaque_monstre', 'deplacement_monstre'])->isNotEmpty())->toBeTrue();
    }

    expect($lances)->toBe(['Tourmente'])
        ->and((int) $ctx['instance']->fresh()->usages_dread)->toBe(1)
        ->and((array) $ctx['instance']->fresh()->sorts_dread_lances)->toBe(['Tourmente']);
});

// ==================================================================
// 2. UN SOUS-BOSS, DEUX SORTS — chacun une fois, puis plus rien
// ==================================================================

it('un sous-boss à deux sorts les lance chacun UNE fois, puis n\'en lance plus — le budget (2) est épuisé par ces deux-là', function () {
    $ctx = sufDeuxHeros('Garde-mage', ['Tourmente', 'Frayeur']);

    $lances = [];

    for ($tour = 0; $tour < 4; $tour++) {
        $lances = [...$lances, ...sufSortsJoues(sufRonde($ctx))];
    }

    expect(collect($lances)->sort()->values()->all())->toBe(['Frayeur', 'Tourmente'])
        ->and((int) $ctx['instance']->fresh()->usages_dread)->toBe(0);
});

// ==================================================================
// 3. « À VOLONTÉ » — se répète, ne dépense rien, n'entre jamais au compteur
// ==================================================================

it('le sort `sort_a_volonte` se répète chaque tour, ne consomme aucun usage et n\'entre jamais au compteur', function () {
    // Même fiche, sort déclaré « à volonté » : c'est le seul moyen de le lancer plus d'une fois, et il ne doit rien coûter.
    $ctx = sufDeuxHeros('Garde-mage', ['Tourmente'], ['sort_a_volonte' => ['sort' => 'Tourmente']]);

    $lances = [];

    for ($tour = 0; $tour < 3; $tour++) {
        $lances = [...$lances, ...sufSortsJoues(sufRonde($ctx))];
    }

    // Deux héros étourdis peuvent faire rejouer la phase : on borne, on ne compte pas exactement.
    expect(count($lances))->toBeGreaterThanOrEqual(2)
        ->and(array_unique($lances))->toBe(['Tourmente'])
        ->and((int) $ctx['instance']->fresh()->usages_dread)->toBe(2)
        ->and((array) $ctx['instance']->fresh()->sorts_dread_lances)->toBe([]);
});

// ==================================================================
// 4. RÉSERVE DE CARTES — la règle « une fois » ne la bride pas
// ==================================================================

it('la réserve de cartes de Nexrael n\'est pas bridée par la règle : une carte par Explosion mentale, deux tours de suite', function () {
    // Nexrael au contact d'Albrecht (un seul héros, comme `SortsOgreHordeTest`). Dés : Nexrael 3 crânes, Albrecht 1.
    $ctx = demarrerQueteAvecMonstre('Nexrael');
    ['heros' => $heros, 'instance' => $nexrael, 'alice' => $alice] = $ctx;

    expect(CartesDread::restantes($nexrael, 'Explosion mentale'))->toBe(4);

    $lances = [];

    for ($tour = 0; $tour < 2; $tour++) {
        // Albrecht garde du Mind (2) : Mind Burst ne vise qu'un héros qui en a encore. `refresh()` d'abord : l'objet
        // garde sinon la valeur qu'il connaît (2), et Eloquent n'écrit rien sans changement.
        $heros->refresh()->update(['pv_mind' => 2]);
        desFiges([1, 1, 1, 4, 4, 1, 4, ...array_fill(0, 300, 4)]);

        $reponse = test()->actingAs($alice, 'joueur')
            ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
            ->assertStatus(202);

        $lances = [...$lances, ...sufSortsJoues(collect($reponse->json('resultat.tour_monstres.actions'))->all())];
    }

    expect($lances)->toBe(['Explosion mentale', 'Explosion mentale'])
        ->and(CartesDread::restantes($nexrael->fresh(), 'Explosion mentale'))->toBe(2)
        ->and((array) $nexrael->fresh()->sorts_dread_lances)->toBe([]);
});

// ==================================================================
// 5. REPRISE — les sorts déjà lancés restent lancés
// ==================================================================

it('une reprise de partie garde les sorts déjà lancés : le sous-boss ne relance pas son Tourmente', function () {
    $ctx = sufDeuxHeros('Garde-mage', ['Tourmente']);
    sufRonde($ctx);
    expect((array) $ctx['instance']->fresh()->sorts_dread_lances)->toBe(['Tourmente']);

    $snapshot = app(Sauvegarde::class)->snapshotter($ctx['groupe']->fresh(), 'nouveau_tour');

    // On efface l'état de la rencontre, puis on restaure le snapshot : la liste et le budget reviennent tels quels.
    $ctx['instance']->fresh()->update(['sorts_dread_lances' => [], 'usages_dread' => 2]);
    app(Sauvegarde::class)->restaurer($ctx['groupe']->fresh(), $snapshot);

    $restaure = InstanceMonstre::findOrFail($ctx['instance']->id);

    expect((array) $restaure->sorts_dread_lances)->toBe(['Tourmente'])
        ->and((int) $restaure->usages_dread)->toBe(1);

    $lances = [];

    for ($tour = 0; $tour < 2; $tour++) {
        $lances = [...$lances, ...sufSortsJoues(sufRonde($ctx))];
    }

    expect($lances)->not->toContain('Tourmente');
});

// ==================================================================
// 6. REGISTRE — « à volonté » et réserve, testés DANS LES DEUX SENS
// ==================================================================

it('REGISTRE : « à volonté » (`sort_a_volonte`) n\'est porté que par les deux figures dont le texte l\'écrit', function () {
    $porteurs = Monstre::all()
        ->filter(fn (Monstre $m) => array_key_exists('sort_a_volonte', (array) $m->capacites))
        ->pluck('nom_base')->sort()->values()->all();

    // F9907 p. 20 : Bloomrot Scion, « may cast the Channel Dread spell at will » ; First Light, carte Dragon
    // (« may cast Ball of Flame at will », lu sur la carte en 2026-09-30 — absent du texte extrait du livret).
    expect($porteurs)->toBe(['Dragon', 'Scion Fleur-Pourrie']);
});

it('REGISTRE : la réserve de cartes (`reserve_cartes`) n\'est portée que par les trois Sorciers d\'Against the Ogre Horde', function () {
    $porteurs = Monstre::all()
        ->filter(fn (Monstre $m) => array_key_exists('reserve_cartes', (array) $m->capacites))
        ->pluck('nom_base')->sort()->values()->all();

    // F9528 p. 31 (Nexrael, « uses a reserve of 4 cards »), p. 35 (Festral, « uses 3 cards for each spell »),
    // p. 37 (Xenloth, « He has 5 cards for each spell »).
    expect($porteurs)->toBe(['Festral', 'Nexrael', 'Xenloth']);
});
