<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\InstanceMonstre;
use App\Partie\EtatGroupe;
use App\Partie\MenuMoteur;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Cohérence menu ⇄ plateau (correctifs §2.1/§2.2) : le menu moteur ne propose
 * que des options réellement jouables sur l'état COURANT, et le résolveur
 * revérifie les invariants (cible active ET visible) même si un menu périmé
 * la contient encore.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Menu moteur régénéré pour le héros depuis l'état exact. */
function menuMoteurPour(App\Models\Groupe $groupe, App\Models\Personnage $heros): array
{
    desFiges(array_fill(0, 20, 4));

    return app(MenuMoteur::class)->generer($groupe->fresh(), $heros->fresh());
}

it('n\'offre PAS d\'attaque sur un monstre adjacent DORMANT (non révélé)', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');

    // Le monstre au contact redevient dormant (salle non découverte).
    $instance->update(['revele' => false]);

    $menu = menuMoteurPour($groupe, $heros);
    $attaques = array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'attaque');

    expect($attaques)->toBe([]);
});

it('offre l\'attaque quand le même monstre adjacent est RÉVÉLÉ', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');
    $instance->update(['revele' => true]);

    $menu = menuMoteurPour($groupe, $heros);
    $attaques = array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'attaque');

    expect($attaques)->not->toBe([]);
});

it('le résolveur refuse une attaque contre un monstre non révélé, même si un menu périmé la propose', function () {
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');
    $instance->update(['revele' => false]);

    // Menu périmé (d'avant que la salle redevienne dormante) mémorisé en cache.
    Cache::put(GenererMenu::cleMenu($groupe->id, (int) $alice->id), [
        'personnage_id' => $heros->id,
        'menu' => ['options' => [[
            'id' => "attaquer_{$instance->id}", 'libelle' => 'Attaquer', 'type' => 'attaque', 'cible_id' => $instance->id,
        ]]],
    ], now()->addMinutes(60));

    desFiges(array_fill(0, 20, 1));
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "attaquer_{$instance->id}"])
        ->assertStatus(422);

    // La cible n'a pas été touchée (elle reste à ses PV pleins).
    expect((int) $instance->fresh()->pv_body)->toBe((int) $instance->pv_body);
});

it('masque « Se déplacer » quand le héros est totalement bloqué (aucune case libre)', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'quete' => $quete, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');

    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // On bouche chaque case orthogonale ENCORE LIBRE avec un monstre actif :
    // combinées aux murs, les 4 directions deviennent infranchissables.
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if (caseQueteLibre($quete, $hx + $dx, $hy + $dy)) {
            InstanceMonstre::create([
                'quete_id' => $quete->id,
                'monstre_id' => $instance->monstre_id,
                'pv_body' => 2,
                'pv_mind' => 0,
                'position_x' => $hx + $dx,
                'position_y' => $hy + $dy,
                'etat' => 'actif',
                'revele' => true,
            ]);
        }
    }

    $menu = menuMoteurPour($groupe, $heros);
    $deplacements = array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'deplacement');

    expect($deplacements)->toBe([])
        // « Terminer le tour » reste toujours proposé (jamais de menu vide).
        ->and(array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'attente'))->not->toBe([]);
});

it('propose « Se déplacer » dès qu\'au moins une case adjacente est libre', function () {
    ['groupe' => $groupe, 'heros' => $heros] = demarrerQueteAvecMonstre('Gobelin');

    $menu = menuMoteurPour($groupe, $heros);
    $deplacements = array_filter($menu['options'], fn ($o) => ($o['type'] ?? null) === 'deplacement');

    expect($deplacements)->not->toBe([]);
});

it('ne propose AUCUNE action une fois le tour joué (a_joue), même si les créneaux ne sont pas tous marqués (correctifs A1)', function () {
    ['groupe' => $groupe, 'heros' => $heros, 'quete' => $quete] = demarrerQueteAvecMonstre('Gobelin');

    // Action terminante (relever / concentration / « Terminer le tour ») : pose
    // a_joue=true SANS marquer a_deplace ni a_agi. Le menu doit refléter la fin
    // du tour, pas rouvrir des créneaux fantômes.
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)
        ->update(['a_joue' => true, 'a_deplace' => false, 'a_agi' => false]);

    $menu = menuMoteurPour($groupe, $heros);

    expect($menu['options'])->toBe([])
        ->and($menu['situation'])->toContain('terminé');
});

it('retire un monstre vaincu de l\'état partagé — plus sur la carte manette/table (correctifs A2)', function () {
    ['groupe' => $groupe, 'instance' => $instance] = demarrerQueteAvecMonstre('Gobelin');
    $instance->update(['revele' => true]);

    $etatGroupe = app(EtatGroupe::class);

    $avant = collect($etatGroupe->payload($groupe->fresh())['entites'])->where('type', 'monstre')->pluck('id');
    expect($avant)->toContain($instance->id);

    $instance->update(['etat' => 'vaincu']);

    $apres = collect($etatGroupe->payload($groupe->fresh())['entites'])->where('type', 'monstre')->pluck('id');
    expect($apres)->not->toContain($instance->id);
});
