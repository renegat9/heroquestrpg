<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Snapshot;
use App\Partie\Arene\DemarreurArene;
use App\Partie\Arene\GroupesArene;
use App\Partie\Grille;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/**
 * MODE ARÈNE, lot T2 — l'ACTIVATION ALTERNÉE (livret F9528 p. 12-16, `docs/plan-tournoi-worlds-end.md` §1, T2) :
 * le Defender ouvre chaque round avec une figure ; le Challenger active n'importe quel héros (le premier qui appuie) ;
 * chaque activation de héros reçoit sa réponse ; l'équipe à court laisse l'autre enchaîner ; le combat finit quand une
 * équipe est éteinte. Joué par les vraies routes, deux joueurs, deux téléphones.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('le Defender ouvre le combat, puis les DEUX héros ont la main — le premier qui appuie', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);

    expect(jetonsDuDefender($c['quete']))->toBe(1);

    $this->actingAs($c['alice'], 'joueur');
    expect($this->getJson("/api/groupes/{$c['arene']->identifiant}/menu")->json('menu'))->not->toBeNull();
    $this->actingAs($c['bob'], 'joueur');
    expect($this->getJson("/api/groupes/{$c['arene']->identifiant}/menu")->json('menu'))->not->toBeNull();
});

it('un héros qui ENTAME son activation la garde seul jusqu\'au bout', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin']);
    $id = $c['arene']->identifiant;

    $this->actingAs($c['bob'], 'joueur');
    $menu = $this->getJson("/api/groupes/{$id}/menu")->json('menu');
    $destination = collect(collect($menu['options'])->firstWhere('id', 'se_deplacer')['parametres']['destinations'])->first();
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => $destination['x'], 'y' => $destination['y']]])
        ->assertSuccessful();

    // Bob a entamé : Alice n'a plus la main, et son choix est refusé.
    $this->actingAs($c['alice'], 'joueur');
    expect($this->getJson("/api/groupes/{$id}/menu")->json('menu'))->toBeNull();
    $this->postJson("/api/groupes/{$id}/choix", ['option_id' => 'attendre'])->assertUnprocessable();

    // Bob finit : le Defender répond d'UNE figure, et Alice retrouve la main.
    jouerEnArene($c['bob'], $id, 'attendre')->assertSuccessful();
    expect(jetonsDuDefender($c['quete']))->toBe(2);

    $this->actingAs($c['alice'], 'joueur');
    expect($this->getJson("/api/groupes/{$id}/menu")->json('menu'))->not->toBeNull();
});

it('le Challenger à court, le Defender enchaîne, le round se referme et le Defender rouvre le suivant', function () {
    $c = combatADeux(['Gobelin', 'Gobelin', 'Gobelin', 'Gobelin', 'Gobelin']);
    $id = $c['arene']->identifiant;
    $etat = fn (Personnage $p) => EtatPersonnageQuete::where('quete_id', $c['quete']->id)->where('personnage_id', $p->id)->first();

    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();   // jetons : 1 (ouverture) + 1
    expect(jetonsDuDefender($c['quete']))->toBe(2);

    jouerEnArene($c['bob'], $id, 'attendre')->assertSuccessful();     // plus aucun héros : les 3 restants enchaînent

    // Le round s'est refermé et rouvert : jetons remis à zéro, puis UNE figure pour ouvrir le nouveau round ; les
    // deux héros ont de nouveau la main.
    expect(jetonsDuDefender($c['quete']))->toBe(1)
        ->and($etat($c['a'])->a_joue)->toBeFalse()
        ->and($etat($c['b'])->a_joue)->toBeFalse()
        ->and(Snapshot::where('groupe_id', $c['arene']->id)->count())->toBe(0);
});

it('le Defender à court, le Challenger enchaîne sans réponse', function () {
    $c = combatADeux(['Gobelin']);
    $id = $c['arene']->identifiant;

    // Le seul gobelin a ouvert le round : il a déjà son jeton.
    expect(jetonsDuDefender($c['quete']))->toBe(1);

    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();
    expect(jetonsDuDefender($c['quete']))->toBe(1);   // aucune réponse possible : Bob enchaîne

    $this->actingAs($c['bob'], 'joueur');
    expect($this->getJson("/api/groupes/{$id}/menu")->json('menu'))->not->toBeNull();
});

it('VICTOIRE : le Defender éteint, le combat est clos — ni fouille, ni vote de sortie', function () {
    $c = combatADeux(['Gobelin', 'Gobelin']);
    InstanceMonstre::where('quete_id', $c['quete']->id)->update(['etat' => 'vaincu']);

    jouerEnArene($c['alice'], $c['arene']->identifiant, 'attendre')->assertSuccessful();

    expect($c['quete']->refresh()->etat)->toBe('terminee')
        ->and($c['arene']->refresh()->phase)->toBe('hub')
        ->and($c['arene']->quete_courante_id)->toBeNull();
});

it('DÉFAITE : le dernier héros à terre, le combat est perdu', function () {
    $c = combatADeux(['Orque']);
    $quete = $c['quete'];
    $id = $c['arene']->identifiant;

    // Bob est déjà à terre ; Alice, à 1 PV, a l'orque au contact. Tous les dés font crâne : l'orque touche, Alice ne
    // pare rien.
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['b']->id)->update(['tombe' => true]);
    $c['a']->update(['pv_body' => 1]);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['a']->id)->first();
    $orque = InstanceMonstre::where('quete_id', $quete->id)->first();
    $orque->update(['position_x' => $etatA->position_x + 1, 'position_y' => $etatA->position_y, 'active_ce_round' => false]);
    desFiges(array_fill(0, 200, 1));

    jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful();

    expect($quete->refresh()->etat)->toBe('echouee')
        ->and($c['arene']->refresh()->phase)->toBe('hub');
});
