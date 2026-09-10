<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Sort;
use App\Partie\MoteurDegats;
use App\Partie\Talents;
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
 * PHASE 1 du plan glace (docs/plan-glace-et-degats-mind.md) : le PRODUCTEUR
 * qui manquait aux dégâts de Mind. `ResolveurTour` le disait lui-même à deux
 * endroits — la branche Mind de `resoudreRelever()` est « correcte mais
 * dormante » (rien ne réduit `pv_mind`), et `restaure_pv_mind` (Récupération
 * Psychique) « rendra 0 tant que rien ne saura entamer l'esprit ».
 *
 * `MoteurDegats::infligerMindAHeros()` est une méthode SŒUR de
 * `infligerAHeros()`, pas un paramètre `$jauge` : ces tests prouvent ce
 * qu'elle reprend (la mémoire par source) et ce qu'elle laisse DÉLIBÉRÉMENT
 * de côté (réduction de dégâts, réactions hors tour) — l'absence est le
 * comportement attendu, pas un oubli.
 *
 * Aucun sort ne l'appelle encore en jeu : Gel de l'Esprit (Mind Freeze) est
 * une phase à part du plan (phase 2). Les tests 1 à 4 appellent donc le
 * producteur directement, exactement comme il sera appelé le jour où son
 * premier lecteur réel arrivera. Le test 5 est la preuve que ça en valait la
 * peine : un lecteur DÉJÀ écrit (`restaure_pv_mind`) cesse de rendre 0.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : les parchemins dérivent des sorts
    // (`ObjetSeeder` boucle `Sort::all()`), inverser l'ordre seederait un
    // catalogue sans un seul « Parchemin : … ».
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class, ObjetSeeder::class]);
});

it('retire des points de Mind et les mémorise sous SA PROPRE source', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];
    $avant = (int) $heros->pv_mind; // 2 par défaut (creerHeros)

    $subis = app(MoteurDegats::class)->infligerMindAHeros(
        $heros, 1, MoteurDegats::SOURCE_SORT_DREAD_MIND,
    );

    expect($subis)->toBe(1)
        ->and((int) $heros->fresh()->pv_mind)->toBe($avant - 1);

    $etat->refresh();

    // Clé DISTINCTE de tout ce que la branche Body écrirait : mélanger les
    // deux jauges dans `degats_subis` ferait rendre à la Plume anti-poison
    // des PV de Body pour des points d'esprit perdus.
    expect((array) $etat->degats_subis)->toBe([MoteurDegats::SOURCE_SORT_DREAD_MIND => 1])
        ->and($etat->dernier_degat)->toBe(['source' => MoteurDegats::SOURCE_SORT_DREAD_MIND, 'montant' => 1])
        ->and($etat->tombe)->toBeFalse(); // 2 → 1 : encore debout
});

it('n\'applique PAS reduction_degats au Mind (Cuir tanné ne protège que le Body)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    // Cuir tanné (barbare) : « chaque coup que tu subis t'inflige 1 dégât de
    // moins ». La carte dit « damage » dans un jeu où le seul dégât physique
    // existe — l'étendre au Mind serait inventer.
    donnerTalent($heros, 'Cuir tanné');

    expect(app(Talents::class)->valeur($heros->fresh(), 'reduction_degats'))->toBe(1);

    $avant = (int) $heros->pv_mind;
    $subis = app(MoteurDegats::class)->infligerMindAHeros(
        $heros, 1, MoteurDegats::SOURCE_SORT_DREAD_MIND,
    );

    // Si la réduction s'appliquait, 1 dégât − 1 talent = 0 : le test la
    // détecterait immédiatement.
    expect($subis)->toBe(1)
        ->and((int) $heros->fresh()->pv_mind)->toBe($avant - 1);
});

it('ne propose AUCUNE réaction hors tour sur un dégât de Mind, même à 0 PV avec de quoi se soigner', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];

    // Une potion de soin en poche : si `soin_urgence` pouvait s'offrir sur une
    // chute d'esprit comme elle le fait sur une chute de Body, elle aurait de
    // quoi répondre. La preuve porte donc sur l'ABSENCE délibérée de l'appel
    // à `MoteurReactions::proposer()`, pas sur l'absence de ressource.
    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Potion de soin')->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);

    app(MoteurDegats::class)->infligerMindAHeros(
        $heros, (int) $heros->pv_mind, MoteurDegats::SOURCE_SORT_DREAD_MIND,
    );

    expect((int) $heros->fresh()->pv_mind)->toBe(0)
        ->and($etat->fresh()->reaction_en_attente)->toBeNull();
});

it('fait TOMBER le héros à 0 Mind — symétrique de la chute à 0 Body (arbitrage de René, 2026-09-06)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $etat = $ctx['etatHeros'];

    expect($etat->tombe)->toBeFalse();

    app(MoteurDegats::class)->infligerMindAHeros(
        $heros, (int) $heros->pv_mind, MoteurDegats::SOURCE_SORT_DREAD_MIND,
    );

    expect((int) $heros->fresh()->pv_mind)->toBe(0)
        ->and($etat->fresh()->tombe)->toBeTrue();

    // `verdictDeChute()` ne lit QUE la colonne `tombe`, jamais `pv_body`
    // directement (voir son docblock) : un groupe entier tombé d'esprit est
    // donc déjà un TPK par construction, sans qu'une ligne y ait été ajoutée.
    $verdict = app(App\Partie\ResolveurTour::class)->verdictDeChute($ctx['groupe']->fresh(), $ctx['quete']->fresh());

    expect($verdict)->toBe('tpk');
});

it('RÉVEILLE LE LECTEUR DORMANT : Récupération Psychique rend autre chose que 0 après un dégât de Mind', function () {
    // C'est LA preuve de la phase 1 : `ResolveurTour` disait lui-même que ce
    // parchemin « rendra 0 tant que rien ne saura entamer l'esprit ». Rien ne
    // l'appelle encore en jeu (Gel de l'Esprit est la phase 2 du plan), donc
    // le producteur est actionné directement — exactement comme le fera son
    // premier appelant réel.
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $alice = $ctx['alice'];
    $groupe = $ctx['groupe'];
    $mage = $ctx['heros'];

    app(MoteurDegats::class)->infligerMindAHeros($mage, 1, MoteurDegats::SOURCE_SORT_DREAD_MIND);
    expect((int) $mage->fresh()->pv_mind)->toBe((int) $mage->pv_mind_max - 1);

    $sort = Sort::where('nom', 'Récupération Psychique')->firstOrFail();
    $ligne = Inventaire::create([
        'personnage_id' => $mage->id,
        'objet_id' => Objet::where('nom', "Parchemin : {$sort->nom}")->value('id'),
        'emplacement' => 'consommable',
        'quantite' => 1,
    ]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $mage->id);
    $options = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options']);
    $option = $options->firstWhere('id', 'lire_parchemin');
    $entree = collect($option['parametres']['parchemins'] ?? [])->firstWhere('cle', "parchemin:{$ligne->id}");

    expect($option)->not->toBeNull('l\'option « Lire un parchemin » doit être proposée au menu')
        ->and($entree)->not->toBeNull('le parchemin fraîchement acquis doit figurer dans la liste');

    test()->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lire_parchemin',
        'parametres' => ['cle' => $entree['cle'], 'cible_id' => $mage->id, 'cible_type' => 'heros'],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.type', 'parchemin')
        // LA preuve : plus 0, le montant réellement perdu.
        ->assertJsonPath('resultat.soin_pv_mind', 1);

    expect((int) $mage->fresh()->pv_mind)->toBe((int) $mage->pv_mind_max);
});
