<?php

declare(strict_types=1);

use App\Models\Quete;
use App\Partie\FabriqueGrille;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verdict §2.16 — l'avancement d'exploration vivait UNIQUEMENT en cache (TTL
 * 6 h). Sa disparition refermait le brouillard sur des zones déjà explorées :
 * le BFS de la manette n'accepte que les cases connues, plus AUCUNE case
 * n'était accessible et tout le groupe se retrouvait figé sur place, sans
 * recours et sans explication.
 *
 * Verdict §2.17 — un héros à terre ne bloquait rien côté moteur : un monstre
 * venait se poster sur son corps, ce qui le rendait ensuite impossible à
 * relever (« une autre figure occupe sa case ») alors que l'option restait
 * proposée.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

function queteDemarree(): Quete
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    return Quete::findOrFail($groupe->fresh()->quete_courante_id);
}

it('garde l\'exploration d\'une quête après un vidage complet du cache', function () {
    $quete = queteDemarree();

    // Le groupe explore : la salle 1 est découverte en cours de partie.
    $quete->marquerSalleDecouverte(1);
    expect($quete->fresh()->sallesDecouvertes())->toContain(0, 1);

    // Le cache disparaît (expiration, purge, redéploiement…) — c'est
    // exactement ce qui a figé le groupe pendant le test de jeu.
    Cache::flush();

    expect($quete->fresh()->sallesDecouvertes())->toContain(0, 1)
        ->and(Quete::find($quete->id)->salles_decouvertes)->not->toBeNull();
});

it('garde la fouille de trésor d\'une salle après un vidage du cache', function () {
    $quete = queteDemarree();

    $heros = $quete->groupe->personnages()->firstOrFail();

    // Une fouille appartient désormais à UN héros : chacun tire sa carte,
    // comme au plateau.
    $quete->marquerTresorFouille(2, (int) $heros->id);
    Cache::flush();

    expect($quete->fresh()->tresorsFouilles())->toContain(2)
        ->and($quete->fresh()->aFouille(2, (int) $heros->id))->toBeTrue();
});

it('considère toujours la salle de départ comme découverte, même sans donnée', function () {
    $quete = queteDemarree();

    $quete->update(['salles_decouvertes' => null]);

    expect($quete->fresh()->sallesDecouvertes())->toBe([0]);
});

it('marque une salle découverte de façon idempotente', function () {
    $quete = queteDemarree();

    $quete->marquerSalleDecouverte(1);
    $quete->marquerSalleDecouverte(1);

    $vues = $quete->fresh()->sallesDecouvertes();

    expect(count(array_keys($vues, 1, true)))->toBe(1);
});

it('laisse la case d\'un héros à terre franchissable — on l\'enjambe', function () {
    $quete = queteDemarree();

    $etat = $quete->etatsPersonnages()->firstOrFail();
    $x = (int) $etat->position_x;
    $y = (int) $etat->position_y;

    // Debout : la case est prise.
    expect(FabriqueGrille::pour($quete)->estTraversable($x, $y))->toBeFalse();

    // À terre : la case redevient libre (règle assumée, cf. HerosTombeTest).
    // C'est ce qui permet à une autre figure de s'y placer — et donc ce qui
    // rend le relevage impossible tant qu'elle y reste (§2.17).
    $etat->update(['tombe' => true]);

    expect(FabriqueGrille::pour($quete)->estTraversable($x, $y))->toBeTrue();
});
