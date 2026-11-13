<?php

declare(strict_types=1);

use App\Models\Condition;
use App\Models\Evenement;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Les effets À COMPTEUR ne passent pas d'une quête à l'autre (René, 2026-11-13) : un héros empoisonné au dernier round
 * d'une quête entrait dans la suivante empoisonné. Ils sont levés au démarrage (`MoteurSorts::dissiperEffetsACompteur()`)
 * et annoncés ; un effet SANS compteur (malédiction du loup-garou) reste.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

it('lève les effets à compteur au démarrage d\'une quête, les annonce, et garde ceux sans compteur', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $hero->conditions()->attach(Condition::where('nom', 'Empoisonné')->value('id'), ['duree' => 2, 'source' => 'venin']);
    $hero->conditions()->attach(Condition::where('nom', 'Ralenti')->value('id'), ['duree' => 1, 'source' => 'test']);
    $hero->conditions()->attach(Condition::where('nom', 'Malédiction du loup-garou')->value('id'), ['duree' => 0, 'source' => 'malediction']);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    expect($hero->fresh()->conditions()->pluck('nom')->all())->toBe(['Malédiction du loup-garou']);

    $entree = Evenement::where('groupe_id', $groupe->id)->where('type', 'combat')->get()
        ->first(fn (Evenement $e) => is_array($e->payload) && isset($e->payload['effets_globaux_annonces']));

    expect($entree)->not->toBeNull()
        ->and($entree->payload['effets_globaux_annonces'][0]['type'])->toBe('conditions_levees');

    $fil = collect($this->getJson('/api/groupes/table-1/etat')->assertOk()->json('journal_combat'))->pluck('texte');

    expect($fil)->toContain('Albrecht : Empoisonné se dissipe')
        ->and($fil)->toContain('Albrecht : Ralenti se dissipe');
});

it('n\'annonce rien quand aucun effet à compteur ne traîne', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    expect(Evenement::where('groupe_id', $groupe->id)->where('type', 'combat')->get()
        ->contains(fn (Evenement $e) => collect($e->payload['effets_globaux_annonces'] ?? [])->contains('type', 'conditions_levees')))
        ->toBeFalse();
});
