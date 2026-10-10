<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Partie\MenuCourant;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Fenêtre de régénération du menu (Morcar, 2026-10-09, « Aucun menu en attente »).
 *
 * Après une résolution, `ExecutionChoix` consomme le menu et confie la régénération à
 * un job : entre les deux, il n'y a plus de menu en cache. Un choix LÉGAL envoyé pendant
 * cette fenêtre était refusé à tort. Ces tests simulent la fenêtre en retirant le menu
 * du cache (le job n'a pas encore publié) : la règle, c'est que le serveur le recalcule
 * sur place pour le héros qui a la main, et qu'il ne sert ni n'accepte jamais un menu
 * qui ne désigne pas l'acteur.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/**
 * Groupe à DEUX joueurs (Albrecht, puis Brunhilde) en quête : Albrecht a la main.
 *
 * @return array{0: JoueurAuthentifiable, 1: \App\Models\Groupe, 2: \App\Models\Personnage, 3: JoueurAuthentifiable, 4: \App\Models\Personnage}
 */
function fenetreDeuxHeros(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $brunhilde = creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    return [$alice, $groupe->fresh(), $albrecht, $bob, $brunhilde];
}

it('accepte un choix LÉGAL envoyé pendant la régénération du menu (menu consommé, job pas encore passé)', function () {
    [$alice, $groupe] = fenetreDeuxHeros();

    // La résolution précédente a consommé le menu ; le job n'a pas encore publié le suivant.
    Cache::forget(GenererMenu::cleMenu($groupe->id, (int) $alice->id));

    // « Terminer le tour » est légal pour Albrecht, qui a la main : refusé à tort avant.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);
});

it('refuse toujours une option ABSENTE du menu de l\'instant (la liste est la liste blanche)', function () {
    [$alice, $groupe] = fenetreDeuxHeros();

    Cache::forget(GenererMenu::cleMenu($groupe->id, (int) $alice->id));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'lancer_une_bombe'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('option_id');
});

it('ne sert JAMAIS un menu en cache qui ne désigne pas l\'acteur : GET /menu rend le sien', function () {
    [$alice, $groupe, $albrecht, , $brunhilde] = fenetreDeuxHeros();

    // Un menu resté en cache, celui de BRUNHILDE, alors que c'est le tour d'ALBRECHT.
    Cache::put(GenererMenu::cleMenu($groupe->id, (int) $alice->id), [
        'personnage_id' => $brunhilde->id,
        'allie_id' => null,
        'menu' => ['situation' => 'Périmé', 'options' => [['id' => 'attendre', 'libelle' => 'Périmé', 'type' => 'attente']]],
    ], now()->addMinutes(5));

    $r = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json();

    expect($r['menu'])->not->toBeNull()
        ->and($r['personnage_id'])->toBe($albrecht->id)
        ->and($r['menu']['situation'])->not->toBe('Périmé');
});

it('jette un menu périmé à la place du choix : une option qui n\'est plus proposée reste refusée', function () {
    [$alice, $groupe, , , $brunhilde] = fenetreDeuxHeros();

    Cache::put(GenererMenu::cleMenu($groupe->id, (int) $alice->id), [
        'personnage_id' => $brunhilde->id,
        'allie_id' => null,
        'menu' => ['situation' => 'Périmé', 'options' => [['id' => 'fantome', 'libelle' => 'Fantôme', 'type' => 'attente']]],
    ], now()->addMinutes(5));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'fantome'])->assertStatus(422);

    // Le menu périmé a été jeté, et remplacé par celui de l'instant.
    $cache = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id));
    expect($cache['personnage_id'])->toBe($groupe->personnages()->where('nom', 'Albrecht')->value('personnages.id'))
        ->and(collect($cache['menu']['options'])->pluck('id'))->not->toContain('fantome');
});

it('refuse le choix d\'un joueur qui n\'a PAS la main, faute de menu jouable', function () {
    [, $groupe, , $bob] = fenetreDeuxHeros();

    $this->actingAs($bob, 'joueur');

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(422)
        ->assertJsonPath('errors.option_id.0', 'Aucun menu en attente pour ce joueur — attendez la proposition du MJ.');

    expect(app(MenuCourant::class)->aLaMain($groupe, (int) $bob->id))->toBeFalse();
});

it('aLaMain est vrai pour le joueur dont c\'est le tour : c\'est lui qui reçoit « le menu se met à jour »', function () {
    [$alice, $groupe] = fenetreDeuxHeros();

    expect(app(MenuCourant::class)->aLaMain($groupe, (int) $alice->id))->toBeTrue();
});

it('l\'aperçu de trajet ne tombe pas dans la fenêtre de régénération', function () {
    [$alice, $groupe, $albrecht] = fenetreDeuxHeros();

    Cache::forget(GenererMenu::cleMenu($groupe->id, (int) $alice->id));

    $etat = \App\Models\EtatPersonnageQuete::where('personnage_id', $albrecht->id)->firstOrFail();

    $this->postJson('/api/groupes/table-1/deplacement/apercu', [
        'x' => (int) $etat->position_x,
        'y' => (int) $etat->position_y,
    ])->assertOk();
});
