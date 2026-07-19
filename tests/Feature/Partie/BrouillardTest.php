<?php

declare(strict_types=1);

use App\Models\Quete;
use App\Partie\ResolveurTour;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Brouillard de guerre (chantier 2) : la carte servie (/etat) ne dévoile que la
 * salle de départ et ce qu'on atteint depuis elle par des portes OUVERTES. Les
 * salles non découvertes (derrière une porte fermée) sont masquées ('b') jusqu'à
 * ce qu'un héros y entre (decouvrirSalle). Purement cosmétique — le moteur
 * travaille toujours sur la carte réelle.
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/** Centre (x,y) d'une salle assemblée. */
function centreDe(array $s): array
{
    return ['x' => (int) $s['x'] + intdiv((int) $s['largeur'], 2), 'y' => (int) $s['y'] + intdiv((int) $s['hauteur'], 2)];
}

it('masque les salles non découvertes et laisse voir la salle de départ', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $salles = $quete->carte->grille['salles'];
    expect(count($salles))->toBeGreaterThan(1);

    $cases = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.cases');

    // La salle de départ (0) est visible : son centre n'est pas du brouillard.
    $c0 = centreDe($salles[0]);
    expect($cases[$c0['y']][$c0['x']])->not->toBe('b');

    // La dernière salle (non découverte, derrière des portes fermées) est masquée.
    $cN = centreDe($salles[count($salles) - 1]);
    expect($cases[$cN['y']][$cN['x']])->toBe('b');

    // Et au moins une case de la carte est bel et bien passée en brouillard.
    expect(collect($cases)->flatten()->contains('b'))->toBeTrue();
});

it('lève le brouillard sur une salle une fois découverte', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $salles = $quete->carte->grille['salles'];
    $derniere = count($salles) - 1;
    $cN = centreDe($salles[$derniere]);

    // Masquée au départ…
    $avant = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.cases');
    expect($avant[$cN['y']][$cN['x']])->toBe('b');

    // …puis découverte → dévoilée.
    Cache::put(ResolveurTour::cleSallesDecouvertes($quete->id), [0, $derniere], now()->addMinutes(360));
    $apres = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.cases');
    expect($apres[$cN['y']][$cN['x']])->not->toBe('b');
});
