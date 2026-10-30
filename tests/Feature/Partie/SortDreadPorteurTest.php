<?php

declare(strict_types=1);

use App\Engine\MotsClesEquipement;
use App\Events\SceneTable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Evenement;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\SortDread;
use App\Partie\Equipement;
use App\Partie\JournalCombat;
use App\Partie\Salles;
use App\Partie\SortsDreadPorteur;
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
 * Le SORT DE DREAD DU PORTEUR — Talisman du Savoir maudit (chantier RESTES-TALISMAN, 2026-10-29), dés injectés, vraies routes.
 *
 * Source : Prophecy of Telor p. 10 : « The bearer of the talisman knows two Dread spells: Lightning Bolt and Firestorm.
 * Each time the hero casts one of these spells, they gain 1 Mind Point. Each spell may only be cast once per quest. »
 * Noms de nos cartes : Lightning Bolt = Éclair de Chaos (rayon, 2 PV), Firestorm = Tempête de feu (salle, 3 PV moins
 * les succès de 2 dés rouges). L'effet est LU au catalogue (`SortDread`), jamais recopié.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);
});

/** Le talisman de TEST qui porte les deux sorts (la ligne catalogue vient en dernier, une fois ses lecteurs prouvés). */
function sortsDreadTalisman(): Objet
{
    return Objet::create([
        'nom' => 'Talisman de sorts d\'essai',
        'categorie' => 'armure',
        'rarete' => 'unique',
        'prix_base' => 800,
        'emplacement' => 'talisman',
        'effet' => [
            'bonus_pv_mind_max' => 1,
            MotsClesEquipement::SORTS_DREAD_PORTEUR => ['Éclair de Chaos', 'Tempête de feu'],
        ],
    ]);
}

/**
 * Une quête : Albrecht PORTE le talisman de sorts, une figure (`$monstre`) est au contact, révélée, à 10 PV de Body.
 * Le premier tour du porteur est ouvert au démarrage : on efface sa manche (`capacites_tour`) pour un menu neuf.
 */
function sortsDreadQuete(string $monstre = 'Gobelin'): array
{
    $ctx = demarrerQueteAvecMonstre($monstre);
    $heros = $ctx['heros'];

    $ligne = Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => sortsDreadTalisman()->id,
        'emplacement' => 'sac',
        'quantite' => 1,
    ]);
    (new Equipement)->equiper($heros->refresh(), $ligne);

    $ctx['instance']->update(['pv_body' => 10, 'pv_body_max' => 10]);
    $ctx['heros']->update(['pv_mind' => 2, 'pv_mind_max' => 4]);

    return $ctx;
}

/** Le menu du porteur, tel que la manette le lit. */
function sortsDreadMenu(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return collect(test()->actingAs($ctx['alice'], 'joueur')->getJson('/api/groupes/table-1/menu')->json('menu.options'))
        ->all();
}

/** L'option `lancer_sort_dread` du menu, ou null. */
function sortsDreadOption(array $options): ?array
{
    return collect($options)->firstWhere('id', 'lancer_sort_dread');
}

/** Pose une figure dans la salle du porteur (sur une case libre de la salle de départ), le porteur aussi. */
function sortsDreadDansLaSalle(array $ctx): void
{
    placerHerosSurAxeDegage($ctx['quete'], $ctx['etatHeros']);
    $ctx['etatHeros']->refresh();

    $salle = $ctx['quete']->carte->grille['salles'][0];

    for ($y = (int) $salle['y'] + 1; $y < (int) $salle['y'] + (int) $salle['hauteur'] - 1; $y++) {
        for ($x = (int) $salle['x'] + 1; $x < (int) $salle['x'] + (int) $salle['largeur'] - 1; $x++) {
            if (caseQueteLibre($ctx['quete'], $x, $y)) {
                $ctx['instance']->update(['position_x' => $x, 'position_y' => $y]);

                return;
            }
        }
    }

    throw new RuntimeException('Aucune case libre dans la salle de départ.');
}

/** Lance un sort de Dread du porteur par la route de choix réelle, sans dés figés : à appeler après `desFiges()`. */
function sortsDreadLancer(array $ctx, string $nom, ?int $cibleId = null): \Illuminate\Testing\TestResponse
{
    $parametres = ['cle' => 'sort_dread:'.$nom];

    if ($cibleId !== null) {
        $parametres['cible_id'] = $cibleId;
    }

    return test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'lancer_sort_dread', 'parametres' => $parametres]);
}

it('le menu n\'offre le sort de Dread qu\'au porteur, et seulement les sorts qui ont une cible', function () {
    $ctx = sortsDreadQuete();

    $option = sortsDreadOption(sortsDreadMenu($ctx));

    expect($option)->not->toBeNull()
        ->and(collect($option['parametres']['sorts'])->pluck('nom')->all())->toContain('Éclair de Chaos')
        ->and(collect($option['parametres']['sorts'])->firstWhere('nom', 'Éclair de Chaos')['cibles'][0]['instance_id'])
        ->toBe($ctx['instance']->id);

    // Sans le talisman, rien : le sort est une propriété de la PIÈCE portée, pas du héros.
    Inventaire::where('personnage_id', $ctx['heros']->id)->where('emplacement', 'talisman')->update(['emplacement' => 'sac']);

    expect(sortsDreadOption(sortsDreadMenu($ctx)))->toBeNull();
});

it('Éclair de Chaos : 2 PV à la figure de la ligne, et +1 PV de Mind au lancer', function () {
    $ctx = sortsDreadQuete();
    desFiges([6, ...array_fill(0, 60, 6)]);
    sortsDreadMenu($ctx);

    sortsDreadLancer($ctx, 'Éclair de Chaos', (int) $ctx['instance']->id)->assertStatus(202);

    expect((int) $ctx['instance']->refresh()->pv_body)->toBe(8)
        ->and((int) $ctx['heros']->refresh()->pv_mind)->toBe(3);
});

it('Tempête de feu : 3 PV à chaque figure de la salle, moins les succès de ses dés rouges (le catalogue)', function () {
    $ctx = sortsDreadQuete();
    sortsDreadDansLaSalle($ctx);
    // Le dé de mouvement est tiré au démarrage (tour du porteur déjà ouvert) : le lancer consomme 1 + 1, aucun succès, 3 PV.
    desFiges([1, 1, ...array_fill(0, 60, 6)]);

    sortsDreadMenu($ctx);
    sortsDreadLancer($ctx, 'Tempête de feu')->assertStatus(202);

    expect((int) $ctx['instance']->refresh()->pv_body)->toBe(7)
        ->and((int) $ctx['heros']->refresh()->pv_mind)->toBe(3);
});

it('Tempête de feu : deux 6 sur les dés rouges de la figure réduisent le coup de 2 (résistance du catalogue)', function () {
    $ctx = sortsDreadQuete();
    sortsDreadDansLaSalle($ctx);
    desFiges([6, 6, ...array_fill(0, 60, 6)]); // deux 6 pour les dés rouges de la figure : deux succès, 3 - 2 = 1 PV

    sortsDreadMenu($ctx);
    sortsDreadLancer($ctx, 'Tempête de feu')->assertStatus(202);

    expect((int) $ctx['instance']->refresh()->pv_body)->toBe(9);
});

it('une fois par quête : le sort lancé n\'est plus offert, et le compteur le dit', function () {
    $ctx = sortsDreadQuete();
    desFiges([6, ...array_fill(0, 60, 6)]);
    sortsDreadMenu($ctx);
    sortsDreadLancer($ctx, 'Éclair de Chaos', (int) $ctx['instance']->id)->assertStatus(202);

    $etat = $ctx['etatHeros']->refresh();

    expect((array) $etat->capacites_utilisees)->toContain('sort_dread_porteur:Éclair de Chaos')
        ->and(collect(app(SortsDreadPorteur::class)->entrees($ctx['quete'], $etat, $ctx['heros']->refresh()))
            ->pluck('nom')->all())->not->toContain('Éclair de Chaos');
});

it('un sort hors de la liste publiée est refusé (422) et ne dépense ni PV de Mind ni le compteur', function () {
    $ctx = sortsDreadQuete();
    desFiges([6, ...array_fill(0, 60, 6)]);
    sortsDreadMenu($ctx);

    sortsDreadLancer($ctx, 'Éclair de Chaos', 999999)->assertStatus(422);

    expect((int) $ctx['instance']->refresh()->pv_body)->toBe(10)
        ->and((int) $ctx['heros']->refresh()->pv_mind)->toBe(2)
        ->and((array) $ctx['etatHeros']->refresh()->capacites_utilisees)->not->toContain('sort_dread_porteur:Éclair de Chaos');
});

it('une figure IMMUNISÉE aux sorts n\'est pas une cible : pas d\'entrée, donc pas d\'option', function () {
    $ctx = sortsDreadQuete('Effigie gardienne');

    expect(sortsDreadOption(sortsDreadMenu($ctx)))->toBeNull()
        ->and(Monstre::where('nom_base', 'Effigie gardienne')->firstOrFail()->immuniseAuxSorts())->toBeTrue();
});

it('un porteur EN COULOIR ne lance pas Tempête de feu : « hors_couloir », lu au catalogue', function () {
    $ctx = sortsDreadQuete();
    $quete = $ctx['quete'];
    $salles = (array) $quete->carte->grille['salles'];
    $couloir = null;

    foreach ((array) $quete->carte->grille['cases'] as $y => $ligne) {
        foreach (array_keys((array) $ligne) as $x) {
            if (Salles::indexDe($salles, (int) $x, (int) $y) === null && caseQueteLibre($quete, (int) $x, (int) $y)) {
                $couloir = ['x' => (int) $x, 'y' => (int) $y];

                break 2;
            }
        }
    }

    expect($couloir)->not->toBeNull('la carte doit offrir au moins une case de couloir libre');

    $ctx['etatHeros']->update(['position_x' => $couloir['x'], 'position_y' => $couloir['y']]);

    expect(app(SortsDreadPorteur::class)->cibles(
        $quete, $ctx['etatHeros']->refresh(), SortDread::where('nom', 'Tempête de feu')->firstOrFail(),
    ))->toBeNull();
});

it('annoncé à la table : une scène de sort, et le fil nomme le sort et le gain de Mind', function () {
    $ctx = sortsDreadQuete();
    sortsDreadDansLaSalle($ctx);
    desFiges([1, 1, ...array_fill(0, 60, 6)]);
    sortsDreadMenu($ctx);
    Event::fake([SceneTable::class]);

    sortsDreadLancer($ctx, 'Tempête de feu')->assertStatus(202);

    $scenes = Event::dispatched(SceneTable::class)
        ->map(fn (array $appel) => $appel[0]->scene)
        ->filter(fn (array $scene) => ($scene['genre'] ?? null) === 'sort')
        ->values();

    $journal = Evenement::where('groupe_id', $ctx['groupe']->id)->get()
        ->map(fn (Evenement $e) => $e->payload)
        ->filter(fn ($p) => is_array($p) && ($p['type'] ?? null) === 'sort_dread_porteur')
        ->values()
        ->all();

    $texte = collect(app(JournalCombat::class)->depuisResultat($journal[0], 'Albrecht'))->pluck('texte')->implode(' ');

    expect($scenes)->toHaveCount(1)
        ->and($scenes[0]['titre'])->toBe('Tempête de feu')
        ->and($journal)->toHaveCount(1)
        ->and($texte)->toContain('Tempête de feu')
        ->and($texte)->toContain('PV de Mind');
});

it('la carte nomme les deux sorts AVANT qu\'on la porte', function () {
    $phrases = implode(' | ', MotsClesEquipement::avantages([
        MotsClesEquipement::SORTS_DREAD_PORTEUR => ['Éclair de Chaos', 'Tempête de feu'],
    ]));

    expect($phrases)->toContain('Éclair de Chaos, Tempête de feu')
        ->and($phrases)->toContain('+1 PV de Mind à chaque lancer');
});

it('le rayon traverse la figure visée et ÉPARGNE la figure immunisée posée derrière elle sur la même ligne', function () {
    $ctx = sortsDreadQuete();
    $quete = $ctx['quete'];
    $axe = placerHerosSurAxeDegage($quete, $ctx['etatHeros']); // trois cases libres dans une direction
    $ctx['etatHeros']->refresh();
    $hx = (int) $ctx['etatHeros']->position_x;
    $hy = (int) $ctx['etatHeros']->position_y;

    // La figure visée, au premier pas ; l'Effigie immunisée, au second, sur la même ligne.
    $ctx['instance']->update(['position_x' => $hx + $axe['dx'], 'position_y' => $hy + $axe['dy']]);
    $effigie = $quete->instancesMonstres()->whereKeyNot($ctx['instance']->id)->orderBy('id')->firstOrFail();
    $effigie->update([
        'monstre_id' => Monstre::where('nom_base', 'Effigie gardienne')->firstOrFail()->id,
        'pv_body' => 2, 'pv_body_max' => 2, 'etat' => 'actif', 'revele' => true,
        'position_x' => $hx + 2 * $axe['dx'], 'position_y' => $hy + 2 * $axe['dy'],
    ]);
    $effigie->refresh()->load('monstre');

    $options = sortsDreadMenu($ctx);
    $entree = collect(sortsDreadOption($options)['parametres']['sorts'])->firstWhere('nom', 'Éclair de Chaos');

    desFiges([1, ...array_fill(0, 60, 6)]);

    expect(collect($entree['cibles'])->pluck('instance_id')->all())->toContain($ctx['instance']->id)
        ->and(collect($entree['cibles'])->pluck('instance_id')->all())->not->toContain($effigie->id);

    sortsDreadLancer($ctx, 'Éclair de Chaos', (int) $ctx['instance']->id)->assertStatus(202);

    expect((int) $ctx['instance']->refresh()->pv_body)->toBe(8)
        ->and((int) $effigie->refresh()->pv_body)->toBe(2);
});
