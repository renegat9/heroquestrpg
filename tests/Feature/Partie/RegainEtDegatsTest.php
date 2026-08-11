<?php

declare(strict_types=1);

use App\Engine\RegainEffet;
use App\Events\HerosVaSubirDegats;
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
 * Les deux moitiés du chantier « réactions » (2026-08-11) :
 *
 *  1. `HerosVaSubirDegats` — un point d'interception AVANT que les PV bougent,
 *     là où `Personnage::booted()` n'observait la baisse qu'une fois écrite.
 *     C'est ce qui rend *Dark Wings* et *Twisting Torrent* portables : ces deux
 *     cartes annulent des dégâts pendant le tour d'un monstre.
 *  2. `RegainEffet` — à quel ÉVÉNEMENT un sort épuisé redevient lançable.
 *     `disponible` ne se rechargeait qu'au changement de quête.
 *
 * Ces tests exercent les MÉCANIQUES, pas des lignes de catalogue : les sorts
 * qui les porteront (Shapeshift, Demonform, Inspiring Tale) attendent leurs
 * classes. Un mot-clé déclaré sans preuve d'application est exactement ce que
 * le projet retire depuis une semaine.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        MobilierSeeder::class, ClasseHerosSeeder::class, SortSeeder::class]);
});

it('laisse un écouteur ANNULER les dégâts avant qu\'ils ne touchent les PV', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $avant = (int) $heros->pv_body;

    // *Twisting Torrent* : « Activate this technique when you take damage to
    // cancel that damage. » L'écouteur est ici le test lui-même.
    Event::listen(function (HerosVaSubirDegats $e) {
        if ($e->source === MoteurDegats::SOURCE_ATTAQUE_MONSTRE) {
            $e->degats = 0;
        }
    });

    $subis = app(MoteurDegats::class)->infligerAHeros(
        $heros, 3, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
    );

    expect($subis)->toBe(0)
        ->and((int) $heros->fresh()->pv_body)->toBe($avant);
});

it('distingue la SOURCE : annuler un coup n\'annule pas une chute dans une fosse', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $avant = (int) $heros->pv_body;

    // C'est tout l'intérêt de porter la source : l'observateur historique ne
    // voyait que « −2 PV » et n'aurait pas su faire la différence.
    Event::listen(function (HerosVaSubirDegats $e) {
        if ($e->source === MoteurDegats::SOURCE_ATTAQUE_MONSTRE) {
            $e->degats = 0;
        }
    });

    $subis = app(MoteurDegats::class)->infligerAHeros(
        $heros, 2, MoteurDegats::SOURCE_PIEGE,
    );

    expect($subis)->toBe(2)
        ->and((int) $heros->fresh()->pv_body)->toBe($avant - 2);
});

it('ne laisse pas un écouteur AUGMENTER les dégâts', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];
    $avant = (int) $heros->pv_body;

    // Le point d'interception sert à protéger, pas à frapper plus fort : le
    // moteur reste l'autorité sur ce qu'un coup inflige au maximum.
    Event::listen(function (HerosVaSubirDegats $e) {
        $e->degats = 99;
    });

    $subis = app(MoteurDegats::class)->infligerAHeros(
        $heros, 1, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
    );

    expect($subis)->toBe(1)
        ->and((int) $heros->fresh()->pv_body)->toBe($avant - 1);
});

it('rend un sort épuisé quand les PV reviennent au maximum', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    // On donne à un sort du catalogue la forme de *Shapeshift* : regagné quand
    // le Body revient à son maximum.
    $sort = Sort::query()->firstOrFail();
    $sort->update(['effet' => [...$sort->effet, 'regain' => RegainEffet::BODY_AU_MAX]]);
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => false]]);

    // Blessé puis soigné à fond.
    app(MoteurDegats::class)->infligerAHeros($heros, 2, MoteurDegats::SOURCE_PIEGE);

    expect($heros->sorts()->wherePivot('disponible', true)->where('sorts.id', $sort->id)->exists())
        ->toBeFalse('le sort ne doit pas revenir tant que les PV sont entamés');

    $heros->update(['pv_body' => $heros->pv_body_max]);

    expect($heros->sorts()->wherePivot('disponible', true)->where('sorts.id', $sort->id)->exists())
        ->toBeTrue();
});

it('rend un sort épuisé à celui qui ABAT un monstre, et à lui seul', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    $sort = Sort::query()->firstOrFail();
    $sort->update(['effet' => [...$sort->effet, 'regain' => RegainEffet::MONSTRE_VAINCU]]);
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => false]]);

    $rendus = app(MoteurSorts::class)->regagnerSorts($heros, RegainEffet::MONSTRE_VAINCU);

    expect($rendus)->toBe(1)
        ->and($heros->sorts()->wherePivot('disponible', true)->where('sorts.id', $sort->id)->exists())
        ->toBeTrue();

    // Deuxième passage : rien à rendre, l'événement ne se consomme pas pour rien.
    expect(app(MoteurSorts::class)->regagnerSorts($heros, RegainEffet::MONSTRE_VAINCU))->toBe(0);
});

it('ne rend rien sur un événement qui ne concerne pas le sort', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $heros = $ctx['heros'];

    $sort = Sort::query()->firstOrFail();
    $sort->update(['effet' => [...$sort->effet, 'regain' => RegainEffet::MONSTRE_VAINCU]]);
    $heros->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => false]]);

    expect(app(MoteurSorts::class)->regagnerSorts($heros, RegainEffet::ALLIE_DEUX_BOUCLIERS_BLANCS))->toBe(0);
});

it('déclare chaque regain sans utilisateur comme une DETTE nommée', function () {
    // Même dispositif que `TypeDegat::SANS_SOURCE` : le projet interdit le
    // mot-clé décoratif, mais accepte la dette — à condition qu'elle dise ce
    // qui lui manque. Les trois moteurs existent ; ce sont les sorts qui
    // attendent leurs classes.
    foreach (RegainEffet::tous() as $regain) {
        $porte = Sort::query()->get()->contains(fn (Sort $s) => ($s->effet['regain'] ?? null) === $regain);

        if (! $porte) {
            expect(array_key_exists($regain, RegainEffet::SANS_UTILISATEUR))->toBeTrue(
                "Le regain « {$regain} » n'est porté par aucun sort et n'est pas déclaré comme dette.",
            );
        }
    }
});
