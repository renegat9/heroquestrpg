<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\MoteurSorts;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * RELEVER = UNE ACTION, PAS TOUT LE TOUR (décision de René, 2026-10-09, verdict Morcar).
 *
 * Avant, `relever` posait `a_joue` : le héros sacrifiait son tour entier pour une seule
 * relève. Il consomme désormais le créneau d'ACTION. Trois propriétés, par les vraies
 * routes :
 *  1. le héros peut encore SE DÉPLACER après la relève (menu ET résolveur) ;
 *  2. une seconde action est refusée, et le menu ne la propose pas — « le menu ne
 *     propose jamais ce que le résolveur refuse » ;
 *  3. s'il avait ENTAMÉ son déplacement, la relève lui confisque le reliquat, et le menu
 *     l'a annoncé AVANT le geste (`perd_deplacement`) — règle gardée, mais annoncée.
 */

beforeEach(function () {
    $this->seed([SortSeeder::class, ObjetSeeder::class, ConditionSeeder::class,
        ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class]);
    Http::fake();
});

/**
 * Grimnar (le sauveteur, en (10,10)) et Khazra TOMBÉ en (11,10), à son contact.
 * Une place 4×4 dégagée et entourée de roche : aucune autre voie que celle du test,
 * aucun monstre (tous vaincus).
 *
 * @return array{alice: JoueurAuthentifiable, groupe: \App\Models\Groupe, quete: Quete, grimnar: Personnage, khazra: Personnage, eG: EtatPersonnageQuete}
 */
function scenarioReleverUneAction(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $grimnar = creerHeros($alice, $groupe, 'Grimnar', 1, ['classe' => 'barbare']);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    $khazra = creerHeros($bob, $groupe, 'Khazra', 2, ['classe' => 'nain']);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);

    $carte = $quete->carte;
    $grille = $carte->grille;
    for ($y = 8; $y <= 13; $y++) {
        for ($x = 8; $x <= 13; $x++) {
            $grille['cases'][$y][$x] = 'm';
        }
    }
    for ($y = 9; $y <= 12; $y++) {
        for ($x = 9; $x <= 12; $x++) {
            $grille['cases'][$y][$x] = 's';
        }
    }
    $grille['pieges'] = [];
    $carte->update(['grille' => $grille]);

    $eG = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $grimnar->id)->firstOrFail();
    $eG->update(['position_x' => 10, 'position_y' => 10, 'deplacement_tour' => 6,
        'deplacement_restant' => null, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $khazra->id)
        ->update(['position_x' => 11, 'position_y' => 10, 'tombe' => true]);
    $khazra->update(['pv_body' => 0]);

    return compact('alice', 'groupe', 'quete', 'grimnar', 'khazra', 'eG');
}

/** Le menu courant de Grimnar, tel que la manette le reçoit (régénéré à la demande). */
function menuDeGrimnar(\App\Models\Groupe $groupe, JoueurAuthentifiable $alice, Personnage $grimnar): array
{
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $grimnar->id);

    return Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
}

/** Un pas de Grimnar vers (x, y), par la vraie route. */
function pasVers(JoueurAuthentifiable $alice, int $x, int $y): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($alice, 'joueur')->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'se_deplacer', 'parametres' => ['x' => $x, 'y' => $y],
    ]);
}

it('relever consomme le créneau d\'ACTION : le menu le publie ainsi, et le tour ne se clôt pas', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'grimnar' => $grimnar, 'eG' => $eG] = scenarioReleverUneAction();

    $menu = menuDeGrimnar($groupe, $alice, $grimnar);
    $relever = collect($menu['options'])->firstWhere('type', 'relever');

    expect($relever)->not->toBeNull()
        ->and($relever['creneau'])->toBe('action')
        ->and($relever['perd_deplacement'])->toBe(0);   // rien n'est entamé : rien à perdre

    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'relever');

    // L'ACTION est dépensée, le TOUR ne l'est pas.
    $eG->refresh();
    expect((bool) $eG->a_agi)->toBeTrue()
        ->and((bool) $eG->a_joue)->toBeFalse();

    // Le menu suivant propose encore le déplacement, à l'allonce ENTIÈRE, et
    // plus aucune action : la relève n'est plus offerte, la fin du tour l'est.
    $apres = menuDeGrimnar($groupe, $alice, $grimnar);

    expect(collect($apres['options'])->firstWhere('type', 'deplacement'))->not->toBeNull()
        ->and(collect($apres['options'])->firstWhere('type', 'relever'))->toBeNull()
        ->and(collect($apres['options'])->firstWhere('type', 'attente'))->not->toBeNull();

    // …et le résolveur accepte réellement ce pas, avec le reliquat entier.
    pasVers($alice, 10, 11)->assertStatus(202);
    expect((int) $eG->fresh()->deplacement_restant)->toBe(5);   // 6 − 1 pas
});

it('une seconde action après la relève est refusée, et le menu ne la propose plus', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'grimnar' => $grimnar] = scenarioReleverUneAction();

    $relever = collect(menuDeGrimnar($groupe, $alice, $grimnar)['options'])->firstWhere('type', 'relever');
    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(202);

    // Rejouer la même relève : refusée (l'option a quitté le menu).
    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(422);
});

it('relever après un pas CONFISQUE le reliquat, et le menu l\'annonce avant le geste', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'grimnar' => $grimnar, 'eG' => $eG] = scenarioReleverUneAction();

    // (11,11) est au contact de Khazra (11,10) : la relève reste offerte après le pas.
    // Chemin de 2 cases → reliquat 6 − 2 = 4.
    pasVers($alice, 11, 11)->assertStatus(202);
    expect((int) $eG->fresh()->deplacement_restant)->toBe(4);

    $menu = menuDeGrimnar($groupe, $alice, $grimnar);
    $actions = collect($menu['options'])->where('creneau', 'action');

    expect($actions)->not->toBeEmpty()
        ->and($actions->pluck('perd_deplacement')->unique()->values()->all())->toBe([4]);

    $relever = $actions->firstWhere('type', 'relever');
    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(202);

    // Le reliquat est confisqué par l'action, le déplacement est fini.
    $eG->refresh();
    expect((int) $eG->deplacement_restant)->toBe(0)
        ->and((bool) $eG->a_deplace)->toBeTrue()
        ->and((bool) $eG->a_agi)->toBeTrue()
        ->and((bool) $eG->a_joue)->toBeFalse();
});

it('Vent Véloce (×2) : un pas puis une relève confisquent le reliquat — la règle ne dépend plus du seul jet', function () {
    // Régression : la condition « entamé » comparait le reliquat au total du JET
    // (6). Avec ×2, le reliquat après un pas est 12 − 2 = 10 > 6 : rien n'était perdu.
    ['alice' => $alice, 'groupe' => $groupe, 'grimnar' => $grimnar, 'eG' => $eG] = scenarioReleverUneAction();

    app(MoteurSorts::class)->appliquerBuff($grimnar, Sort::where('nom', 'Vent Véloce')->firstOrFail());

    pasVers($alice, 11, 11)->assertStatus(202);
    expect((int) $eG->fresh()->deplacement_restant)->toBe(10);

    $actions = collect(menuDeGrimnar($groupe, $alice, $grimnar)['options'])->where('creneau', 'action');
    expect($actions->pluck('perd_deplacement')->unique()->values()->all())->toBe([10]);

    $relever = $actions->firstWhere('type', 'relever');
    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(202);

    expect((int) $eG->fresh()->deplacement_restant)->toBe(0)
        ->and((bool) $eG->fresh()->a_deplace)->toBeTrue();
});

it('un drapeau de Vague montante ce tour : l\'action ne confisque rien, et le menu le dit (perd_deplacement 0)', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'grimnar' => $grimnar, 'eG' => $eG] = scenarioReleverUneAction();

    pasVers($alice, 11, 11)->assertStatus(202);
    // Le drapeau que pose le style « Vague montante » (`deplacement_scinde`) ce tour.
    $eG->update(['capacites_tour' => ['deplacement_scinde']]);

    $actions = collect(menuDeGrimnar($groupe, $alice, $grimnar)['options'])->where('creneau', 'action');
    expect($actions->pluck('perd_deplacement')->unique()->values()->all())->toBe([0]);

    $relever = $actions->firstWhere('type', 'relever');
    test()->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => $relever['id']])
        ->assertStatus(202);

    // Le reliquat est GARDÉ : Vague montante lève la confiscation, et rien d'autre.
    $eG->refresh();
    expect((int) $eG->deplacement_restant)->toBe(4)
        ->and((bool) $eG->a_deplace)->toBeFalse();
});
