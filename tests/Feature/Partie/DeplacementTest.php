<?php

declare(strict_types=1);

use App\Auth\JoueurAuthentifiable;
use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Déplacement : l'allonce du tour (base + 1d6, doc 03 §3) est lancée UNE fois
 * et exposée dans le menu, pour que le joueur la voie avant de choisir sa case.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('le menu expose l\'allonce (base + 1d6) lancée une seule fois par tour', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1); // deplacement_base = 4

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    // Force un nouveau tour « vierge » puis fige le d6 à 4.
    $etat->update(['deplacement_tour' => null, 'a_joue' => false]);
    desFiges([4]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $dep = collect($menu['options'])->firstWhere('type', 'deplacement');

    expect($dep['parametres']['base'])->toBe(4)
        ->and($dep['parametres']['de'])->toBe(4)
        ->and($dep['parametres']['portee'])->toBe(8)        // 4 + 1d6(4)
        ->and((int) $etat->fresh()->deplacement_tour)->toBe(8);

    // Régénérer le menu ne RELANCE pas le dé (allonce stable sur le tour).
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    expect((int) $etat->fresh()->deplacement_tour)->toBe(8);
});

it('deux créneaux : se déplacer n\'achève PAS le tour, le héros peut encore agir', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2); // 2nd héros : le tour ne passe pas aux monstres

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $etatA->update(['deplacement_tour' => 8, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);
    $cible = caseAdjacenteLibre($quete, (int) $etatA->position_x, (int) $etatA->position_y);

    // 1) Déplacement : créneau MOUVEMENT consommé, mais le tour n'est pas fini.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202);
    $etatA->refresh();
    expect($etatA->a_deplace)->toBeTrue()
        ->and($etatA->a_agi)->toBeFalse()
        ->and($etatA->a_joue)->toBeFalse();

    // 2) Le menu régénéré n'offre plus le déplacement, mais permet encore d'agir.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    expect(collect($menu['options'])->firstWhere('type', 'deplacement'))->toBeNull()
        ->and(collect($menu['options'])->firstWhere('type', 'attente'))->not->toBeNull();

    // 3) Terminer le tour → les deux créneaux sont consommés → a_joue.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);
    expect($etatA->fresh()->a_joue)->toBeTrue();
});
