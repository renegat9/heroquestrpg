<?php

declare(strict_types=1);

use App\Events\JournalCombatDiffuse;
use App\Events\SceneTable;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Personnage;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * L'OUVERTURE DU ROUND 1, EN CAMPAGNE (René, 2026-11-12 : « applique en campagne et en arène »).
 *
 * Le défaut : les effets de DÉBUT DE TOUR du héros (`ResolveurTour::ouvrirTourDesHeros()` : jet du loup-garou, ruptures de
 * sort de Dread, gel et sa levée, Étourdi, Terreur) ne passaient qu'au passage d'un round au suivant — jamais à l'ouverture
 * du round 1, que `DemarreurQuete::demarrer()` ouvre sans rien appeler. Une malédiction du loup-garou, qui survit d'une
 * quête à l'autre, ne faisait donc pas son jet au premier round de chaque quête.
 *
 * Ces tests jouent le démarrage par la vraie route (`POST /api/groupes/table-1/quetes`) : les effets posés AVANT le
 * démarrage doivent être lus à son ouverture, annoncés (journal + fil direct), et ne jamais être relus au round suivant
 * pour le même round. Les dés sont fixés (`desFiges`) : le démarrage n'en consomme aucun avant le jet du loup-garou
 * (`JEU_ELITE_ACTIF=false` en test).
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

// ------------------------------------------------------------------
// Helpers (préfixés `opr` : les fichiers de test partagent l'espace des fonctions)
// ------------------------------------------------------------------

/** Dés servis à la suite, puis des boucliers blancs (4) : rien d'autre ne se joue au-delà. */
function oprDes(array $valeurs): void
{
    desFiges([...$valeurs, ...array_fill(0, 300, 4)]);
}

/** Pose une condition sur le héros, comme le ferait son origine (sort de Dread, Tourmente…). */
function oprAttacher(Personnage $heros, string $nomCondition, string $source): void
{
    $heros->conditions()->attach(Condition::where('nom', $nomCondition)->firstOrFail()->id, [
        'duree' => 0, 'source' => $source,
    ]);
}

/**
 * Deux héros (Albrecht, puis Brunhild) dans un groupe SANS quête : rien n'est encore démarré. Le joueur d'Albrecht est
 * alice, celui de Brunhild bob.
 *
 * @return array<string, mixed>
 */
function oprDeuxHerosAvantDepart(): array
{
    $alice = connecterJoueur('alice');
    $bob = connecterJoueur('bob');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    $brunhild = creerHeros($bob, $groupe, 'Brunhild', 2);

    return compact('alice', 'bob', 'groupe', 'albrecht', 'brunhild');
}

/** Démarre la quête par la vraie route, en tant que `$joueur`. */
function oprDemarrer(\App\Auth\JoueurAuthentifiable $joueur): void
{
    test()->actingAs($joueur, 'joueur')->postJson('/api/groupes/table-1/quetes')->assertCreated();
}

/** Les payloads journalisés d'un type donné, dans l'ordre du journal. */
function oprPayloads(Groupe $groupe, string $type): array
{
    return Evenement::where('groupe_id', $groupe->id)->orderBy('sequence')->get()
        ->filter(fn (Evenement $e) => is_array($e->payload) && ($e->payload['type'] ?? null) === $type)
        ->map(fn (Evenement $e) => $e->payload)
        ->values()
        ->all();
}

/** Le menu servi à ce joueur (`null` s'il n'a pas la main). */
function oprMenuDe(\App\Auth\JoueurAuthentifiable $joueur): ?array
{
    return test()->actingAs($joueur, 'joueur')->getJson('/api/groupes/table-1/menu')->assertOk()->json();
}

// ------------------------------------------------------------------
// 1. Le jet du loup-garou se fait au ROUND 1
// ------------------------------------------------------------------

it('MALÉDICTION posée avant le démarrage : le héros maudit fait son jet au ROUND 1 — 10 à 12, il se transforme et perd son tour', function () {
    Event::fake([JournalCombatDiffuse::class, SceneTable::class]);
    ['alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'albrecht' => $albrecht, 'brunhild' => $brunhild] = oprDeuxHerosAvantDepart();
    oprAttacher($albrecht, 'Malédiction du loup-garou', 'sort_dread:Malédiction du loup-garou');

    oprDes([5, 6]); // 11 : la transformation
    oprDemarrer($alice);

    $jets = oprPayloads($groupe->fresh(), 'malediction_jet');

    expect($jets)->toHaveCount(1)
        ->and($jets[0]['personnage_id'])->toBe($albrecht->id)
        ->and($jets[0]['total'])->toBe(11)
        ->and($jets[0]['transforme'])->toBeTrue()
        ->and($albrecht->fresh()->conditions()->where('nom', 'Forme de loup')->exists())->toBeTrue();

    // Son tour est perdu : aucun menu pour Albrecht, Brunhild a la main.
    expect(oprMenuDe($alice)['menu'] ?? null)->toBeNull()
        ->and(oprMenuDe($bob)['personnage_id'] ?? null)->toBe($brunhild->id);

    // Le fil DIT le jet (un jet que personne ne voit n'a pas eu lieu pour la table)…
    Event::assertDispatched(JournalCombatDiffuse::class,
        fn (JournalCombatDiffuse $e) => $e->groupe->id === $groupe->id
            && collect($e->lignes)->pluck('texte')->contains(
                'Albrecht, maudit, lance 5 + 6 = 11 sur ses deux dés rouges : il se transforme en loup — Zargon le jouera à son tour',
            ));

    // …et la table le MONTRE, comme un jet de la phase des monstres.
    Event::assertDispatched(SceneTable::class,
        fn (SceneTable $e) => $e->groupe->id === $groupe->id
            && ($e->scene['titre'] ?? null) === 'Albrecht — la malédiction du loup-garou');
});

it('MALÉDICTION posée avant le démarrage : de 2 à 9, le héros reste humain et joue son tour au ROUND 1', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht] = oprDeuxHerosAvantDepart();
    oprAttacher($albrecht, 'Malédiction du loup-garou', 'sort_dread:Malédiction du loup-garou');

    oprDes([2, 3]); // 5 : aucune transformation
    oprDemarrer($alice);

    $jets = oprPayloads($groupe->fresh(), 'malediction_jet');

    expect($jets)->toHaveCount(1)
        ->and($jets[0]['total'])->toBe(5)
        ->and($jets[0]['transforme'])->toBeFalse()
        ->and($albrecht->fresh()->conditions()->where('nom', 'Forme de loup')->exists())->toBeFalse()
        // Albrecht a la main au premier round : c'est SON menu qui s'ouvre.
        ->and(oprMenuDe($alice)['personnage_id'] ?? null)->toBe($albrecht->id);
});

// ------------------------------------------------------------------
// 2. L'Étourdi et « aucun héros ne peut agir » au ROUND 1
// ------------------------------------------------------------------

it('ÉTOURDI posé avant le démarrage : le héros perd son tour au ROUND 1, et son compagnon joue', function () {
    ['alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'albrecht' => $albrecht, 'brunhild' => $brunhild] = oprDeuxHerosAvantDepart();
    oprAttacher($albrecht, 'Étourdi', 'sort_dread:Tourmente');

    oprDemarrer($alice);

    $perdus = oprPayloads($groupe->fresh(), 'tour_perdu');

    expect($perdus)->toHaveCount(1)
        ->and($perdus[0]['personnage_id'])->toBe($albrecht->id)
        ->and($perdus[0]['cause'])->toBe('Étourdi')
        // L'Étourdi est consommé par son tour perdu, et personne d'autre ne saute : aucune phase rejouée.
        ->and($albrecht->fresh()->conditions()->where('nom', 'Étourdi')->exists())->toBeFalse()
        ->and(oprPayloads($groupe->fresh(), 'phase_rejouee'))->toHaveCount(0)
        ->and(oprMenuDe($alice)['menu'] ?? null)->toBeNull()
        ->and(oprMenuDe($bob)['personnage_id'] ?? null)->toBe($brunhild->id);
});

it('ÉTOURDI seul posé avant le démarrage : aucun héros ne peut agir, Zargon joue encore, et le héros agit au round suivant', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    oprAttacher($albrecht, 'Étourdi', 'sort_dread:Tourmente');

    oprDemarrer($alice);

    expect(oprPayloads($groupe->fresh(), 'tour_perdu'))->toHaveCount(1)
        ->and(oprPayloads($groupe->fresh(), 'phase_rejouee'))->toHaveCount(1)
        // Le round suivant rend la main : le menu s'ouvre pour Albrecht.
        ->and(oprMenuDe($alice)['personnage_id'] ?? null)->toBe($albrecht->id);
});

// ------------------------------------------------------------------
// 3. Un effet par round : le round 1 puis le round 2, deux jets, pas trois
// ------------------------------------------------------------------

it('UN JET PAR ROUND : celui du round 1 (au démarrage) puis celui du round 2 — deux au total, pas trois', function () {
    ['alice' => $alice, 'bob' => $bob, 'groupe' => $groupe, 'albrecht' => $albrecht] = oprDeuxHerosAvantDepart();
    oprAttacher($albrecht, 'Malédiction du loup-garou', 'sort_dread:Malédiction du loup-garou');

    oprDes([2, 3]); // round 1 : 5, humain
    oprDemarrer($alice);
    expect(oprPayloads($groupe->fresh(), 'malediction_jet'))->toHaveCount(1);

    // Albrecht finit son tour, puis Brunhild : Zargon joue, et le round 2 s'ouvre.
    oprDes([]);
    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
    oprDes([]);
    test()->actingAs($bob, 'joueur')->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    $ouverts = Evenement::where('groupe_id', $groupe->id)->where('type', 'systeme')->get()
        ->filter(fn (Evenement $e) => ($e->payload['action'] ?? null) === 'nouveau_tour')->count();

    expect($ouverts)->toBe(1)
        ->and(oprPayloads($groupe->fresh(), 'malediction_jet'))->toHaveCount(2);
});

// ------------------------------------------------------------------
// 4. La REPRISE restitue l'état APRÈS l'ouverture : rien n'est perdu, rien n'est relancé
// ------------------------------------------------------------------

it('REPRISE : le redémarrage de la quête restitue l\'état APRÈS l\'ouverture du round 1 — le loup reste loup, et rien n\'est relancé', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'albrecht' => $albrecht] = oprDeuxHerosAvantDepart();
    oprAttacher($albrecht, 'Malédiction du loup-garou', 'sort_dread:Malédiction du loup-garou');

    oprDes([5, 6]); // la transformation, au round 1
    oprDemarrer($alice);

    app(Sauvegarde::class)->redemarrerQuete($groupe->fresh());

    // Le point de départ est celui d'APRÈS le jet : la transformation et le tour perdu sont toujours là…
    $etat = EtatPersonnageQuete::where('personnage_id', $albrecht->id)->firstOrFail();

    expect($albrecht->fresh()->conditions()->where('nom', 'Forme de loup')->exists())->toBeTrue()
        ->and((bool) $etat->a_joue)->toBeTrue()
        // …et rien n'a été relancé : un seul jet dans le journal.
        ->and(oprPayloads($groupe->fresh(), 'malediction_jet'))->toHaveCount(1);
});

// ------------------------------------------------------------------
// 5. Rien à dire, rien de diffusé : un départ sans effet n'ajoute aucune ligne d'ouverture au fil
// ------------------------------------------------------------------

it('sans effet posé avant le démarrage, l\'ouverture du round 1 ne diffuse aucune ligne d\'ouverture au fil', function () {
    Event::fake([JournalCombatDiffuse::class]);
    ['alice' => $alice, 'groupe' => $groupe] = oprDeuxHerosAvantDepart();

    oprDemarrer($alice);

    expect(oprPayloads($groupe->fresh(), 'malediction_jet'))->toHaveCount(0)
        ->and(oprPayloads($groupe->fresh(), 'tour_perdu'))->toHaveCount(0);

    Event::assertNotDispatched(JournalCombatDiffuse::class,
        fn (JournalCombatDiffuse $e) => collect($e->lignes)->pluck('texte')->contains(
            fn (string $texte) => str_contains($texte, 'deux dés rouges')
                || str_contains($texte, 'encore étourdi')
                || str_contains($texte, 'Zargon joue encore'),
        ));
});
