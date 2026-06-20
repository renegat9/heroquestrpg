<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Jobs\GenererNarration;
use App\Models\EtatPersonnageQuete;
use App\Models\Quete;
use App\Partie\ResolveurTour;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Découverte de salle : entrer dans une salle encore inexplorée (déplacement ou
 * Traverser la Pierre) déclenche la description de la salle par le MJ. La salle
 * de départ est connue d'emblée (couverte par la narration de démarrage).
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
    Cache::put(ResolveurTour::cleSallesDecouvertes($quete->id), [0], now()->addMinutes(360));
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Queue::fake();
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    Queue::assertPushed(GenererNarration::class, fn (GenererNarration $j) =>
        ($j->resultatMoteur['type'] ?? null) === 'salle_decouverte' && ($j->resultatMoteur['salle'] ?? null) === 1);

    expect(Cache::get(ResolveurTour::cleSallesDecouvertes($quete->id)))->toContain(1);
});

it('ne re-décrit pas la salle de départ (déjà connue)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Le héros reste dans la salle de départ (salle 0), déjà découverte.
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);

    Queue::fake();
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'attendre'])->assertStatus(202);

    Queue::assertNotPushed(GenererNarration::class); // ni description de salle ni autre narration (action triviale)
});
