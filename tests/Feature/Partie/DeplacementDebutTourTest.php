<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Events\SceneTable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
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
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * LE DÉ DE DÉPLACEMENT, AU DÉBUT DU TOUR DU HÉROS (René, 2026-09-16 : « un popup
 * pour afficher le dé de déplacement au début d'un tour de joueur »).
 *
 * Ce qui se mesure ici n'est pas le rendu mais l'INSTANT. Les menus sont
 * recalculés pour tous les héros après chaque choix ; le dé partait donc pour
 * les quatre dès le début du round, et une scène « au début d'un tour » n'avait
 * aucun moment à qui appartenir. Il se lance désormais au tour du héros, une
 * fois, et c'est ce lancer qui fait partir la scène.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

/** Deux héros de deux joueurs, Albrecht joue avant Bertrand. Alice reste connectée. */
function queteADeuxHeros(): array
{
    $alice = connecterJoueur('alice');
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $groupe = creerGroupe();
    $albrecht = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bertrand = creerHeros($bob, $groupe, 'Bertrand', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    return compact('alice', 'bob', 'groupe', 'albrecht', 'bertrand', 'quete');
}

/** Les scènes de début de tour diffusées, dans l'ordre. */
function scenesDeDebutDeTour(): array
{
    return Event::dispatched(SceneTable::class)
        ->map(fn (array $appel) => $appel[0]->scene)
        ->filter(fn (array $scene) => $scene['genre'] === 'deplacement')
        ->values()
        ->all();
}

function etatDe(Quete $quete, Personnage $heros): EtatPersonnageQuete
{
    return $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
}

it('lance le dé du PREMIER héros seul, et l\'annonce une fois', function () {
    Event::fake([SceneTable::class]);
    desFiges(array_fill(0, 60, 4));

    $ctx = queteADeuxHeros();

    expect(etatDe($ctx['quete'], $ctx['albrecht'])->deplacement_tour)->not->toBeNull()
        // Bertrand n'a pas encore joué, mais ce n'est pas son tour : pas de dé.
        ->and(etatDe($ctx['quete'], $ctx['bertrand'])->deplacement_tour)->toBeNull();

    $scenes = scenesDeDebutDeTour();

    expect($scenes)->toHaveCount(1)
        ->and($scenes[0]['titre'])->toBe("Au tour d'Albrecht")
        ->and($scenes[0]['deplacement']['des'])->toBe([4]);
});

it('n\'annonce rien de plus quand les menus sont recalculés pendant le tour', function () {
    Event::fake([SceneTable::class]);
    $ctx = queteADeuxHeros();
    $jet = etatDe($ctx['quete'], $ctx['albrecht'])->deplacement_tour;

    // Les menus de TOUS les héros sont recalculés après chaque choix, et la
    // manette en redemande un à chaque reconnexion.
    foreach ([$ctx['albrecht'], $ctx['bertrand'], $ctx['albrecht']] as $heros) {
        GenererMenu::dispatchSync($ctx['groupe']->id, (int) $heros->joueur_id, (int) $heros->id);
    }

    expect(scenesDeDebutDeTour())->toHaveCount(1)
        ->and(etatDe($ctx['quete'], $ctx['albrecht'])->deplacement_tour)->toBe($jet);
});

it('lance et annonce le dé du héros SUIVANT quand le tour passe', function () {
    Event::fake([SceneTable::class]);
    $ctx = queteADeuxHeros();

    // AVANT la fin du tour d'Albrecht, rien pour Bertrand — sans quoi ce test
    // passerait aussi sur l'ancien code, qui lançait les deux dés d'emblée.
    expect(etatDe($ctx['quete'], $ctx['bertrand'])->deplacement_tour)->toBeNull()
        ->and(collect(scenesDeDebutDeTour())->pluck('titre')->all())->toBe(["Au tour d'Albrecht"]);

    // Albrecht termine son tour par la vraie route.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    expect(etatDe($ctx['quete'], $ctx['bertrand'])->deplacement_tour)->not->toBeNull()
        ->and(collect(scenesDeDebutDeTour())->pluck('titre')->all())
        ->toBe(["Au tour d'Albrecht", 'Au tour de Bertrand']);
});

it('annonce la MÊME portée que celle que le téléphone propose', function () {
    Event::fake([SceneTable::class]);
    $ctx = queteADeuxHeros();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['albrecht']->joueur_id, (int) $ctx['albrecht']->id);
    $menu = Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['albrecht']->joueur_id))['menu'];
    $option = collect($menu['options'])->firstWhere('id', 'se_deplacer');

    expect($option)->not->toBeNull('le premier héros doit pouvoir se déplacer au début de la quête');

    $scene = scenesDeDebutDeTour()[0];

    expect($scene['issue']['libelle'])->toStartWith($option['parametres']['portee'].' case')
        ->and($scene['deplacement']['calcul'])->toEndWith('= '.$option['parametres']['portee']);
});
