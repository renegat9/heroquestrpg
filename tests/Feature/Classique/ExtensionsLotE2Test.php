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

it('gouffre sans fond : qui y tombe est perdu à jamais ; seule une fosse peut l\'être', function () {
    expect(codesE2(campagneE2(['pieges' => [['slug' => 'piege-a-lances', 'x' => 3, 'y' => 3, 'gouffre' => true]]])))
        ->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $campagne = campagneE2(['pieges' => [['slug' => 'fosse', 'x' => 3, 'y' => 3, 'gouffre' => true]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['groupe']->update(['mode_mort' => 'standard']);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 3]);

    deplacerE2($this, $partie, 3, 3)->assertAccepted();

    expect($partie['heros']->fresh()->mort_le)->not->toBeNull()
        ->and($partie['quete']->etatsPersonnages()->count())->toBe(0);
});

it('arrêter : une note de la case arrête la marche du héros (piège à monstre errant)', function () {
    $campagne = campagneE2(['notes' => [['repere' => 'W', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 4, 'y' => 3]]],
        'alors' => [['effet' => 'arreter'], ['effet' => 'apparition', 'monstres' => [['slug' => 'squelette', 'x' => 4, 'y' => 2]]]]]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 3]);

    deplacerE2($this, $partie, 6, 3)->assertAccepted();

    $etat = $partie['quete']->etatsPersonnages()->first();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([4, 3]);
});

it('voler : de l\'or de la bourse, ou un objet du sac — jamais ce qu\'il porte', function () {
    $campagne = campagneE2(['notes' => [
        ['repere' => 'O', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]], 'alors' => [['effet' => 'voler', 'or' => 25]]],
        ['repere' => 'S', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 2]], 'alors' => [['effet' => 'voler']]],
    ]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['heros']->update(['or' => 10]);
    $dague = \App\Models\Objet::where('nom', 'Dague')->firstOrFail();
    \App\Partie\RangementObjet::ranger($dague, (int) $partie['heros']->id, 1);
    $notes = app(\App\Partie\Classique\NotesDeQuete::class);

    $notes->signaler($partie['quete'], \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 1], $partie['heros']);
    expect((int) $partie['heros']->fresh()->or)->toBe(0);

    $avant = $partie['heros']->inventaire()->where('emplacement', '!=', 'sac')->count();
    $notes->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 2], $partie['heros']->fresh());
    expect($partie['heros']->inventaire()->where('objet_id', $dague->id)->where('emplacement', 'sac')->exists())->toBeFalse()
        ->and($partie['heros']->inventaire()->where('emplacement', '!=', 'sac')->count())->toBe($avant);
});

it('piège à monstre errant : arrêté sur la case, les errants de la quête surgissent autour de lui et frappent', function () {
    $campagne = campagneE2([
        'monstre_errant' => ['slug' => 'squelette', 'nombre' => 2],
        'notes' => [['repere' => 'W', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 4, 'y' => 3]]],
            'alors' => [['effet' => 'arreter'], ['effet' => 'errants']]]],
    ]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 3]);

    deplacerE2($this, $partie, 6, 3)->assertAccepted();

    $etat = $partie['quete']->etatsPersonnages()->first();
    $errants = $partie['quete']->instancesMonstres()->get();
    $note = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()
        ->first(fn ($e) => ($e->payload['type'] ?? null) === 'note_de_quete');
    $frappes = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()
        ->filter(fn ($e) => ($e->payload['type'] ?? null) === 'attaque_monstre');
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([4, 3])
        ->and($errants)->toHaveCount(2)
        ->and(collect($note->payload['effets'])->firstWhere('effet', 'errants')['monstres'])->toHaveCount(2)
        ->and($frappes->count())->toBeGreaterThanOrEqual(1)
        ->and($etat->a_joue)->toBeFalse();
});

it('hache balancière : 2 dés sans défense, et elle reste armée jusqu\'au désamorçage ; jamais dans un donjon généré', function () {
    $this->seed(\Database\Seeders\PiegeSeeder::class);
    $campagne = campagneE2(['pieges' => [['slug' => 'hache-balanciere', 'x' => 3, 'y' => 3]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 3]);
    // Deux crânes pour la hache (`deplacerE2()` ne roulerait que des boucliers noirs).
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 40, 1)));
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => 3, 'y' => 3]])->assertAccepted();

    $entree = collect($partie['quete']->fresh()->carte->grille['pieges'])->firstWhere('x', 3);
    expect((int) $partie['heros']->fresh()->pv_body)->toBe(28)
        ->and($entree['etat'])->toBe(\App\Partie\MoteurPieges::ETAT_DETECTE);

    // Le donjon généré du mode RPG ne la tire jamais, ni la stalactite.
    $vivier = (new ReflectionMethod(\App\Partie\AssembleurCarte::class, 'piegesDeSol'))
        ->invoke(app(\App\Partie\AssembleurCarte::class), \App\Partie\BestiaireGroupe::manuel(['horreur_des_glaces']));
    expect($vivier->pluck('nom')->intersect(\App\Partie\MoteurPieges::RESERVES_AU_CLASSIQUE)->all())->toBe([]);
});

it('porte cachée : la fouille ne la trouve jamais, seule la note la révèle ; refusée déjà découverte', function (bool $cachee) {
    $portes = fn (array $secrete) => [
        ['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale'], ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'],
        ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete', ...$secrete]];
    expect(codesE2(campagneE2(['portes' => $portes(['cachee' => true, 'revelee' => true])])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $campagne = campagneE2(['portes' => $portes($cachee ? ['cachee' => true] : []), 'notes' => [
        ['repere' => 'C', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]], 'alors' => [['effet' => 'reveler_porte', 'porte' => 2]]]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 9, 'position_y' => 4]);
    $porte = fn () => collect($partie['quete']->fresh()->carte->grille['portes'])->first(fn ($p) => (int) $p['x'] === 9 && (int) $p['y'] === 5);

    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    // Le jet de Mind de la fouille réussit (crânes) : le témoin trouve donc sa porte à coup sûr.
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 60, 1)));
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'fouiller'])->assertAccepted();
    // Le témoin (sans `cachee`) : la même fouille trouve la même porte.
    expect($porte()['etat'])->toBe($cachee ? \App\Partie\MoteurPortes::ETAT_SECRETE : \App\Partie\MoteurPortes::ETAT_FERMEE);

    if ($cachee) {
        app(\App\Partie\Classique\NotesDeQuete::class)->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 1], $partie['heros']);
        expect($porte()['etat'])->toBe(\App\Partie\MoteurPortes::ETAT_FERMEE)->and($porte()['revele'])->toBeTrue();
    }
})->with(['cachée' => [true], 'témoin : secrète ordinaire' => [false]]);

// ---------------------------------------------------------------- The Mage of the Mirror

it('richesses elfiques : la première fouille d\'une salle à coffre sans note paie l\'or, une fois ; la note l\'emporte', function (bool $note, ?int $or) {
    $campagne = campagneE2([
        'regles' => ['richesses_des_coffres' => 200],
        'mobilier' => [['slug' => 'coffre', 'x' => 10, 'y' => 2, 'rotation' => 0]],
        'notes' => $note ? [['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'texte', 'texte' => 'Le coffre est vide.']]]] : [],
    ]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['richesses_des_coffres' => 0]])))->not->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);

    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    $resultat = $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'fouiller_tresor'])->assertAccepted()->json('resultat');

    expect($resultat['issue'])->toBe($note ? 'note' : 'richesses')
        ->and((int) $partie['heros']->fresh()->or)->toBe($or ?? 0);
    if (! $note) {
        // Pas deux fois : la salle a déjà été fouillée.
        expect(app(\App\Partie\Classique\RichessesElfiques::class)->montant($partie['quete']->fresh(), (int) $resultat['salle'], false))->toBeNull();
    }
})->with(['sans note : 200 po' => [false, 200], 'la note nomme la salle : rien' => [true, null]]);

// ---------------------------------------------------------------- Rise of the Dread Moon

it('monstres renforcés : tous lancent un dé d\'attaque de plus, au contact et à distance ; annoncé au départ', function () {
    $campagne = campagneE2(['regles' => ['bonus_attaque_monstres' => 1], 'monstres' => [
        ['slug' => 'orque', 'x' => 9, 'y' => 3], ['slug' => 'archer-elfe', 'x' => 10, 'y' => 4]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['bonus_attaque_monstres' => 0]])))->not->toBe([]);
    $partie = partieE2($this, $campagne);

    [$orque, $archer] = $partie['quete']->instancesMonstres()->with('monstre')->orderBy('id')->get()->all();
    expect($orque->attaqueEffective())->toBe((int) $orque->monstre->attaque + 1)
        ->and($archer->attaqueDistanceEffective())->toBe((int) $archer->monstre->attaque_distance + 1)
        ->and(collect(\App\Partie\EffetsGlobauxQuete::publier($partie['quete']->fresh()))->pluck('source')->all())->toContain('regle_de_quete');
});

it('planque : une fouille par quête, la carte de monstre errant n\'y joue pas', function () {
    $campagne = campagneE2(['regles' => ['planque' => 'galerie']]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['planque' => 'nulle-part']])))->toBe([ValidationCampagne::SALLE_INCONNUE]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    $quete = $partie['quete']->fresh();
    $quete->update(['deck_fouille' => [['issue' => 'errant'], ...(array) $quete->deck_fouille]]);

    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    $resultat = $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'fouiller_tresor'])->assertAccepted()->json('resultat');

    expect($resultat['issue'])->toBe('planque')
        ->and($partie['quete']->instancesMonstres()->count())->toBe(0)
        ->and(app(\App\Partie\Classique\Planque::class)->fouilleFermee($partie['quete']->fresh(), (int) $resultat['salle']))->toBeTrue();
});

it('planque : le dé rouge du soin, puis la répartition choisie parmi celles que le menu publie', function () {
    $partie = partieE2($this, campagneE2(['regles' => ['planque' => 'galerie']]));
    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    $partie['heros']->update(['pv_body' => 25, 'pv_mind' => 0]);
    app()->instance(LanceurDes::class, new LanceurDeterministe([4, ...array_fill(0, 20, 1)]));
    $menu = function () use ($partie) {
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));

        return collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id');
    };

    expect($menu())->toContain('soin_planque');
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'soin_planque'])->assertAccepted()->assertJsonPath('resultat.a_repartir', true);
    // 4 au dé, 5 Corps et 2 Esprit perdus : 4/0, 3/1, 2/2.
    expect($menu()->filter(fn ($id) => str_starts_with($id, 'repartir_planque_'))->values()->all())
        ->toBe(['repartir_planque_4', 'repartir_planque_3', 'repartir_planque_2'])
        ->and($menu())->not->toContain('soin_planque');

    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'repartir_planque_3'])->assertAccepted();
    expect([(int) $partie['heros']->fresh()->pv_body, (int) $partie['heros']->fresh()->pv_mind])->toBe([28, 1])
        ->and($menu()->filter(fn ($id) => str_contains($id, 'planque'))->all())->toBe([]);
});

it('déguisés : la figure endormie est retirée au contact d\'un déguisé ; éveillée, elle ignore le déguisé', function () {
    $campagne = campagneE2(['regles' => ['deguisement' => true], 'portes' => [
        ['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale', 'ouverte' => true],
        ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'], ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete']], 'monstres' => [
        ['slug' => 'gobelin', 'x' => 9, 'y' => 3, 'capacites' => ['ignore_deguises', 'retire_au_contact_deguise']],
        ['slug' => 'orque', 'x' => 9, 'y' => 5, 'capacites' => ['ignore_deguises']]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 6, 'position_y' => 3, 'deguise' => true]);
    [$gobelin, $orque] = $partie['quete']->instancesMonstres()->orderBy('id')->get()->all();
    expect(app(\App\Partie\MoteurDormants::class)->estDormant($gobelin))->toBeTrue();

    deplacerE2($this, $partie, 8, 3)->assertAccepted();
    expect($gobelin->fresh()->etat)->toBe('vaincu')->and($gobelin->fresh()->position_x)->toBeNull();

    // L'orque, éveillé, au contact du déguisé : il ne le frappe pas.
    $orque->update(['passif_etat' => ['eveille' => true, 'agi' => false, 'attaque' => false], 'position_x' => 8, 'position_y' => 4]);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    $pv = (int) $partie['heros']->fresh()->pv_body;
    $phase = new ReflectionMethod(ResolveurTour::class, 'phaseMonstres');
    $actions = (array) ($phase->invoke(app(ResolveurTour::class), $partie['groupe'], $partie['quete']->fresh())['actions'] ?? []);
    expect(collect($actions)->where('type', 'attaque_monstre')->all())->toBe([])
        ->and((int) $partie['heros']->fresh()->pv_body)->toBe($pv);

    // Le témoin : sans déguisement, la même figure frappe.
    $partie['quete']->etatsPersonnages()->update(['deguise' => false, 'a_joue' => false]);
    $actions = (array) ($phase->invoke(app(ResolveurTour::class), $partie['groupe'], $partie['quete']->fresh())['actions'] ?? []);
    expect(collect($actions)->where('type', 'attaque_monstre')->count())->toBe(1);
});
