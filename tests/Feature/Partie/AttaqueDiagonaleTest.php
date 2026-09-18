<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Equipement;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
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
        MonstreSeeder::class,
        TuileSeeder::class,
        GabaritQueteSeeder::class,
        PiegeSeeder::class,
        ObjetSeeder::class,
    ]);
});

/** Équipe l'arme nommée en main principale, sans passer par la maîtrise. */
function armerEnMain(Personnage $heros, string $nomObjet): void
{
    $heros->inventaire()->where('emplacement', 'arme_principale')->delete();

    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', $nomObjet)->firstOrFail()->id,
        'emplacement' => 'arme_principale',
        'quantite' => 1,
    ]);

    app(Equipement::class)->recalculerCombat($heros->refresh());
}

/** Première case DIAGONALE libre autour de (x,y), ou null. */
function caseDiagonaleLibre(Quete $quete, int $x, int $y): ?array
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

// ---------------------------------------------------------------------------
// Sous-choix `attaquer` à DEUX ARMES (René, 2026-09-18) : `cibles` reste PAR
// ENTRÉE, et c'est ici que ça se vérifie — une liste commune au niveau de
// l'option offrirait, avec la dague, une cible que seule l'arme longue atteint.
// ---------------------------------------------------------------------------

/**
 * Équipe Rapière (une main, diagonale) à droite et Épée courte (aucune
 * diagonale) à gauche : DEUX armes en main, donc l'option `attaquer` porte
 * `parametres.armes[]` (au lieu d'être publiée à plat).
 */
function armerRapiereEtEpeeCourte(Personnage $heros): void
{
    armerEnMain($heros, 'Rapière');
    Inventaire::create([
        'personnage_id' => $heros->id,
        'objet_id' => Objet::where('nom', 'Épée courte')->firstOrFail()->id,
        'emplacement' => 'arme_secondaire',
        'quantite' => 1,
    ]);
    app(Equipement::class)->recalculerCombat($heros->refresh());
}

/** Réveille un second Gobelin (parmi les instances vaincues par le seed de
 *  `demarrerQueteAvecMonstre`) sur la première case ORTHOGONALE libre — au
 *  contact des DEUX armes, contrairement au premier posé en diagonale. */
function reveillerSecondGobelin(Quete $quete, int $hx, int $hy): App\Models\InstanceMonstre
{
    $catalogue = Monstre::where('nom_base', 'Gobelin')->firstOrFail();
    $adjacent = caseAdjacenteLibre($quete, $hx, $hy);

    $second = $quete->instancesMonstres()->where('etat', 'vaincu')->firstOrFail();
    $second->update([
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'etat' => 'actif',
        'elite' => false,
        'revele' => true,
        'position_x' => $adjacent['x'],
        'position_y' => $adjacent['y'],
    ]);

    return $second->refresh()->load('monstre');
}

it('offre les cibles diagonales SEULEMENT sur l\'entrée de l\'arme longue, jamais sur celle de l\'arme courte', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete, 'instance' => $diagonal, 'etatHeros' => $etat] = $ctx;

    $diag = caseDiagonaleLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    if ($diag === null) {
        test()->markTestSkipped('Carte générée sans case diagonale libre autour du héros.');
    }
    $diagonal->update(['position_x' => $diag['x'], 'position_y' => $diag['y']]);

    // Un second Gobelin AU CONTACT ORTHOGONAL : atteint par les deux armes,
    // ce qui garde les DEUX entrées non vides (sinon l'option retombe à plat,
    // faute de second choix réel — cf. « depth follows the data »).
    $orthogonal = reveillerSecondGobelin($quete, (int) $etat->position_x, (int) $etat->position_y);

    armerRapiereEtEpeeCourte($heros);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $option = collect($menu['options'])->firstWhere('id', 'attaquer');
    expect($option)->not->toBeNull();

    $armes = collect($option['parametres']['armes']);
    $droite = $armes->firstWhere('slot', 'arme_principale'); // Rapière
    $gauche = $armes->firstWhere('slot', 'arme_secondaire'); // Épée courte

    expect(collect($droite['cibles'])->pluck('id'))->toContain($diagonal->id)->toContain($orthogonal->id)
        ->and(collect($gauche['cibles'])->pluck('id'))->not->toContain($diagonal->id)
        ->and(collect($gauche['cibles'])->pluck('id'))->toContain($orthogonal->id);
});

it('422 sur une entrée `attaquer` hors de la liste publiée', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros, 'instance' => $instance] = $ctx;

    // Contact ORTHOGONAL par défaut : les deux armes voient le même Gobelin,
    // donc l'option est bien publiée en LISTE (`armes[]`), pas à plat.
    armerRapiereEtEpeeCourte($heros);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);

    desFiges(array_fill(0, 20, 1));
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        // Aucune des deux mains ne porte cette clé.
        'parametres' => ['cle' => 'arme:inexistante', 'cible_id' => $instance->id],
    ])->assertStatus(422)->assertJsonValidationErrors('parametres');

    expect((int) $instance->fresh()->pv_body)->toBe((int) $instance->pv_body);
});

it('422 sur une cible hors des `cibles` de l\'ENTRÉE choisie (l\'arme courte ne peut pas viser où seule la longue porte)', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    ['alice' => $alice, 'groupe' => $groupe, 'heros' => $heros,
        'quete' => $quete, 'instance' => $diagonal, 'etatHeros' => $etat] = $ctx;

    $diag = caseDiagonaleLibre($quete, (int) $etat->position_x, (int) $etat->position_y);
    if ($diag === null) {
        test()->markTestSkipped('Carte générée sans case diagonale libre autour du héros.');
    }
    $diagonal->update(['position_x' => $diag['x'], 'position_y' => $diag['y']]);

    // Le second Gobelin garde l'entrée de l'Épée courte non vide : sans lui
    // l'option retomberait à plat et `parametres.cle` n'existerait même pas.
    reveillerSecondGobelin($quete, (int) $etat->position_x, (int) $etat->position_y);
    armerRapiereEtEpeeCourte($heros);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);

    // Le Gobelin en diagonale EST une cible active, révélée et légale — mais
    // seulement pour la Rapière (main droite). Le viser avec l'Épée courte
    // (main gauche, `arme:arme_secondaire`) doit échouer : sans cette garde,
    // un client frapperait à la portée de la longue avec la courte.
    desFiges(array_fill(0, 20, 1));
    $this->postJson('/api/groupes/table-1/choix', [
        'option_id' => 'attaquer',
        'parametres' => ['cle' => 'arme:arme_secondaire', 'cible_id' => $diagonal->id],
    ])->assertStatus(422);

    expect((int) $diagonal->fresh()->pv_body)->toBe((int) $diagonal->pv_body);
});
