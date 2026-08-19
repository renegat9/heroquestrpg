<?php

declare(strict_types=1);

use App\Events\NarrationDiffusee;
use App\Jobs\GenererMenu;
use App\Models\Evenement;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * Découverte de salle : entrer dans une salle encore inexplorée (déplacement ou
 * Traverser la Pierre) déclenche la description de la salle par le MJ. La salle
 * de départ est connue d'emblée (couverte par la narration de démarrage).
 *
 * Bascule 2026-08-18 : la narration est résolue SYNCHRONEMENT (plus de job
 * `GenererNarration`) — on vérifie donc le `NarrationDiffusee` diffusé plutôt
 * qu'un job poussé en file.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

function centreSalle(array $s): array
{
    return ['x' => (int) $s['x'] + intdiv((int) $s['largeur'], 2), 'y' => (int) $s['y'] + intdiv((int) $s['hauteur'], 2)];
}

it('décrit une salle nouvellement explorée quand un héros y agit', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $salles = $quete->carte->grille['salles'];
    expect(count($salles))->toBeGreaterThan(1);

    // Héros placé dans la salle 1 (non découverte ; seule la 0 l'est au départ).
    $c = centreSalle($salles[1]);
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)
        ->update(['position_x' => $c['x'], 'position_y' => $c['y'], 'a_joue' => false]);
    $quete->update(['salles_decouvertes' => [0]]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Event::fake([NarrationDiffusee::class]);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Salle 1 marquée découverte au journal SYSTÈME (inchangé par la bascule)…
    $evenementSysteme = Evenement::where('groupe_id', $groupe->id)->where('type', 'systeme')->get()
        ->first(fn ($e) => ($e->payload['action'] ?? null) === 'salle_decouverte' && ($e->payload['salle'] ?? null) === 1);
    expect($evenementSysteme)->not->toBeNull();

    // … et narrée SYNCHRONEMENT (pack de quête pré-généré, sinon repli
    // scripté de config/narration.php — jamais d'appel LLM).
    Event::assertDispatched(NarrationDiffusee::class);

    expect($quete->fresh()->sallesDecouvertes())->toContain(1);
});

it('garde les monstres d\'une salle DORMANTS jusqu\'à sa découverte, puis les révèle', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Au démarrage : des monstres existent mais sont DORMANTS → invisibles.
    expect($quete->instancesMonstres()->where('revele', false)->count())->toBeGreaterThan(0);
    $etat0 = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($etat0['entites'])->where('type', 'monstre'))->toHaveCount(0);

    // Le héros entre dans la salle d'un monstre dormant.
    $inst = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $salles = $quete->carte->grille['salles'];
    $idx = collect($salles)->search(fn ($s) => (int) $inst->position_x >= (int) $s['x']
        && (int) $inst->position_x < (int) $s['x'] + (int) $s['largeur']
        && (int) $inst->position_y >= (int) $s['y']
        && (int) $inst->position_y < (int) $s['y'] + (int) $s['hauteur']);
    $c = centreSalle($salles[$idx]);
    EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)
        ->update(['position_x' => $c['x'], 'position_y' => $c['y'], 'a_joue' => false]);
    $quete->update(['salles_decouvertes' => [0]]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    // Le monstre de cette salle est révélé et désormais visible sur la table.
    expect((bool) $inst->fresh()->revele)->toBeTrue();
    $etat1 = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($etat1['entites'])->where('type', 'monstre')->count())->toBeGreaterThan(0);
});

it('ne re-décrit pas la salle de départ (déjà connue)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Le héros reste dans la salle de départ (salle 0), déjà découverte.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Event::fake([NarrationDiffusee::class]);
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    Event::assertNotDispatched(NarrationDiffusee::class); // ni description de salle ni autre narration (action triviale)
});
