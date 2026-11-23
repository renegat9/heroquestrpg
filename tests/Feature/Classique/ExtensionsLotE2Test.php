<?php

declare(strict_types=1);

use App\Engine\Des\LanceurDes;
use App\Engine\Des\LanceurDeterministe;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\ResolveurTour;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LES RÈGLES D'UNE SEULE BOÎTE EN MODE CLASSIQUE — lot E2 (2026-11-23). Plateau inventé (`DocumentExemple`) ; les règles
 * citées viennent des livrets (hors dépôt).
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
        ObjetSeeder::class, SortSeeder::class, SortDreadSeeder::class]);
});

function campagneE2(array $quete = []): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['prerequis_heros'] = [];
    $campagne['quetes'][0] = array_replace($campagne['quetes'][0], [
        'monstres' => [], 'fin' => ['type' => 'sortir', 'libelle' => 'Ressortir.'],
    ], $quete);

    return $campagne;
}

function codesE2(array $campagne): array
{
    return array_column(app(ValidationCampagne::class)->valider($campagne), 'code');
}

/** @return array{code: string, groupe: Groupe, quete: Quete, heros: Personnage} */
function partieE2(object $test, array $campagne): array
{
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 30, 'pv_body' => 30, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();

    return ['code' => $code, 'groupe' => $groupe, 'quete' => Quete::findOrFail($groupe->refresh()->quete_courante_id), 'heros' => $heros];
}

/** Le héros frappe la première figure de la quête, depuis la case voisine, avec ces dés (d6). */
function frapperE2(array $partie, array $des): array
{
    app()->instance(LanceurDes::class, new LanceurDeterministe([...$des, ...array_fill(0, 30, 1)]));
    $quete = $partie['quete']->fresh();
    $instance = $quete->instancesMonstres()->with('monstre')->firstOrFail();
    $etat = $quete->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => (int) $instance->position_x - 1, 'position_y' => (int) $instance->position_y]);

    return app(ResolveurTour::class)->frapper($partie['groupe'], $quete, $etat->fresh(), $partie['heros']->fresh(), $instance);
}

// ---------------------------------------------------------------- The Frozen Horror

it('brouillard vivant : un leurre gaspille l\'attaque, le bouclier blanc touche le vrai monstre', function (int $de, bool $leurre) {
    $campagne = campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'pv_body' => 9]], 'regles' => ['brouillard_vivant' => ['galerie']]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);

    // Le dé du brouillard, puis trois crânes d'attaque.
    $resultat = frapperE2($partie, [$de]);

    expect($resultat['brouillard']['leurre'])->toBe($leurre)
        ->and((int) $partie['quete']->instancesMonstres()->first()->pv_body < 9)->toBe(! $leurre);
})->with(['crâne : un leurre' => [1, true], 'bouclier blanc : le vrai' => [4, false]]);

it('refuse un brouillard dans une salle fermée', function () {
    expect(codesE2(campagneE2(['regles' => ['brouillard_vivant' => ['crypte']]])))->toBe([ValidationCampagne::SALLE_INCONNUE]);
});

it('reddition : attaquée au contact, la figure se rend, s\'enfuit et laisse son arme', function () {
    $campagne = campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'nom' => 'Arbalétrier', 'se_rend' => ['objet' => 'arbalete']]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);

    $resultat = frapperE2($partie, []);

    $figure = $partie['quete']->instancesMonstres()->first();
    expect($figure->etat)->toBe('vaincu')->and($figure->position_x)->toBeNull()
        ->and($resultat['reddition_auteur']['objet'])->toBe('Arbalète')
        ->and($partie['heros']->inventaire()->whereHas('objet', fn ($q) => $q->where('nom', 'Arbalète'))->exists())->toBeTrue();
    $fil = collect(app(\App\Partie\JournalCombat::class)->depuisResultat($resultat, 'Albrecht'))->pluck('texte')->implode(' | ');
    expect($fil)->toContain('Arbalétrier se rend et s\'enfuit, laissant Arbalète à Albrecht');
});

/** Le héros se déplace par la vraie route jusqu'à (x, y), dés de déplacement imposés (6). */
function deplacerE2(object $test, array $partie, int $x, int $y): \Illuminate\Testing\TestResponse
{
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 40, 6)));
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $test->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();

    return $test->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => $x, 'y' => $y]]);
}

it('glissière : le héros est emporté jusqu\'à la case d\'arrivée de l\'auteur', function () {
    $campagne = campagneE2(['terrain' => [['slug' => 'glissiere-de-glace', 'x' => 3, 'y' => 3, 'sortie' => ['x' => 5, 'y' => 5]]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['terrain' => [['slug' => 'glace-glissante', 'x' => 3, 'y' => 3, 'sortie' => ['x' => 5, 'y' => 5]]]])))
        ->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 3]);

    deplacerE2($this, $partie, 3, 3)->assertAccepted();

    $etat = $partie['quete']->etatsPersonnages()->first();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([5, 5]);
});

it('tunnel de glace : vers une salle encore cachée, qui se révèle', function () {
    $campagne = campagneE2(['terrain' => [
        ['slug' => 'tunnel-de-glace', 'x' => 3, 'y' => 3, 'paire_id' => 'a'],
        ['slug' => 'tunnel-de-glace', 'x' => 9, 'y' => 4, 'paire_id' => 'a'],
    ]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 3]);
    $galerie = collect($partie['quete']->carte->grille['salles'])->search(fn (array $s) => $s['id'] === 'galerie');
    expect($partie['quete']->sallesDecouvertes())->not->toContain($galerie);

    deplacerE2($this, $partie, 3, 3)->assertAccepted();

    $etat = $partie['quete']->etatsPersonnages()->first();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([9, 4])
        ->and($partie['quete']->fresh()->sallesDecouvertes())->toContain($galerie);
});

function crevasseE2(object $test): array
{
    $campagne = campagneE2(['regles' => ['crevasse' => ['galerie']], 'portes' => [
        ['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale', 'ouverte' => true],
        ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'], ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete']]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($test, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 5, 'position_y' => 3]);

    return $partie;
}

it('rebord de crevasse, à la première case : sauf, ramené, ou perdu à jamais', function (array $des, string $issue, ?array $case, int $pv, string $mode) {
    $partie = crevasseE2($this);
    $partie['groupe']->update(['mode_mort' => $mode]);
    app()->instance(LanceurDes::class, new LanceurDeterministe([...$des, ...array_fill(0, 30, 1)]));
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => 8, 'y' => 3]])->assertAccepted();

    $annonce = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()->first(fn ($e) => ($e->payload['type'] ?? null) === 'crevasse');
    $etat = $partie['quete']->etatsPersonnages()->first();
    expect($annonce->payload['issue'])->toBe($issue)
        ->and($etat === null ? null : [(int) $etat->position_x, (int) $etat->position_y])->toBe($case)
        ->and((int) $partie['heros']->fresh()->pv_body)->toBe($pv);
})->with([
    // Le déplacement est déjà tiré au départ de la quête : ces dés sont ceux de la crevasse.
    'sauf (crâne)' => [[1], 'sauf', [8, 3], 30, 'standard'],
    'ramené (noir puis blanc)' => [[6, 4], 'ramene', [6, 3], 29, 'standard'],
    'perdu (noir puis noir)' => [[6, 6], 'perdu', null, 0, 'standard'],
    // Seul en jeu, le héros à terre fait perdre la quête : son état de quête disparaît avec elle.
    'Story : au bord, à terre' => [[6, 6], 'au_bord', null, 29, 'story'],
]);

it('rebord de crevasse : au début de son tour dans la salle, le héros roule d\'abord — une fois', function () {
    $partie = crevasseE2($this);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    app()->instance(LanceurDes::class, new LanceurDeterministe([6, 4, ...array_fill(0, 30, 1)]));
    $crevasse = app(\App\Partie\Classique\Crevasse::class);
    $etat = $partie['quete']->etatsPersonnages()->first();

    $premier = $crevasse->debutDeTour($partie['groupe'], $partie['quete']->fresh(), $partie['heros'], $etat);
    $second = $crevasse->debutDeTour($partie['groupe'], $partie['quete']->fresh(), $partie['heros'], $etat->fresh());

    expect($premier['issue'])->toBe('ramene')->and($second)->toBeNull()
        ->and((int) $partie['heros']->fresh()->pv_body)->toBe(29);
});
