<?php

declare(strict_types=1);

use App\Models\Evenement;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Partie\JournalCombat;
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
 * MODE ARÈNE — une activation de figure du Defender PUBLIE TOUT ce qu'elle a fait (contrat « L'activation alternée »,
 * 2026-11-12) : une figure qui marche puis frappe rend `[deplacement_monstre, attaque_monstre]` dans
 * `tour_monstres.actions`, le `vers` du déplacement est la case réelle, et le fil dit le trajet (« avance de N case »).
 * Validation par combats d'agents.
 */
beforeEach(function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class, MobilierSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
    ]);
});

it('ARÈNE : une figure du Defender qui marche puis frappe rend les DEUX actions, dans l\'ordre, avec le trajet réel', function () {
    $c = combatADeux(['Gobelin', 'Gobelin']);
    $id = $c['arene']->identifiant;
    $quete = $c['quete'];
    $aldra = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['a']->id)->firstOrFail();

    // Le Defender a ouvert : sa première figure a déjà son jeton, on l'écarte sur la tuile. La seconde se pose à DEUX
    // cases d'Aldra, la case entre les deux libre : elle marche d'une case, puis la frappe. Aucune autre figure dans la salle.
    $figures = InstanceMonstre::where('quete_id', $quete->id)->orderBy('id')->get();
    $premiere = $figures[0];
    $seconde = $figures[1];
    $premiere->update(['position_x' => 17, 'position_y' => 6]);
    $milieu = ['x' => (int) $aldra->position_x + 1, 'y' => (int) $aldra->position_y];
    $seconde->update([
        'position_x' => (int) $aldra->position_x + 2, 'position_y' => (int) $aldra->position_y,
        'active_ce_round' => false, 'mvm_deplace' => false, 'mvm_agi' => false,
    ]);
    $occupee = InstanceMonstre::where('quete_id', $quete->id)->where('position_x', $milieu['x'])->where('position_y', $milieu['y'])->exists()
        || EtatPersonnageQuete::where('quete_id', $quete->id)->where('position_x', $milieu['x'])->where('position_y', $milieu['y'])->exists();
    expect($occupee)->toBeFalse('la case du milieu doit être libre, sinon le scénario ne prouve rien');

    // Alice termine son activation : le Defender répond avec la seule figure qui n'a pas joué.
    $resultat = jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful()->json('resultat');

    $actions = collect($resultat['tour_monstres']['actions'] ?? []);
    $indexMarche = $actions->search(fn ($a) => ($a['type'] ?? null) === 'deplacement_monstre' && (int) ($a['id'] ?? 0) === $seconde->id);
    $indexFrappe = $actions->search(fn ($a) => ($a['type'] ?? null) === 'attaque_monstre' && (int) ($a['instance_id'] ?? 0) === $seconde->id);

    expect($indexMarche)->not->toBeFalse('la marche de la figure doit être publiée dans tour_monstres.actions')
        ->and($indexFrappe)->not->toBeFalse('la frappe doit suivre dans tour_monstres.actions')
        ->and($indexMarche)->toBeLessThan($indexFrappe);

    $marche = $actions[$indexMarche];
    $seconde->refresh();
    expect($marche['chemin'])->toHaveCount(1)
        ->and([(int) $marche['vers']['x'], (int) $marche['vers']['y']])->toBe([(int) $seconde->position_x, (int) $seconde->position_y])
        ->and([$marche['vers']['x'], $marche['vers']['y']])->toBe([$milieu['x'], $milieu['y']]);

    // Le fil dit le trajet, à la table comme au journal : une ligne « avance de 1 case ».
    $lignes = collect(app(JournalCombat::class)->depuisResultat($resultat, 'Alice'))->pluck('texte')->all();
    expect($lignes)->toContain('Gobelin avance de 1 case');

    // Et le journal (rejeu à la reconnexion) porte CETTE marche une seule fois — pas de doublon, pas de trou.
    $journalises = Evenement::where('quete_id', $quete->id)->where('type', 'action')->get()
        ->filter(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'deplacement_monstre' && (int) ($e->payload['id'] ?? 0) === $seconde->id);
    expect($journalises)->toHaveCount(1);

    $rejeu = $this->getJson("/api/groupes/{$id}/etat")->json('journal_combat');
    expect(collect($rejeu)->pluck('texte')->contains('Gobelin avance de 1 case'))->toBeTrue();
});

it('ARÈNE : une figure déjà au contact ne publie PAS de marche vide', function () {
    $c = combatADeux(['Gobelin', 'Gobelin']);
    $id = $c['arene']->identifiant;
    $quete = $c['quete'];
    $aldra = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $c['a']->id)->firstOrFail();

    $figures = InstanceMonstre::where('quete_id', $quete->id)->orderBy('id')->get();
    $premiere = $figures[0];
    $seconde = $figures[1];
    $premiere->update(['position_x' => 17, 'position_y' => 6]);
    $seconde->update([
        'position_x' => (int) $aldra->position_x + 1, 'position_y' => (int) $aldra->position_y,
        'active_ce_round' => false, 'mvm_deplace' => false, 'mvm_agi' => false,
    ]);

    $resultat = jouerEnArene($c['alice'], $id, 'attendre')->assertSuccessful()->json('resultat');
    $actions = collect($resultat['tour_monstres']['actions'] ?? []);

    expect($actions->contains(fn ($a) => ($a['type'] ?? null) === 'attaque_monstre' && (int) ($a['instance_id'] ?? 0) === $seconde->id))
        ->toBeTrue()
        ->and($actions->contains(fn ($a) => ($a['type'] ?? null) === 'deplacement_monstre' && (int) ($a['id'] ?? 0) === $seconde->id))
        ->toBeFalse('une figure déjà au contact ne marche pas : pas de déplacement à publier');
});
