<?php

declare(strict_types=1);

use App\Models\Monstre;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/**
 * Scène sonore exposée par EtatGroupe (boucle d'ambiance de la table) :
 * hub / exploration / combat / boss selon l'état.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

function ambianceDe(): string
{
    return test()->getJson('/api/groupes/table-1/etat')->assertOk()->json('groupe.ambiance');
}

it('scène « hub » hors quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    expect(ambianceDe())->toBe('hub');
});

it('scène combat / exploration / boss selon les monstres actifs', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Tout vaincu → exploration.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    expect(ambianceDe())->toBe('exploration');

    // Un monstre de base actif → combat.
    $premier = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $premier->update(['etat' => 'actif', 'revele' => true, 'monstre_id' => Monstre::where('nom_base', 'Gobelin')->value('id')]);
    expect(ambianceDe())->toBe('combat');

    // Un boss/sous-boss actif → boss.
    $premier->update(['monstre_id' => Monstre::where('nom_base', 'Seigneur')->value('id')]);
    expect(ambianceDe())->toBe('boss');
});

it('scène « defaite » au hub après un TPK (dernière quête échouée)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => \App\Models\GabaritQuete::query()->value('id'),
        'titre' => 'Quête 1', 'position_arc' => 1, 'type_jalon' => 'normale',
        'etat' => 'echouee', 'or_initial' => 0,
    ]);

    expect(ambianceDe())->toBe('defaite');
});

it('scène « victoire » au hub après le boss final vaincu', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    Quete::create([
        'groupe_id' => $groupe->id,
        'gabarit_id' => \App\Models\GabaritQuete::where('type_jalon', 'boss_final')->value('id'),
        'titre' => 'Confrontation finale', 'position_arc' => 1, 'type_jalon' => 'boss_final',
        'etat' => 'terminee', 'or_initial' => 0,
    ]);

    expect(ambianceDe())->toBe('victoire');
});
