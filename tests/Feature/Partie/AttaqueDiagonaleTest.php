<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Inventaire;
use App\Models\Objet;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Armes longues et attaque en diagonale (reference/16_armurerie.md §6.2,
 * livret de règles 2021 p. 14) : « Some long weapons, like the staff and the
 * longsword, allow you to attack diagonally. The attack is made and defended
 * normally. »
 *
 * L'asymétrie est canonique et volontaire : le héros à l'arme longue frappe en
 * diagonale, le monstre ne riposte JAMAIS en diagonale — le livret qualifie
 * cette case de « safe ». C'est ce qui permet à deux héros d'encadrer un
 * monstre bloquant un seuil de porte.
 *
 * Jusqu'ici `attaque_diagonale` était une clé DÉCORATIVE : zéro lecteur dans
 * tout le moteur. Le Bâton annonçait une portée qu'il n'avait pas.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        Database\Seeders\MonstreSeeder::class,
        Database\Seeders\TuileSeeder::class,
        Database\Seeders\GabaritQueteSeeder::class,
        Database\Seeders\PiegeSeeder::class,
        Database\Seeders\ObjetSeeder::class,
    ]);
});

/** Équipe l'arme nommée en main principale, sans passer par la maîtrise. */
function armerEnMain(App\Models\Personnage $heros, string $nomObjet): void
{
    $heros->inventaire()->where('emplacement', 'arme_principale')->delete();

    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => 'arme_principale',
        'quantite' => 1,
    ]);

    app(App\Partie\Equipement::class)->recalculerCombat($heros->refresh());
}

/** Première case DIAGONALE libre autour de (x,y), ou null. */
function caseDiagonaleLibre(App\Models\Quete $quete, int $x, int $y): ?array
{
    foreach ([[1, 1], [-1, 1], [1, -1], [-1, -1]] as [$dx, $dy]) {
        if (caseQueteLibre($quete, $x + $dx, $y + $dy)) {
            return ['x' => $x + $dx, 'y' => $y + $dy];
        }
    }

    return null;
}

it('le Bâton permet d\'attaquer un monstre en DIAGONALE, l\'Épée courte non', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete, 'instance' => $instance, 'etatHeros' => $etat] = $ctx;

    $diag = caseDiagonaleLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    if ($diag === null) {
        test()->markTestSkipped('Carte générée sans case diagonale libre autour du héros.');
    }

    $instance->update(['position_x' => $diag['x'], 'position_y' => $diag['y']]);

    // 1) Épée courte (aucune diagonale) : le monstre n'est PAS une cible.
    armerEnMain($heros, 'Épée courte');
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];

    expect(collect($menu['options'])->firstWhere('id', 'attaquer'))->toBeNull();

    // 2) Bâton : la même case devient atteignable.
    armerEnMain($heros, 'Bâton');
    $quete->etatsPersonnages()->update(['deplacement_tour' => null, 'a_joue' => false, 'a_agi' => false]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];

    $attaque = collect($menu['options'])->firstWhere('id', 'attaquer');
    expect($attaque)->not->toBeNull()
        ->and(collect($attaque['parametres']['cibles'])->pluck('id'))->toContain($instance->id);

    // Et le résolveur l'accepte : le menu ne propose rien qu'il refuserait.
    desFiges(array_fill(0, 20, 1));
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cible_id' => $instance->id],
    ])->assertStatus(202)->assertJsonPath('resultat.type', 'attaque');
});

it('le résolveur REFUSE une attaque en diagonale sans arme longue', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete, 'instance' => $instance, 'etatHeros' => $etat] = $ctx;

    $diag = caseDiagonaleLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    if ($diag === null) {
        test()->markTestSkipped('Carte générée sans case diagonale libre autour du héros.');
    }

    $instance->update(['position_x' => $diag['x'], 'position_y' => $diag['y']]);
    armerEnMain($heros, 'Épée courte');

    // Menu forcé : le résolveur revalide l'adjacence, il ne fait pas confiance
    // au menu (même garde que pour un menu périmé, cf. CoherenceMenuTest).
    Cache::put(GenererMenu::cleMenu($groupe->id, (int) $alice->id), [
        'personnage_id' => $heros->id,
        'menu' => ['options' => [[
            'id' => 'attaquer', 'libelle' => 'Attaquer', 'type' => 'attaque',
            'parametres' => ['cibles' => [['id' => $instance->id, 'type' => 'monstre', 'nom' => 'Gobelin']]],
        ]]],
    ], now()->addMinutes(60));

    desFiges(array_fill(0, 20, 1));
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cible_id' => $instance->id],
    ])->assertStatus(422);

    expect((int) $instance->fresh()->pv_body)->toBe((int) $instance->pv_body);
});
