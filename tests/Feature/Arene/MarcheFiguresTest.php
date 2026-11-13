<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MonstreSeeder;
use Illuminate\Validation\ValidationException;

/**
 * MODE ARÈNE — la MARCHE d'une figure Monster vs Monster (contrat « Monster vs Monster — le combat », 2026-11-12) :
 * chaque destination publiée est atteinte telle quelle (une grande figure ne s'arrête pas à mi-chemin), la figure
 * publie son emprise, et un tir refusé dit sa vraie raison. Validation par combats d'agents.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class]);
});

/** Un combat Monster vs Monster lancé (puissance 4, deux gobelins par camp) ; renvoie [alice, bob, code, camp défenseur]. */
function combatMvmPourMarche(): array
{
    $alice = connecterJoueur('alice');
    $code = test()->postJson('/api/arene/monstres')->assertCreated()->json('groupe.identifiant');
    $bob = connecterJoueur('bob');
    test()->postJson("/api/arene/{$code}/camp", ['camp' => 'b'])->assertOk();

    test()->actingAs($alice, 'joueur');
    test()->putJson("/api/arene/{$code}/puissance", ['puissance' => 4])->assertOk();
    $salon = test()->postJson("/api/arene/{$code}/tirage")->assertOk()->json('groupe.arene');
    $defender = $salon['tour_selection'];
    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;
    $gobelin = (int) Monstre::where('nom_base', 'Gobelin')->where('boite', 'base')->value('id');

    foreach ([$defender, $defender === 'a' ? 'b' : 'a', $defender, $defender === 'a' ? 'b' : 'a'] as $camp) {
        test()->actingAs($joueurDe($camp), 'joueur');
        test()->postJson("/api/arene/{$code}/selection", ['monstre_id' => $gobelin])->assertOk();
    }

    test()->postJson("/api/arene/{$code}/lancer")->assertCreated();

    return [$alice, $bob, $code, $defender];
}

it('MARCHE : chaque destination publiée d\'un Ogre champion (emprise 1×2) est atteinte, et (5,5) n\'est plus offerte', function () {
    [$alice, $bob, $code, $defender] = combatMvmPourMarche();
    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;
    $quete = Groupe::where('identifiant', $code)->firstOrFail()->queteCourante;
    $ogre = Monstre::where('nom_base', 'Ogre champion')->firstOrFail();

    // L'exemple du défaut : un Ogre champion au milieu du hall, emprise 1×2 (9,4)+(9,5). Le gravat (7,5) coupe
    // sa seconde case sur le chemin direct vers (5,5) : la publication l'offrait, la marche s'arrêtait en (8,4).
    $figure = InstanceMonstre::where('quete_id', $quete->id)->where('camp_arene', $defender)->orderBy('id')->firstOrFail();
    $remettre = fn () => $figure->refresh()->update([
        'monstre_id' => $ogre->id, 'pv_body' => $ogre->pv_body, 'pv_body_max' => $ogre->pv_body,
        'position_x' => 9, 'position_y' => 4, 'mvm_deplace' => false, 'mvm_agi' => false,
    ]);
    $remettre();

    $this->actingAs($joueurDe($defender), 'joueur');
    $destinations = collect($this->getJson("/api/arene/{$code}/figures")->json('figures'))
        ->firstWhere('instance_id', $figure->id)['destinations'];

    expect($destinations)->not->toBeEmpty()
        ->and(collect($destinations)->contains(fn ($d) => $d['x'] === 5 && $d['y'] === 5))->toBeFalse();

    // Chaque case publiée : le déplacement y finit, exactement.
    $atteintes = [];
    foreach ($destinations as $case) {
        $remettre();
        $this->postJson("/api/arene/{$code}/geste", [
            'instance_id' => $figure->id, 'geste' => 'deplacer', 'parametres' => ['x' => $case['x'], 'y' => $case['y']],
        ])->assertOk();

        $figure->refresh();
        $atteintes["{$case['x']},{$case['y']}"] = "{$figure->position_x},{$figure->position_y}";
    }

    $ecarts = collect($atteintes)->reject(fn (string $arrivee, string $publiee) => $arrivee === $publiee)->all();
    expect($ecarts)->toBe([]);
});

it('PUBLICATION : une figure porte son emprise, celle de son catalogue (un ogre : l 1, h 2)', function () {
    [$alice, $bob, $code, $defender] = combatMvmPourMarche();
    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;
    $quete = Groupe::where('identifiant', $code)->firstOrFail()->queteCourante;
    $ogre = Monstre::where('nom_base', 'Ogre champion')->firstOrFail();

    $figure = InstanceMonstre::where('quete_id', $quete->id)->where('camp_arene', $defender)->orderBy('id')->firstOrFail();
    $figure->update(['monstre_id' => $ogre->id]);
    $autre = InstanceMonstre::where('quete_id', $quete->id)->where('camp_arene', $defender)->where('id', '!=', $figure->id)->firstOrFail();

    $this->actingAs($joueurDe($defender), 'joueur');
    $figures = collect($this->getJson("/api/arene/{$code}/figures")->json('figures'));

    expect($figures->firstWhere('instance_id', $figure->id)['emprise'])->toBe(['l' => 1, 'h' => 2])
        ->and($figures->firstWhere('instance_id', $autre->id)['emprise'])->toBe(['l' => 1, 'h' => 1]);
});

it('TIR : un tireur qui ne voit pas sa cible le dit (« en ligne de vue ») ; une frappe trop loin garde « à portée de frappe »', function () {
    [$alice, $bob, $code, $defender] = combatMvmPourMarche();
    $challenger = $defender === 'a' ? 'b' : 'a';
    $joueurDe = fn (string $camp) => $camp === 'a' ? $alice : $bob;
    $quete = Groupe::where('identifiant', $code)->firstOrFail()->queteCourante;
    $archer = Monstre::where('nom_base', 'Gobelin archer')->firstOrFail();

    // Le tireur (12,4) et la cible (10,4) : le gravat (11,4) coupe la ligne de tir, et la cible n'est pas au contact.
    $tireur = InstanceMonstre::where('quete_id', $quete->id)->where('camp_arene', $defender)->orderBy('id')->firstOrFail();
    $tireur->update(['monstre_id' => $archer->id, 'position_x' => 12, 'position_y' => 4]);
    $melee = InstanceMonstre::where('quete_id', $quete->id)->where('camp_arene', $defender)->where('id', '!=', $tireur->id)->firstOrFail();
    $melee->update(['position_x' => 12, 'position_y' => 5]);
    $cible = InstanceMonstre::where('quete_id', $quete->id)->where('camp_arene', $challenger)->orderBy('id')->firstOrFail();
    $cible->update(['position_x' => 10, 'position_y' => 4]);

    $this->actingAs($joueurDe($defender), 'joueur');
    $this->postJson("/api/arene/{$code}/geste", ['instance_id' => $tireur->id, 'geste' => 'attaquer', 'parametres' => ['cible_id' => $cible->id]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.parametres.0', 'Cette figure n\'est pas en ligne de vue.');

    // Le résolveur, seul, dit la même chose : c'est lui qui refuse, la route ne fait que le relayer.
    try {
        app(ResolveurTour::class)->frapperMonstreArene(Groupe::where('identifiant', $code)->firstOrFail(), $quete, $tireur->fresh()->load('monstre'), $cible->id);
        $motif = null;
    } catch (ValidationException $e) {
        $motif = $e->errors()['parametres'][0] ?? null;
    }
    expect($motif)->toBe('Cette figure n\'est pas en ligne de vue.');

    $this->postJson("/api/arene/{$code}/geste", ['instance_id' => $melee->id, 'geste' => 'attaquer', 'parametres' => ['cible_id' => $cible->id]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.parametres.0', 'Cette figure n\'est pas à portée de frappe.');
});
