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

/**
 * Lit l'entrée « utiliser_objet » du menu courant — comme la manette, par le cache du menu.
 *
 * @return list<array<string, mixed>>
 */
function gorgeesDuMenu(App\Models\Groupe $groupe, App\Auth\JoueurAuthentifiable $joueur, App\Models\Personnage $heros): array
{
    GenererMenu::dispatchSync($groupe->id, (int) $joueur->id, (int) $heros->id);
    $menu = Illuminate\Support\Facades\Cache::get(GenererMenu::cleMenu($groupe->id, (int) $joueur->id));

    $option = collect($menu['menu']['options'] ?? [])->firstWhere('id', 'utiliser_objet');

    return (array) ($option['parametres']['objets'] ?? []);
}

it('AVANT la frappe, par la route : la gorgée est offerte, et la frappe est DOUBLÉE (menu, puis résolveur)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ligne = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail()->id, 'emplacement' => 'sac',
    ]);
    $cible = ['cible_id' => $ctx['heros']->id, 'cible_type' => 'heros'];

    expect(collect(gorgeesDuMenu($ctx['groupe'], $ctx['alice'], $ctx['heros']))->firstWhere('inventaire_id', $ligne->id))
        ->not->toBeNull('offerte avant toute frappe');

    desFiges(array_fill(0, 30, 6));
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet', 'parametres' => ['cle' => "objet:{$ligne->id}", ...$cible],
    ])->assertStatus(202);

    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue('la gorgée arme la seconde frappe');

    // Première frappe : le créneau d'action normal. Le drapeau SURVIT — c'est lui la seconde.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    expect((bool) $ctx['etatHeros']->fresh()->a_agi)->toBeTrue()
        ->and((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue();

    // Seconde frappe : offerte, acceptée — et c'est elle qui dépense le drapeau.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse('la seconde frappe a eu lieu');
});

it('APRÈS la frappe, la Potion d\'héroïsme n\'est plus offerte — et le résolveur la refuse aussi', function () {
    // L'ancien ordre (boire APRÈS avoir frappé pour en refaire une) n'est ni offert ni accepté.
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ligne = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail()->id, 'emplacement' => 'sac',
    ]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 12, 6));
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    expect(collect(gorgeesDuMenu($ctx['groupe'], $ctx['alice'], $ctx['heros']))->firstWhere('inventaire_id', $ligne->id))
        ->toBeNull('plus offerte une fois la frappe partie');

    // La route refuse (la liste du menu est la liste blanche)…
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $ctx['heros']->id, 'cible_type' => 'heros'],
    ])->assertStatus(422);

    // …et le résolveur, pris seul, aussi : rien n'est armé.
    expect(fn () => app(MoteurPotions::class)->boire($ctx['heros']->fresh(), $ligne->fresh()))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse('aucune seconde frappe n\'a été armée');
});

it('UNE SEULE gorgée de Potion d\'héroïsme par frappe : la seconde n\'est ni offerte ni acceptée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $premiere = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail()->id, 'emplacement' => 'sac',
    ]);
    $seconde = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail()->id, 'emplacement' => 'sac',
    ]);

    app(MoteurPotions::class)->boire($ctx['heros'], $premiere->fresh());
    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue();

    // Une seconde gorgée ne rajouterait pas une frappe (« two attacks instead of one ») : elle serait perdue.
    expect(collect(gorgeesDuMenu($ctx['groupe'], $ctx['alice'], $ctx['heros']))->firstWhere('inventaire_id', $seconde->id))
        ->toBeNull();
    expect(fn () => app(MoteurPotions::class)->boire($ctx['heros']->fresh(), $seconde->fresh()))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('REPLI : une frappe déjà doublée sous l\'ancien ordre (drapeau posé, frappe faite) garde sa seconde frappe', function () {
    // Une gorgée bue après la frappe, avant ce changement, a posé le même drapeau : elle ne doit pas
    // être perdue par la nouvelle règle. Le drapeau reste lu par la seconde frappe, inchangée.
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['etatHeros']->update(['a_agi' => true, 'attaque_supplementaire' => true]);

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    desFiges(array_fill(0, 12, 6));

    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id],
    ])->assertStatus(202);

    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse('la seconde frappe a consommé le drapeau');
});

// =====================================================================
// LA GORGÉE NON SUIVIE D'UNE FRAPPE SE DISSIPE AU FIL (2026-10-17) — défaut relevé
// en partie réelle : le drapeau tombait à l'ouverture du round, sans un mot, et la
// carte dit « Drink this potion before you attack ». Le joueur perdait la potion
// sans le savoir.
// =====================================================================

it('le menu dit qu\'il faut boire AVANT d\'attaquer, et que l\'effet ne vaut que ce tour', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ligne = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail()->id, 'emplacement' => 'sac',
    ]);

    $entree = collect(gorgeesDuMenu($ctx['groupe'], $ctx['alice'], $ctx['heros']))->firstWhere('inventaire_id', $ligne->id);

    expect($entree['detail'] ?? '')->toContain('avant d\'attaquer')
        ->and($entree['detail'] ?? '')->toContain('ce tour');
});

it('une gorgée jamais suivie d\'une frappe se DISSIPE au fil, à la fin du tour — plus perdue en silence', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ligne = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail()->id, 'emplacement' => 'sac',
    ]);

    desFiges(array_fill(0, 60, 4)); // boucliers blancs : le monstre ne frappe pas le héros
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'utiliser_objet',
        'parametres' => ['cle' => "objet:{$ligne->id}", 'cible_id' => $ctx['heros']->id, 'cible_type' => 'heros'],
    ])->assertStatus(202);

    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeTrue('précondition : la gorgée est bue');

    // Le héros termine son tour SANS frapper : la seconde frappe n'aura jamais lieu.
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat');

    $dissipations = collect($resultat['annonces_automatiques'] ?? [])->where('type', 'breuvage_dissipe');

    expect($dissipations)->toHaveCount(1)
        ->and($dissipations->first()['texte'])->toContain('se dissipe, faute d\'attaque')
        ->and((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse('l\'effet est bien retombé');
});

it('une gorgée dont la SECONDE frappe a eu lieu ne se dissipe pas : rien à annoncer', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ctx['instance']->update(['pv_body' => 5, 'pv_body_max' => 5]); // survit aux deux frappes manquées
    $ligne = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail()->id, 'emplacement' => 'sac',
    ]);
    app(MoteurPotions::class)->boire($ctx['heros'], $ligne->fresh());

    desFiges(array_fill(0, 60, 4)); // 4 = bouclier blanc : deux frappes manquées, le monstre tient

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id]])
        ->assertStatus(202);
    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attaquer', 'parametres' => ['cible_id' => $ctx['instance']->id]])
        ->assertStatus(202);

    expect((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse('la seconde frappe a consommé la gorgée');

    GenererMenu::dispatchSync($ctx['groupe']->id, (int) $ctx['alice']->id, (int) $ctx['heros']->id);
    $resultat = $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202)->json('resultat');

    expect(collect($resultat['annonces_automatiques'] ?? [])->where('type', 'breuvage_dissipe'))->toHaveCount(0);
});

it('un tour qui s\'ouvre sans « Terminer le tour » (héros tombé en plein tour) dissipe quand même la gorgée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $ligne = $ctx['heros']->inventaire()->create([
        'objet_id' => Objet::where('nom', 'Potion d\'héroïsme')->firstOrFail()->id, 'emplacement' => 'sac',
    ]);
    app(MoteurPotions::class)->boire($ctx['heros'], $ligne->fresh());

    // Le round se rouvre sans que le héros ait jamais fermé son tour : le filet de `ouvrirNouveauTour()`.
    $methode = new ReflectionMethod(\App\Partie\ResolveurTour::class, 'ouvrirNouveauTour');
    app(\App\Partie\TamponAnnonces::class)->ouvrir();
    $actions = [];
    $methode->invokeArgs(app(\App\Partie\ResolveurTour::class), [$ctx['groupe'], $ctx['quete'], &$actions]);
    $annonces = app(\App\Partie\TamponAnnonces::class)->vider();

    expect(collect($annonces)->where('type', 'breuvage_dissipe'))->toHaveCount(1)
        ->and((bool) $ctx['etatHeros']->fresh()->attaque_supplementaire)->toBeFalse();
});
