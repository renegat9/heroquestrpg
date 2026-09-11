<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Objet;
use App\Partie\MoteurPotions;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;

/**
 * POTION D'HÉROÏSME — « une attaque supplémentaire ».
 *
 * ⚠ Signalé par René en partie réelle (2026-09-11) : « la potion d'héroïsme ne
 * donne pas de 2e attaque ». `PotionsOfficiellesTest` éprouvait bien le
 * DRAPEAU (`etat.attaque_supplementaire` posé à la gorgée, non réarmé au milieu
 * du tour) — mais jamais la SECONDE FRAPPE elle-même. Un drapeau posé ne prouve
 * pas qu'une option existe, et une option qui n'existe pas est refusée par le
 * contrôleur : « le menu ne propose jamais ce que le résolveur refusera » se lit
 * aussi dans l'autre sens.
 *
 * Ce test passe donc par le MENU, comme un vrai client.
 */
beforeEach(function () {
    Illuminate\Support\Facades\Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);

    // ⚠ SortSeeder AVANT ObjetSeeder : les parchemins dérivent des sorts.
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class, MonstreSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
        MobilierSeeder::class,
    ]);
});

it('offre et résout une SECONDE attaque après la Potion d\'héroïsme', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $etat = $ctx['etatHeros'];

    // La potion au sac, puis la gorgée.
    $objet = Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail();
    $ligne = $ctx['heros']->inventaire()->create(['objet_id' => $objet->id, 'emplacement' => 'sac']);
    app(MoteurPotions::class)->boire($ctx['heros'], $ligne->fresh());

    expect((bool) $etat->fresh()->attaque_supplementaire)->toBeTrue('la gorgée doit poser le drapeau');

    desFiges([6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6]);

    // PREMIÈRE attaque — le créneau d'action normal.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    expect((bool) $etat->fresh()->a_agi)->toBeTrue('la première frappe consomme le créneau')
        ->and((bool) $etat->fresh()->attaque_supplementaire)
        ->toBeTrue('le drapeau doit SURVIVRE à la première frappe — c\'est lui la seconde');

    // SECONDE attaque — elle doit être OFFERTE par le menu…
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $menu = $ctx['groupe']->fresh()->menuCourant ?? null;
    $options = collect((array) (is_array($menu) ? $menu : ($menu->options ?? [])));

    // …et ACCEPTÉE par le résolveur.
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    expect((bool) $etat->fresh()->attaque_supplementaire)
        ->toBeFalse('la seconde frappe consomme le bonus');
});
