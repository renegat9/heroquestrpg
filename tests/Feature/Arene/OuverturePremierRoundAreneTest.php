<?php

declare(strict_types=1);

use App\Events\JournalCombatDiffuse;
use App\Events\SceneTable;
use App\Models\Condition;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * L'OUVERTURE DU ROUND 1, EN ARÈNE (René, 2026-11-12 : « applique en campagne et en arène »).
 *
 * Le round 1 d'un combat d'arène s'ouvre par l'activation d'ouverture du Defender (`ouvrirCombatArene()`, appelée par
 * `DemarreurArene`). Les effets de début de tour des doublures, eux, n'y passaient pas : `ouvrirNouveauTour()` les
 * applique à chaque round SUIVANT, jamais au premier. Le correctif les applique au round 1 avec le MÊME point de passage
 * (`ouvrirTourDesHeros()`), dans le MÊME ordre que les rounds suivants.
 *
 * L'ordre n'est pas une préférence : au round suivant, `ouvrirNouveauTour()` applique les effets des héros AVANT que le
 * Defender n'active sa première figure (la phase des monstres se rejoue ensuite, dans la récursion de `phaseMonstres()`).
 * Le test « référence » ci-dessous fige cet ordre tel qu'il est déjà, et le round 1 doit le reprendre.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

// ------------------------------------------------------------------
// Helpers (préfixés `opa` : les fichiers de test partagent l'espace des fonctions)
// ------------------------------------------------------------------

/**
 * Un combat d'arène AVANT son premier round : deux doublures (Aldra, puis Borin), le Defender donné. `$poseAldra` et
 * `$poseBorin` posent des conditions sur les doublures AVANT `DemarreurArene::demarrer()` — ce que l'ouverture doit lire.
 * `$des`, s'il est donné, fige les dés servis au démarrage (le premier jet est celui du héros maudit, s'il y en a un).
 *
 * @param  list<string>  $defender  noms du catalogue
 * @param  array<string, string>  $poseAldra  nom de condition => source
 * @param  array<string, string>  $poseBorin  nom de condition => source
 * @param  list<int>|null  $des
 * @return array{arene: Groupe, quete: \App\Models\Quete, alice: mixed, bob: mixed, a: Personnage, b: Personnage}
 */
function opaCombatAvantDepart(array $defender, array $poseAldra = [], array $poseBorin = [], ?array $des = null): array
{
    $arenes = app(GroupesArene::class);
    $arene = $arenes->ouvrir();

    $alice = connecterJoueur('alice');
    $ha = Personnage::findOrFail(test()->postJson('/api/personnages', ['nom' => 'Aldra', 'classe' => 'barbare', 'mode' => 'arene'])->json('personnage.id'));
    $a = $arenes->engager($arene, $ha);

    $bob = connecterJoueur('bob');
    $hb = Personnage::findOrFail(test()->postJson('/api/personnages', ['nom' => 'Borin', 'classe' => 'nain', 'mode' => 'arene'])->json('personnage.id'));
    $b = $arenes->engager($arene, $hb);

    foreach ($poseAldra as $nom => $source) {
        $a->conditions()->attach(Condition::where('nom', $nom)->firstOrFail()->id, ['duree' => 0, 'source' => $source]);
    }
    foreach ($poseBorin as $nom => $source) {
        $b->conditions()->attach(Condition::where('nom', $nom)->firstOrFail()->id, ['duree' => 0, 'source' => $source]);
    }

    $ids = array_map(fn (string $nom) => (int) Monstre::where('nom_base', $nom)->value('id'), $defender);

    if ($des !== null) {
        desFiges([...$des, ...array_fill(0, 300, 4)]);
    }

    $quete = app(DemarreurArene::class)->demarrer($arene, $ids);

    return ['arene' => $arene->refresh(), 'quete' => $quete->refresh(), 'alice' => $alice, 'bob' => $bob, 'a' => $a, 'b' => $b];
}

/** Dés servis à la suite, puis des boucliers blancs (4). */
function opaDes(array $valeurs): void
{
    desFiges([...$valeurs, ...array_fill(0, 300, 4)]);
}

/** Les événements du journal de l'arène, dans l'ordre. */
function opaEvenements(Groupe $arene): \Illuminate\Support\Collection
{
    return Evenement::where('groupe_id', $arene->id)->orderBy('sequence')->get();
}

/** Les payloads d'un type donné, dans l'ordre du journal. */
function opaPayloads(Groupe $arene, string $type): array
{
    return opaEvenements($arene)
        ->filter(fn (Evenement $e) => is_array($e->payload) && ($e->payload['type'] ?? null) === $type)
        ->map(fn (Evenement $e) => $e->payload)
        ->values()
        ->all();
}

/** Le premier événement du journal, APRÈS `$depuis`, dont l'acteur est une figure du Defender. */
function opaPremiereActionDuDefender(Groupe $arene, int $depuis): ?Evenement
{
    return opaEvenements($arene)->first(fn (Evenement $e) => $e->sequence > $depuis
        && is_array($e->acteur) && ($e->acteur['type'] ?? null) === 'monstre');
}

/** L'événement de journal d'un type (et d'un payload `action` donné, pour le systeme). */
function opaEvenementSysteme(Groupe $arene, string $action): ?Evenement
{
    return opaEvenements($arene)->first(fn (Evenement $e) => $e->type === 'systeme' && ($e->payload['action'] ?? null) === $action);
}

/** Joue `option` pour le joueur, comme la manette : menu lu, puis choix. */
function opaJouer($joueur, string $identifiant, string $option): void
{
    test()->actingAs($joueur, 'joueur');
    test()->getJson("/api/groupes/{$identifiant}/menu")->assertOk();
    test()->postJson("/api/groupes/{$identifiant}/choix", ['option_id' => $option])->assertSuccessful();
}

// ------------------------------------------------------------------
// 1. Le jet du héros maudit au ROUND 1, avant l'activation du Defender
// ------------------------------------------------------------------

it('ARÈNE — le héros maudit fait son jet au ROUND 1 : il se transforme, et le jet précède l\'activation d\'ouverture du Defender', function () {
    Event::fake([JournalCombatDiffuse::class, SceneTable::class]);
    $c = opaCombatAvantDepart(['Gobelin'], ['Malédiction du loup-garou' => 'sort_dread:Malédiction du loup-garou'], [], [5, 6]);
    $arene = $c['arene'];

    $jets = opaPayloads($arene, 'malediction_jet');

    expect($jets)->toHaveCount(1)
        ->and($jets[0]['personnage_id'])->toBe($c['a']->id)
        ->and($jets[0]['transforme'])->toBeTrue();

    // Ordre : le jet d'abord, puis la première figure du Defender qui s'active.
    $jet = opaEvenements($arene)->first(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'malediction_jet');
    $premiere = opaPremiereActionDuDefender($arene, 0);

    expect($premiere)->not->toBeNull()
        ->and($jet->sequence)->toBeLessThan($premiere->sequence);

    // Le fil le DIT, en direct, sur le canal du groupe — et la table le montre.
    Event::assertDispatched(JournalCombatDiffuse::class,
        fn (JournalCombatDiffuse $e) => $e->groupe->id === $arene->id
            && collect($e->lignes)->pluck('texte')->contains(
                'Aldra, maudit, lance 5 + 6 = 11 sur ses deux dés rouges : il se transforme en loup — Zargon le jouera à son tour',
            ));
    Event::assertDispatched(SceneTable::class,
        fn (SceneTable $e) => $e->groupe->id === $arene->id
            && ($e->scene['titre'] ?? null) === 'Aldra — la malédiction du loup-garou');
});

// ------------------------------------------------------------------
// 2. Référence : le round SUIVANT déjà fait ainsi, et un jet par round
// ------------------------------------------------------------------

it('ARÈNE — un jet PAR ROUND : celui du round 1, puis celui du round 2, qui précède l\'ouverture du Defender (ordre déjà en place)', function () {
    $c = opaCombatAvantDepart(['Gobelin'], ['Malédiction du loup-garou' => 'sort_dread:Malédiction du loup-garou'], [], [2, 3]);
    $arene = $c['arene'];
    $id = $arene->identifiant;

    // Aldra finit son activation, puis Borin : plus personne n'a la main, le Defender referme le round.
    opaJouer($c['alice'], $id, 'attendre');
    opaJouer($c['bob'], $id, 'attendre');

    expect(opaEvenementSysteme($arene, 'nouveau_tour'))->not->toBeNull();

    // Deux jets au total — le round 1 et le round 2, pas trois.
    $jets = opaEvenements($arene)->filter(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'malediction_jet')->values();
    expect($jets)->toHaveCount(2);

    // Au round 2 : le jet précède le marqueur de round, qui précède l'activation d'ouverture du Defender.
    $marqueur = opaEvenementSysteme($arene, 'nouveau_tour');
    $premiere = opaPremiereActionDuDefender($arene, $marqueur->sequence);

    expect($jets[1]->sequence)->toBeLessThan($marqueur->sequence)
        ->and($premiere)->not->toBeNull()
        ->and($marqueur->sequence)->toBeLessThan($premiere->sequence);
});

// ------------------------------------------------------------------
// 3. Un effet de début de tour qui prend le héros au ROUND 1
// ------------------------------------------------------------------

it('ARÈNE — un héros ÉTOURDI avant le premier round perd son tour au ROUND 1 : son compagnon a la main', function () {
    $c = opaCombatAvantDepart(['Gobelin'], ['Étourdi' => 'sort_dread:Tourmente']);
    $id = $c['arene']->identifiant;

    expect(opaPayloads($c['arene'], 'tour_perdu'))->toHaveCount(1)
        ->and(opaPayloads($c['arene'], 'phase_rejouee'))->toHaveCount(0)
        ->and(opaPayloads($c['arene'], 'tour_perdu')[0]['personnage_id'])->toBe($c['a']->id);

    test()->actingAs($c['alice'], 'joueur');
    expect(test()->getJson("/api/groupes/{$id}/menu")->json('menu'))->toBeNull();

    test()->actingAs($c['bob'], 'joueur');
    expect(test()->getJson("/api/groupes/{$id}/menu")->json('personnage_id'))->toBe($c['b']->id);
});

it('ARÈNE — deux héros ÉTOURDIS avant le premier round : aucun ne peut agir, le Defender joue encore, et le round 2 rend la main', function () {
    $c = opaCombatAvantDepart(['Gobelin'], ['Étourdi' => 'sort_dread:Tourmente'], ['Étourdi' => 'sort_dread:Tourmente']);
    $id = $c['arene']->identifiant;

    expect(opaPayloads($c['arene'], 'tour_perdu'))->toHaveCount(2)
        ->and(opaPayloads($c['arene'], 'phase_rejouee'))->toHaveCount(1);

    test()->actingAs($c['alice'], 'joueur');
    expect(test()->getJson("/api/groupes/{$id}/menu")->json('personnage_id'))->toBe($c['a']->id);
});
