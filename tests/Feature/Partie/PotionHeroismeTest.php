<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Objet;
use App\Partie\EtatGroupe;
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

it('publie le drapeau au client APRÈS la première frappe — le miroir n\'a plus à deviner', function () {
    // ⚠ Signalé une seconde fois en partie réelle (2026-09-11) : le journal
    // annonçait bien la seconde attaque (corrigé la veille), mais le BOUTON
    // restait grisé — `ActionTab::creneauConsomme()` ne connaissait que
    // `a_agi`, jamais `attaque_supplementaire`, et `EtatGroupe` ne le
    // publiait pas non plus : le miroir client ne POUVAIT pas savoir.
    //
    // Ce test ne peut pas cliquer un bouton Vue (aucun harnais JS dans ce
    // projet), mais il prouve la moitié qu'il PEUT prouver : au moment précis
    // où `ActionTab` doit décider si l'option d'attaque reste active — juste
    // après `a_agi`, avant la seconde frappe —, `EtatGroupe.entites[]` porte
    // bien `attaque_supplementaire: true` sur le héros. `ManetteView`
    // (`creneauxDuTour`) relaie ce champ tel quel, et `creneauConsomme()`
    // grise désormais `!!moi.a_agi && !moi.attaque_supplementaire` pour une
    // option `type: "attaque"` — donc `false` (cliquable) exactement quand ce
    // test observe `attaque_supplementaire: true`.
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $etat = $ctx['etatHeros'];

    $objet = Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail();
    $ligne = $ctx['heros']->inventaire()->create(['objet_id' => $objet->id, 'emplacement' => 'sac']);
    app(MoteurPotions::class)->boire($ctx['heros'], $ligne->fresh());

    desFiges(array_fill(0, 12, 6));

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    $entite = collect(app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['entites'])
        ->firstWhere('id', $ctx['heros']->id);

    expect($entite)->not->toBeNull()
        ->and($entite['a_agi'] ?? null)->toBeTrue('précondition : le créneau normal EST consommé')
        ->and($entite['attaque_supplementaire'] ?? null)
        ->toBeTrue('sans ce champ, `ActionTab` grise l\'attaque malgré le bonus payé');
});
