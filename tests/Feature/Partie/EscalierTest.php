<?php

declare(strict_types=1);

use App\Jobs\GenererMenu;
use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\Quete;
use App\Partie\AssembleurCarte;
use App\Partie\Grille;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * Chantier escalier-entrée (2026-10-05, René : « il faudrait ajouter un
 * escalier ou une porte d'entrée pour chaque quête »).
 *
 * Un escalier 2×2 TRAVERSABLE est désormais posé par `AssembleurCarte` dans
 * la salle de départ (salle 0) de chaque quête — le repère du plateau
 * d'origine. `quitter_donjon` n'est offert qu'à un héros sur une case de
 * l'escalier ; `battre_en_retraite` reste sans aucune condition (inchangé).
 *
 * La mission « secourir » (extraction jusqu'à l'escalier) a ses propres
 * tests dans `MissionSecourirTest.php` — ce fichier couvre la couche de
 * carte elle-même et la garde de `quitter_donjon`.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);
    $this->seed([MonstreSeeder::class, TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class]);
});

/**
 * Première case de la salle 0 qui n'appartient PAS à l'escalier — jamais
 * supposée depuis une position de spawn (qui pourrait y tomber par hasard
 * géométrique), toujours calculée depuis le rectangle de la salle.
 *
 * @return array{x: int, y: int}
 */
function caseSalle0HorsEscalier(Quete $quete): array
{
    $salle0 = $quete->carte->grille['salles'][0];
    $casesEscalier = collect($quete->carte->casesEscalier())
        ->map(fn (array $c) => "{$c['x']},{$c['y']}")->all();

    for ($y = (int) $salle0['y'] + 1; $y < (int) $salle0['y'] + (int) $salle0['hauteur'] - 1; $y++) {
        for ($x = (int) $salle0['x'] + 1; $x < (int) $salle0['x'] + (int) $salle0['largeur'] - 1; $x++) {
            if (! in_array("{$x},{$y}", $casesEscalier, true)) {
                return ['x' => $x, 'y' => $y];
            }
        }
    }

    throw new RuntimeException('Salle 0 entièrement couverte par l\'escalier — scénario de test invalide.');
}

it('pose un escalier 2×2 TRAVERSABLE dans la salle 0, jamais sur une case de porte', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarits = GabaritQuete::all();
    $vus = 0;

    foreach (range(1, 20) as $i) {
        $gabarit = $gabarits[$i % count($gabarits)];
        $carte = $assembleur->assembler($gabarit, $i * 9173);
        $escalier = $carte['escalier'];

        expect($escalier)->not->toBeNull("graine {$i} : aucun escalier posé dans la salle 0.");
        expect($escalier['l'])->toBe(2)->and($escalier['h'])->toBe(2);
        $vus++;

        $salle0 = $carte['salles'][0];
        $casesPorte = [];
        foreach ($carte['portes'] as $porte) {
            foreach (Grille::casesPorte($porte) as $c) {
                $casesPorte["{$c['x']},{$c['y']}"] = true;
            }
        }
        $grille = new Grille($carte['cases']);

        for ($dy = 0; $dy < 2; $dy++) {
            for ($dx = 0; $dx < 2; $dx++) {
                $x = $escalier['x'] + $dx;
                $y = $escalier['y'] + $dy;

                expect($x)->toBeGreaterThanOrEqual($salle0['x'])
                    ->and($x)->toBeLessThan($salle0['x'] + $salle0['largeur'])
                    ->and($y)->toBeGreaterThanOrEqual($salle0['y'])
                    ->and($y)->toBeLessThan($salle0['y'] + $salle0['hauteur']);

                expect(isset($casesPorte["{$x},{$y}"]))->toBeFalse(
                    "graine {$i} : l'escalier recouvre une case de porte ({$x},{$y}).",
                );
                expect($grille->estTraversable($x, $y))->toBeTrue(
                    "graine {$i} : la case ({$x},{$y}) de l'escalier n'est pas traversable.",
                );
            }
        }
    }

    expect($vus)->toBe(20);
});

it('est reproductible : la MÊME graine pose le MÊME escalier', function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarit = GabaritQuete::query()->firstOrFail();

    $a = $assembleur->assembler($gabarit, 424242);
    $b = $assembleur->assembler($gabarit, 424242);

    expect($a['escalier'])->toBe($b['escalier'])
        // L'escalier ne consomme AUCUN tirage PRNG : le reste de la carte
        // (pièges, mobilier, leviers...) doit rester identique.
        ->and($a['cases'])->toBe($b['cases'])
        ->and($a['mobilier'])->toBe($b['mobilier']);
});

it('EtatGroupe publie carte.escalier — mêmes cases que Carte::casesEscalier()', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $attendu = $quete->carte->casesEscalier();
    expect($attendu)->toHaveCount(4);

    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $publie = $etat['carte']['escalier'];
    expect($publie)->not->toBeNull()
        ->and($publie['l'])->toBe(2)->and($publie['h'])->toBe(2);

    $cases = [];
    for ($dy = 0; $dy < $publie['h']; $dy++) {
        for ($dx = 0; $dx < $publie['l']; $dx++) {
            $cases[] = ['x' => $publie['x'] + $dx, 'y' => $publie['y'] + $dy];
        }
    }
    expect($cases)->toEqualCanonicalizing($attendu);
});

it('« quitter le donjon » n\'est offert qu\'à un héros SUR une case de l\'escalier', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    // Donjon vidé : le filet anti-blocage suffit à satisfaire l'objectif —
    // seule la garde de l'escalier reste à observer.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    $horsEscalier = caseSalle0HorsEscalier($quete);
    $etat->update(['position_x' => $horsEscalier['x'], 'position_y' => $horsEscalier['y']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids)->not->toContain('quitter_donjon');

    $escalier = $quete->carte->casesEscalier()[0];
    $etat->update(['position_x' => $escalier['x'], 'position_y' => $escalier['y']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids)->toContain('quitter_donjon');

    // Et il s'y résout normalement : ouvre le vote de sortie.
    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'quitter_donjon'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'sortie');
});

it('le résolveur refuse « quitter le donjon » si le héros n\'est plus sur l\'escalier — menu périmé', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();

    // Sur l'escalier au moment où le menu est construit — l'option y figure.
    $escalier = $quete->carte->casesEscalier()[0];
    $etat->update(['position_x' => $escalier['x'], 'position_y' => $escalier['y']]);
    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids)->toContain('quitter_donjon');

    // Il s'éloigne ENTRE-TEMPS (le menu en cache, lui, n'est pas régénéré —
    // exactement le scénario que « refuse de libérer un captif hors de
    // contact » couvre déjà pour la mission « secourir »).
    $horsEscalier = caseSalle0HorsEscalier($quete);
    $etat->update(['position_x' => $horsEscalier['x'], 'position_y' => $horsEscalier['y']]);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'quitter_donjon'])
        ->assertStatus(422);
});

it('REPLI : une carte assemblée SANS la couche escalier offre « quitter le donjon » n\'importe où', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);

    // Simule une carte assemblée AVANT ce chantier (campagne EN COURS dans
    // la vraie base) : la couche `escalier` n'existe pas du tout.
    $carte = $quete->carte;
    $grille = $carte->grille;
    unset($grille['escalier']);
    $carte->update(['grille' => $grille]);
    expect($quete->fresh()->carte->casesEscalier())->toBe([]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');
    expect($ids)->toContain('quitter_donjon');

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => 'quitter_donjon'])
        ->assertStatus(202)
        ->assertJsonPath('resultat.type', 'sortie');
});

it('« battre en retraite » reste offert SANS AUCUNE condition, même loin de l\'escalier', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Albrecht', 1);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $etat = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $hero->id)->firstOrFail();
    $horsEscalier = caseSalle0HorsEscalier($quete);
    $etat->update(['position_x' => $horsEscalier['x'], 'position_y' => $horsEscalier['y']]);

    GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $hero->id);
    $ids = collect(Cache::get(GenererMenu::cleMenu($groupe->id, (int) $alice->id))['menu']['options'])->pluck('id');

    expect($ids)->not->toContain('quitter_donjon') // objectif non accompli, donjon non vidé
        ->and($ids)->toContain('battre_en_retraite');
});

it("fait démarrer le groupe SUR l'escalier, puis au plus près (René, 2026-10-05)", function () {
    $assembleur = app(AssembleurCarte::class);
    $gabarits = GabaritQuete::all();

    foreach (range(1, 20) as $i) {
        $carte = $assembleur->assembler($gabarits[$i % count($gabarits)], $i * 7919);
        $e = $carte['escalier'];
        $spawns = $carte['spawn_heros'];

        $distance = fn (array $p) => max(
            max(0, $e['x'] - $p['x'], $p['x'] - ($e['x'] + $e['l'] - 1)),
            max(0, $e['y'] - $p['y'], $p['y'] - ($e['y'] + $e['h'] - 1)),
        );

        // Les quatre premières places sont les quatre marches, sans doublon.
        $quatre = array_slice($spawns, 0, 4);
        expect(collect($quatre)->map(fn ($p) => $distance($p))->all())->toBe([0, 0, 0, 0], "graine {$i}")
            ->and(collect($spawns)->map(fn ($p) => "{$p['x']},{$p['y']}")->unique()->count())->toBe(count($spawns));

        // La suite s'éloigne sans jamais revenir : la 5e place (5e joueur ou
        // premier allié) est adjacente dès que la salle le permet.
        $distances = collect(array_slice($spawns, 4))->map(fn ($p) => $distance($p))->all();
        expect($distances)->toBe(collect($distances)->sort()->values()->all(), "graine {$i}");
        if ($distances !== []) {
            expect($distances[0])->toBe(1, "graine {$i}");
        }
    }
});
