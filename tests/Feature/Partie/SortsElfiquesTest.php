<?php

declare(strict_types=1);

use App\Engine\Des\LanceurDeterministe;
use App\Events\HerosVaSubirDegats;
use App\Listeners\ImageMiroir;
use App\Models\Sort;
use App\Partie\MoteurDegats;
use App\Partie\MoteurSorts;
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
use Illuminate\Support\Facades\Http;

/*
 * Répertoire elfique (© 2023 Hasbro, The Mage of the Mirror) — 5 sorts portés
 * sur 8. Les trois autres sont des dettes nommées dans SortSeeder.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

it('sème un répertoire elfique DISTINCT des quatre écoles', function () {
    $elfiques = Sort::where('element', MoteurSorts::REPERTOIRE_ELFIQUE)->pluck('nom')->all();

    // 4 des 8 cartes sont portées ; les 4 autres sont des dettes nommées dans
    // SortSeeder (Flashback écarté par René, Twist Wood sans cible possible,
    // Hypnotic Blaze faute de zone, Disappear faute d'intangibilité).
    expect($elfiques)->toHaveCount(4);

    // ⚠ Il ne se mélange pas aux éléments : un sort elfique ne doit jamais
    // arriver par le choix d'une école, sinon la seconde voie de l'Elfe n'en
    // serait plus une.
    expect(array_intersect($elfiques, Sort::whereIn('element', MoteurSorts::ELEMENTS)->pluck('nom')->all()))
        ->toBe([]);
});

it('IMAGE DOUBLE annule le coup sur 1-3, et le laisse passer sur 4-6', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    $sort = Sort::where('nom', 'Image double')->firstOrFail();
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => true]]);
    app(MoteurSorts::class)->appliquerBuff($heros, $sort);

    // Un d6 à 2 : l'image encaisse.
    $ecouteur = new ImageMiroir(app(MoteurSorts::class), new LanceurDeterministe([2]));
    $evenement = new HerosVaSubirDegats($heros, 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);
    $ecouteur->handle($evenement);

    expect($evenement->degats)->toBe(0);

    // Un d6 à 4 : le héros prend tout.
    $ecouteur = new ImageMiroir(app(MoteurSorts::class), new LanceurDeterministe([4]));
    $evenement = new HerosVaSubirDegats($heros, 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);
    $ecouteur->handle($evenement);

    expect($evenement->degats)->toBe(3);
});

it('IMAGE DOUBLE ne trompe pas un piège : on ne leurre pas une fosse avec un mirage', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    $sort = Sort::where('nom', 'Image double')->firstOrFail();
    app(MoteurSorts::class)->appliquerBuff($heros, $sort);

    // Même jet gagnant (2), mais la source est un piège : la carte parle
    // d'« an ATTACK against the hero ».
    $ecouteur = new ImageMiroir(app(MoteurSorts::class), new LanceurDeterministe([2]));
    $evenement = new HerosVaSubirDegats($heros, 2, MoteurDegats::SOURCE_PIEGE);
    $ecouteur->handle($evenement);

    expect($evenement->degats)->toBe(2);
});

it('n\'annule rien SANS le sort, même sur un jet gagnant', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');

    $ecouteur = new ImageMiroir(app(MoteurSorts::class), new LanceurDeterministe([1]));
    $evenement = new HerosVaSubirDegats($ctx['heros'], 2, MoteurDegats::SOURCE_ATTAQUE_MONSTRE);
    $ecouteur->handle($evenement);

    expect($evenement->degats)->toBe(2);
});

it('ARRÊT DU TEMPS réarme le tour au lieu de le terminer, et une seule fois', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $etat = $ctx['etatHeros'];

    $etat->update(['tour_supplementaire' => true, 'a_agi' => true, 'a_deplace' => true]);

    // Le moteur n'accepte qu'une option du DERNIER menu proposé : on le fait
    // donc générer, puis on y prend « Terminer le tour ».
    App\Jobs\GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    $menu = Illuminate\Support\Facades\Cache::get(
        App\Jobs\GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id),
    )['menu'];

    // « Terminer le tour » porte le type `attente` — c'est le créneau `tour`
    // de ResolveurTour::creneauOption(), celui-là même que l'Arrêt du temps
    // détourne.
    $fin = collect($menu['options'])->firstWhere('type', 'attente');

    expect($fin)->not->toBeNull('le menu doit proposer de terminer le tour');

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $fin['id']])
        ->assertStatus(202);

    $apres = $etat->fresh();

    expect((bool) $apres->a_joue)->toBeFalse('le tour ne doit pas s\'achever')
        ->and((bool) $apres->a_agi)->toBeFalse()
        ->and((bool) $apres->a_deplace)->toBeFalse()
        // ⚠ Consommé : sans ça le héros jouerait indéfiniment.
        ->and((bool) $apres->tour_supplementaire)->toBeFalse();
});
