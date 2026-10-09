<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Objet;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Un sort à cible n'est offert que s'il a une cible LÉGALE (2026-10-08).
 *
 * Défaut signalé : le menu proposait *Désapprentissage* avec `cibles: []` alors
 * qu'aucun Sorcier de Dread n'était en vue. Le joueur le choisissait, et le
 * résolveur le refusait (« Cible requise »). Une liste vide n'est pas une liste :
 * l'entrée ne doit pas exister, ni grisée, ni vide.
 *
 * Joué EN JEU : le menu est lu par la vraie route `GET /api/groupes/{id}/menu`,
 * après une régénération — le menu de démarrage est en cache et périmé dès qu'on
 * modifie la scène.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Le menu que la route publie maintenant au héros, après régénération. */
function sansCibleMenu(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return test()->actingAs($ctx['alice'], 'joueur')
        ->getJson('/api/groupes/table-1/menu')
        ->assertOk()
        ->json('menu.options');
}

/** Les entrées (`sorts` ou `parchemins`) de l'option `$id`, ou [] si elle ne paraît pas. */
function sansCibleListe(array $options, string $id, string $liste): array
{
    $option = collect($options)->firstWhere('id', $id);

    return $option === null ? [] : (array) ($option['parametres'][$liste] ?? []);
}

/** Donne au héros l'exemplaire au sac du parchemin de `$nomSort`. */
function sansCibleParchemin(array $ctx, string $nomSort): void
{
    $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', "Parchemin : {$nomSort}")->firstOrFail()->id,
        'quantite' => 1,
        'emplacement' => 'consommable',
    ]);
}

it('sans Sorcier de Dread en vue, Désapprentissage n\'est pas proposé — même avec un monstre ordinaire au contact', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');

    $options = sansCibleMenu($ctx);
    $entrees = collect(sansCibleListe($options, 'lancer_sort', 'sorts'));

    // Le menu n'est pas vide pour autant : Mur de Pierre et Invisibilité restent proposés.
    expect($entrees->firstWhere('nom', 'Invisibilité'))->not->toBeNull()
        ->and($entrees->firstWhere('nom', 'Désapprentissage'))->toBeNull();

    // Le résolveur refuse ce choix : le menu et la liste blanche disent la même chose.
    $unlearn = Sort::where('nom', 'Désapprentissage')->firstOrFail();
    test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', [
            'option_id' => 'lancer_sort',
            'parametres' => ['cle' => "sort:{$unlearn->id}"],
        ])->assertStatus(422);
});

it('avec un Sorcier de Dread en vue, Désapprentissage est proposé, et vise ce Sorcier', function () {
    $ctx = demarrerQueteAvecMonstre('Seigneur', ['classe' => 'magicien']);
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'protection');

    $entree = collect(sansCibleListe(sansCibleMenu($ctx), 'lancer_sort', 'sorts'))->firstWhere('nom', 'Désapprentissage');

    expect($entree)->not->toBeNull()
        ->and($entree['disponible'])->toBeTrue()
        ->and(collect($entree['cibles'])->pluck('type')->unique()->all())->toBe(['monstre'])
        ->and(collect($entree['cibles'])->pluck('id')->all())->toBe([$ctx['instance']->id]);
});

it('un sort visant un monstre le propose avec ce monstre en vue', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'tenebres');

    $fleches = collect(sansCibleListe(sansCibleMenu($ctx), 'lancer_sort', 'sorts'))->firstWhere('nom', 'Flèches de la Nuit');

    expect($fleches)->not->toBeNull()
        ->and(collect($fleches['cibles'])->where('type', 'monstre')->pluck('id')->all())->toContain($ctx['instance']->id);
});

it('aucune entrée de sort ou de parchemin ne porte une liste de cibles vide, pour TOUS les sorts (un seul point de passage)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);

    // Le héros connaît TOUS les sorts et possède le parchemin de chacun.
    foreach (Sort::query()->distinct()->pluck('element') as $element) {
        app(MoteurSorts::class)->attacherElement($ctx['heros'], (string) $element);
    }
    foreach (Sort::orderBy('id')->get() as $sort) {
        if (Objet::where('nom', "Parchemin : {$sort->nom}")->exists()) {
            sansCibleParchemin($ctx, $sort->nom);
        }
    }

    // Scène 1 : le Gobelin en vue. Scène 2 : plus aucun monstre actif.
    $scenes = [
        'monstre en vue' => fn () => null,
        'aucun monstre actif' => fn () => $ctx['instance']->update(['etat' => 'vaincu']),
    ];

    foreach ($scenes as $scene => $appliquer) {
        $appliquer();
        $options = sansCibleMenu($ctx);

        $tout = [
            ...sansCibleListe($options, 'lancer_sort', 'sorts'),
            ...sansCibleListe($options, 'lire_parchemin', 'parchemins'),
        ];

        // Une entrée qui porte `cibles` en porte au moins une : jamais `cibles: []`.
        $vides = collect($tout)->filter(fn ($e) => array_key_exists('cibles', $e) && $e['cibles'] === [])->pluck('nom')->all();
        expect($vides)->toBe([], "[$scene] entrées à cibles vides : ".implode(', ', $vides));

        // Sans Sorcier, Désapprentissage ne figure dans AUCUNE des deux listes.
        expect(collect($tout)->firstWhere('nom', 'Désapprentissage'))->toBeNull("[$scene]");

        // Le test n'est pas vide : un sort à cible légale est là dans les DEUX scènes
        // — un soin vise toujours le lanceur lui-même.
        expect(collect($tout)->firstWhere('nom', 'Eau de Guérison'))->not->toBeNull("[$scene]");

        // Boule de Feu (« any one monster ») n'a d'entrée que monstre en vue. Sans
        // monstre, elle ne tombe PAS sur le magicien : pas de cible, pas d'entrée
        // (décision de René, 2026-10-09).
        $boule = collect($tout)->firstWhere('nom', 'Boule de Feu');

        if ($scene === 'monstre en vue') {
            expect($boule)->not->toBeNull("[$scene]");
        } else {
            expect($boule)->toBeNull("[$scene]");
        }
    }
});

it('le parchemin de Désapprentissage suit la même règle : sans Sorcier, il n\'est pas proposé', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    sansCibleParchemin($ctx, 'Désapprentissage');

    $options = sansCibleMenu($ctx);

    // Seul parchemin du sac : l'option `lire_parchemin` elle-même ne paraît pas.
    expect(collect($options)->firstWhere('id', 'lire_parchemin'))->toBeNull();
});

it('le parchemin de Désapprentissage est proposé avec un Sorcier de Dread en vue, visant ce Sorcier', function () {
    $ctx = demarrerQueteAvecMonstre('Seigneur', ['classe' => 'magicien']);
    sansCibleParchemin($ctx, 'Désapprentissage');

    $entree = collect(sansCibleListe(sansCibleMenu($ctx), 'lire_parchemin', 'parchemins'))->firstWhere('nom', 'Désapprentissage');

    expect($entree)->not->toBeNull()
        ->and(collect($entree['cibles'])->pluck('id')->all())->toBe([$ctx['instance']->id]);
});

it('l\'option lancer_sort ne paraît pas quand son seul sort n\'a aucune cible légale', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    $ctx['heros']->sorts()->syncWithoutDetaching([
        Sort::where('nom', 'Désapprentissage')->firstOrFail()->id => ['disponible' => true],
    ]);

    expect(collect(sansCibleMenu($ctx))->firstWhere('id', 'lancer_sort'))->toBeNull();
});

it('Conte inspirant (« excluding yourself ») : le Barde seul ne se le lance pas, et jamais sur lui-même', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'barde');
    $ctx['instance']->update(['etat' => 'vaincu']);

    // Seul héros de la quête : aucune autre cible légale, donc aucune entrée.
    expect(collect(sansCibleListe(sansCibleMenu($ctx), 'lancer_sort', 'sorts'))->firstWhere('nom', 'Conte inspirant'))->toBeNull();
});

it('Conte inspirant : avec un allié au contact, il ne vise QUE cet allié', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'magicien']);
    app(MoteurSorts::class)->attacherElement($ctx['heros'], 'barde');

    // Un second héros, posé au contact du Barde (ligne de vue dégagée).
    $allie = creerHeros($ctx['alice'], $ctx['groupe'], 'Brunhilde', 2);
    $quete = $ctx['quete'];
    $etatBarde = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $ctx['heros']->id)->firstOrFail();
    $contact = caseAdjacenteLibre($quete, (int) $etatBarde->position_x, (int) $etatBarde->position_y);
    EtatPersonnageQuete::create([
        'quete_id' => $quete->id, 'personnage_id' => $allie->id,
        'position_x' => $contact['x'], 'position_y' => $contact['y'],
    ]);
    $quete->refresh();

    $entree = collect(sansCibleListe(sansCibleMenu($ctx), 'lancer_sort', 'sorts'))->firstWhere('nom', 'Conte inspirant');

    expect($entree)->not->toBeNull()
        ->and(collect($entree['cibles'])->pluck('id')->all())->toBe([$allie->id]);
});
