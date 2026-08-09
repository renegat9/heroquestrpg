<?php

declare(strict_types=1);

use App\Models\Quete;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
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
    $quete->update(['salles_decouvertes' => [0, $derniere]]);
    $apres = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.cases');
    expect($apres[$cN['y']][$cN['x']])->not->toBe('b');
});

it('montre à un héros les murs qui le TOUCHENT, même hors zone révélée', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $etat = $quete->etatsPersonnages()->where('personnage_id', $hero->id)->firstOrFail();

    // Le héros apparaît au centre de sa salle : on le colle à l'angle intérieur,
    // où il touche deux murs.
    $salle = $quete->carte->grille['salles'][0];
    $etat->update([
        'position_x' => (int) $salle['x'] + 1,
        'position_y' => (int) $salle['y'] + 1,
    ]);
    $etat->refresh();

    // On isole le héros : aucune salle « découverte », donc rien de visible
    // sinon ce qu'il touche lui-même.
    $quete->update(['salles_decouvertes' => []]);

    $cases = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.cases');

    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;
    $brut = $quete->carte->grille['cases'];

    // Un mur non adjacent à une zone déjà visible repartait en `b`,
    // indiscernable d'un sol inconnu : la manette le proposait comme
    // destination, le serveur le refusait. Un héros voit ses propres parois.
    $murs = 0;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if (($brut[$hy + $dy][$hx + $dx] ?? 'm') === 'm') {
            $murs++;
            expect($cases[$hy + $dy][$hx + $dx])->toBe('m', "mur en ({$hx}+{$dx},{$hy}+{$dy}) masqué");
        }
    }

    expect($murs)->toBeGreaterThan(0); // le scénario doit bien contenir un mur
});
