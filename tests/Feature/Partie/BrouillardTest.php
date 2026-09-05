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

it('ne voit RIEN au-delà d\'un passage secret non trouvé', function () {
    // ⚠ Régression signalée en partie réelle le 2026-09-04 : « on voit le
    // couloir alors que le passage secret n\'est pas trouvé ».
    //
    // `EtatGroupe` construisait UNE seule liste de portes et s\'en servait pour
    // deux choses opposées : la PUBLICATION (où une porte secrète non révélée
    // doit disparaître — un joueur ne doit pas la voir) et le BROUILLARD (où
    // elle est au contraire l\'obstacle le plus opaque du donjon). Le brouillard
    // recevait donc la liste amputée, l\'arête ne portait plus de porte, le
    // défaut `?? \'ouverte\'` la déclarait ouverte, et le flood-fill traversait
    // le passage secret comme un couloir ordinaire.
    //
    // Le test se joue au niveau du FILTRE, pas d\'une carte tirée au hasard :
    // une porte secrète posée sur l\'arête est du héros ne doit rien laisser
    // voir au-delà, quelle que soit la carte générée.
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $etat = $quete->etatsPersonnages()->firstOrFail();
    $hx = (int) $etat->position_x;
    $hy = (int) $etat->position_y;

    // Toutes les portes deviennent SECRÈTES et non révélées : plus aucune sortie
    // légitime de la salle de départ.
    $carte = $quete->carte;
    $grille = $carte->grille;
    $grille['portes'] = array_map(function (array $p) {
        $p['etat'] = 'secrete';
        $p['revele'] = false;

        return $p;
    }, $grille['portes'] ?? []);
    $carte->update(['grille' => $grille]);

    expect($grille['portes'])->not->toBeEmpty();

    $cases = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json('carte.cases');
    $salles = $grille['salles'];

    // La salle de départ reste visible…
    expect($cases[$hy][$hx])->not->toBe('b');

    // …et AUCUNE case de sol hors de cette salle ne l\'est. Sans le correctif,
    // le couloir d\'en face et la salle au bout se dévoilaient (12 à 16 cases
    // mesurées sur cinq donjons).
    $salleDepart = App\Partie\Salles::indexDe($salles, $hx, $hy);
    $fuites = 0;

    foreach ($cases as $y => $ligne) {
        foreach ($ligne as $x => $type) {
            if ($type === 'b' || $type === 'm') {
                continue;
            }
            if (App\Partie\Salles::indexDe($salles, (int) $x, (int) $y) !== $salleDepart) {
                $fuites++;
            }
        }
    }

    expect($fuites)->toBe(0, "{$fuites} cases visibles au-delà du passage secret");

    // …et le marqueur d\'un levier ne trahit pas davantage : il se lit
    // désormais sur le BROUILLARD, plus sur l\'index de salle — un levier de
    // couloir était auparavant toujours publié.
    foreach ($this->getJson('/api/groupes/table-1/etat')->json('carte.leviers') ?? [] as $levier) {
        expect($cases[(int) $levier['y']][(int) $levier['x']])->not->toBe('b',
            'Un levier est publié sur une case masquée.');
    }
});

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
