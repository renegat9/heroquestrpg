<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * MANETTE À PLUSIEURS HÉROS, lot M1 (`docs/plan-mode-classique.md` §5).
 *
 * Une manette peut jouer plusieurs héros : les siens (une table de deux joueurs à deux héros chacun), ou celui d'un
 * absent qu'on lui a confié (`groupe_personnages.pilote_joueur_id`). Trois propriétés :
 *
 *  1. CHAQUE héros d'une manette reçoit son menu À SON TOUR. Avant ce lot, `MenuCourant` rendait toujours le premier
 *     héros du joueur : le second n'avait jamais la main.
 *  2. Le menu d'attente d'un héros hors tour N'ÉCRASE PAS le menu jouable de l'autre héros de la même manette.
 *  3. On joue le héros qu'on PILOTE, pas celui qu'on possède : le pilote a le menu et le choix, le propriétaire absent
 *     ne les a plus.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('sert à la même manette le menu de chacun de ses héros, à son tour', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $premier = creerHeros($alice, $groupe, 'Albrecht', 1);
    $second = creerHeros($alice, $groupe, 'Brunehaut', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $r = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json();
    expect($r['personnage_id'])->toBe($premier->id)
        ->and($r['menu']['options'])->not->toBeEmpty();

    // Le premier a joué : la main passe au second, sur la MÊME manette.
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $premier->id)->update(['a_joue' => true]);

    $r = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json();
    expect($r['personnage_id'])->toBe($second->id)
        ->and($r['menu']['options'])->not->toBeEmpty();
});

it('ne laisse pas le héros hors tour écraser le menu de celui qui a la main', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $premier = creerHeros($alice, $groupe, 'Albrecht', 1);
    $second = creerHeros($alice, $groupe, 'Brunehaut', 2);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    // Le job du second héros passe APRÈS celui du premier : il ne publie rien, la case garde le menu jouable.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $premier->id);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $second->id);

    $case = Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id));

    expect($case['personnage_id'])->toBe($premier->id)
        ->and($case['au_tour'])->toBeTrue();
});

it('donne le menu et le choix au PILOTE du héros, pas à son propriétaire absent', function () {
    $bob = connecterJoueur('bob');
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $deBob = creerHeros($bob, $groupe, 'Bertrand', 1);
    creerHeros($alice, $groupe, 'Albrecht', 2);

    // Bob est absent : son héros est confié à Alice.
    DB::table('groupe_personnages')->where('groupe_id', $groupe->id)->where('personnage_id', $deBob->id)
        ->update(['pilote_joueur_id' => $alice->id]);

    expect($groupe->piloteDe($deBob))->toBe((int) $alice->id)
        ->and($groupe->herosPilotesPar((int) $alice->id)->count())->toBe(2)
        ->and($groupe->herosPilotesPar((int) $bob->id)->count())->toBe(0);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();

    // C'est le tour du héros de Bob : la manette d'Alice a son menu…
    $r = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json();
    expect($r['personnage_id'])->toBe($deBob->id);

    $attendre = collect($r['menu']['options'])->firstWhere('type', 'attente');
    expect($attendre)->not->toBeNull();

    // …et Bob, s'il se connecte quand même, n'en a aucun et ne peut rien jouer.
    $this->actingAs($bob, 'joueur');
    expect($this->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu'))->toBeNull();
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $attendre['id']])->assertStatus(422);

    // Alice joue le tour du héros de Bob.
    $this->actingAs($alice, 'joueur');
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => $attendre['id']])->assertAccepted();
});
