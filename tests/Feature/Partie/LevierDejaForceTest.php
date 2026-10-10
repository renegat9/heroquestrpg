<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use App\Partie\MoteurPortes;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Levier déjà forcé (verdict Morcar, 2026-10-09 § 2) :
 *  - un levier dont la porte est OUVERTE n'est plus proposé par le menu, et le résolveur
 *    refuse le geste (même prédicat : `MoteurPortes::levierAOuvrir()`) ;
 *  - une réussite NOMME toutes les portes qu'elle ouvre, jumelles de seuil comprises.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class,
        PiegeSeeder::class, ObjetSeeder::class, CompetenceSeeder::class, ConditionSeeder::class]);
});

/** Quête démarrée à deux héros ; Albrecht (le premier) a la main. @return array{0: JoueurAuthentifiable, 1: \App\Models\Groupe, 2: \App\Models\Personnage, 3: Quete, 4: EtatPersonnageQuete} */
function demarrerLeverForce(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    return [$alice, $groupe, $hero, $quete, $etat];
}

/** Fixe portes et leviers de la carte (les éléments générés sont écartés). */
function poserLeviersTest(Quete $quete, array $portes, array $leviers): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['portes'] = $portes;
    $grille['leviers'] = $leviers;
    $carte->update(['grille' => $grille]);
    $quete->load('carte');
}

it('ne propose plus un levier déjà forcé : sa porte est ouverte', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerLeverForce();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Témoin : porte encore fermée → le levier est proposé.
    poserLeviersTest($quete, [['x' => $hx + 2, 'y' => $hy, 'etat' => 'verrouillee', 'verrou' => ['type' => 'levier', 'levier_id' => 'L1']]],
        [['x' => $hx + 1, 'y' => $hy, 'levier_id' => 'L1']]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids)->toContain("actionner_levier_".($hx + 1)."_{$hy}");

    // Même levier, porte déjà ouverte → le menu ne le propose plus.
    poserLeviersTest($quete, [['x' => $hx + 2, 'y' => $hy, 'etat' => 'ouverte', 'verrou' => ['type' => 'levier', 'levier_id' => 'L1']]],
        [['x' => $hx + 1, 'y' => $hy, 'levier_id' => 'L1']]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');

    expect($ids)->not->toContain("actionner_levier_".($hx + 1)."_{$hy}");
});

it('refuse un levier déjà forcé même si un menu périmé le propose encore', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerLeverForce();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    poserLeviersTest($quete, [['x' => $hx + 2, 'y' => $hy, 'etat' => 'ouverte', 'verrou' => ['type' => 'levier', 'levier_id' => 'L1']]],
        [['x' => $hx + 1, 'y' => $hy, 'levier_id' => 'L1']]);

    $optionId = "actionner_levier_".($hx + 1)."_{$hy}";
    Cache::put(GenererMenu::cleMenu($groupe->id, (int) $alice->id), [
        'personnage_id' => $hero->id,
        'allie_id' => null,
        'menu' => ['situation' => 'Périmé', 'options' => [[
            'id' => $optionId, 'libelle' => 'Forcer le levier', 'type' => 'actionner_levier',
            'parametres' => ['levier' => ['x' => $hx + 1, 'y' => $hy, 'levier_id' => 'L1']],
        ]]],
    ], now()->addMinutes(5));

    desFiges(array_fill(0, 8, 1));

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $optionId])
        ->assertStatus(422)
        ->assertJsonValidationErrors('option_id');

    expect($quete->fresh()->carte->grille['portes'][0]['etat'])->toBe('ouverte');
});

it('nomme TOUTES les portes qu\'une réussite ouvre, jumelles de seuil comprises', function () {
    [$alice, $groupe, $hero, $quete, $etat] = demarrerLeverForce();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Deux portes d'un MÊME seuil (même jonction, même côté, même X) : `MoteurPortes::ouvrir()`
    // les ouvre ensemble. Le levier n'est lié qu'à la première.
    poserLeviersTest($quete, [
        ['x' => $hx + 2, 'y' => $hy, 'etat' => 'verrouillee', 'cote' => 'e', 'jonction' => 'J1',
            'verrou' => ['type' => 'levier', 'levier_id' => 'L1']],
        ['x' => $hx + 2, 'y' => $hy + 1, 'etat' => 'verrouillee', 'cote' => 'e', 'jonction' => 'J1',
            'verrou' => ['type' => 'levier', 'levier_id' => 'L1']],
    ], [['x' => $hx + 1, 'y' => $hy, 'levier_id' => 'L1']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $optionId = "actionner_levier_".($hx + 1)."_{$hy}";

    desFiges(array_fill(0, 8, 1)); // crânes : le levier cède

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => $optionId])
        ->assertStatus(202)
        ->assertJsonPath('resultat.force', true);

    $ouvertes = collect($reponse->json('resultat.portes_ouvertes'));

    expect($ouvertes)->toHaveCount(2)
        ->and($ouvertes->pluck('y')->sort()->values()->all())->toBe([$hy, $hy + 1])
        ->and(collect($quete->fresh()->carte->grille['portes'])->pluck('etat')->unique()->all())->toBe(['ouverte'])
        ->and(app(MoteurPortes::class)->levierAOuvrir($quete->fresh()->carte, 'L1'))->toBeFalse();
});
