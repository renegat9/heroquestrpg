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

it('déplacement fractionné : un pas laisse des points, on peut CONTINUER à se déplacer (E1)', function () {
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

    // 1) Un pas (1 case) sur 8 → il reste 7 points : le mouvement N'EST PAS fini.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202)
        ->assertJsonPath('resultat.deplacement_restant', 7);
    $etatA->refresh();
    expect($etatA->deplacement_restant)->toBe(7)
        ->and($etatA->a_deplace)->toBeFalse()
        ->and($etatA->a_agi)->toBeFalse()
        ->and($etatA->a_joue)->toBeFalse();

    // 2) Le menu régénéré propose ENCORE le déplacement (« Continuer »), à la
    //    portée restante, plus les actions et « Terminer le tour ».
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    $dep = collect($menu['options'])->firstWhere('type', 'deplacement');
    expect($dep)->not->toBeNull()
        ->and($dep['parametres']['portee'])->toBe(7)
        ->and(collect($menu['options'])->firstWhere('type', 'attente'))->not->toBeNull();

    // 3) Terminer le tour → a_joue.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);
    expect($etatA->fresh()->a_joue)->toBeTrue();
});

it('diffuse le trajet du héros (.mouvement.anime) pour l\'animation case-par-case (E4)', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\MouvementAnime::class]);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2); // pas de phase monstres

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $etatA->update(['deplacement_tour' => 8, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    $depart = ['x' => (int) $etatA->position_x, 'y' => (int) $etatA->position_y];
    $cible = caseAdjacenteLibre($quete, $depart['x'], $depart['y']);

    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202);

    \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\MouvementAnime::class, function ($e) use ($groupe, $heroA, $depart, $cible) {
        $mv = collect($e->mouvements)->firstWhere('id', $heroA->id);

        return $e->groupe->id === $groupe->id
            && $mv !== null
            && $mv['type'] === 'heros'
            && $mv['depart'] === $depart
            && end($mv['chemin']) === $cible; // le chemin se termine sur la case visée
    });
});

it('garde le déplacement restant APRÈS une action : on agit puis on continue à se déplacer, le tour ne finit que sur décision', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $etatA->update(['deplacement_tour' => 8, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);
    $cible = caseAdjacenteLibre($quete, (int) $etatA->position_x, (int) $etatA->position_y);

    // Un pas → il reste 7 points.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202);
    expect($etatA->fresh()->deplacement_restant)->toBe(7);

    // Puis « Fouiller » (action) : le mouvement restant est CONSERVÉ (on peut
    // agir puis continuer). a_agi posé, mais le tour N'EST PAS terminé.
    desFiges(array_fill(0, 20, 1));
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])
        ->assertStatus(202);
    $etatA->refresh();
    expect($etatA->deplacement_restant)->toBe(7)
        ->and($etatA->a_deplace)->toBeFalse()
        ->and($etatA->a_agi)->toBeTrue()
        ->and($etatA->a_joue)->toBeFalse();

    // Le menu régénéré propose ENCORE de se déplacer (au restant) + « Terminer le tour ».
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);
    $menu = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu'];
    expect(collect($menu['options'])->firstWhere('type', 'deplacement'))->not->toBeNull()
        ->and(collect($menu['options'])->firstWhere('id', 'attendre'))->not->toBeNull();

    // Le joueur DÉCIDE de terminer → a_joue.
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])
        ->assertStatus(202);
    expect($etatA->fresh()->a_joue)->toBeTrue();
});

it('permet d\'AGIR PUIS de se déplacer (action avant mouvement), le mouvement reste offert', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heroA = creerHeros($alice, $groupe, 'Albrecht', 1);
    $bob = JoueurAuthentifiable::create(['pseudo' => 'bob', 'identifiant' => 'bob', 'mot_de_passe' => 'secret']);
    creerHeros($bob, $groupe, 'Brunhilde', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etatA = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heroA->id)->firstOrFail();
    $etatA->update(['deplacement_tour' => 6, 'a_deplace' => false, 'a_agi' => false, 'a_joue' => false]);

    // 1) Le héros AGIT d'abord (fouiller) — aucun déplacement encore fait.
    desFiges(array_fill(0, 20, 4));
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'fouiller'])->assertStatus(202);
    $etatA->refresh();
    expect($etatA->a_agi)->toBeTrue()
        ->and($etatA->a_deplace)->toBeFalse()
        ->and($etatA->a_joue)->toBeFalse();

    // Le menu propose ENCORE « Se déplacer » (le créneau mouvement est intact).
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heroA->id);
    expect(collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->firstWhere('type', 'deplacement'))
        ->not->toBeNull();

    // 2) PUIS il se déplace : accepté, la figurine bouge.
    $cible = caseAdjacenteLibre($quete, (int) $etatA->position_x, (int) $etatA->position_y);
    $this->actingAs($alice, 'joueur')
        ->postJson('/api/groupes/table-1/choix', ['option_id' => 'se_deplacer', 'parametres' => $cible])
        ->assertStatus(202)
        ->assertJsonPath('resultat.vers', $cible);
    $etatA->refresh();
    expect((int) $etatA->position_x)->toBe($cible['x'])
        ->and((int) $etatA->position_y)->toBe($cible['y']);
});
