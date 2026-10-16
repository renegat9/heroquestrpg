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
use App\Partie\Grille;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Dague de jet magique — « It may be thrown at any monster or player visible to
 * the owner. The dagger is lost once thrown. It cannot be used on an adjacent
 * target. » (reference/16 §10)
 *
 * Deux défauts se cachaient derrière une arme qui porte À LA FOIS `portee:
 * distance` et `jetable` :
 *  1. `MenuMoteur` la rangeait sous `attaquer` (le test `! $aDistanceArme` la
 *     croyait arme de tir) : le résolveur ne la supprimait jamais, et on la
 *     relançait indéfiniment. Une arme jetable visée hors contact part TOUJOURS
 *     par `lancer`, et se perd.
 *  2. `inutilisable_adjacent` n'avait de lecteur que dans le RÉSOLVEUR : le menu
 *     offrait la cible au contact, le résolveur la refusait. Un seul prédicat,
 *     `Objet::interditAuContact()`, lu des deux côtés — pour l'Arbalète comme
 *     pour la dague.
 *  3. (René, 2026-10-15) L'arme ne tire pas au contact, mais le héros n'est pas
 *     désarmé : au contact il FRAPPE À MAINS NUES, l'arme reste en main. Le menu
 *     garde la cible, marquée par le serveur (`mains_nues`, `des_attaque`,
 *     `indication`) ; `frapper()` applique la même règle ; seul un `lancer` au
 *     contact reste refusé.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

/** Remplace tout l'inventaire du héros par UNE arme en main droite. */
function dagueArmerSeulementDe(Personnage $personnage, string $nom): Inventaire
{
    $personnage->inventaire()->delete();

    $ligne = Inventaire::create([
        'personnage_id' => $personnage->id,
        'objet_id' => Objet::where('nom', $nom)->firstOrFail()->id,
        'emplacement' => 'arme_principale',
        'quantite' => 1,
    ]);

    app(Equipement::class)->recalculerCombat($personnage->refresh());

    return $ligne;
}

/** Une case de sol à ≥ 2 de (hx, hy), en ligne de vue dégagée, hors `$exclues`. */
function dagueCaseADistance(Quete $quete, int $hx, int $hy, array $exclues = []): array
{
    $grille = Grille::depuisCarte($quete->carte);

    foreach ($quete->carte->grille['cases'] as $y => $ligne) {
        foreach ($ligne as $x => $c) {
            if (! in_array($c, ['s', 'p'], true) || abs($x - $hx) + abs($y - $hy) < 2) {
                continue;
            }

            if (in_array("{$x},{$y}", $exclues, true)) {
                continue;
            }

            if ($grille->ligneDeVue($hx, $hy, $x, $y)) {
                return ['x' => $x, 'y' => $y];
            }
        }
    }

    throw new RuntimeException('Aucune case à distance avec ligne de vue.');
}

function dagueEloigner(array $ctx): void
{
    $case = dagueCaseADistance($ctx['quete'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y);
    $ctx['instance']->update(['position_x' => $case['x'], 'position_y' => $case['y']]);
}

/** Génère le menu comme en jeu et rend ses options (le contrôleur ne connaît que lui). */
function dagueMenu(array $ctx): array
{
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);

    return Cache::get(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id))['menu']['options'];
}

/** Les ids de cibles que l'option `$id` met sur sa liste blanche (toutes armes confondues). */
function dagueCiblesDe(array $options, string $id): array
{
    $option = collect($options)->firstWhere('id', $id);

    if ($option === null) {
        return [];
    }

    $listes = isset($option['parametres']['armes'])
        ? collect($option['parametres']['armes'])->pluck('cibles')
        : collect([$option['parametres']['cibles'] ?? []]);

    return $listes->flatten(1)->pluck('id')->map(fn ($i) => (int) $i)->all();
}

/** Remplace le menu du héros par un menu PÉRIMÉ / FORGÉ qui offre `$option`. */
function dagueForgerMenu(array $ctx, array $option): void
{
    Cache::put(GenererMenu::cleMenu($ctx['groupe']->id, (int) $ctx['alice']->id), [
        'personnage_id' => $ctx['heros']->id,
        'menu' => ['options' => [$option]],
    ], now()->addMinutes(60));
}

// ---------------------------------------------------------------------------
// Hors contact : toujours `lancer`, et l'arme est perdue
// ---------------------------------------------------------------------------

it('la Dague de jet magique visée à distance part par `lancer` (perdue), jamais sous `attaquer`', function () {
    $ctx = demarrerQueteAvecMonstre('Orque'); // 2 PV : il survit au point unique
    $ligne = dagueArmerSeulementDe($ctx['heros'], 'Dague de jet magique');
    dagueEloigner($ctx);

    $options = dagueMenu($ctx);
    $lancer = collect($options)->firstWhere('id', 'lancer');

    expect($lancer)->not->toBeNull()
        ->and($lancer['libelle'])->toBe('Lancer Dague de jet magique (perdue)')
        ->and($lancer['lancer'])->toBeTrue()
        // La cible figure sur la liste blanche du lancer…
        ->and(dagueCiblesDe($options, 'lancer'))->toBe([$ctx['instance']->id])
        // …et nulle part sous `attaquer` (l'option disparaît faute de cible).
        ->and(dagueCiblesDe($options, 'attaquer'))->toBe([])
        ->and(collect($options)->pluck('id')->all())->not->toContain('attaquer');

    $pvAvant = (int) $ctx['instance']->fresh()->pv_body;
    desFiges([]); // dégâts fixes : le moindre dé lancé ferait exploser le test

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.degats', 1)
        ->assertJsonPath('resultat.faces_attaque', [])
        ->assertJsonPath('resultat.lancer.arme', 'Dague de jet magique')
        ->assertJsonPath('resultat.lancer.perdue', true);

    expect((int) $ctx['instance']->fresh()->pv_body)->toBe($pvAvant - 1)
        // L'arme quitte la main POUR DE BON : plus de seconde lancée possible.
        ->and(Inventaire::whereKey($ligne->id)->exists())->toBeFalse()
        ->and($ctx['heros']->inventaire()->count())->toBe(0);
});

it('une dague de jet magique déjà lancée ne peut plus être relancée', function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    dagueArmerSeulementDe($ctx['heros'], 'Dague de jet magique');
    dagueEloigner($ctx);

    dagueMenu($ctx);
    desFiges([]);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    // Mains vides : le menu suivant n'offre plus ni lancer ni attaque à distance.
    $ctx['etatHeros']->update(['a_agi' => false]);
    $options = collect(dagueMenu($ctx))->pluck('id')->all();

    expect($options)->not->toContain('lancer')
        ->and($options)->not->toContain('attaquer');
});

it('refuse un `attaquer` PÉRIMÉ qui viserait à distance avec la Dague de jet magique', function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    $ligne = dagueArmerSeulementDe($ctx['heros'], 'Dague de jet magique');
    dagueEloigner($ctx);

    // Un menu d'avant le correctif : la cible lointaine sous `attaquer`.
    dagueMenu($ctx);
    dagueForgerMenu($ctx, [
        'id' => 'attaquer', 'libelle' => 'Attaquer', 'type' => 'attaque', 'lancer' => false,
        'parametres' => ['arme' => 'arme_principale', 'cibles' => [
            ['id' => $ctx['instance']->id, 'type' => 'monstre', 'nom' => 'Orque', 'distance' => true],
        ]],
    ]);

    desFiges([]);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(422);

    // Rien n'a bougé : ni PV perdu, ni arme consommée.
    expect(Inventaire::whereKey($ligne->id)->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Au contact : `inutilisable_adjacent` retire la cible, menu ET résolveur
// ---------------------------------------------------------------------------

it('un monstre AU CONTACT est frappé À MAINS NUES avec la Dague de jet magique — jamais lancée, jamais perdue', function () {
    $ctx = demarrerQueteAvecMonstre('Orque'); // placé au contact par le helper
    $ligne = dagueArmerSeulementDe($ctx['heros'], 'Dague de jet magique');
    $desMainsNues = app(Equipement::class)->desAttaqueAvec($ctx['heros']->fresh(), null);

    $options = dagueMenu($ctx);
    $attaquer = collect($options)->firstWhere('id', 'attaquer');
    $cible = $attaquer['parametres']['cibles'][0];

    // La cible au contact reste sur la liste blanche de `attaquer`, marquée PAR LE SERVEUR.
    expect(dagueCiblesDe($options, 'attaquer'))->toBe([$ctx['instance']->id])
        ->and(dagueCiblesDe($options, 'lancer'))->toBe([])
        ->and($cible['mains_nues'])->toBeTrue()
        ->and($cible['distance'])->toBeFalse()
        ->and($cible['des_attaque'])->toBe($desMainsNues)
        ->and($cible['indication'])->toBe("à mains nues ({$desMainsNues} dé".($desMainsNues > 1 ? 's' : '').')');

    // Choix forgé : LANCER la dague sur un monstre au contact reste refusé par le résolveur (422).
    dagueForgerMenu($ctx, [
        'id' => 'lancer', 'lancer' => true, 'libelle' => 'Lancer Dague de jet magique (perdue)', 'type' => 'attaque',
        'parametres' => ['arme' => 'arme_principale', 'cibles' => [
            ['id' => $ctx['instance']->id, 'type' => 'monstre', 'nom' => 'Orque'],
        ]],
    ]);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(422);
    expect(Inventaire::whereKey($ligne->id)->exists())->toBeTrue();

    // Le menu réel : le coup part à MAINS NUES — dés des mains nues, pas les dégâts fixes de la dague.
    dagueMenu($ctx);
    desFiges(array_fill(0, 30, 1)); // des crânes : l'Orque (2 PV) tombe si le coup touche assez

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.portee', 'corps_a_corps')
        ->assertJsonPath('resultat.des_attaque_effectifs', $desMainsNues)
        ->assertJsonMissingPath('resultat.lancer');

    // L'arme est RESTÉE en main : rien n'a été lancé, rien n'est perdu.
    expect(Inventaire::whereKey($ligne->id)->exists())->toBeTrue()
        ->and($ligne->fresh()->emplacement)->toBe('arme_principale');
});

it('avec un monstre au contact ET un autre à distance, seul le second est lançable — le premier se frappe à mains nues', function () {
    $ctx = demarrerQueteAvecMonstre('Orque');
    dagueArmerSeulementDe($ctx['heros'], 'Dague de jet magique');

    $contact = $ctx['instance'];
    $case = dagueCaseADistance(
        $ctx['quete'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y,
        ["{$contact->position_x},{$contact->position_y}"],
    );
    $loin = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Orque')->value('id'),
        'pv_body' => 2, 'pv_body_max' => 2, 'pv_mind' => 0,
        'position_x' => $case['x'], 'position_y' => $case['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    $options = dagueMenu($ctx);

    // Le lointain se LANCE (perdue) ; celui au contact se frappe À MAINS NUES sous `attaquer`.
    expect(dagueCiblesDe($options, 'lancer'))->toBe([$loin->id])
        ->and(dagueCiblesDe($options, 'attaquer'))->toBe([$contact->id])
        ->and(collect($options)->firstWhere('id', 'attaquer')['parametres']['cibles'][0]['mains_nues'])->toBeTrue();

    // Le monstre au contact, soumis au `lancer`, est refusé : hors liste blanche.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer', 'parametres' => ['cible_id' => $contact->id],
    ])->assertStatus(422);

    desFiges([]);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'lancer', 'parametres' => ['cible_id' => $loin->id],
    ])->assertStatus(202)->assertJsonPath('resultat.lancer.perdue', true);
});

it('l\'arbalétrier frappe à mains nues le monstre au contact ET tire à l\'arbalète sur celui qui est loin, dans le même menu', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin', ['classe' => 'elfe']);
    $ligne = dagueArmerSeulementDe($ctx['heros'], 'Arbalète');
    $desMainsNues = app(Equipement::class)->desAttaqueAvec($ctx['heros']->fresh(), null);
    $desArbalete = (int) $ctx['heros']->fresh()->des_attaque;

    // L'arme donne plus de dés que les mains : sans quoi le test ne distinguerait rien.
    expect($desArbalete)->toBeGreaterThan($desMainsNues);

    $contact = $ctx['instance'];
    $case = dagueCaseADistance(
        $ctx['quete'], (int) $ctx['etatHeros']->position_x, (int) $ctx['etatHeros']->position_y,
        ["{$contact->position_x},{$contact->position_y}"],
    );
    $loin = InstanceMonstre::create([
        'quete_id' => $ctx['quete']->id,
        'monstre_id' => Monstre::where('nom_base', 'Orque')->value('id'),
        'pv_body' => 2, 'pv_body_max' => 2, 'pv_mind' => 0,
        'position_x' => $case['x'], 'position_y' => $case['y'],
        'etat' => 'actif', 'revele' => true,
    ]);

    $options = dagueMenu($ctx);
    $cibles = collect(collect($options)->firstWhere('id', 'attaquer')['parametres']['cibles'])->keyBy('id');

    // Une seule entrée d'arme, deux cibles : le contact « à mains nues », le lointain avec l'arme.
    expect(dagueCiblesDe($options, 'lancer'))->toBe([])
        ->and($cibles->keys()->sort()->values()->all())->toBe(collect([$contact->id, $loin->id])->sort()->values()->all())
        ->and($cibles[$contact->id]['mains_nues'])->toBeTrue()
        ->and($cibles[$contact->id]['des_attaque'])->toBe($desMainsNues)
        ->and($cibles[$contact->id]['distance'])->toBeFalse()
        ->and($cibles[$loin->id]['distance'])->toBeTrue()
        ->and(array_key_exists('mains_nues', $cibles[$loin->id]))->toBeFalse()
        ->and(array_key_exists('indication', $cibles[$loin->id]))->toBeFalse();

    // 1. Au contact : dés des MAINS NUES, corps-à-corps, arme conservée.
    desFiges(array_fill(0, 30, 4)); // boucliers blancs : aucun crâne, la frappe ne tue pas, on ne mesure que les dés
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $contact->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.portee', 'corps_a_corps')
        ->assertJsonPath('resultat.des_attaque_effectifs', $desMainsNues);

    expect(Inventaire::whereKey($ligne->id)->exists())->toBeTrue()
        ->and((int) $ctx['heros']->fresh()->des_attaque)->toBe($desArbalete); // la colonne de l'arme n'a pas bougé

    // 2. Tour suivant : sur le lointain, l'ARBALÈTE tire avec ses dés.
    // (requête directe : le modèle du contexte est périmé, un `update` sur lui n'écrirait rien)
    EtatPersonnageQuete::whereKey($ctx['etatHeros']->id)->update(['a_agi' => false, 'a_joue' => false]);
    dagueMenu($ctx);
    desFiges(array_fill(0, 30, 4));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $loin->id],
    ])->assertStatus(202)
        ->assertJsonPath('resultat.portee', 'distance')
        ->assertJsonPath('resultat.des_attaque_effectifs', $desArbalete);
});

// ---------------------------------------------------------------------------
// Les armes SANS la clé ne bougent pas
// ---------------------------------------------------------------------------

it('la Dague et la Hachette ordinaires : attaquer au contact, lancer (perdue) à distance', function (string $nom) {
    // Au contact : sous `attaquer`, jamais sous `lancer`.
    $ctx = demarrerQueteAvecMonstre('Orque');
    dagueArmerSeulementDe($ctx['heros'], $nom);

    $options = dagueMenu($ctx);
    expect(dagueCiblesDe($options, 'attaquer'))->toBe([$ctx['instance']->id])
        ->and(dagueCiblesDe($options, 'lancer'))->toBe([]);

    // À distance : sous `lancer` seulement, libellé « (perdue) ».
    dagueEloigner($ctx);
    $options = dagueMenu($ctx);

    expect(dagueCiblesDe($options, 'lancer'))->toBe([$ctx['instance']->id])
        ->and(dagueCiblesDe($options, 'attaquer'))->toBe([])
        ->and(collect($options)->firstWhere('id', 'lancer')['libelle'])->toBe("Lancer {$nom} (perdue)");
})->with(['Dague', 'Hachette']);

it('la clé `inutilisable_adjacent` ne retire des cibles que là où la carte la porte', function () {
    $porteuses = Objet::all()->filter(fn (Objet $o) => $o->interditAuContact())->pluck('nom')->sort()->values()->all();

    // Les armes de tir de la carte « Ranged » + la dague magique, et rien d'autre.
    expect($porteuses)->toContain('Arbalète', 'Dague de jet magique')
        ->and($porteuses)->not->toContain('Baguette', 'Dague', 'Hachette', 'Épée courte');

    // Une arme sans la clé n'est JAMAIS touchée, lancée ou non.
    expect(Objet::where('nom', 'Hachette')->firstOrFail()->interditAuContact(lancee: true))->toBeFalse()
        ->and(Objet::where('nom', 'Dague')->firstOrFail()->interditAuContact(lancee: true))->toBeFalse();
});
