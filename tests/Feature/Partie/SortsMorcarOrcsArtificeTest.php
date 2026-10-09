<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use App\Partie\FabriqueGrille;
use App\Partie\MoteurDegats;
use App\Partie\MoteurDread;
use App\Partie\MoteurEmbuscade;
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
use Illuminate\Support\Facades\Http;

/**
 * WIZARDS OF MORCAR — VAGUE 2B : l'Orc Warcaster (Nyashak), l'Artificer (la
 * Gardienne), le Golem, le Dreadshifter et son embuscade, le Minotaure et son
 * coup de corne, et l'artefact Urdyn le Défaiseur — JOUÉS par les vraies routes.
 *
 * Même règle que `SortsDreadCartesTest` : une mécanique sans cas ici n'est pas
 * seedée. Le catalogue, lui, est verrouillé par `SortsDreadSourcesTest`.
 *
 * ⚠ Dés par défaut : TOUS À 4 (bouclier blanc). Ni un coup de monstre ne porte,
 * ni un coup de héros : les scénarios ne changent donc pas sous les pieds de
 * l'assertion. Un test qui a besoin de crânes les demande explicitement.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MobilierSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

// ------------------------------------------------------------------
// Helpers locaux (préfixe `morcarB` : les fichiers voisins déclarent les leurs)
// ------------------------------------------------------------------

/**
 * Un sorcier au contact du héros qui ne connaît QUE le sort demandé : le moteur
 * n'a donc qu'un choix, et le test dit lequel.
 *
 * @return array{alice: mixed, groupe: mixed, heros: Personnage, quete: Quete, instance: InstanceMonstre, etatHeros: EtatPersonnageQuete}
 */
function morcarBSorcier(string $monstre, string $sort): array
{
    $ctx = demarrerQueteAvecMonstre($monstre);

    $ctx['instance']->monstre->update(['sorts_dread' => [$sort], 'archetype_lanceur' => null]);
    $ctx['instance']->update(['pv_body_max' => (int) $ctx['instance']->monstre->pv_body]);
    $ctx['instance']->refresh()->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($ctx['instance'], $ctx['quete']);
    $ctx['instance']->refresh()->load('monstre');

    return $ctx;
}

/** « Terminer le tour » : le héros joue, la phase des monstres se déroule. */
function morcarBTour(array $ctx, array $des = []): array
{
    desFiges([...$des, ...array_fill(0, 400, 4)]);

    return test()->actingAs($ctx['alice'], 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)
        ->json();
}

/** @return list<array<string, mixed>> */
function morcarBActions(array $json): array
{
    return array_values((array) ($json['resultat']['tour_monstres']['actions'] ?? []));
}

/** Pose une créature du catalogue sur une case libre adjacente au héros. */
function morcarBPoser(array $ctx, string $nom, ?array $case = null): InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', $nom)->firstOrFail();
    $etat = $ctx['etatHeros']->fresh();
    $case ??= caseAdjacenteLibre($ctx['quete'], (int) $etat->position_x, (int) $etat->position_y);

    return InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body, 'pv_body_max' => $catalogue->pv_body, 'pv_mind' => $catalogue->pv_mind,
        'position_x' => $case['x'], 'position_y' => $case['y'], 'etat' => 'actif', 'revele' => true,
    ])->load('monstre');
}

/**
 * Éloigne le lanceur du héros, sur une case libre qu'il voit — une invocation
 * ne se lance jamais AU CONTACT (un sorcier encerclé frappe, il n'appelle pas :
 * l'arbitrage `assezSeulPourInvoquer()`/`auContact()` vaut pour tous).
 */
function morcarBEloigner(array $ctx): void
{
    $quete = $ctx['quete'];
    $etat = $ctx['etatHeros']->fresh();
    $grille = FabriqueGrille::pour($quete, exceptInstanceId: $ctx['instance']->id);

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            $ecart = abs($x - (int) $etat->position_x) + abs($y - (int) $etat->position_y);

            if (in_array($c, ['s', 'p'], true) && $ecart >= 3 && $ecart <= 6 && caseQueteLibre($quete, $x, $y)
                && $grille->ligneDeVue($x, $y, (int) $etat->position_x, (int) $etat->position_y, figuresBloquent: true)) {
                $ctx['instance']->update(['position_x' => $x, 'position_y' => $y]);

                return;
            }
        }
    }

    throw new RuntimeException('Aucune case à distance en vue du héros — scénario invalide.');
}

/** Les actions du tour qui portent ce sort. */
function morcarBSort(array $actions, string $nom): ?array
{
    return collect($actions)->firstWhere('sort', $nom);
}

// ==================================================================
// ORC WARCASTER — Nyashak
// ==================================================================

it('Appel des orques pose DEUX orques en vue du lanceur, qui jouent TOUT DE SUITE', function () {
    // « places up to 2 Orcs on spaces they can see. […] They may move and attack
    // immediately unless they have already done so this turn. »
    $ctx = morcarBSorcier('Mage de guerre orque', 'Appel des orques');
    morcarBEloigner($ctx);

    $actions = morcarBActions(morcarBTour($ctx));
    $appel = morcarBSort($actions, 'Appel des orques');

    expect($appel)->not->toBeNull()
        ->and($appel['invoques'])->toHaveCount(2)
        ->and($appel['activation_immediate'])->toHaveCount(2);

    foreach ($appel['invoques'] as $o) {
        $orque = InstanceMonstre::with('monstre')->findOrFail($o['instance_id']);
        expect($orque->monstre->nom_base)->toBe('Orque')->and($orque->etat)->toBe('actif');
    }

    // Chaque orque venu d'être posé a joué UNE fois dans cette phase — pas zéro
    // (il aurait attendu le round suivant), pas deux.
    $ids = array_column($appel['invoques'], 'instance_id');
    $joues = collect($actions)->filter(fn ($a) => ($a['monstre'] ?? null) === 'Orque'
        && in_array($a['instance_id'] ?? $a['id'] ?? null, $ids, true));
    expect($joues)->toHaveCount(2);

    // « Each spell may only be used once per quest » : le sort est dépensé.
    expect($ctx['instance']->fresh()->sorts_dread_lances)->toContain('Appel des orques');
});

it('Appel des gobelins en pose QUATRE', function () {
    $ctx = morcarBSorcier('Mage de guerre orque', 'Appel des gobelins');
    morcarBEloigner($ctx);

    $appel = morcarBSort(morcarBActions(morcarBTour($ctx)), 'Appel des gobelins');

    expect($appel['invoques'])->toHaveCount(4)
        ->and(collect($appel['invoques'])->pluck('monstre')->unique()->all())->toBe(['Gobelin']);
});

it('Esprit de vengeance frappe un héros que le lanceur NE VOIT PAS — « any one character on the board »', function () {
    $ctx = morcarBSorcier('Mage de guerre orque', 'Esprit de vengeance');
    ['quete' => $quete, 'instance' => $boss, 'etatHeros' => $etat] = $ctx;

    // Une case de sol libre hors de sa ligne de vue (une autre salle, un angle).
    $grille = FabriqueGrille::pour($quete, exceptInstanceId: $boss->id);
    $cache = null;

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (in_array($c, ['s', 'p'], true) && caseQueteLibre($quete, $x, $y)
                && ! $grille->ligneDeVue((int) $boss->position_x, (int) $boss->position_y, $x, $y, figuresBloquent: true)) {
                $cache = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }

    expect($cache)->not->toBeNull('aucune case cachée du lanceur — scénario invalide');
    $etat->update(['position_x' => $cache['x'], 'position_y' => $cache['y']]);

    // 4 dés d'attaque ET 2 de défense, tous crânes : « the character defends as normal ».
    $actions = morcarBActions(morcarBTour($ctx, array_fill(0, 12, 1)));
    $esprit = morcarBSort($actions, 'Esprit de vengeance');

    expect($esprit)->not->toBeNull()
        ->and($esprit['resultats'][0]['cible']['nom'])->toBe($ctx['heros']->nom)
        ->and($esprit['resultats'][0]['degats'])->toBe(4);
});

it('Bouclier de protection : +1 dé de défense au lanceur ET aux orques de la salle, jusqu\'à SON prochain tour', function () {
    // « the spellcaster and all Orcs in the same room roll 1 extra combat die in
    // defense until the start of spellcaster's next turn. »
    $ctx = morcarBSorcier('Mage de guerre orque', 'Bouclier de protection');
    $boss = $ctx['instance'];
    $orque = morcarBPoser($ctx, 'Orque');
    $archer = morcarBPoser($ctx, 'Orque archer');
    $gobelin = morcarBPoser($ctx, 'Gobelin'); // pas un orque : rien pour lui

    $json = morcarBTour($ctx);
    $sort = morcarBSort(morcarBActions($json), 'Bouclier de protection');

    expect($sort['renforts']['creatures'])->toHaveCount(3) // lanceur + orque + orque archer
        ->and($orque->fresh()->load('monstre')->defenseEffective())->toBe(3)         // Orque D2 + 1
        ->and($archer->fresh()->load('monstre')->defenseEffective())->toBe(3)        // la variante à distance en est aussi
        ->and($gobelin->fresh()->load('monstre')->defenseEffective())->toBe(1)       // Gobelin D1, inchangé
        ->and($boss->fresh()->load('monstre')->defenseEffective())->toBe(6);         // Warcaster D5 + 1

    // Publié : un effet automatique que rien n'annonce est injouable.
    expect(MoteurDread::etiquettesDread($orque->fresh()))->toContain('Bouclier de protection (+1 dé de défense)');

    // Au début de SON prochain tour, il tombe — et se dit.
    $actions2 = morcarBActions(morcarBTour($ctx));
    expect($orque->fresh()->load('monstre')->defenseEffective())->toBe(2)
        ->and($boss->fresh()->load('monstre')->defenseEffective())->toBe(5)
        ->and(collect($actions2)->contains(fn ($a) => ($a['mecanique'] ?? null) === 'buff_faction'))->toBeTrue();
});

it('Lames aiguisées : +1 dé d\'attaque aux orques de la salle, CE ROUND SEULEMENT, et pas au lanceur', function () {
    $ctx = morcarBSorcier('Mage de guerre orque', 'Lames aiguisées');
    $orque = morcarBPoser($ctx, 'Orque');

    $actions = morcarBActions(morcarBTour($ctx));
    $sort = morcarBSort($actions, 'Lames aiguisées');

    expect($sort['renforts']['creatures'])->toBe(['Orque']); // le lanceur n'est pas un orque

    // L'orque, qui joue APRÈS le lanceur, attaque à 3 + 1 = 4 dés.
    $attaque = collect($actions)->first(fn ($a) => ($a['type'] ?? null) === 'attaque_monstre' && ($a['monstre'] ?? null) === 'Orque');
    expect($attaque)->not->toBeNull()
        ->and($attaque['faces_attaque'])->toHaveCount(4);

    // « For this turn only » : le round suivant s'ouvre, les lames s'émoussent.
    expect($orque->fresh()->etatDread('buff_attaque'))->toBeNull()
        ->and($orque->fresh()->load('monstre')->attaqueEffective())->toBe(3);
});

it('Orque berserker : un orque en vue joue son tour DEUX FOIS, et pas trois', function () {
    // « The Orc moves and attacks twice on this turn only. »
    $ctx = morcarBSorcier('Mage de guerre orque', 'Orque berserker');
    // À côté du SORCIER : une figure (le héros) entre eux masquerait la vue.
    $orque = morcarBPoser($ctx, 'Orque', caseAdjacenteLibre($ctx['quete'], (int) $ctx['instance']->position_x, (int) $ctx['instance']->position_y));

    $actions = morcarBActions(morcarBTour($ctx));
    $sort = morcarBSort($actions, 'Orque berserker');

    expect($sort)->not->toBeNull()->and($sort['double_tour']['instance_id'])->toBe($orque->id);

    $coups = collect($actions)->filter(fn ($a) => ($a['monstre'] ?? null) === 'Orque'
        && in_array($a['type'] ?? '', ['attaque_monstre', 'deplacement_monstre'], true));
    expect($coups)->toHaveCount(2);
});

it('Orque berserker REFUSE un orque qui a déjà joué — « may not be cast on an Orc that has already moved or attacked »', function () {
    // L'orque est le premier de la file (il joue AVANT le sorcier) : quand le
    // sorcier lance son tour, il a déjà agi. Pas d'orque éligible, pas de sort.
    $ctx = demarrerQueteAvecMonstre('Orque');
    $orque = $ctx['instance'];

    $catalogue = Monstre::where('nom_base', 'Mage de guerre orque')->firstOrFail();
    $catalogue->update(['sorts_dread' => ['Orque berserker'], 'archetype_lanceur' => null]);
    $case = caseAdjacenteLibre($ctx['quete'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);
    $sorcier = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id, 'monstre_id' => $catalogue->id, 'pv_body' => 5, 'pv_body_max' => 5,
        'pv_mind' => 7, 'position_x' => $case['x'], 'position_y' => $case['y'], 'etat' => 'actif', 'revele' => true,
    ])->load('monstre');
    app(MoteurDread::class)->reinitialiserUsagesInstance($sorcier, $ctx['quete']);

    expect($sorcier->id)->toBeGreaterThan($orque->id);

    $actions = morcarBActions(morcarBTour($ctx));

    expect(morcarBSort($actions, 'Orque berserker'))->toBeNull();
    expect(collect($actions)->filter(fn ($a) => ($a['monstre'] ?? null) === 'Orque'))->toHaveCount(1);
});

// ==================================================================
// ARTIFICER — la Gardienne
// ==================================================================

it('Parchemins de Morcar : trois jetons, chaque coup entier en retire UN, le sort se brise au dernier', function () {
    $ctx = morcarBSorcier('Artificière', 'Parchemins de Morcar');
    $boss = $ctx['instance'];

    $sort = morcarBSort(morcarBActions(morcarBTour($ctx)), 'Parchemins de Morcar');
    expect($sort['amelioration']['jetons_ombre'])->toBe(3);

    $boss = $boss->fresh()->load('monstre');
    expect((int) $boss->etatDread('jetons_ombre'))->toBe(3)
        ->and(MoteurDread::etiquettesDread($boss))->toContain('Parchemins de Morcar (3 jetons)');

    $pv = (int) $boss->pv_body;
    $degats = app(MoteurDegats::class);

    // « any amount of damage » : un coup de 4 PV compte pour UN jeton, et ne
    // blesse pas.
    for ($restants = 2; $restants >= 0; $restants--) {
        $r = $degats->infligerAMonstre($boss->fresh()->load('monstre'), 4, MoteurDegats::SOURCE_ATTAQUE_HEROS);

        expect($r['reaction'])->toBe('jeton_ombre')
            ->and($r['jetons_restants'])->toBe($restants)
            ->and((int) $boss->fresh()->pv_body)->toBe($pv)
            ->and($r['vaincu'])->toBeFalse();
    }

    // Le troisième coup a éteint le dernier jeton : le quatrième blesse.
    $r = $degats->infligerAMonstre($boss->fresh()->load('monstre'), 4, MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($r['reaction'])->toBeNull()->and((int) $boss->fresh()->pv_body)->toBe($pv - 4)
        ->and(MoteurDread::etiquettesDread($boss->fresh()))->toBe([]);
});

it('Marteau de la Ruine : +2 dés d\'attaque, et il se BRISE sur un coup qui ne retire aucun PV', function () {
    $ctx = morcarBSorcier('Artificière', 'Marteau de la Ruine');
    $boss = $ctx['instance'];

    expect($boss->attaqueEffective())->toBe(4); // « Attack 4+2* » : le +2 n'est pas une statistique

    morcarBTour($ctx);
    $boss = $boss->fresh()->load('monstre');
    expect($boss->attaqueEffective())->toBe(6)
        ->and(MoteurDread::etiquettesDread($boss))->toContain("Marteau de la Ruine (+2 dés d'attaque)");

    // Round suivant : plus aucun sort, il frappe — avec 6 dés, tous boucliers :
    // l'attaque ne retire rien, le sort est brisé.
    $actions = morcarBActions(morcarBTour($ctx));
    $coup = collect($actions)->firstWhere('type', 'attaque_monstre');

    expect($coup)->not->toBeNull()
        ->and($coup['faces_attaque'])->toHaveCount(6)
        ->and($coup['degats'])->toBe(0)
        ->and($coup['marteau_brise'] ?? false)->toBeTrue()
        ->and($boss->fresh()->load('monstre')->attaqueEffective())->toBe(4);
});

it('Drain de vie : un dé par autre figure, égal ou supérieur au Mind — elle perd 1 PV, le lanceur en regagne 1', function () {
    $ctx = morcarBSorcier('Artificière', 'Drain de vie');
    $boss = $ctx['instance'];
    $boss->update(['pv_body' => 3]); // blessé : il y a de quoi regagner
    $pvHeros = (int) $ctx['heros']->pv_body;

    // Mind du héros : 2. Un 5 l'atteint.
    $actions = morcarBActions(morcarBTour($ctx, [5]));
    $sort = morcarBSort($actions, 'Drain de vie');

    expect($sort['resultats'][0]['touche'])->toBeTrue()
        ->and($sort['resultats'][0]['degats'])->toBe(1)
        ->and($sort['drain']['pv_rendus'])->toBe(1)
        ->and((int) $boss->fresh()->pv_body)->toBe(4)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($pvHeros - 1);
});

it('Drain de vie : un dé SOUS le Mind de la cible ne la touche pas, le lanceur ne regagne rien', function () {
    $ctx = morcarBSorcier('Artificière', 'Drain de vie');
    $ctx['instance']->update(['pv_body' => 3]);
    $pvHeros = (int) $ctx['heros']->pv_body;

    $sort = morcarBSort(morcarBActions(morcarBTour($ctx, [1])), 'Drain de vie');

    expect($sort['resultats'][0]['touche'])->toBeFalse()
        ->and($sort['drain']['pv_rendus'])->toBe(0)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($pvHeros);
});

it('Drain de vie saigne aussi les créatures du lanceur — « each OTHER figure » — quand les héros sont au moins aussi nombreux', function () {
    $ctx = morcarBSorcier('Artificière', 'Drain de vie');
    $ctx['instance']->update(['pv_body' => 3]);
    $gobelin = morcarBPoser($ctx, 'Gobelin'); // Mind 1 : touché par tout dé

    $sort = morcarBSort(morcarBActions(morcarBTour($ctx, [5, 5])), 'Drain de vie');

    expect($sort['monstres_touches'])->toHaveCount(1)
        ->and($gobelin->fresh()->etat)->toBe('vaincu')
        ->and($sort['drain']['pv_rendus'])->toBe(2); // le héros ET le gobelin
});

it('Drain de vie n\'est pas lancé quand il saignerait plus les siens que les héros', function () {
    $ctx = morcarBSorcier('Artificière', 'Drain de vie');
    morcarBPoser($ctx, 'Gobelin');
    morcarBPoser($ctx, 'Gobelin'); // deux des siens pour un seul héros

    expect(morcarBSort(morcarBActions(morcarBTour($ctx)), 'Drain de vie'))->toBeNull();
});

it('Invocation de golem pose UN Golem en vue, qui jouera au round suivant', function () {
    $ctx = morcarBSorcier('Artificière', 'Invocation de golem');
    morcarBEloigner($ctx);
    $golem = morcarBSort(morcarBActions(morcarBTour($ctx)), 'Invocation de golem');

    expect($golem['invoques'])->toHaveCount(1)
        ->and($golem['invoques'][0]['monstre'])->toBe('Golem')
        // La carte ne dit rien d'une activation immédiate : le Golem jouera au round suivant.
        ->and($golem)->not->toHaveKey('activation_immediate');
});

it('Appel du Dreadshifter pose UN Dreadshifter en vue, jamais déguisé', function () {
    $ctx = morcarBSorcier('Artificière', 'Appel du Dreadshifter');
    morcarBEloigner($ctx);
    $appel = morcarBSort(morcarBActions(morcarBTour($ctx)), 'Appel du Dreadshifter');
    $ds = InstanceMonstre::findOrFail($appel['invoques'][0]['instance_id']);

    expect($ds->revele)->toBeTrue()->and(MoteurEmbuscade::estDeguise($ds))->toBeFalse();
});

it('Implorer les puissances du Dread : à 0 PV, 3-5 pose une Gargouille SUR LA CASE du lanceur', function () {
    $ctx = morcarBSorcier('Artificière', 'Implorer les puissances du Dread');
    $boss = $ctx['instance'];
    $x = (int) $boss->position_x;
    $y = (int) $boss->position_y;

    desFiges([4]);
    $r = app(MoteurDegats::class)->infligerAMonstre($boss->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    // Le sorcier tombe — la carte ne le sauve pas —, la Gargouille prend sa place.
    expect($r['vaincu'])->toBeTrue()
        ->and($r['reaction_dread']['issue'])->toBe('invoque')
        ->and($r['reaction_dread']['sans_action'])->toBeTrue();

    $garg = InstanceMonstre::with('monstre')->where('quete_id', $ctx['quete']->id)->where('etat', 'actif')
        ->whereHas('monstre', fn ($q) => $q->where('nom_base', 'Gargouille'))->first();
    expect($garg)->not->toBeNull()
        ->and([(int) $garg->position_x, (int) $garg->position_y])->toBe([$x, $y]);

    // Une seule fois : le sort est dépensé.
    expect($boss->fresh()->sorts_dread_lances)->toContain('Implorer les puissances du Dread');
});

it('Implorer les puissances du Dread : 6 glace l\'air — chaque héros du lieu perd 2 PV', function () {
    $ctx = morcarBSorcier('Artificière', 'Implorer les puissances du Dread');
    $pv = (int) $ctx['heros']->pv_body;

    desFiges([6, ...array_fill(0, 20, 4)]);
    $r = app(MoteurDegats::class)->infligerAMonstre($ctx['instance']->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($r['reaction_dread']['issue'])->toBe('froid')
        ->and($r['reaction_dread']['resultats'][0]['degats'])->toBe(2)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($pv - 2);
});

it('Implorer les puissances du Dread : 1-2, ignoré — le sorcier meurt sans rien de plus', function () {
    $ctx = morcarBSorcier('Artificière', 'Implorer les puissances du Dread');
    $pv = (int) $ctx['heros']->pv_body;

    desFiges([1, ...array_fill(0, 20, 4)]);
    $r = app(MoteurDegats::class)->infligerAMonstre($ctx['instance']->fresh()->load('monstre'), 99, MoteurDegats::SOURCE_ATTAQUE_HEROS);

    expect($r['reaction_dread']['issue'])->toBe('ignoree')
        ->and($r['vaincu'])->toBeTrue()
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBe($pv)
        ->and(InstanceMonstre::where('quete_id', $ctx['quete']->id)->where('etat', 'actif')->count())->toBe(0);
});

it('Implorer les puissances du Dread n\'est JAMAIS choisi comme action du tour', function () {
    $ctx = morcarBSorcier('Artificière', 'Implorer les puissances du Dread');

    expect(morcarBSort(morcarBActions(morcarBTour($ctx)), 'Implorer les puissances du Dread'))->toBeNull();
});

it('un sorcier de Morcar lance chacun de ses six sorts UNE SEULE fois par quête', function () {
    $ctx = demarrerQueteAvecMonstre('Artificière');
    $boss = $ctx['instance']->fresh()->load('monstre');

    // Répertoire complet : six sorts, six usages.
    expect((int) $boss->usages_dread)->toBe(6)
        ->and(config('archetypes_lanceurs.artificiere_morcar.sorts'))->toHaveCount(6);

    $lances = [];

    for ($tour = 0; $tour < 8; $tour++) {
        foreach (morcarBActions(morcarBTour($ctx)) as $a) {
            if (($a['type'] ?? null) === 'sort_dread' && isset($a['sort'])) {
                $lances[] = $a['sort'];
            }
        }
    }

    // Jamais deux fois le même.
    expect($lances)->toBe(array_values(array_unique($lances)))->and(count($lances))->toBeGreaterThanOrEqual(3);
});

// ==================================================================
// DREADSHIFTER — l'embuscade
// ==================================================================

it('le Dreadshifter est un COFFRE jusqu\'à ce qu\'un héros entre dans les 8 cases autour, puis il bondit', function () {
    $ctx = demarrerQueteAvecMonstre('Dreadshifter');
    ['quete' => $quete, 'instance' => $ds, 'etatHeros' => $etat, 'alice' => $alice] = $ctx;

    // Un axe dégagé de 4 cases : héros en tête, créature au bout.
    ['dx' => $dx, 'dy' => $dy] = placerHerosSurAxeDegage($quete, $etat, 4);
    $hx = (int) $etat->fresh()->position_x;
    $hy = (int) $etat->fresh()->position_y;
    $ds->update(['position_x' => $hx + 4 * $dx, 'position_y' => $hy + 4 * $dy]);

    expect(app(MoteurEmbuscade::class)->deguiser($quete->fresh(), $ds->fresh()->load('monstre')))->toBeTrue();

    $ds = $ds->fresh()->load('monstre');
    expect($ds->revele)->toBeFalse()->and(MoteurEmbuscade::estDeguise($ds))->toBeTrue();

    // Un COFFRE est posé sur sa case, marqué du faux-meuble.
    $faux = collect($quete->fresh()->carte->grille['mobilier'])->first(fn ($m) => MoteurEmbuscade::estFauxMeuble($m));
    expect($faux)->not->toBeNull()->and([(int) $faux['x'], (int) $faux['y']])->toBe([(int) $ds->position_x, (int) $ds->position_y]);

    // La salle se découvre : un coffre n'est pas un monstre dormant, il reste caché.
    $revelerSalle = new ReflectionMethod(App\Partie\ResolveurTour::class, 'revelerSalle');
    $revelerSalle->setAccessible(true);
    $quete->update(['salles_decouvertes' => []]);
    $revelerSalle->invoke(app(App\Partie\ResolveurTour::class), $ctx['groupe'], $quete->fresh(), 0);
    expect($ds->fresh()->revele)->toBeFalse();

    // Deux pas : toujours hors des 8 cases (distance 2) — rien ne se passe.
    desFiges(array_fill(0, 100, 4));
    $etat->update(['deplacement_tour' => 6, 'deplacement_restant' => null, 'a_deplace' => false, 'a_joue' => false]);

    test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => $hx + 2 * $dx, 'y' => $hy + 2 * $dy],
    ])->assertStatus(202)->assertJsonMissingPath('resultat.embuscades');
    expect($ds->fresh()->revele)->toBeFalse();

    // Un pas de plus : la case voisine du coffre. L'embuscade se déclenche.
    $reponse = test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => $hx + 3 * $dx, 'y' => $hy + 3 * $dy],
    ])->assertStatus(202);

    $embuscade = $reponse->json('resultat.embuscades.0');

    expect($embuscade['type'])->toBe('embuscade')
        ->and($embuscade['instance_id'])->toBe($ds->id)
        // « It may move and attack immediately » : son tour est joué dans la foulée.
        ->and($embuscade['action']['type'] ?? null)->toBe('attaque_monstre');

    $ds = $ds->fresh();
    expect($ds->revele)->toBeTrue()->and(MoteurEmbuscade::estDeguise($ds))->toBeFalse();

    // Le coffre a disparu de la carte.
    $reste = collect($quete->fresh()->carte->grille['mobilier'])
        ->first(fn ($m) => MoteurEmbuscade::estFauxMeuble($m) && empty($m['detruit']));
    expect($reste)->toBeNull();
});

it('un Dreadshifter DÉGUISÉ n\'est ni une cible, ni un acteur de la phase des monstres', function () {
    $ctx = demarrerQueteAvecMonstre('Dreadshifter');
    $ctx['instance']->update(['position_x' => null]); // hors de portée de tout test de voisinage
    $case = caseAdjacenteLibre($ctx['quete'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);
    // Placé au CONTACT mais déguisé : il ne frappe pas, il ne se laisse pas viser.
    $ctx['instance']->update(['position_x' => $case['x'], 'position_y' => $case['y']]);
    app(MoteurEmbuscade::class)->deguiser($ctx['quete']->fresh(), $ctx['instance']->fresh()->load('monstre'));

    $actions = morcarBActions(morcarBTour($ctx));

    expect(collect($actions)->contains(fn ($a) => ($a['instance_id'] ?? null) === $ctx['instance']->id))->toBeFalse();

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(422);
});

// ==================================================================
// MINOTAURE — le coup de corne
// ==================================================================

it('le Minotaure encorne un héros qui FINIT SON TOUR dans les cases autour de lui — 2 dés, hors tour', function () {
    // « a minotaur may immediately roll 2 Attack dice against a hero who ends
    // their turn in one of the 10 spaces surrounding it. »
    $ctx = demarrerQueteAvecMonstre('Minotaure');
    $pv = (int) $ctx['heros']->pv_body;

    // Tout crâne : le coup de corne (2 dés) frappe pour 2, la défense ne pare rien.
    $json = morcarBTour($ctx, array_fill(0, 40, 1));
    $corne = $json['resultat']['coups_de_corne'] ?? [];

    expect($corne)->toHaveCount(1)
        ->and($corne[0]['mecanique'])->toBe('coup_de_corne')
        ->and($corne[0]['faces_attaque'])->toHaveCount(2)
        ->and($corne[0]['degats'])->toBe(2)
        ->and((int) $ctx['heros']->fresh()->pv_body)->toBeLessThan($pv - 1);
});

it('un Minotaure ENDORMI ne frappe pas — « may not gore if they are incapacitated »', function () {
    $ctx = demarrerQueteAvecMonstre('Minotaure');
    $ctx['instance']->update(['habillage' => ['conditions' => ['endormi' => true]]]);

    $json = morcarBTour($ctx, array_fill(0, 40, 1));

    expect($json['resultat'])->not->toHaveKey('coups_de_corne');
});

it('un héros qui finit son tour HORS de l\'anneau du Minotaure n\'est pas encorné', function () {
    $ctx = demarrerQueteAvecMonstre('Minotaure');
    ['quete' => $quete, 'instance' => $m, 'etatHeros' => $etat] = $ctx;

    // Le héros s'éloigne à plus de 2 cases du Minotaure (anneau = 1 case de rayon).
    $grille = FabriqueGrille::pour($quete, exceptInstanceId: $m->id);
    $loin = null;

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (in_array($c, ['s', 'p'], true) && caseQueteLibre($quete, $x, $y)
                && max(abs($x - (int) $m->position_x), abs($y - (int) $m->position_y)) >= 4) {
                $loin = ['x' => $x, 'y' => $y];
                break 2;
            }
        }
    }

    expect($loin)->not->toBeNull();
    $etat->update(['position_x' => $loin['x'], 'position_y' => $loin['y']]);

    $json = morcarBTour($ctx, array_fill(0, 40, 4));
    expect($json['resultat'])->not->toHaveKey('coups_de_corne');
});

// ==================================================================
// URDYN LE DÉFAISEUR
// ==================================================================

/** Met Urdyn en main et recalcule les dés du héros. */
function morcarBArmerUrdyn(Personnage $personnage): void
{
    Inventaire::create([
        'personnage_id' => $personnage->id,
        'objet_id' => Objet::where('nom', 'Urdyn le Défaiseur')->firstOrFail()->id,
        'emplacement' => 'arme_principale',
        'quantite' => 1,
    ]);

    app(Equipement::class)->recalculerCombat($personnage->refresh());
}

function morcarBAttaquer(array $ctx)
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return test()->actingAs($ctx['alice'], 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ]);
}

it('Urdyn lance 4 dés contre un Golem', function () {
    $ctx = demarrerQueteAvecMonstre('Golem');
    morcarBArmerUrdyn($ctx['heros']);
    desFiges(array_fill(0, 40, 4));

    morcarBAttaquer($ctx)->assertStatus(202)->assertJsonPath('resultat.des_attaque_effectifs', 4);
});

it('Urdyn lance 4 dés contre un Dreadshifter — « magical construct (Dreadshifter, Golem) »', function () {
    $ctx = demarrerQueteAvecMonstre('Dreadshifter');
    morcarBArmerUrdyn($ctx['heros']);
    desFiges(array_fill(0, 40, 4));

    morcarBAttaquer($ctx)->assertStatus(202)->assertJsonPath('resultat.des_attaque_effectifs', 4);
});

it('Urdyn ne lance que 2 dés contre toute autre créature', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    morcarBArmerUrdyn($ctx['heros']);
    desFiges(array_fill(0, 40, 4));

    morcarBAttaquer($ctx)->assertStatus(202)->assertJsonPath('resultat.des_attaque_effectifs', 2);
});

// ==================================================================
// Registre et catalogue — dans les deux sens
// ==================================================================

it('recense les 12 cartes des deux sorciers de la vague 2B, six chacun, et les répertoires les nomment toutes', function () {
    $cartes = collect(config('cartes.dread.cartes'))
        ->filter(fn ($c) => preg_match('/Wizards of Morcar — (Orc Warcaster|Artificer)$/', (string) $c['paquet']) === 1);

    expect($cartes)->toHaveCount(12)
        ->and($cartes->groupBy('paquet')->map->count()->values()->all())->toBe([6, 6]);

    foreach (['Orc Warcaster' => 'guerriere_orque_morcar', 'Artificer' => 'artificiere_morcar'] as $sorcier => $archetype) {
        $attendus = $cartes->filter(fn ($c) => str_ends_with((string) $c['paquet'], $sorcier))->pluck('sort_dread')->sort()->values()->all();
        $declares = collect(config("archetypes_lanceurs.{$archetype}.sorts"))->sort()->values()->all();

        expect($declares)->toBe($attendus, "{$sorcier} : répertoire ≠ cartes");
    }

    // Les douze sont portées : aucune carte écartée, donc aucune dette à nommer.
    expect($cartes->whereNull('sort_dread'))->toBeEmpty();
});

it('porte les cinq créatures de la vague 2B exactement comme le tableau des monstres du livret (p. 41)', function () {
    $attendu = [
        'Mage de guerre orque' => [7, 5, 5, 5, 7],   // Orc Warcaster
        'Artificière' => [6, 4, 3, 5, 8],            // Artificer (« 4+2* » : le +2 est Hammer of Ruin)
        'Dreadshifter' => [5, 4, 3, 2, 4],
        'Golem' => [5, 4, 5, 3, 0],
        'Minotaure' => [7, 4, 5, 6, 4],
    ];

    foreach ($attendu as $nom => $stats) {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();

        expect([$m->deplacement, $m->attaque, $m->defense, $m->pv_body, $m->pv_mind])->toBe($stats, $nom)
            ->and($m->boite)->toBe('wizards_of_morcar');
    }

    expect(Monstre::where('nom_base', 'Mage de guerre orque')->value('tier'))->toBe('sous_boss')
        ->and(Monstre::where('nom_base', 'Artificière')->value('tier'))->toBe('boss')
        ->and(Monstre::where('nom_base', 'Minotaure')->value('capacites'))->toBe(['coup_de_corne'])
        ->and(Monstre::where('nom_base', 'Dreadshifter')->value('capacites'))->toBe(['embuscade'])
        ->and(Monstre::where('nom_base', 'Mage de guerre orque')->value('capacites'))->toBe(['sorts_uniques']);
});

it('n\'invoque et n\'appelle que des créatures qui EXISTENT au bestiaire (réaction à 0 PV comprise)', function () {
    $bestiaire = Monstre::pluck('nom_base')->all();

    foreach (['Appel des orques', 'Appel des gobelins', 'Invocation de golem', 'Appel du Dreadshifter'] as $nom) {
        $sort = App\Models\SortDread::where('nom', $nom)->firstOrFail();

        foreach (array_keys((array) data_get($sort->effet, 'invoque', [])) as $creature) {
            expect(in_array($creature, $bestiaire, true))->toBeTrue("{$nom} appelle « {$creature} », absent du bestiaire.");
        }
    }

    $beseech = App\Models\SortDread::where('nom', 'Implorer les puissances du Dread')->firstOrFail();

    foreach ((array) data_get($beseech->effet, 'sur_zero_pv', []) as $ligne) {
        foreach (array_keys((array) ($ligne['invoque'] ?? [])) as $creature) {
            expect(in_array($creature, $bestiaire, true))->toBeTrue("Beseech invoque « {$creature} », absent du bestiaire.");
        }
    }
});

it('le démarrage d\'une quête DÉGUISE en coffre toute créature qui porte l\'embuscade, sur sa case de départ', function () {
    // Les spawns d'une quête sont tirés par budget : on donne la capacité à tout
    // le tier `base` pour que le tirage en contienne à coup sûr, et l'on vérifie
    // le point de passage (`DemarreurQuete` → `MoteurEmbuscade::deguiser()`).
    Monstre::where('tier', 'base')->update(['capacites' => ['embuscade']]);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $deguises = $quete->instancesMonstres()->with('monstre')->get()
        ->filter(fn (InstanceMonstre $i) => MoteurEmbuscade::estDeguise($i));

    expect($deguises)->not->toBeEmpty();

    $faux = collect($quete->carte->grille['mobilier'])->filter(fn ($m) => MoteurEmbuscade::estFauxMeuble($m));
    expect($faux)->toHaveCount($deguises->count());

    foreach ($deguises as $i) {
        expect($i->revele)->toBeFalse()
            ->and($faux->contains(fn ($m) => (int) $m['embuscade_instance_id'] === $i->id
                && (int) $m['x'] === (int) $i->position_x && (int) $m['y'] === (int) $i->position_y))->toBeTrue();
    }
});
