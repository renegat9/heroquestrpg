<?php

declare(strict_types=1);

use App\Models\EtatPersonnageQuete;
use App\Models\GabaritQuete;
use App\Models\GroupeMercenaire;
use App\Models\Mercenaire;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Chantier 3b (2026-10-04) — mission « secourir » : un captif posé sur la
 * carte, libéré au contact, devient un allié joué par son joueur (3a) pour
 * le reste de la quête ; l'objectif est rempli s'il sort vivant, échoue s'il
 * meurt (Gothar, Frozen Horror p. 19 : « escort […] if the Barbarian dies,
 * Gothar is automatically captured »).
 *
 * ⚠ `DemarreurQuete::choisirGabarit()` ne tire « Mission de sauvetage » que
 * sur une rotation déterministe (1 quête sur `RATIO_SECOURIR`) : ces tests
 * FORCENT le gabarit après le démarrage plutôt que de dépendre du tirage —
 * ils exercent le MÉCANISME (objectif, libération, échec), pas la rotation
 * elle-même.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, MercenaireSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

it('Gothar n\'est jamais recrutable au hub', function () {
    $alice = connecterJoueur('alice');
    $catalogue = $this->getJson('/api/mercenaires')->assertOk()->json('mercenaires');
    expect(collect($catalogue)->pluck('nom')->all())->not->toContain('Gothar');

    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 5000]);

    $gothar = Mercenaire::where('nom', 'Gothar')->firstOrFail();
    $this->postJson('/api/groupes/table-1/mercenaires', ['mercenaire_id' => $gothar->id])
        ->assertStatus(422);
});

/**
 * Démarre une quête à un héros, force le gabarit « Mission de sauvetage », et
 * pose Gothar (captif) adjacent au héros, dans sa salle de départ (déjà
 * découverte).
 *
 * @return array{0: \App\Models\Groupe, 1: Quete, 2: \App\Models\Personnage, 3: GroupeMercenaire, 4: \App\Auth\JoueurAuthentifiable}
 */
function queteAvecCaptif(): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $groupe->refresh();
    $quete = Quete::findOrFail($groupe->quete_courante_id);

    $gabaritSecourir = GabaritQuete::where('nom', 'Mission de sauvetage')->firstOrFail();
    $quete->update(['gabarit_id' => $gabaritSecourir->id]);

    $gothar = Mercenaire::where('nom', 'Gothar')->firstOrFail();
    $etatHeros = EtatPersonnageQuete::where('quete_id', $quete->id)->where('personnage_id', $heros->id)->firstOrFail();
    $case = caseAdjacenteLibre($quete, (int) $etatHeros->position_x, (int) $etatHeros->position_y);

    $captif = GroupeMercenaire::create([
        'groupe_id' => $groupe->id,
        'mercenaire_id' => $gothar->id,
        'pv_body' => (int) $gothar->pv_body,
        'position_x' => $case['x'],
        'position_y' => $case['y'],
        'etat' => 'captif',
    ]);
    $quete->update(['captif_mercenaire_id' => $captif->id]);

    // Le captif est posé APRÈS le premier menu du héros (dispatché, en
    // synchrone, par le démarrage de la quête ci-dessus) — contrairement au
    // jeu réel, où `DemarreurQuete` le pose AVANT ce premier menu. On force
    // donc sa RÉGÉNÉRATION (synchrone, comme au jeu réel) pour que le menu en
    // cache voie l'option « Libérer », faute de quoi un `POST /choix` dessus
    // répondrait 422 « option illégale » sur un menu périmé par ce raccourci
    // de test, pas par le mécanisme lui-même.
    \App\Jobs\GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);

    return [$groupe, $quete, $heros, $captif->fresh(), $alice];
}

it('publie le captif sur la carte (salle découverte), et l\'objectif n\'est pas accompli', function () {
    [, $quete, , $captif] = queteAvecCaptif();

    $quete->refresh();
    expect($quete->objectif())->toBe('secourir')
        ->and($quete->objectifAccompli())->toBeFalse()
        ->and($quete->objectifLibelle())->toContain('Gothar');

    $etat = test()->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    $entite = collect($etat['entites'])->firstWhere('type', 'captif');

    expect($entite)->not->toBeNull()
        ->and($entite['id'])->toBe($captif->id)
        ->and($entite['nom'])->toBe('Gothar')
        ->and($entite['pv_body'])->toBe(2)
        ->and($entite['pv_body_max'])->toBe(2);

    // Pas encore un allié : absent de `groupe.mercenaires` (hub/allié exposé).
    expect(collect($etat['groupe']['mercenaires'] ?? [])->pluck('id')->all())->not->toContain($captif->id);
});

it('reste CACHÉ tant que sa salle n\'est pas découverte, et réapparaît une fois la salle ouverte', function () {
    [$groupe, $quete, , $captif] = queteAvecCaptif();

    // La salle 0 (départ) est TOUJOURS tenue pour découverte
    // (`Quete::sallesDecouvertes()`) : il faut une AUTRE salle pour tester le
    // brouillard — le gabarit en garantit au moins 5 (plancher du passage
    // secret), aucune autre n'est découverte à l'instant du départ.
    $salles = (array) data_get($quete->carte?->grille, 'salles', []);
    $indexAutreSalle = null;
    foreach (array_keys($salles) as $i) {
        if ((int) $i !== 0) {
            $indexAutreSalle = (int) $i;
            break;
        }
    }
    expect($indexAutreSalle)->not->toBeNull();
    $autreSalle = $salles[$indexAutreSalle];

    $captif->update(['position_x' => (int) $autreSalle['x'], 'position_y' => (int) $autreSalle['y']]);

    $etat = test()->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($etat['entites'])->firstWhere('type', 'captif'))->toBeNull();

    $quete->update(['salles_decouvertes' => [0, $indexAutreSalle]]);

    $etat = test()->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($etat['entites'])->firstWhere('type', 'captif'))->not->toBeNull();
});

it('un héros au contact libère le captif : il devient un allié qu\'il contrôle, mais l\'objectif attend l\'escalier', function () {
    [$groupe, $quete, $heros, $captif] = queteAvecCaptif();

    $menu = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu');
    $option = collect($menu['options'])->firstWhere('id', "liberer_{$captif->id}");
    expect($option)->not->toBeNull()->and($option['type'])->toBe('liberer_captif');

    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => "liberer_{$captif->id}"])
        ->assertStatus(202);

    expect($reponse->json('resultat.type'))->toBe('captif_libere')
        ->and($reponse->json('resultat.allie'))->toBe('Gothar');

    $captif->refresh();
    expect($captif->etat)->toBe('actif')
        ->and($captif->recruteur_personnage_id)->toBe($heros->id);

    // ESCALIER D'ENTRÉE (2026-10-05) : libéré n'est plus accompli tant que le
    // captif n'est pas sur l'escalier — la mission « secourir » est une
    // EXTRACTION (Frozen Horror p. 19 : « escort »). On le place d'abord sur
    // une case de la salle 0 GARANTIE hors escalier (plutôt que de supposer
    // que sa position adjacente au héros, choisie plus haut, n'y tombe pas
    // par coïncidence géométrique).
    $quete->refresh();
    $salle0 = $quete->carte->grille['salles'][0];
    $casesEscalier = collect($quete->carte->casesEscalier())->map(fn (array $c) => "{$c['x']},{$c['y']}")->all();
    $horsEscalier = null;
    for ($y = (int) $salle0['y'] + 1; $y < (int) $salle0['y'] + (int) $salle0['hauteur'] - 1 && $horsEscalier === null; $y++) {
        for ($x = (int) $salle0['x'] + 1; $x < (int) $salle0['x'] + (int) $salle0['largeur'] - 1; $x++) {
            if (! in_array("{$x},{$y}", $casesEscalier, true)) {
                $horsEscalier = ['x' => $x, 'y' => $y];

                break;
            }
        }
    }
    expect($horsEscalier)->not->toBeNull();
    $captif->update(['position_x' => $horsEscalier['x'], 'position_y' => $horsEscalier['y']]);

    expect($quete->fresh()->objectifAccompli())->toBeFalse();

    // Il a basculé de `captif` à `allie` dans l'état publié.
    $etat = $this->getJson('/api/groupes/table-1/etat')->assertOk()->json();
    expect(collect($etat['entites'])->firstWhere('type', 'captif'))->toBeNull();
    $allieEntite = collect($etat['entites'])->firstWhere('type', 'allie');
    expect($allieEntite)->not->toBeNull()->and($allieEntite['id'])->toBe($captif->id);

    // Ramené à l'escalier : l'objectif s'accomplit.
    $escalier = $quete->carte->casesEscalier();
    expect($escalier)->not->toBe([]);
    $captif->update(['position_x' => $escalier[0]['x'], 'position_y' => $escalier[0]['y']]);

    expect($quete->fresh()->objectifAccompli())->toBeTrue();
});

it('REPLI : sur une carte sans la couche escalier, la libération seule accomplit la mission', function () {
    [, $quete, , $captif] = queteAvecCaptif();

    // Simule une carte assemblée AVANT le chantier escalier-entrée (campagne
    // EN COURS dans la vraie base) : la couche n'existe pas du tout.
    $carte = $quete->carte;
    $grille = $carte->grille;
    unset($grille['escalier']);
    $carte->update(['grille' => $grille]);

    $captif->update(['etat' => 'actif']);

    expect($quete->fresh()->objectifAccompli())->toBeTrue();
});

it('REPLI : sur une carte sans la couche escalier, « quitter le donjon » est offert n\'importe où', function () {
    [$groupe, $quete, $heros, , $alice] = queteAvecCaptif();

    $carte = $quete->carte;
    $grille = $carte->grille;
    unset($grille['escalier']);
    $carte->update(['grille' => $grille]);

    // Donjon vidé : le filet anti-blocage suffit à ouvrir `quitter_donjon`,
    // peu importe où se trouve le héros.
    $quete->instancesMonstres()->update(['etat' => 'vaincu']);
    \App\Jobs\GenererMenu::dispatchSync($groupe->id, (int) $alice->id, (int) $heros->id);

    $menu = $this->getJson('/api/groupes/table-1/menu')->assertOk()->json('menu');
    expect(collect($menu['options'])->pluck('id'))->toContain('quitter_donjon');
});

it('refuse de libérer un captif hors de contact', function () {
    [, $quete, $heros, $captif] = queteAvecCaptif();

    // On éloigne le captif après coup : l'option a été construite pour le
    // menu courant, mais le résolveur revalide le contact lui-même.
    $captif->update(['position_x' => 0, 'position_y' => 0]);

    $this->postJson('/api/groupes/table-1/choix', ['option_id' => "liberer_{$captif->id}"])
        ->assertStatus(422);
});

it('la mort du captif libéré échoue la quête IMMÉDIATEMENT, comme un TPK', function () {
    [$groupe, $quete, $heros, $captif] = queteAvecCaptif();

    // Fragile à coup sûr (1 PV), et un monstre robuste posté à SA case —
    // adjacent dès qu'il sera libéré, là où se trouve encore le captif.
    $captif->update(['pv_body' => 1]);
    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $contact = caseAdjacenteLibre($quete, (int) $captif->position_x, (int) $captif->position_y);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y'], 'revele' => true, 'pv_body' => 20]);

    // Des crânes partout : le monstre touche l'allié fraîchement libéré, qui
    // ne pare rien.
    desFiges(array_fill(0, 80, 1));

    // Libérer ferme le tour du héros ET celui de l'allié fraîchement libéré
    // (il ne joue pas le round de sa propre libération) : la phase des
    // monstres s'ouvre dans CETTE MÊME réponse.
    $reponse = $this->postJson('/api/groupes/table-1/choix', ['option_id' => "liberer_{$captif->id}"])
        ->assertStatus(202);

    $attaque = collect($reponse->json('resultat.tour_monstres.actions'))->firstWhere('type', 'attaque_monstre');
    expect($attaque)->not->toBeNull()->and($attaque['allie_vaincu'])->toBeTrue();

    $quete->refresh();
    $groupe->refresh();
    expect($quete->etat)->toBe('echouee')
        ->and($groupe->phase)->toBe('hub')
        ->and($groupe->mercenaires()->count())->toBe(0);
});

it('un gabarit « secourir » SANS captif désigné tient l\'objectif pour accompli (jamais une mission impossible)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $groupe->update(['or' => 500]);

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->update([
        'gabarit_id' => GabaritQuete::where('nom', 'Mission de sauvetage')->firstOrFail()->id,
        'captif_mercenaire_id' => null,
    ]);

    expect($quete->fresh()->objectifAccompli())->toBeTrue();
});

it("la case du captif n'est franchissable par personne avant sa libération", function () {
    [, $quete, $heros, $captif] = queteAvecCaptif();

    $pourHeros = App\Partie\FabriqueGrille::pour($quete->fresh(), exceptPersonnageId: $heros->id, franchitAllies: true);
    $pourMonstre = App\Partie\FabriqueGrille::pour($quete->fresh(), exceptInstanceId: PHP_INT_MAX, franchitAllies: true);

    expect($pourHeros->estTraversable((int) $captif->position_x, (int) $captif->position_y))->toBeFalse()
        ->and($pourMonstre->estTraversable((int) $captif->position_x, (int) $captif->position_y))->toBeFalse();
});
