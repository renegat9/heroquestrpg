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

it('potion de vitesse elfique : 12 cases sans dé et deux attaques par tour, jusqu\'au premier point de Corps perdu', function () {
    $this->seed([\Database\Seeders\ObjetSeeder::class, \Database\Seeders\ConditionSeeder::class]);
    $partie = partieE2($this, campagneE2());
    $potion = \App\Models\Objet::where('nom', 'Potion de vitesse elfique')->firstOrFail();
    expect($potion->prix_base)->toBe(500)->and($potion->tag_equipement)->toBe('potion_elfe');
    $sorts = app(\App\Partie\MoteurSorts::class);

    $sorts->appliquerBuffPotion($partie['heros'], $potion);
    expect($sorts->valeurBuff($partie['heros']->fresh(), 'deplacement_fixe'))->toBe(12);

    // Un nouveau tour : le déplacement du menu vaut 12, sans dé.
    $partie['quete']->etatsPersonnages()->update(['deplacement_tour' => null, 'deplacement_restant' => null]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    expect((int) $partie['quete']->etatsPersonnages()->first()->deplacement_tour)->toBe(12);

    // Le premier point de Corps perdu : l'effet tombe.
    app(\App\Partie\MoteurDegats::class)->infligerAHeros($partie['heros']->fresh(), 1, \App\Partie\MoteurDegats::SOURCE_PIEGE, []);
    expect($sorts->valeurBuff($partie['heros']->fresh(), 'deplacement_fixe'))->toBe(0);
});

it('porte qu\'on frappe : un crâne est un succès et la porte est murée ; au seuil, la bonne porte s\'ouvre et pose le drapeau', function () {
    $campagne = campagneE2(['regles' => ['portes_a_frapper' => ['succes' => 1, 'drapeau' => 'bonne_porte']], 'portes' => [
        ['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale'],
        ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale', 'frapper' => true],
        ['x' => 5, 'y' => 2, 'cote' => 'n', 'type' => 'normale', 'frapper' => true],
        ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'normale', 'frapper' => true]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['portes' => [['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'secrete', 'frapper' => true]]])))->toContain(ValidationCampagne::VALEUR_INVALIDE);
    $partie = partieE2($this, $campagne);
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 20, 1)));
    $frapper = function (int $x) use ($partie) {
        $partie['quete']->etatsPersonnages()->update(['position_x' => $x, 'position_y' => 2]);
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
        $ids = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id');
        // Une porte du nord se range sur l'arête de la case du dessus (`CarteConstruite::porteMoteur()`) : (x, 1, s).
        expect($ids)->toContain("frapper_porte_{$x}_1_s")->and($ids->filter(fn ($id) => str_starts_with($id, 'ouvrir_porte_'))->all())->toBe([]);

        return $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => "frapper_porte_{$x}_1_s"])->assertAccepted()->json('resultat');
    };
    $porte = fn (int $x) => collect($partie['quete']->fresh()->carte->grille['portes'])->first(fn ($p) => (int) $p['x'] === $x && (int) $p['y'] === 1);

    expect($frapper(3))->toMatchArray(['type' => 'porte_frappee', 'bonne' => false, 'reussi' => true, 'succes' => 1])
        ->and($porte(3)['muree'])->toBeTrue();
    expect($frapper(5))->toMatchArray(['bonne' => true])
        ->and($porte(5)['etat'])->toBe(\App\Partie\MoteurPortes::ETAT_OUVERTE)
        ->and($partie['quete']->fresh()->aDrapeau('bonne_porte'))->toBeTrue();
});

it('porte assiégée : le monstre posté à son contact la frappe au lieu de jouer ; brisée, elle s\'ouvre et pose le drapeau', function () {
    $campagne = campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 5]], 'portes' => [
        ['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale'], ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'],
        ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'normale', 'assiegee' => ['pv' => 2, 'defense' => 3, 'drapeau' => 'porte_brisee']]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 2]);
    $partie['quete']->instancesMonstres()->update(['revele' => true]);
    // Crânes à l'attaque, boucliers blancs à la défense de la porte : tout passe.
    app()->instance(LanceurDes::class, new LanceurDeterministe([1, 1, 1, 4, 4, 4, ...array_fill(0, 30, 4)]));

    $phase = new ReflectionMethod(ResolveurTour::class, 'phaseMonstres');
    $actions = (array) ($phase->invoke(app(ResolveurTour::class), $partie['groupe'], $partie['quete']->fresh())['actions'] ?? []);

    $assaut = collect($actions)->firstWhere('type', 'assaut_porte');
    $porte = collect($partie['quete']->fresh()->carte->grille['portes'])->first(fn ($p) => (int) $p['x'] === 9);
    expect($assaut)->toMatchArray(['brisee' => true, 'pv_restants' => 0])
        ->and($porte['etat'])->toBe(\App\Partie\MoteurPortes::ETAT_OUVERTE)
        ->and($partie['quete']->fresh()->aDrapeau('porte_brisee'))->toBeTrue();
});

it('marché souterrain : dans la salle, l\'étal s\'ouvre, on y achète et on y vend pour soi, sans action', function () {
    $this->seed(\Database\Seeders\ObjetSeeder::class);
    $campagne = campagneE2(['regles' => ['marche_en_quete' => 'galerie']]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['heros']->update(['or' => 100]);
    $menu = function () use ($partie) {
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));

        return collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id');
    };
    $choisir = fn (string $id) => $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => $id])->assertAccepted()->json('resultat');

    // Hors de la salle : rien.
    $partie['quete']->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);
    expect($menu())->not->toContain('ouvrir_etal');

    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    expect($menu())->toContain('ouvrir_etal');
    $choisir('ouvrir_etal');
    $dague = \App\Models\Objet::where('nom', 'Dague')->firstOrFail();
    $ids = $menu();
    // L'étal ne propose que ce que la bourse paie (100 po).
    $prixDague = (int) collect(app(\App\Partie\Marche\PhaseMarche::class)->etal($partie['groupe']))->firstWhere('objet_id', $dague->id)['prix'];
    expect($ids)->toContain("acheter_etal_{$dague->id}")->and($ids)->toContain('fermer_etal')
        ->and($ids->filter(fn ($id) => str_starts_with($id, 'acheter_etal_'))->count())->toBeGreaterThan(0);

    expect($choisir("acheter_etal_{$dague->id}"))->toMatchArray(['nom' => 'Dague', 'prix' => $prixDague])
        ->and((int) $partie['heros']->fresh()->or)->toBe(100 - $prixDague);

    $ligne = $partie['heros']->inventaire()->where('objet_id', $dague->id)->firstOrFail();
    expect($menu())->toContain("vendre_etal_{$ligne->id}");
    $choisir("vendre_etal_{$ligne->id}");
    expect((int) $partie['heros']->fresh()->or)->toBe(100 - $prixDague + intdiv($prixDague, 2))
        ->and($partie['quete']->etatsPersonnages()->first()->a_agi)->toBeFalse();
});

// ---------------------------------------------------------------- Against the Ogre Horde

it('au tour du MJ, le héros dans les couloirs désignés déclenche la note ; salle sans monstre en condition', function () {
    $campagne = campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3]], 'notes' => [
        ['repere' => 'B', 'quand' => ['declencheur' => 'heros_au_tour_du_mj', 'cible' => ['cases' => [['x' => 3, 'y' => 3], ['x' => 4, 'y' => 3]]]],
            'alors' => [['effet' => 'drapeau', 'nom' => 'vu_au_couloir']]],
        ['repere' => 'C', 'quand' => ['declencheur' => 'fin_de_tour', 'cible' => ['salle' => 'galerie'], 'si_sans_monstre' => 'galerie'],
            'alors' => [['effet' => 'drapeau', 'nom' => 'salle_tenue']], 'une_fois' => false],
    ]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['notes' => [['repere' => 'X', 'quand' => ['declencheur' => 'fin_de_tour', 'cible' => ['salle' => 'galerie'], 'si_sans_monstre' => 'nulle-part'],
            'alors' => [['effet' => 'texte', 'texte' => '…']]]]])))->toBe([ValidationCampagne::SALLE_INCONNUE]);
    $partie = partieE2($this, $campagne);
    $notes = app(\App\Partie\Classique\NotesDeQuete::class);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 4, 'position_y' => 3]);

    $phase = new ReflectionMethod(ResolveurTour::class, 'phaseMonstres');
    $phase->invoke(app(ResolveurTour::class), $partie['groupe'], $partie['quete']->fresh());
    expect($partie['quete']->fresh()->aDrapeau('vu_au_couloir'))->toBeTrue();

    // La salle n'est « tenue » que sans monstre.
    $notes->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::FIN_DE_TOUR, ['salle' => 1], $partie['heros']);
    expect($partie['quete']->fresh()->aDrapeau('salle_tenue'))->toBeFalse();
    $partie['quete']->instancesMonstres()->update(['etat' => 'vaincu']);
    $notes->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::FIN_DE_TOUR, ['salle' => 1], $partie['heros']);
    expect($partie['quete']->fresh()->aDrapeau('salle_tenue'))->toBeTrue();
});

it('lame balançoire de livret : sa zone de l\'auteur, jamais déclenchée par une case, seulement par la note de la porte', function () {
    $this->seed(\Database\Seeders\PiegeSeeder::class);
    $lame = ['slug' => 'lame-balancoire', 'x' => 9, 'y' => 3, 'par_note' => true, 'zone' => [['x' => 9, 'y' => 2], ['x' => 9, 'y' => 3], ['x' => 9, 'y' => 4]]];
    $campagne = campagneE2(['pieges' => [$lame], 'notes' => [['repere' => 'A', 'quand' => ['declencheur' => 'ouverture_porte', 'cible' => ['porte' => 0]],
        'alors' => [['effet' => 'declencher_piege', 'x' => 9, 'y' => 3]]]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['pieges' => [['slug' => 'lame-balancoire', 'x' => 9, 'y' => 3]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE2(campagneE2(['pieges' => [['slug' => 'fosse', 'x' => 9, 'y' => 3, 'zone' => [['x' => 9, 'y' => 3]]]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $heros = fn () => $partie['heros']->fresh();

    // Foulée : rien (« It cannot be activated in any other way »).
    $partie['quete']->etatsPersonnages()->update(['position_x' => 9, 'position_y' => 4]);
    $resultat = app(\App\Partie\MoteurPieges::class)->controlerChemin($partie['groupe'], $partie['quete']->fresh()->carte, $heros(),
        $partie['quete']->etatsPersonnages()->first(), [['x' => 9, 'y' => 3]], ['x' => 9, 'y' => 4]);
    expect($resultat['declenchements'])->toBe([]);

    // La note de la porte : la zone est balayée, le héros qui s'y tient défend.
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 30, 1)));
    app(\App\Partie\Classique\NotesDeQuete::class)->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::OUVERTURE_PORTE, ['porte' => 0], $heros());
    expect((int) $heros()->pv_body)->toBeLessThan(30);
});

it('la Fosse de l\'Effroi : l\'anneau jeté depuis le bord joue la note ; l\'abîme bloque la marche et reste hors du donjon généré', function () {
    $this->seed([\Database\Seeders\ObjetSeeder::class, \Database\Seeders\TerrainSeeder::class]);
    $campagne = campagneE2([
        'terrain' => [['slug' => 'abime-sans-fond', 'x' => 9, 'y' => 3]],
        'regles' => ['receptacle_objets' => [['x' => 9, 'y' => 3]]],
        'notes' => [['repere' => 'F', 'quand' => ['declencheur' => 'objet_jete', 'cible' => ['objet' => 'anneau-de-puissance-de-festral']],
            'alors' => [['effet' => 'drapeau', 'nom' => 'fosse_detruite']]]],
    ]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $anneau = \App\Models\Objet::where('nom', 'Anneau de Puissance de Festral')->firstOrFail();
    \App\Partie\RangementObjet::ranger($anneau, (int) $partie['heros']->id, 1);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);

    expect(\App\Partie\FabriqueGrille::pour($partie['quete']->fresh())->estTraversable(9, 3))->toBeFalse();

    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $ligne = $partie['heros']->inventaire()->where('objet_id', $anneau->id)->firstOrFail();
    $ids = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id');
    expect($ids)->toContain("jeter_objet_quete_{$ligne->id}");

    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => "jeter_objet_quete_{$ligne->id}"])->assertAccepted();
    expect($partie['quete']->fresh()->aDrapeau('fosse_detruite'))->toBeTrue()
        ->and($partie['heros']->inventaire()->where('objet_id', $anneau->id)->exists())->toBeFalse();

    $vivier = \App\Models\Terrain::query()->get()->filter(fn ($t) => in_array($t->nom, \App\Partie\MoteurTerrain::RESERVES_AU_CLASSIQUE, true));
    expect($vivier->pluck('nom')->all())->toBe(['Abîme sans fond']);
});

it('salles qui se lèvent : au tour du MJ, un dé rouge et la salle cachée la plus proche se découvre, porte ouverte', function () {
    $campagne = campagneE2(['regles' => ['activation_salles' => true], 'monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 4]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);
    $orque = $partie['quete']->instancesMonstres()->firstOrFail();
    $orque->update(['revele' => false]);
    app()->instance(LanceurDes::class, new LanceurDeterministe([3, ...array_fill(0, 40, 4)]));

    $payload = app(\App\Partie\Classique\ActivationSalles::class)->auTourDuMJ($partie['groupe'], $partie['quete']->fresh());

    expect($payload['de'])->toBe(3)->and($payload['salles'])->toHaveCount(1)
        ->and($orque->fresh()->revele)->toBeTrue()
        ->and(collect($partie['quete']->fresh()->carte->grille['portes'])->first(fn ($p) => (int) $p['x'] === 6)['etat'])->toBe(\App\Partie\MoteurPortes::ETAT_OUVERTE);
});

// ---------------------------------------------------------------- Le tournoi de World's End (lots TC)

function tournoiE2(array $tournoi = [], array $quete = []): array
{
    return campagneE2(array_replace([
        'monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3]],
        'fin' => ['type' => 'vaincre', 'cibles' => [0], 'libelle' => 'Vaincre le Defender.'],
        'portes' => [['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale', 'ouverte' => true],
            ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'], ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete']],
        'tournoi' => array_replace([
            'camp_heros' => [['x' => 3, 'y' => 3], ['x' => 3, 'y' => 4]],
            'camp_defender' => [['x' => 9, 'y' => 3], ['x' => 9, 'y' => 4]],
            'trophees' => [['x' => 5, 'y' => 3]],
        ], $tournoi),
    ], $quete));
}

it('tournoi (TC2) : les héros partent de leur tuile, le Defender est dévoilé et joue d\'abord, les trophées sont posés', function () {
    expect(codesE2(tournoiE2()))->toBe([])
        ->and(codesE2(tournoiE2(['camp_heros' => []])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, tournoiE2());

    $quete = $partie['quete']->fresh();
    $etat = $quete->etatsPersonnages()->first();
    expect($quete->estTournoi())->toBeTrue()
        ->and($partie['groupe']->fresh()->estEnTournoi())->toBeTrue()
        ->and([(int) $etat->position_x, (int) $etat->position_y])->toBe([3, 3])
        ->and($quete->instancesMonstres()->first()->revele)->toBeTrue()
        ->and(count($quete->trophees_arene['cases'] ?? []))->toBe(1)
        // Le Defender a ouvert : sa figure porte son jeton d'activation.
        ->and((bool) $quete->instancesMonstres()->first()->active_ce_round)->toBeTrue();
});

it('tournoi (TC3-TC4) : la vague suivante entre sur la tuile du Defender ; la dernière tombée, le tournoi est gagné', function () {
    $campagne = tournoiE2(['vagues' => [[['slug' => 'gobelin'], ['slug' => 'gobelin', 'nom' => 'Rat de Skutter']]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(tournoiE2(['vagues' => [[]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $degats = app(\App\Partie\MoteurDegats::class);
    $tuer = function () use ($partie, $degats) {
        foreach ($partie['quete']->instancesMonstres()->where('etat', 'actif')->get() as $m) {
            $degats->infligerAMonstre($m, 99, \App\Partie\MoteurDegats::SOURCE_PIEGE);
        }
        $nettoye = new ReflectionMethod(ResolveurTour::class, 'donjonNettoye');

        return $nettoye->invoke(app(ResolveurTour::class), [], $partie['quete']->fresh());
    };

    $apres1 = $tuer();
    expect($apres1['vague_tournoi']['monstres'])->toBe(['Gobelin', 'Rat de Skutter'])
        ->and($partie['quete']->instancesMonstres()->where('etat', 'actif')->pluck('position_x')->all())->toBe([9, 9]);

    $apres2 = $tuer();
    expect($apres2['tournoi_gagne'] ?? false)->toBeTrue()
        ->and($partie['quete']->fresh()->etat)->toBe('terminee');
});

it('tournoi (TC3) : un héros tombé est hors jeu, jamais tué, quel que soit le mode de mort ; on ne le relève pas', function () {
    $partie = partieE2($this, tournoiE2());
    $partie['groupe']->update(['mode_mort' => 'standard']);
    $partie['quete']->etatsPersonnages()->update(['tombe' => true]);
    $partie['heros']->update(['pv_body' => 0]);

    expect(app(\App\Partie\Classique\MortHeroic::class)->mortsStandard($partie['groupe']->fresh(), $partie['quete']->fresh()))->toBe([])
        ->and(app(\App\Partie\Classique\MortHeroic::class)->tousMorts($partie['groupe']->fresh(), $partie['quete']->fresh()))->toBe([])
        ->and($partie['heros']->fresh()->mort_le)->toBeNull();
});

it('tournoi (TC5) : le coffre se fouille en diagonale et tire la table 2d6 du tournoi', function () {
    $partie = partieE2($this, tournoiE2([], ['mobilier' => [['slug' => 'coffre', 'x' => 5, 'y' => 4, 'rotation' => 0]]]));
    $coffre = collect($partie['quete']->fresh()->carte->grille['mobilier'])->first(fn ($m) => (int) $m['x'] === 5);
    expect($coffre['tresor_tournoi'] ?? false)->toBeTrue()->and($coffre['diagonale'] ?? false)->toBeTrue();
});

it('tournoi : par les routes, le héros a la main après l\'ouverture du Defender, et le combat se joue en activations', function () {
    $partie = partieE2($this, tournoiE2([], ['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3], ['slug' => 'gobelin', 'x' => 9, 'y' => 4]],
        'fin' => ['type' => 'vaincre', 'cibles' => [0, 1], 'libelle' => 'Vaincre le Defender.']]));
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $ids = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id');
    expect($ids)->toContain('attendre');

    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'attendre'])->assertAccepted();
    // Seul héros : son activation finie, le Defender enchaîne ses figures, le round se referme et se rouvre — une figure
    // pour ouvrir le nouveau round (comme à l'arène, `ActivationAlterneeTest`), et le héros a de nouveau la main.
    expect($partie['quete']->instancesMonstres()->where('active_ce_round', true)->count())->toBe(1)
        ->and((bool) $partie['quete']->etatsPersonnages()->first()->a_joue)->toBeFalse();
});

it('salles qui se lèvent : la proximité se lit au CHEMIN, portes closes comptées ouvertes, murs barrant', function () {
    $partie = partieE2($this, campagneE2(['regles' => ['activation_salles' => true], 'monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 4]]]));
    $partie['quete']->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);
    $distances = (new ReflectionMethod(\App\Partie\Classique\ActivationSalles::class, 'distances'))
        ->invoke(app(\App\Partie\Classique\ActivationSalles::class), $partie['quete']->fresh(), $partie['quete']->etatsPersonnages()->get());

    // Par la porte (6, 3) close : 3 → 7 en quatre pas ; par la porte nord close (bord haut de (3, 2)) : le couloir en deux.
    expect($distances['7,3'])->toBe(4)->and($distances['3,1'])->toBe(2)
        // (6, 2) → (7, 2) est un mur : on passe par la porte puis on remonte, cinq pas.
        ->and($distances['7,2'])->toBe(5);
});

it('salles qui se lèvent : trois, deux ou une candidate — un dé tant qu\'il reste un choix, jamais deux fois la même salle', function (array $pool, int $nombre, array $des, array $attendu) {
    // Un 2 de remplissage, qu'aucun cas ne lance : il prouve que le tirage a lu ses dés, et pas un de plus.
    $lanceur = new LanceurDeterministe([...$des, ...array_fill(0, 5, 2)]);
    $tirer = new ReflectionMethod(\App\Partie\Classique\ActivationSalles::class, 'tirer');

    expect($tirer->invoke(new \App\Partie\Classique\ActivationSalles($lanceur), $pool, $nombre))->toBe($attendu)
        ->and($lanceur->d6())->toBe(2);
})->with([
    'trois, un 5 : la deuxième' => [[10, 11, 12], 1, [5], [11]],
    'trois sur un 6, puis un 6 : la troisième, puis la deuxième des deux restantes' => [[10, 11, 12], 2, [6, 6], [12, 11]],
    'deux, un 4 : la deuxième' => [[10, 11], 1, [4], [11]],
    'deux sur un 6 : les deux' => [[10, 11], 2, [1], [10, 11]],
    'une seule : elle se lève sans dé' => [[10], 1, [], [10]],
    'une seule sur un 6 : une seule se lève' => [[10], 2, [], [10]],
    'aucune' => [[], 2, [], []],
]);

it('salles qui se lèvent : une seule salle cachée sur un 6 n\'en lève qu\'une ; plus aucune, le tour du MJ ne lance rien', function () {
    $partie = partieE2($this, campagneE2(['regles' => ['activation_salles' => true], 'monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 4]]]));
    $partie['quete']->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);
    $partie['quete']->instancesMonstres()->update(['revele' => false]);
    app()->instance(LanceurDes::class, new LanceurDeterministe([6, ...array_fill(0, 40, 4)]));
    $activation = app(\App\Partie\Classique\ActivationSalles::class);

    $payload = $activation->auTourDuMJ($partie['groupe'], $partie['quete']->fresh());
    expect($payload['de'])->toBe(6)->and($payload['salles'])->toHaveCount(1);

    // La galerie découverte, plus aucune salle ne cache de monstre, et la quête n'a pas d'errant : ni dé, ni annonce.
    expect($activation->auTourDuMJ($partie['groupe'], $partie['quete']->fresh()))->toBeNull();
});

it('salles qui se lèvent : au-delà de la portée, la salle dort, et le tour du MJ ne lève rien ni ne fait surgir d\'errant', function () {
    $partie = partieE2($this, campagneE2(['regles' => ['activation_salles' => true], 'monstre_errant' => 'gobelin',
        'monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 4]]]));
    $partie['quete']->instancesMonstres()->update(['revele' => false]);
    $activation = app(\App\Partie\Classique\ActivationSalles::class);
    $portee = \App\Partie\Classique\ActivationSalles::PORTEE;

    // (3, 3) : la galerie est à 4 pas, à portée. Le héros posé loin, hors de portée.
    $partie['quete']->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);
    expect($activation->aUneSalleAPortee($partie['quete']->fresh()))->toBeTrue();
    $distances = (new ReflectionMethod($activation, 'distances'))->invoke($activation, $partie['quete']->fresh(), $partie['quete']->etatsPersonnages()->get());
    $loin = collect($distances)->filter(fn (int $pas, string $cle) => $pas > $portee + 4
        && \App\Partie\FabriqueGrille::pour($partie['quete']->fresh())->estTraversable(...array_map('intval', explode(',', $cle))))->keys()->first();
    expect($loin)->not->toBeNull();
    [$lx, $ly] = array_map('intval', explode(',', $loin));
    $partie['quete']->etatsPersonnages()->update(['position_x' => $lx, 'position_y' => $ly]);
    expect($activation->aUneSalleAPortee($partie['quete']->fresh()))->toBeFalse();

    $avant = $partie['quete']->instancesMonstres()->count();
    expect($activation->auTourDuMJ($partie['groupe'], $partie['quete']->fresh()))->toBeNull()
        ->and($partie['quete']->instancesMonstres()->count())->toBe($avant)
        ->and($partie['quete']->instancesMonstres()->where('revele', false)->count())->toBe(1);
});

it('salles qui se lèvent : la carte de monstre errant lève une salle ; plus aucune, elle redevient un monstre errant (« may »)', function () {
    $partie = partieE2($this, campagneE2(['regles' => ['activation_salles' => true], 'monstre_errant' => 'gobelin',
        'monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 4]]]));
    $partie['quete']->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);
    $orque = $partie['quete']->instancesMonstres()->firstOrFail();
    $orque->update(['revele' => false]);
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 40, 4)));
    $butin = function () use ($partie) {
        $methode = new ReflectionMethod(ResolveurTour::class, 'appliquerButin');
        $etat = $partie['quete']->etatsPersonnages()->firstOrFail();

        return $methode->invoke(app(ResolveurTour::class), ['issue' => 'errant'], ['type' => 'fouille_tresor'],
            $partie['groupe'], $partie['quete']->fresh(), $partie['heros'], $etat, false);
    };

    $leve = $butin();
    expect($leve['salles_activees'] ?? null)->toHaveCount(1)->and($orque->fresh()->revele)->toBeTrue();

    $avant = $partie['quete']->instancesMonstres()->count();
    $errant = $butin();
    expect($errant)->not->toHaveKey('salles_activees')
        ->and($partie['quete']->instancesMonstres()->count())->toBe($avant + 1);
});

// ---------------------------------------------------------------- Prophecy of Telor : le talisman

it('talisman de Telor : sort appris et +1 Esprit au-delà du maximum, durables ; le talisman devient maudit puis l\'arène défait tout', function () {
    $campagne = campagneE2(['notes' => [
        ['repere' => 'D', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1], 'si_porte' => 'talisman-du-savoir'],
            'alors' => [['effet' => 'esprit', 'points' => 1], ['effet' => 'apprendre_sort_dread', 'sort' => 'eclair-de-chaos']]],
        ['repere' => 'M', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 2]],
            'alors' => [['effet' => 'remplacer_objet', 'slug' => 'talisman-du-savoir', 'par' => 'talisman-du-savoir-maudit']]],
        ['repere' => 'G', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 3]],
            'alors' => [['effet' => 'remplacer_objet', 'slug' => 'talisman-du-savoir-maudit', 'par' => 'talisman-du-savoir'],
                ['effet' => 'oublier_sorts_dread'], ['effet' => 'esprit_d_origine']]],
    ]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['notes' => [['repere' => 'X', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]],
            'alors' => [['effet' => 'esprit', 'points' => 0], ['effet' => 'remplacer_objet', 'slug' => 'dague', 'par' => 'dague']]]]])))
        ->toBe([ValidationCampagne::VALEUR_INVALIDE, ValidationCampagne::VALEUR_INVALIDE]);

    $partie = partieE2($this, $campagne);
    $heros = $partie['heros'];
    $talisman = \App\Models\Objet::where('nom', 'Talisman du Savoir')->firstOrFail();
    $heros->inventaire()->create(['objet_id' => $talisman->id, 'emplacement' => 'talisman', 'quantite' => 1]);
    $mindMax = (int) $heros->fresh()->pv_mind_max;
    $notes = app(\App\Partie\Classique\NotesDeQuete::class);
    $quete = $partie['quete']->fresh();
    $quete->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);

    $jouees = $notes->signaler($quete, \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 1], $heros->fresh());
    $heros->refresh();
    expect($heros->pv_mind_max)->toBe($mindMax + 1)->and($heros->pv_mind)->toBe($mindMax + 1)
        ->and($heros->esprit_de_livret)->toBe(1)
        ->and($heros->sorts_dread_appris)->toBe(['Éclair de Chaos']);
    $lignes = collect(app(\App\Partie\JournalCombat::class)->depuisResultat(['type' => 'note_de_quete', ...$jouees[0]], 'Albrecht'))->pluck('texte')->implode(' | ');
    expect($lignes)->toContain('gagne 1 point(s) d\'Esprit')->toContain('apprend le sort de Dread Éclair de Chaos');

    // Le sort appris s'offre au menu du porteur, contre un monstre dans sa ligne ; lancé, il relève encore le total.
    $quete->instancesMonstres()->create(['monstre_id' => \App\Models\Monstre::where('nom_base', 'Orque')->value('id'),
        'pv_body' => 1, 'pv_mind' => 2, 'position_x' => 5, 'position_y' => 3, 'etat' => 'actif', 'elite' => false, 'revele' => true]);
    $etat = $quete->etatsPersonnages()->firstOrFail();
    $entrees = app(\App\Partie\SortsDreadPorteur::class)->entrees($quete->fresh(), $etat, $heros);
    expect(collect($entrees)->pluck('nom')->all())->toBe(['Éclair de Chaos']);
    $orque = $quete->instancesMonstres()->latest('id')->firstOrFail();
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $heros));
    expect(collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id'))->toContain('lancer_sort_dread');
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'lancer_sort_dread',
        'parametres' => ['cle' => 'sort_dread:Éclair de Chaos', 'cible_id' => $orque->id]])->assertAccepted();
    // Mode classique : le lancer relève le TOTAL, au-delà du maximum de départ (mode RPG : plafonné, `SortDreadPorteurTest`).
    expect($heros->fresh()->pv_mind_max)->toBe($mindMax + 2)->and($heros->fresh()->esprit_de_livret)->toBe(2)
        ->and($orque->fresh()->etat)->not->toBe('actif');

    // q. 7 : le talisman devient maudit, à la même place ; le plafond ne bouge pas (même carte, même +1).
    $notes->signaler($quete->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 2], $heros->fresh());
    $ligne = $heros->inventaire()->with('objet')->firstOrFail();
    expect($ligne->objet->nom)->toBe('Talisman du Savoir maudit')->and($ligne->emplacement)->toBe('talisman')
        ->and($heros->fresh()->pv_mind_max)->toBe($mindMax + 2);

    // q. 10 G : talisman ordinaire, sorts oubliés, Esprit d'origine.
    $notes->signaler($quete->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 3], $heros->fresh());
    $heros->refresh();
    expect($heros->inventaire()->with('objet')->firstOrFail()->objet->nom)->toBe('Talisman du Savoir')
        ->and($heros->sorts_dread_appris)->toBeNull()
        ->and($heros->pv_mind_max)->toBe($mindMax)->and($heros->pv_mind)->toBe($mindMax)
        ->and($heros->esprit_de_livret)->toBe(0);
});

// ---------------------------------------------------------------- Prophecy of Telor : le chemin imposé (q. 6)

function cheminE2(array $notes = []): array
{
    return campagneE2(['regles' => ['chemin_impose' => [
        'porteur' => 'talisman-du-savoir',
        'cases' => [['x' => 3, 'y' => 3], ['x' => 4, 'y' => 3], ['x' => 5, 'y' => 3], ['x' => 6, 'y' => 3], ['x' => 7, 'y' => 3], ['x' => 8, 'y' => 3], ['x' => 9, 'y' => 3]],
        'arrets' => [2, 5],
    ]], 'notes' => $notes]);
}

it('chemin imposé : la validation veut des cases qui se suivent et des arrêts croissants', function () {
    expect(codesE2(cheminE2()))->toBe([]);
    $trou = cheminE2();
    $trou['quetes'][0]['regles']['chemin_impose']['cases'][3] = ['x' => 6, 'y' => 5];
    $arrets = cheminE2();
    $arrets['quetes'][0]['regles']['chemin_impose']['arrets'] = [3, 2];
    $sansChemin = campagneE2(['notes' => [['repere' => 'C', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]],
        'alors' => [['effet' => 'avancer_sur_chemin', 'des' => 2]]]]]);
    expect(codesE2($trou))->toContain(ValidationCampagne::VALEUR_INVALIDE)
        ->and(codesE2($arrets))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE2($sansChemin))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
});

it('chemin imposé : le porteur joue d\'abord, il est mené jusqu\'à l\'arrêt du tour (porte franchie ouverte), et la note C le fait avancer aux dés', function () {
    $partie = partieE2($this, cheminE2([
        ['repere' => 'B', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 5, 'y' => 3]], 'si_porte' => 'talisman-du-savoir'],
            'alors' => [['effet' => 'texte', 'texte' => 'Vous entendez la voix.']]],
        ['repere' => 'C', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 8, 'y' => 3]], 'si_porte' => 'talisman-du-savoir'],
            'alors' => [['effet' => 'avancer_sur_chemin', 'des' => 2]]],
    ]));
    $quete = $partie['quete']->fresh();
    $heros = $partie['heros'];
    $heros->inventaire()->create(['objet_id' => \App\Models\Objet::where('nom', 'Talisman du Savoir')->value('id'), 'emplacement' => 'talisman', 'quantite' => 1]);

    // Un second héros, PREMIER dans l'ordre d'initiative : le porteur passe devant lui.
    $b = Personnage::create(['joueur_id' => $heros->joueur_id, 'nom' => 'Brunehaut', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4]);
    $partie['groupe']->personnages()->syncWithoutDetaching([$b->id => ['actif' => true, 'ordre_initiative' => 0]]);
    $etatB = $quete->etatsPersonnages()->firstOrFail()->replicate();
    $etatB->fill(['personnage_id' => $b->id, 'position_x' => 2, 'position_y' => 2])->save();
    $quete->etatsPersonnages()->where('personnage_id', $heros->id)->update(['position_x' => 3, 'position_y' => 3]);
    expect(app(\App\Partie\OrdreDuTour::class)->herosActifId($partie['groupe']->fresh()))->toBe((int) $heros->id);

    // Tour 1 : le menu du porteur ouvre son tour — mené de (3, 3) à l'arrêt n° 3, (5, 3) ; la note B y joue.
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 40, 3)));
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $heros));
    $ids = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id');
    $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->firstOrFail();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([5, 3])
        ->and((bool) $etat->a_deplace)->toBeTrue()
        ->and($ids)->not->toContain('se_deplacer')
        ->and(\App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()
            ->contains(fn ($e) => ($e->payload['type'] ?? null) === 'note_de_quete' && ($e->payload['repere'] ?? null) === 'B'))->toBeTrue();

    // Tour 2 : jusqu'à l'arrêt n° 6, (8, 3), à travers la porte close (6, 3) qui s'ouvre ; la note C : 2 dés (3 + 3), il
    // avancerait de 6 — le chemin s'arrête à (9, 3).
    $quete->update(['tours_mj' => 1]);
    $etat->update(['capacites_tour' => [], 'a_deplace' => false, 'a_joue' => false]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $heros));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    $etat->refresh();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([9, 3])
        ->and(collect($quete->carte->fresh()->grille['portes'])->first(fn ($p) => (int) $p['x'] === 6 && (int) $p['y'] === 3)['etat'])
        ->toBe(\App\Partie\MoteurPortes::ETAT_OUVERTE);

    // Tour 3 : plus d'arrêt, il marche à sa guise.
    $quete->update(['tours_mj' => 2]);
    $etat->update(['capacites_tour' => [], 'a_deplace' => false, 'a_joue' => false]);
    expect(app(\App\Partie\Classique\CheminImpose::class)->debutDeTour($partie['groupe'], $quete->fresh(), $heros, $etat->fresh()))->toBeNull();
});

// ---------------------------------------------------------------- Prophecy of Telor : les héros changés en orques (q. 5)

it('métamorphose : changé en orque — dés d\'orque, 2 PV au plus, sans sorts, montré sur la fiche ; la note D rend la forme et tout le Corps', function () {
    $campagne = campagneE2(['notes' => [
        ['repere' => 'B', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]],
            'alors' => [['effet' => 'metamorphoser', 'en' => 'orque', 'pv_body' => 2]]],
        ['repere' => 'D', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 2]], 'alors' => [['effet' => 'rendre_forme']]],
    ]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $heros = $partie['heros'];
    $notes = app(\App\Partie\Classique\NotesDeQuete::class);
    $classe = app(\App\Partie\Equipement::class)->desDeBase($heros->fresh());

    $jouees = $notes->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 1], $heros->fresh());
    $heros->refresh();
    expect($heros->pv_body_max)->toBe(2)->and($heros->pv_body)->toBe(2)
        ->and([(int) $heros->des_attaque, (int) $heros->des_defense])->toBe([3, 2])
        ->and(app(\App\Partie\MoteurSorts::class)->sortsInterdits($heros))->toBeTrue()
        ->and(collect(app(\App\Partie\JournalCombat::class)->depuisResultat(['type' => 'note_de_quete', ...$jouees[0]], 'Albrecht'))->pluck('texte')->implode(' '))
        ->toContain('changé(s) en Orque');

    // Aucun soin ne passe le plafond : le maximum EST le plafond.
    $heros->update(['pv_body' => min((int) $heros->pv_body_max, 99)]);
    expect($heros->fresh()->pv_body)->toBe(2);
    $fiche = collect($this->getJson("/api/groupes/{$partie['code']}/etat")->assertOk()->json('entites'))
        ->first(fn ($e) => ($e['type'] ?? null) === 'heros' && (int) $e['id'] === (int) $heros->id);
    expect($fiche['conditions'][0]['nom'] ?? null)->toBe('Changé en Orque');

    $notes->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 2], $heros->fresh());
    $heros->refresh();
    expect($heros->metamorphose)->toBeNull()->and($heros->pv_body_max)->toBe(30)->and($heros->pv_body)->toBe(30)
        ->and([(int) $heros->des_attaque, (int) $heros->des_defense])->toBe([$classe['attaque'], $classe['defense']])
        ->and(app(\App\Partie\MoteurSorts::class)->sortsInterdits($heros))->toBeFalse();
});

// ---------------------------------------------------------------- Prophecy of Telor : l'arène sans magie (q. 10 G)

it('arène : chacun à sa porte, ils sont poussés dedans ; à mains nues, sans sort ni artefact, la magie se dissipe ; potions permises', function () {
    $this->seed(\Database\Seeders\ConditionSeeder::class);
    $campagne = campagneE2([
        'salles_speciales' => [['id' => 'arene', 'x' => 14, 'y' => 2, 'l' => 3, 'h' => 3, 'sans_magie' => true]],
        'portes' => [['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale'], ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'],
            ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete'], ['x' => 14, 'y' => 3, 'cote' => 'o', 'type' => 'normale', 'verrou' => ['type' => 'note']]],
        'notes' => [['repere' => 'G', 'quand' => ['declencheur' => 'tous_aux_portes', 'cible' => ['portes' => [3]]],
            'alors' => [['effet' => 'ouvrir_porte', 'porte' => 3], ['effet' => 'franchir_portes', 'portes' => [3]]]]],
    ]);
    expect(app(ValidationCampagne::class)->valider($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $heros = $partie['heros'];
    $epee = \App\Models\Objet::where('nom', 'Épée longue')->firstOrFail();
    $heros->inventaire()->create(['objet_id' => $epee->id, 'emplacement' => 'arme_principale', 'quantite' => 1]);
    $potion = \App\Models\Objet::where('nom', 'Potion de soin mineur')->firstOrFail();
    $heros->inventaire()->create(['objet_id' => $potion->id, 'emplacement' => 'sac', 'quantite' => 1]);
    $equipement = app(\App\Partie\Equipement::class);
    $equipement->recalculerCombat($heros->fresh());
    $armee = (int) $heros->fresh()->des_attaque;
    $heros->update(['pv_body' => 20]);
    // Après la mise à jour du Corps (elle relit les buffs adossés aux PV) : un buff de sort, qui doit tomber dans l'arène.
    $heros->conditions()->attach(\App\Models\Condition::where('nom', 'Renforcé')->value('id'), ['duree' => 0, 'source' => 'sort:Peau de Pierre']);

    // Devant la porte de l'arène (13, 3) : la note G joue — la porte s'ouvre, le héros est poussé en (14, 3).
    $quete = $partie['quete']->fresh();
    $quete->etatsPersonnages()->update(['position_x' => 13, 'position_y' => 3]);
    $notes = app(\App\Partie\Classique\NotesDeQuete::class);
    $jouees = $notes->signaler($quete, \App\Engine\MotsClesQuete::TOUS_AUX_PORTES, ['portes' => app(ResolveurTour::class)->portesDesHeros($quete)], $heros);
    $etat = $quete->etatsPersonnages()->firstOrFail();
    expect($jouees)->toHaveCount(1)->and([(int) $etat->position_x, (int) $etat->position_y])->toBe([14, 3]);

    $heros->refresh();
    expect(\App\Partie\Classique\SalleSansMagie::pourHeros($heros))->toBeTrue()
        ->and(app(\App\Partie\MoteurSorts::class)->sortsInterdits($heros))->toBeTrue()
        ->and($equipement->armesEnMain($heros))->toBe([])
        ->and((int) $heros->des_attaque)->toBeLessThan($armee)
        ->and($heros->conditions()->count())->toBe(0)
        ->and(\App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()->contains(fn ($e) => ($e->payload['type'] ?? null) === 'magie_dissipee'))->toBeTrue();

    // « Potions and elixirs can be used » : la potion reste dans « Utiliser un objet ».
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $heros));
    $options = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'));
    expect(collect($options->firstWhere('id', 'utiliser_objet')['parametres']['objets'] ?? [])->pluck('nom')->all())->toBe(['Potion de soin mineur']);

    // Hors de l'arène (quête suivante, ou sortie), l'épée reprend ses dés.
    $etat->update(['position_x' => 8, 'position_y' => 3]);
    app(\App\Partie\Classique\SalleSansMagie::class)->actualiser($quete->fresh(), $heros);
    expect((int) $heros->fresh()->des_attaque)->toBe($armee);
});

// ---------------------------------------------------------------- Prophecy of Telor : la Flamme de Zargon (q. 13)

it('Flamme de Zargon : le coup qui porte se joue au dé contre deux chiffres ; l\'Eau de Guérison la frappe, un seul chiffre', function () {
    $this->seed(\Database\Seeders\SortSeeder::class);
    $campagne = campagneE2(['monstres' => [['slug' => 'orque', 'x' => 4, 'y' => 3, 'nom' => 'Chevalier mort-vivant', 'pv_body' => 5, 'capacites' => ['flamme_de_zargon']]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $flambant = $partie['quete']->instancesMonstres()->firstOrFail();
    $flambant->update(['revele' => true]);
    $degats = app(\App\Partie\MoteurDegats::class);

    // Zargon annonce 2 et 5 ; le dé donne 5 : épargné, malgré 3 PV de dégâts.
    app()->instance(LanceurDes::class, new LanceurDeterministe([2, 5, 5]));
    expect($degats->infligerAMonstre($flambant->fresh(), 3, \App\Partie\MoteurDegats::SOURCE_PIEGE)['degats'])->toBe(0)
        ->and((int) $flambant->fresh()->pv_body)->toBe(5);

    // Le dé donne 3 : consumé, alors qu'un seul PV de dégâts ne l'aurait pas abattu.
    app()->instance(LanceurDes::class, new LanceurDeterministe([2, 5, 3]));
    expect($degats->infligerAMonstre($flambant->fresh(), 1, \App\Partie\MoteurDegats::SOURCE_PIEGE)['vaincu'])->toBeTrue();
    $annonce = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()->last(fn ($e) => ($e->payload['type'] ?? null) === 'flamme_de_zargon');
    expect(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($annonce->payload, 'Zargon'))->pluck('texte')->first())
        ->toBe('Flamme de Zargon : Zargon annonce 2 ou 5, le dé donne 3 — Chevalier mort-vivant est consumée par les flammes');

    // L'Eau de Guérison : la figure flambante est une cible du sort (et seulement elle) ; touchée, un seul chiffre.
    $flambant->refresh()->update(['etat' => 'actif', 'revele' => true, 'pv_body' => 5, 'position_x' => 4, 'position_y' => 3]);
    $eau = \App\Models\Sort::where('nom', 'Eau de Guérison')->firstOrFail();
    $candidat = ['type' => 'monstre', 'id' => (int) $flambant->id, 'nom' => 'Chevalier mort-vivant', 'x' => 4, 'y' => 3];
    $cibles = app(\App\Partie\MoteurSorts::class)->ciblesLegales($eau, [$candidat], []);
    expect(collect($cibles)->pluck('id')->all())->toBe([(int) $flambant->id])
        ->and(app(\App\Partie\MoteurSorts::class)->ciblesLegales(\App\Models\Sort::where('nom', 'Soin du Corps')->firstOrFail(), [$candidat], []))->toBe([]);

    $sortUtilitaire = new ReflectionMethod(ResolveurTour::class, 'sortUtilitaire');
    $etat = $partie['quete']->etatsPersonnages()->firstOrFail();
    app()->instance(LanceurDes::class, new LanceurDeterministe([4, 4]));
    $issue = $sortUtilitaire->invoke(app(ResolveurTour::class), $partie['quete']->fresh(), $partie['heros'], $etat, $eau,
        ['parametres' => ['cibles' => [['type' => 'monstre', 'id' => (int) $flambant->id, 'nom' => 'Chevalier mort-vivant']]]],
        ['cible_id' => (int) $flambant->id, 'cible_type' => 'monstre']);
    expect($issue['flamme'])->toBeTrue()->and($issue['vaincu'])->toBeFalse();
    $derniere = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()->last(fn ($e) => ($e->payload['type'] ?? null) === 'flamme_de_zargon');
    expect($derniere->payload['nombres'])->toBe([4])->and($derniere->payload['eau'])->toBeTrue();
});

// ---------------------------------------------------------------- Prophecy of Telor : la quête à étages (q. 3)

function etagesE2(array $niveaux = []): array
{
    return campagneE2([
        'monstres' => [['slug' => 'orque', 'x' => 5, 'y' => 2]],
        'mobilier' => [['slug' => 'table', 'x' => 4, 'y' => 4, 'rotation' => 0]],
        'etages' => ['escalier' => [['x' => 2, 'y' => 4]], 'niveaux' => $niveaux ?: [[
            'titre' => 'La réserve',
            'monstres' => [['slug' => 'gobelin', 'x' => 5, 'y' => 3]],
            'mobilier' => [['slug' => 'coffre', 'x' => 6, 'y' => 2, 'rotation' => 0]],
            'notes' => [['repere' => 'B', 'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]], 'alors' => [['effet' => 'drapeau', 'nom' => 'gobelin-tombe']]]],
        ]]],
    ]);
}

it('étages : chaque niveau se valide comme la quête, refus à son propre chemin ; pas de fin sur des figures', function () {
    expect(codesE2(etagesE2()))->toBe([]);
    $faux = app(ValidationCampagne::class)->valider(etagesE2([['titre' => 'X', 'monstres' => [['slug' => 'licorne', 'x' => 5, 'y' => 3]]]]));
    expect(collect($faux)->pluck('chemin')->all())->toBe(['quetes.0.etages.niveaux.0.monstres.0.slug']);
    $vaincre = etagesE2();
    $vaincre['quetes'][0]['fin'] = ['type' => 'vaincre', 'cibles' => [0], 'libelle' => 'Vaincre.'];
    expect(codesE2($vaincre))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
});

it('étages : l\'étage vidé, tous remontés sur l\'escalier, le niveau suivant se pose — meubles, monstres, notes, annonce', function () {
    $partie = partieE2($this, etagesE2());
    $quete = $partie['quete']->fresh();
    $etages = app(\App\Partie\Classique\Etages::class);
    $orque = $quete->instancesMonstres()->firstOrFail();

    // L'orque debout : rien ne se pose.
    $quete->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 4]);
    expect($etages->apresMarche($partie['groupe'], $quete->fresh(), ['x' => 3, 'y' => 4], ['x' => 2, 'y' => 4]))->toBeNull();

    $orque->update(['etat' => 'vaincu', 'pv_body' => 0]);
    $quete->update(['tresors_fouilles' => ['0:'.$partie['heros']->id]]);
    $annonce = $etages->apresMarche($partie['groupe'], $quete->fresh(), ['x' => 3, 'y' => 4], ['x' => 2, 'y' => 4]);
    expect($annonce)->toMatchArray(['type' => 'etage', 'numero' => 2, 'total' => 2, 'titre' => 'La réserve'])
        ->and(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($annonce, 'Zargon'))->pluck('texte')->first())->toStartWith('Étage 2 sur 2 : La réserve');

    $quete = $quete->fresh();
    $grille = $quete->carte->grille;
    $nomsMeubles = collect($grille['mobilier'])->map(fn ($m) => \App\Models\Mobilier::find($m['mobilier_id'])->nom)->all();
    expect($nomsMeubles)->toBe(['Coffre'])
        ->and($quete->tresors_fouilles)->toBe([])
        ->and(collect($quete->documentConstruit()['notes'])->pluck('repere')->all())->toBe(['B']);
    $gobelin = \App\Partie\Classique\NotesDeQuete::figuresPosees($quete)->first();
    expect($gobelin->monstre->nom_base)->toBe('Gobelin')->and((bool) $gobelin->revele)->toBeTrue();

    // La note de l'étage vise SON monstre 0 : le gobelin.
    app(\App\Partie\MoteurDegats::class)->infligerAMonstre($gobelin, 9, \App\Partie\MoteurDegats::SOURCE_PIEGE);
    expect($quete->fresh()->aDrapeau('gobelin-tombe'))->toBeTrue();

    // Plus d'étage : remonter ne pose plus rien.
    expect($etages->apresMarche($partie['groupe'], $quete->fresh(), ['x' => 3, 'y' => 4], ['x' => 2, 'y' => 4]))->toBeNull();
});

// ---------------------------------------------------------------- Prophecy of Telor : l'invocation de Melar (q. 7 à 11)

it('invocation de Melar : le porteur rate son fardeau, Invocation d\'orques autour de lui, une fois par quête', function () {
    $note = ['repere' => 'M', 'quand' => ['declencheur' => 'fardeau_rate', 'cible' => ['objet' => 'talisman-du-savoir-maudit']],
        'alors' => [['effet' => 'invoquer', 'sort' => 'invocation-dorques']]];
    $campagne = campagneE2(['notes' => [$note]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['notes' => [['repere' => 'X', 'quand' => $note['quand'],
            'alors' => [['effet' => 'invoquer', 'sort' => 'boule-de-flammes'], ['effet' => 'invoquer', 'sort' => 'invocation-dorques', 'x' => 3]]]]])))
        ->toBe([ValidationCampagne::VALEUR_INVALIDE, ValidationCampagne::VALEUR_INVALIDE]);

    $partie = partieE2($this, $campagne);
    $heros = $partie['heros'];
    $heros->update(['pv_mind' => 12, 'pv_mind_max' => 12]); // deux dés sous 12 : le jet est raté
    $maudit = \App\Models\Objet::where('nom', 'Talisman du Savoir maudit')->firstOrFail();
    $heros->inventaire()->create(['objet_id' => $maudit->id, 'emplacement' => 'talisman', 'quantite' => 1]);
    $quete = $partie['quete']->fresh();
    $quete->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);

    // Fardeau 1 + 1, puis le dé de l'invocation : 4 → cinq orques.
    app()->instance(LanceurDes::class, new LanceurDeterministe([1, 1, 4, ...array_fill(0, 30, 1)]));
    $fardeau = app(\App\Partie\MoteurFardeau::class)->debutDeTour($partie['groupe'], $quete, $heros->fresh(), $quete->etatsPersonnages()->firstOrFail());
    expect($fardeau['perd_pv_body'])->toBeTrue();
    $orques = $quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get();
    expect($orques)->toHaveCount(5)
        ->and($orques->pluck('monstre.nom_base')->unique()->all())->toBe(['Orque'])
        ->and($orques->every(fn ($o) => abs($o->position_x - 3) + abs($o->position_y - 3) <= 3))->toBeTrue();

    // Le fil de la note le dit.
    $evenement = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()
        ->first(fn ($e) => ($e->payload['type'] ?? null) === 'note_de_quete' && ($e->payload['repere'] ?? null) === 'M');
    $lignes = collect(app(\App\Partie\JournalCombat::class)->depuisResultat($evenement->payload, 'Albrecht'))->pluck('texte')->implode(' | ');
    expect($lignes)->toContain("Sort de Dread : Invocation d'orques (dé : 4)")->toContain('5 Orque');

    // Manche suivante, nouveau jet raté : Melar ne lance plus (« only once per quest »).
    $quete->etatsPersonnages()->update(['capacites_tour' => null]);
    app()->instance(LanceurDes::class, new LanceurDeterministe([1, 1, 6, ...array_fill(0, 30, 1)]));
    app(\App\Partie\MoteurFardeau::class)->debutDeTour($partie['groupe'], $quete->fresh(), $heros->fresh(), $quete->etatsPersonnages()->firstOrFail());
    expect($quete->instancesMonstres()->where('etat', 'actif')->count())->toBe(5);
});

it('invocation de Melar : près d\'une case donnée, les orques surgissent autour d\'elle, sans héros', function () {
    $campagne = campagneE2(['notes' => [['repere' => 'E', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]],
        'alors' => [['effet' => 'invoquer', 'sort' => 'invocation-dorques', 'x' => 8, 'y' => 3]]]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    app()->instance(LanceurDes::class, new LanceurDeterministe([2, ...array_fill(0, 30, 1)]));
    app(\App\Partie\Classique\NotesDeQuete::class)->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 1]);
    $orques = $partie['quete']->instancesMonstres()->where('etat', 'actif')->get();
    expect($orques)->toHaveCount(4)
        ->and($orques->every(fn ($o) => abs($o->position_x - 8) + abs($o->position_y - 3) <= 3))->toBeTrue();
});

// ---------------------------------------------------------------- Prophecy of Telor : la zone hors du plateau (q. 2)

it('zone hors du plateau : case au bord, figures placées par la note ; la validation refuse le reste', function () {
    $note = ['repere' => 'B', 'une_fois' => false, 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]],
        'alors' => [['effet' => 'en_reserve', 'monstres' => [['slug' => 'gobelin', 'nombre' => 2], ['slug' => 'orque']]]]];
    expect(codesE2(campagneE2(['regles' => ['reserve_hors_plateau' => ['x' => 8, 'y' => 13]], 'notes' => [$note]])))->toBe([])
        // Au milieu du plateau, pas au bord ; et l'effet sans zone.
        ->and(codesE2(campagneE2(['regles' => ['reserve_hors_plateau' => ['x' => 8, 'y' => 7]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE2(campagneE2(['notes' => [$note]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
});

it('zone hors du plateau : placées au tour de Zargon, les figures entrent par la case et jouent ; un héros sur la case est frappé, rien n\'entre', function () {
    $campagne = campagneE2(['regles' => ['reserve_hors_plateau' => ['x' => 12, 'y' => 13]], 'notes' => [
        ['repere' => 'B', 'une_fois' => false, 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]],
            'alors' => [['effet' => 'en_reserve', 'monstres' => [['slug' => 'gobelin', 'nombre' => 2], ['slug' => 'orque']]]]],
    ]]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete']->fresh();
    $etat = $quete->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => 12, 'position_y' => 9]); // dans le couloir : le chemin depuis la case est libre
    $reserve = app(\App\Partie\Classique\ReserveHorsPlateau::class);

    // Le héros attend : au tour de Zargon, la note place trois figures dans la zone, puis chacune entre par la case et
    // s'en écarte vers le héros.
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 300, 6)));
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'attendre'])->assertAccepted();
    $evenements = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->orderBy('sequence')->get()->pluck('payload');
    $entrees = $evenements->where('type', 'reserve_entree')->values();
    expect($entrees)->toHaveCount(3)
        ->and($entrees[0]['monstre'])->toBe('Gobelin')->and($entrees[0]['restants'])->toBe(2)
        ->and($reserve->figures($quete)->count())->toBe(0)
        ->and($quete->instancesMonstres()->where('etat', 'actif')->whereNotNull('position_x')->count())->toBe(3);
    expect(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($entrees[0], ''))->pluck('texte')->first())
        ->toBe('Gobelin surgit de la zone hors du plateau (2 attendent encore)');
    $note = $evenements->first(fn ($p) => ($p['type'] ?? null) === 'note_de_quete' && ($p['repere'] ?? null) === 'B');
    expect(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($note, ''))->pluck('texte')->implode(' | '))
        ->toContain('2 Gobelin, 1 Orque arrivent dans la zone hors du plateau (3 y attendent)');

    // Les figures de la zone n'ont pas de case : la table les montre en pastille sur la case d'entrée.
    $reserve->ajouter($quete->fresh(), [['slug' => 'orque', 'nombre' => 2]]);
    expect(app(\App\Partie\EtatGroupe::class)->payload($partie['groupe']->fresh())['carte']['reserve'] ?? null)
        ->toBe(['x' => 12, 'y' => 13, 'noms' => ['Orque', 'Orque']]);

    // Un héros posté sur la case : au tour suivant, UNE figure de la zone le frappe, et rien n'entre.
    $quete->instancesMonstres()->where('etat', 'actif')->update(['etat' => 'vaincu']);
    $etat->refresh()->update(['position_x' => 12, 'position_y' => 13]);
    $avant = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->max('sequence');
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'attendre'])->assertAccepted();
    $nouveaux = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->where('sequence', '>', $avant)->get()->pluck('payload');
    expect($nouveaux->where('type', 'attaque_monstre')->count())->toBe(1)
        ->and($nouveaux->where('type', 'reserve_entree')->count())->toBe(0)
        ->and($reserve->figures($quete)->count())->toBe(5); // deux restées, trois nouvelles de la note
});

it('donjon vidé : une note qui attend le prochain tour du MJ le fait jouer quand même ; sans elle, pas de phase', function () {
    $partie = partieE2($this, campagneE2(['notes' => [
        ['repere' => 'N', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]], 'alors' => [['effet' => 'texte', 'texte' => 'Des pas au loin.']]],
    ]]));
    $partie['quete']->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);
    $finir = function () use ($partie) {
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
        $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'attendre'])->assertAccepted();
    };

    $finir();
    expect($partie['quete']->fresh()->tours_mj)->toBe(1)
        ->and($partie['quete']->fresh()->notes_jouees)->toBe([0]);

    // La note jouée, plus rien n'attend Zargon dans ce donjon vide : son tour ne se joue plus.
    $finir();
    expect($partie['quete']->fresh()->tours_mj)->toBe(1);
});

// ---------------------------------------------------------------- Prophecy of Telor : la salle en contrebas (q. 9 C)

function contrebasE2(array $regle = []): array
{
    return campagneE2(['regles' => ['salle_en_contrebas' => array_replace(['salle' => 'galerie', 'galerie' => [['x' => 7, 'y' => 1, 'l' => 4, 'h' => 1]]], $regle)]]);
}

it('salle en contrebas : une salle ouverte, une galerie hors d\'elle et dans le plateau', function () {
    expect(codesE2(contrebasE2()))->toBe([])
        ->and(codesE2(contrebasE2(['salle' => 'crypte'])))->toBe([ValidationCampagne::SALLE_INCONNUE])
        ->and(codesE2(contrebasE2(['galerie' => [['x' => 7, 'y' => 1, 'l' => 4, 'h' => 2]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE2(contrebasE2(['galerie' => [['x' => 18, 'y' => 1, 'l' => 4, 'h' => 1]]])))->toBe([ValidationCampagne::HORS_PLATEAU])
        ->and(codesE2(contrebasE2(['galerie' => []])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
});

it('salle en contrebas : la galerie voit la salle par-dessus le mur ; le héros saute, perd 1 PV, garde son action', function () {
    $partie = partieE2($this, contrebasE2());
    $quete = $partie['quete']->fresh();
    $etat = $quete->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => 8, 'position_y' => 1]);

    // La vue : de la galerie au fond de la salle, à travers le mur ; sans la couche, le mur la coupe.
    expect(\App\Partie\FabriqueGrille::pour($quete)->ligneDeVue(8, 1, 9, 4))->toBeTrue()
        ->and(\App\Partie\FabriqueGrille::pour($quete)->ligneDeVue(9, 4, 8, 1))->toBeTrue();
    $sansVue = $quete->carte->grille;
    unset($sansVue['vues_croisees']);
    expect(\App\Partie\Grille::depuisCarte(new \App\Models\Carte(['grille' => $sansVue]))->ligneDeVue(8, 1, 9, 4))->toBeFalse()
        // Le contact, lui, reste coupé : on ne frappe pas d'en haut.
        ->and(\App\Partie\Grille::sontAdjacentes(8, 1, 8, 2, carte: $quete->carte))->toBeFalse();

    // Le menu offre le saut sur la case d'en dessous ; le résolveur l'accepte.
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $options = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'));
    $saut = $options->firstWhere('id', 'sauter_contrebas_8_2');
    expect($saut)->not->toBeNull()->and($saut['type'])->toBe('saut_contrebas')->and($saut['creneau'] ?? 'mouvement')->toBe('mouvement');
    $pv = (int) $partie['heros']->fresh()->pv_body;
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'sauter_contrebas_8_2'])->assertAccepted();
    $etat->refresh();
    expect([$etat->position_x, $etat->position_y])->toBe([8, 2])
        ->and((bool) $etat->a_deplace)->toBeTrue()->and((bool) $etat->a_agi)->toBeFalse()
        ->and((int) $partie['heros']->fresh()->pv_body)->toBe($pv - 1);
    $payload = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()->pluck('payload')
        ->first(fn ($p) => ($p['type'] ?? null) === 'saut_contrebas' && isset($p['pv_body_perdus'])); // pas le choix lui-même
    expect(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($payload, 'Albrecht'))->pluck('texte')->first())
        ->toBe('Albrecht saute dans la salle en contrebas et perd 1 point(s) de Corps');

    // Hors de la galerie, aucun saut.
    $etat->update(['position_x' => 12, 'position_y' => 1, 'a_deplace' => false]);
    expect(app(\App\Partie\Classique\SalleEnContrebas::class)->sauts($quete->fresh(), $etat->fresh()))->toBe([]);
});

// ---------------------------------------------------------------- Spirit Queen's Torment : les gestes de quête (q. 7 D, 8 B)

function gestesE2(array $gestes, array $notes = []): array
{
    return campagneE2(['gestes' => $gestes, 'notes' => $notes]);
}

it('gestes : alors OU choix, une salle ouverte, des effets ; le soin veut une jauge', function () {
    $feu = ['libelle' => 'Se réchauffer au feu spectral', 'salle' => 'entree', 'par_heros' => true, 'alors' => [['effet' => 'soigner', 'mind' => 'tout']]];
    expect(codesE2(gestesE2([$feu])))->toBe([])
        ->and(codesE2(gestesE2([[...$feu, 'choix' => [['libelle' => 'a', 'alors' => [['effet' => 'texte', 'texte' => 'a']]]]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE2(gestesE2([['libelle' => 'x', 'salle' => 'entree', 'choix' => [['libelle' => 'a', 'alors' => [['effet' => 'texte', 'texte' => 'a']]]]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE2(gestesE2([[...$feu, 'salle' => 'crypte']])))->toBe([ValidationCampagne::SALLE_INCONNUE])
        ->and(codesE2(gestesE2([[...$feu, 'alors' => [['effet' => 'soigner']]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE2(gestesE2([[...$feu, 'sauf_drapeau' => 'jamais']])))->toBe([ValidationCampagne::DRAPEAU_JAMAIS_POSE]);
});

it('gestes : la cheminée rend tout l\'Esprit, une fois par héros, pour une action', function () {
    $partie = partieE2($this, gestesE2([['libelle' => 'Se réchauffer au feu spectral', 'salle' => 'entree', 'par_heros' => true,
        'alors' => [['effet' => 'soigner', 'mind' => 'tout']]]]));
    $partie['heros']->update(['pv_mind_max' => 4, 'pv_mind' => 1]);
    $etat = $partie['quete']->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => 3, 'position_y' => 3]);
    $menu = function () use ($partie) {
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));

        return collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'));
    };

    expect($menu()->firstWhere('id', 'geste_0')['libelle'] ?? null)->toBe('Se réchauffer au feu spectral');
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'geste_0'])->assertAccepted();
    expect((int) $partie['heros']->fresh()->pv_mind)->toBe(4)->and((bool) $etat->fresh()->a_agi)->toBeTrue();
    $lignes = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()->pluck('payload')
        ->filter(fn ($p) => in_array($p['type'] ?? null, ['geste_de_quete', 'note_de_quete'], true) && isset($p['nom']) || ($p['repere'] ?? null) === 'geste')
        ->flatMap(fn ($p) => app(\App\Partie\JournalCombat::class)->depuisResultat($p, 'Albrecht'))->pluck('texte')->implode(' | ');
    expect($lignes)->toContain('Albrecht — Se réchauffer au feu spectral')->toContain('Albrecht récupère 3 point(s) d\'Esprit');

    // Une fois par héros : plus offert, même au tour suivant.
    $etat->update(['a_agi' => false, 'a_joue' => false]);
    expect($menu()->firstWhere('id', 'geste_0'))->toBeNull();
});

it('gestes : l\'énigme des coffres — un mauvais ordre frappe tous les héros et laisse réessayer, le bon la ferme', function () {
    $partie = partieE2($this, gestesE2([['libelle' => 'Poser les gemmes', 'salle' => 'entree', 'sauf_drapeau' => 'enigme_resolue', 'choix' => [
        ['libelle' => 'rubis, améthyste, émeraude', 'alors' => [['effet' => 'degats', 'body' => 2, 'tous_les_heros' => true]]],
        ['libelle' => 'rubis, émeraude, améthyste', 'alors' => [['effet' => 'drapeau', 'nom' => 'enigme_resolue'], ['effet' => 'texte', 'texte' => 'Un esprit orque se lève.']]],
    ]]]));
    $etat = $partie['quete']->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => 3, 'position_y' => 3]);
    $pv = (int) $partie['heros']->fresh()->pv_body;
    $choisir = function (string $id) use ($partie, $etat) {
        $etat->refresh()->update(['a_agi' => false, 'a_joue' => false]);
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
        $ids = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id');
        if ($ids->contains($id)) {
            $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => $id])->assertAccepted();
        }

        return $ids;
    };

    expect($choisir('geste_0_0'))->toContain('geste_0_1');
    expect((int) $partie['heros']->fresh()->pv_body)->toBe($pv - 2);
    expect($choisir('geste_0_1'))->toContain('geste_0_0'); // l'énigme est restée ouverte après l'erreur
    expect($partie['quete']->fresh()->aDrapeau('enigme_resolue'))->toBeTrue()
        ->and($choisir('rien'))->not->toContain('geste_0_0');
});

// ---------------------------------------------------------------- Spirit Queen's Torment : le rappel de la Reine (q. 14 C)

it('rappel de la Reine : en vue, un dé ; un bouclier ôte 1 Esprit, un crâne rien ; à 0 elle s\'efface apaisée, vaincue, la note joue', function () {
    $campagne = campagneE2([
        'monstres' => [['slug' => 'orque', 'x' => 5, 'y' => 3, 'nom' => 'La Reine des Esprits', 'pv_mind' => 2, 'capacites' => ['rappelable']]],
        'fin' => ['type' => 'vaincre', 'cibles' => [0], 'libelle' => 'Affronter la Reine.'],
        'notes' => [['repere' => 'C', 'quand' => ['declencheur' => 'figure_apaisee', 'cible' => ['monstre' => 0]],
            'alors' => [['effet' => 'drapeau', 'nom' => 'nelath_sauvee', 'campagne' => true]]]],
    ]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $etat = $partie['quete']->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => 3, 'position_y' => 3]);
    $reine = $partie['quete']->instancesMonstres()->firstOrFail();
    $rappeler = function (int $de) use ($partie, $etat, $reine) {
        $etat->refresh()->update(['a_agi' => false, 'a_joue' => false]);
        app()->instance(LanceurDes::class, new LanceurDeterministe([$de, ...array_fill(0, 30, 1)]));
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
        expect(collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id'))->toContain("rappeler_{$reine->id}");
        $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => "rappeler_{$reine->id}"])->assertAccepted();
    };

    $rappeler(1); // crâne
    expect((int) $reine->fresh()->pv_mind)->toBe(2);
    $rappeler(6); // bouclier noir
    expect((int) $reine->fresh()->pv_mind)->toBe(1);
    $rappeler(4); // bouclier blanc : 0, elle s'efface
    $reine->refresh();
    expect($reine->etat)->toBe('vaincu')->and($reine->position_x)->toBeNull()->and($reine->habillage['apaisee'] ?? null)->toBeTrue()
        ->and($partie['quete']->fresh()->aDrapeau('nelath_sauvee'))->toBeTrue()
        ->and($partie['quete']->fresh()->objectifAccompli())->toBeTrue();
    $ligne = \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()->pluck('payload')
        ->last(fn ($p) => ($p['type'] ?? null) === 'rappel_figure' && isset($p['apaisee']));
    expect(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($ligne, 'Albrecht'))->pluck('texte')->first())
        ->toBe('Albrecht rappelle à La Reine des Esprits son passé (bouclier blanc) : elle brise l\'enchantement de Zargon, remercie les héros et s\'efface, apaisée');

    // Apaisée, elle n'est plus à rappeler.
    expect(app(\App\Partie\Classique\RappelDeLaReine::class)->cibles($partie['quete']->fresh(), $etat->fresh()))->toHaveCount(0);
});

// ---------------------------------------------------------------- Spirit Queen's Torment : l'apnée (q. 11) et les dés en plus (q. 8)

it('apnée : la validation ; sans orbe le compteur avance à chaque tour, passé le maximum il se noie ; l\'orbe fait respirer', function () {
    expect(codesE2(campagneE2(['regles' => ['apnee' => ['tours' => 7], 'des_deplacement_bonus' => 1]])))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['apnee' => ['tours' => 0]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE2(campagneE2(['regles' => ['des_deplacement_bonus' => 3]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);

    $partie = partieE2($this, campagneE2(['regles' => ['apnee' => ['tours' => 2]]]));
    $etat = $partie['quete']->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => 3, 'position_y' => 3]);
    // Le premier menu, au départ de la quête, a déjà compté le premier tour (le vrai crochet) : on repart de zéro.
    expect(data_get($partie['quete']->fresh()->drapeaux, '_apnee.'.$partie['heros']->id))->toBe(1);
    $partie['quete']->update(['drapeaux' => []]);
    $apnee = app(\App\Partie\Classique\Apnee::class);
    $tour = function () use ($apnee, $partie, $etat) {
        $etat->refresh()->update(['capacites_tour' => null]);

        return $apnee->debutDeTour($partie['groupe']->fresh(), $partie['quete']->fresh(), $partie['heros']->fresh(), $etat->fresh());
    };

    expect($tour()['tour'])->toBe(1);
    // Une seule fois par tour : le menu régénéré ne recompte pas.
    expect($apnee->debutDeTour($partie['groupe'], $partie['quete']->fresh(), $partie['heros']->fresh(), $etat->fresh()))->toBeNull();
    $deuxieme = $tour();
    expect($deuxieme['tour'])->toBe(2)->and($deuxieme['noye'])->toBeFalse()
        ->and(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($deuxieme, ''))->pluck('texte')->first())->toBe('Albrecht retient son souffle — tour 2 sur 2');
    $troisieme = $tour();
    expect($troisieme['noye'])->toBeTrue()->and((bool) $etat->fresh()->tombe)->toBeTrue();

    // Avec une orbe, rien ne compte.
    $etat->update(['tombe' => false]);
    $partie['heros']->update(['pv_body' => 10]);
    $partie['heros']->inventaire()->create(['objet_id' => \App\Models\Objet::where('nom', 'Orbe bleue')->value('id'), 'emplacement' => 'sac', 'quantite' => 4]);
    expect($tour())->toBeNull();
});

it('dés de déplacement en plus : le classique lance 2 + N dés rouges dans la quête qui le dit', function () {
    $partie = partieE2($this, campagneE2(['regles' => ['des_deplacement_bonus' => 1]]));
    expect($partie['groupe']->fresh()->socleDeDeplacement($partie['heros']))->toBe(['base' => 0, 'des' => 3]);
});

// ---------------------------------------------------------------- Spirit Queen's Torment : le captif caché (q. 5)

function cacheE2(): array
{
    return campagneE2([
        'monstres' => [['slug' => 'orque', 'x' => 5, 'y' => 3], ['slug' => 'orque', 'x' => 9, 'y' => 3]],
        'captif' => ['mercenaire' => 'udren', 'cache' => 0, 'echec_si_mort' => true],
    ]);
}

it('captif caché : la validation veut un index de monstre et pas de case', function () {
    $this->seed(\Database\Seeders\MercenaireSeeder::class);
    expect(codesE2(cacheE2()))->toBe([]);
    $avecCase = cacheE2();
    $avecCase['quetes'][0]['captif'] += ['x' => 3, 'y' => 3];
    $horsListe = cacheE2();
    $horsListe['quetes'][0]['captif']['cache'] = 5;
    expect(codesE2($avecCase))->toBe([ValidationCampagne::CLE_INCONNUE, ValidationCampagne::CLE_INCONNUE])
        ->and(codesE2($horsListe))->not->toBe([]);
});

it('captif caché : chercher dans une salle avec un orque ; absent, rien ; présent, il prend la case de sa figure et rejoint le héros', function () {
    $this->seed(\Database\Seeders\MercenaireSeeder::class);
    $partie = partieE2($this, cacheE2());
    $quete = $partie['quete']->fresh();
    expect($quete->captif->etat)->toBe('captif')->and($quete->captif->position_x)->toBeNull();
    $quete->instancesMonstres()->update(['revele' => true]); // les deux salles sont ouvertes et vues
    $etat = $quete->etatsPersonnages()->firstOrFail();
    $chercher = function (int $x, int $y) use ($partie, $etat) {
        $etat->refresh()->update(['position_x' => $x, 'position_y' => $y, 'a_agi' => false, 'a_joue' => false]);
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
        expect(collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id'))->toContain('chercher_captif');
        $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'chercher_captif'])->assertAccepted();

        return \App\Models\Evenement::where('groupe_id', $partie['groupe']->id)->get()->pluck('payload')
            ->last(fn ($p) => ($p['type'] ?? null) === 'recherche_captif' && isset($p['trouve']));
    };

    $rate = $chercher(8, 3); // la galerie, avec l'autre orque
    expect($rate['trouve'])->toBeFalse()->and($quete->captif->fresh()->etat)->toBe('captif');
    $trouve = $chercher(3, 3); // l'entrée, avec la figure cachée
    $captif = $quete->captif->fresh();
    expect($trouve['trouve'])->toBeTrue()
        ->and($captif->etat)->toBe('actif')->and([$captif->position_x, $captif->position_y])->toBe([5, 3])
        ->and((int) $captif->recruteur_personnage_id)->toBe((int) $partie['heros']->id)
        ->and(\App\Partie\Classique\NotesDeQuete::figuresPosees($quete->fresh())[0]->etat)->toBe('vaincu')
        ->and(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($trouve, 'Albrecht'))->pluck('texte')->first())
            ->toBe('Albrecht trouve Udren parmi les monstres ; l\'allié se fait connaître et rejoint les héros');
    // Identifiée, la quête ne la perd plus (sa figure retirée n'est pas « tombée »).
    expect(app(\App\Partie\Classique\Escorte::class)->cacheeTombee($quete->fresh()))->toBeFalse();
});

it('captif caché : sa figure tuée avant l\'identification fait perdre la quête', function () {
    $this->seed(\Database\Seeders\MercenaireSeeder::class);
    $partie = partieE2($this, cacheE2());
    $partie['quete']->etatsPersonnages()->update(['position_x' => 3, 'position_y' => 3]);
    \App\Partie\Classique\NotesDeQuete::figuresPosees($partie['quete']->fresh())[0]->update(['etat' => 'vaincu']);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'attendre'])->assertAccepted();
    expect($partie['quete']->fresh()->etat)->toBe('echouee');
});

// ---------------------------------------------------------------- Spirit Queen's Torment : l'insubstantialité (q. 14)

it('insubstantialité : deux usages de son tour (murs, deux dés), la réaction qui ignore un coup, trois usages en tout', function () {
    expect(codesE2(campagneE2(['regles' => ['insubstantialite' => ['usages' => 3]]])))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['insubstantialite' => ['usages' => 0]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);

    $partie = partieE2($this, campagneE2(['regles' => ['insubstantialite' => ['usages' => 3]]]));
    $heros = $partie['heros'];
    $etat = $partie['quete']->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => 3, 'position_y' => 3]);
    $menu = function () use ($partie) {
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));

        return collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id');
    };

    expect($menu())->toContain('insubstantiel_murs')->toContain('insubstantiel_des');
    $avant = (int) $etat->fresh()->deplacement_tour;
    app()->instance(LanceurDes::class, new LanceurDeterministe([3, 5, ...array_fill(0, 30, 1)]));
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'insubstantiel_des'])->assertAccepted();
    expect((int) $etat->fresh()->deplacement_tour)->toBe($avant + 8)
        ->and(\App\Partie\Classique\Insubstantialite::restants($partie['quete']->fresh(), $heros))->toBe(2)
        ->and($menu())->not->toContain('insubstantiel_des')->toContain('insubstantiel_murs');

    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'insubstantiel_murs'])->assertAccepted();
    expect(app(\App\Partie\MoteurSorts::class)->traverseRoche($heros->fresh()))->toBeTrue()
        ->and(\App\Partie\Classique\Insubstantialite::restants($partie['quete']->fresh(), $heros))->toBe(1);

    // La réaction : un piège le frappe ; il accepte, le coup est défait, le dernier usage part.
    $pv = (int) $heros->fresh()->pv_body;
    app(\App\Partie\MoteurDegats::class)->infligerAHeros($heros->fresh(), 2, \App\Partie\MoteurDegats::SOURCE_PIEGE);
    $attente = $etat->fresh()->reaction_en_attente;
    expect($attente['insubstantiel'] ?? null)->toBeTrue()->and($attente['description'])->toContain('1 usage(s) restant(s)');
    app(\App\Partie\MoteurReactions::class)->resoudre($partie['groupe']->fresh(), $heros->fresh(), true);
    expect((int) $heros->fresh()->pv_body)->toBe($pv)
        ->and(\App\Partie\Classique\Insubstantialite::restants($partie['quete']->fresh(), $heros))->toBe(0);

    // Plus d'usage : ni option, ni réaction.
    $etat->update(['capacites_tour' => null, 'reaction_en_attente' => null]);
    expect($menu())->not->toContain('insubstantiel_murs');
    app(\App\Partie\MoteurDegats::class)->infligerAHeros($heros->fresh(), 1, \App\Partie\MoteurDegats::SOURCE_PIEGE);
    expect($etat->fresh()->reaction_en_attente['insubstantiel'] ?? null)->toBeNull();
});

// ---------------------------------------------------------------- Spirit Queen's Torment : le barde de remplacement (p. 4)

it('barde de remplacement : à la mort d\'un héros (après le drapeau), un barde neuf paraît, au même joueur, ramasse ses affaires — une seule fois', function () {
    $campagne = campagneE2(['notes' => [['repere' => 'S', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]],
        'alors' => [['effet' => 'drapeau', 'nom' => 'sigill_trouve', 'campagne' => true]]]]]);
    $campagne['barde_de_remplacement'] = ['si_drapeau' => 'sigill_trouve'];
    expect(codesE2($campagne))->toBe([]);

    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $heros = app(\App\Partie\CreationHeros::class)->creer($alice->id, 'Albrecht', 'barbare');
    $code = $this->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id, 'mode_mort' => 'heroic'])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $this->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    $etat = $quete->etatsPersonnages()->firstOrFail();
    $etat->update(['position_x' => 3, 'position_y' => 3]);
    $objets = $heros->inventaire()->count();
    $mort = app(\App\Partie\Classique\MortHeroic::class);

    // Avant le drapeau : la mort est une mort.
    expect(app(\App\Partie\Classique\BardeDeRemplacement::class)->aRemplacer($groupe, $quete, $etat))->toBeFalse();
    app(\App\Partie\Classique\NotesDeQuete::class)->signaler($quete, \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 1]);

    $annonce = $mort->mourir($groupe->fresh(), $quete->fresh(), $etat->fresh());
    $barde = Personnage::where('joueur_id', $alice->id)->where('classe', 'barde')->firstOrFail();
    $etatBarde = $quete->etatsPersonnages()->where('personnage_id', $barde->id)->firstOrFail();
    expect($annonce['affaires'])->toBe('au_barde')
        ->and((int) $barde->niveau)->toBe(1)->and((int) $barde->groupe_actif_id)->toBe((int) $groupe->id)
        ->and([$etatBarde->position_x, $etatBarde->position_y])->toBe([3, 3])->and((bool) $etatBarde->a_joue)->toBeTrue()
        ->and($heros->inventaire()->count())->toBe(0)
        ->and($barde->inventaire()->count())->toBeGreaterThanOrEqual($objets)
        ->and($groupe->fresh()->drapeaux_campagne['_barde_remplacant'] ?? null)->toBeTrue();
    $ligne = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()->pluck('payload')->firstWhere('type', 'barde_remplacant');
    expect(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($ligne, ''))->pluck('texte')->first())
        ->toBe('Un barde paraît auprès de Albrecht : le joueur le prend en main et ramasse ses affaires — il agira au prochain tour');

    // Une seule fois — et un barde est désormais joué.
    expect(app(\App\Partie\Classique\BardeDeRemplacement::class)->aRemplacer($groupe->fresh(), $quete->fresh(), $etatBarde->fresh()))->toBeFalse();
});

// ---------------------------------------------------------------- Jungles of Delthrak : les jetons Spawnling

/** Fouille de la galerie (8, 3), le menu frais : rend le résultat. */
function fouillerE2(object $test, array $partie): array
{
    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $test->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();

    return $test->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'fouiller_tresor'])->assertAccepted()->json('resultat');
}

it('Spawnling errant : la carte pose un jeton sur la fiche du fouilleur, aucune figure, et son tour s\'arrête', function () {
    $partie = partieE2($this, campagneE2(['monstre_errant' => 'rejeton-putride']));
    empilerCarteFouille($partie['quete'], ['issue' => 'errant']);

    $resultat = fouillerE2($this, $partie);
    $etat = $partie['quete']->etatsPersonnages()->firstOrFail();

    expect($resultat['issue'])->toBe('errant')
        ->and($resultat['rejetons']['nombre'])->toBe(1)
        ->and($partie['quete']->instancesMonstres()->count())->toBe(0)
        ->and((int) $etat->jetons_rejeton)->toBe(1)
        // « The hero's turn immediately ends » — et la fin de tour ronge déjà : 1 PV par jeton (p. 48).
        // Seul héros : le round s'est refermé et a tout remis à zéro — le PV rongé dit que la fin de tour s'est jouée.
        ->and(\App\Partie\Classique\FinDeTourForcee::demandee($etat))->toBeFalse()
        ->and((int) $partie['heros']->fresh()->pv_body)->toBe(29)
        ->and(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($resultat, 'Albrecht'))->pluck('texte')->first())
            ->toStartWith('Un rejeton s\'accroche à Albrecht : son tour s\'arrête');
});

it('Spawnling d\'une note (les cocons) : « four Blightcrawler Spawnlings » sur la fiche, le tour s\'arrête', function () {
    $campagne = campagneE2(['notes' => [['repere' => 'D', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
        'alors' => [['effet' => 'rejetons', 'nombre' => 4], ['effet' => 'objet', 'slug' => 'potion-de-soin']]]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['notes' => [['repere' => 'D', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'rejetons', 'nombre' => 7]]]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);

    $resultat = fouillerE2($this, $partie);
    $etat = $partie['quete']->etatsPersonnages()->firstOrFail();

    // Le tour s'arrête : la fin de tour ronge aussitôt les quatre (le seul héros referme le round).
    expect((int) $etat->jetons_rejeton)->toBe(4)
        ->and((int) $partie['heros']->fresh()->pv_body)->toBe(26)
        ->and(collect(collect($resultat['annonces_automatiques'] ?? [])->firstWhere('type', 'note_de_quete')['effets'] ?? [])
            ->firstWhere('effet', 'rejetons')['jetons'] ?? null)->toBe(4);
});

// ---------------------------------------------------------------- Jungles of Delthrak : les cocons, un à la fois et dans l'ordre

it('cocons : chaque cocon détruit dans la salle donne l\'étape suivante, au héros qui le détruit ; épuisée, plus rien', function () {
    $campagne = campagneE2([
        'mobilier' => [['slug' => 'cocon', 'x' => 9, 'y' => 2, 'rotation' => 0], ['slug' => 'cocon', 'x' => 9, 'y' => 4, 'rotation' => 0],
            ['slug' => 'cocon', 'x' => 10, 'y' => 3, 'rotation' => 0]],
        'notes' => [['repere' => 'C', 'une_fois' => false, 'quand' => ['declencheur' => 'meuble_detruit', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'en_ordre', 'etapes' => [
                ['alors' => [['effet' => 'or', 'or' => 200]]],
                ['alors' => [['effet' => 'rejetons']]],
            ]]]]],
    ]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['notes' => [['repere' => 'C', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'en_ordre', 'etapes' => [['alors' => [['effet' => 'jet', 'issues' => []]]]]]]]]])))->toContain(ValidationCampagne::VALEUR_INVALIDE);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];

    $detruire = function (int $index) use ($partie, $quete): array {
        $quete->etatsPersonnages()->update(['position_x' => 9, 'position_y' => 3, 'a_joue' => false, 'a_agi' => false, 'a_deplace' => false, 'capacites_tour' => null]);
        \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
        $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();

        return $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => "detruire_par_action_{$index}"])->assertAccepted()->json('resultat');
    };

    $detruire(0);
    expect((int) $partie['heros']->fresh()->or)->toBe(200)
        ->and((int) $quete->etatsPersonnages()->value('jetons_rejeton'))->toBe(0);

    $detruire(1);
    expect((int) $partie['heros']->fresh()->or)->toBe(200)
        ->and((int) $quete->etatsPersonnages()->value('jetons_rejeton'))->toBe(1);

    // Le troisième cocon : la suite est épuisée, le fil le dit.
    $resultat = $detruire(2);
    $note = collect($resultat['annonces_automatiques'] ?? [])->firstWhere('type', 'note_de_quete');
    expect($note['effets'][0])->toHaveKey('etape')->and($note['effets'][0]['etape'])->toBeNull()
        ->and((int) $quete->etatsPersonnages()->value('jetons_rejeton'))->toBe(1)
        ->and(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($note, 'Albrecht'))->pluck('texte')->all())->toContain('Il n\'y a plus rien à trouver');
});

it('apparition près du héros : « place a Blightcrawler next to that hero. It immediately attacks » (q. 6 C)', function () {
    $campagne = campagneE2([
        'mobilier' => [['slug' => 'cocon', 'x' => 9, 'y' => 2, 'rotation' => 0]],
        'notes' => [['repere' => 'C', 'quand' => ['declencheur' => 'meuble_detruit', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'apparition', 'pres_du_heros' => true, 'monstres' => [['slug' => 'orque', 'x' => 7, 'y' => 5]]]]]],
    ]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['notes' => [['repere' => 'C', 'quand' => ['declencheur' => 'meuble_detruit', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'apparition', 'pres_du_heros' => 'oui', 'monstres' => [['slug' => 'orque', 'x' => 7, 'y' => 5]]]]]]])))
            ->toBe([ValidationCampagne::TYPE_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 9, 'position_y' => 3]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();

    $resultat = $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'detruire_par_action_0'])->assertAccepted()->json('resultat');
    $orque = $partie['quete']->instancesMonstres()->firstOrFail();

    expect(max(abs((int) $orque->position_x - 9), abs((int) $orque->position_y - 3)))->toBe(1)
        ->and(collect($resultat['annonces_automatiques'] ?? [])->pluck('type')->all())->toContain('attaque_monstre');
});

// ---------------------------------------------------------------- Jungles of Delthrak : le pont de corde (q. 15 C)

it('pont de corde : un dé rouge à la première case de pont ; 1-2 il s\'agrippe — marche arrêtée, tour fini ; 3-6 il passe', function (int $de, array $case, bool $glisse) {
    $campagne = campagneE2(['regles' => ['pont_de_corde' => [['x' => 8, 'y' => 2, 'l' => 1, 'h' => 3]]], 'portes' => [
        ['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale', 'ouverte' => true],
        ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'], ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete']]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['pont_de_corde' => [['x' => 30, 'y' => 2, 'l' => 1, 'h' => 3]]]])))->toBe([ValidationCampagne::HORS_PLATEAU])
        ->and(codesE2(campagneE2(['regles' => ['pont_de_corde' => []]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 5, 'position_y' => 3]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    // Un dé part avant la marche (dans la même requête), puis celui du pont.
    app()->instance(LanceurDes::class, new LanceurDeterministe([4, $de, ...array_fill(0, 30, 3)]));
    $resultat = $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => 9, 'y' => 3]])
        ->assertAccepted()->json('resultat');

    $etat = $partie['quete']->etatsPersonnages()->first();
    $annonce = collect($resultat['annonces_automatiques'] ?? [])->firstWhere('type', 'pont_de_corde');
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe($case)
        ->and($annonce['glisse'])->toBe($glisse)->and($annonce['de'])->toBe($de);
})->with([
    'glisse (1)' => [1, [8, 3], true],
    'passe (3)' => [3, [9, 3], false],
]);

it('pont de corde : glissé, il ne se défend plus jusqu\'à son prochain tour ; un seul jet par tour ; l\'Élixir de pas d\'araignée l\'en dispense', function () {
    $partie = partieE2($this, campagneE2(['regles' => ['pont_de_corde' => [['x' => 8, 'y' => 2, 'l' => 1, 'h' => 3]]]]));
    app()->instance(LanceurDes::class, new LanceurDeterministe([2, 6, ...array_fill(0, 30, 6)]));
    $pont = app(\App\Partie\Classique\PontDeCorde::class);
    $etat = $partie['quete']->etatsPersonnages()->first();
    $etat->update(['position_x' => 8, 'position_y' => 2, 'capacites_tour' => null]);
    $sorts = app(\App\Partie\MoteurSorts::class);
    $defense = $sorts->desDefenseHeros($partie['heros']->fresh());

    $premier = $pont->jet($partie['quete']->fresh(), $partie['heros'], $etat, [['x' => 7, 'y' => 2], ['x' => 8, 'y' => 2], ['x' => 8, 'y' => 3]]);
    expect($premier)->toMatchArray(['index' => 1, 'de' => 2, 'glisse' => true])
        ->and($sorts->desDefenseHeros($partie['heros']->fresh()))->toBe($defense); // rien d'écrit avant d'appliquer
    $pont->appliquer($partie['groupe'], $partie['heros'], $etat, $premier);
    $second = $pont->jet($partie['quete']->fresh(), $partie['heros'], $etat->fresh(), [['x' => 8, 'y' => 3]]);
    expect($defense)->toBeGreaterThan(0)
        ->and($second)->toBeNull()
        ->and($sorts->desDefenseHeros($partie['heros']->fresh()))->toBe(0)
        ->and(\App\Partie\Classique\FinDeTourForcee::demandee($etat->fresh()))->toBeTrue();

    // Le round suivant efface son tour : il se défend de nouveau.
    $etat->update(['capacites_tour' => null]);
    expect($sorts->desDefenseHeros($partie['heros']->fresh()))->toBe($defense);

    // Sous l'Élixir de pas d'araignée, aucun jet.
    $this->seed(\Database\Seeders\ConditionSeeder::class);
    $elixir = \App\Models\Objet::where('nom', 'Élixir de pas d\'araignée')->firstOrFail();
    $sorts->appliquerBuffPotion($partie['heros']->fresh(), $elixir);
    expect($pont->jet($partie['quete']->fresh(), $partie['heros']->fresh(), $etat->fresh(), [['x' => 8, 'y' => 4]]))->toBeNull();
});

// ---------------------------------------------------------------- Jungles of Delthrak : le cristal (q. 4 E, p. 3)

it('relèvement : un gobelin vaincu dans la salle se relève en Crâne putride, tant que le drapeau du cristal n\'est pas posé', function (bool $cristalDetruit) {
    $campagne = campagneE2([
        'monstres' => [['slug' => 'gobelin', 'x' => 9, 'y' => 3]],
        'regles' => ['relevements' => [['salle' => 'galerie', 'de' => 'gobelin', 'en' => 'crane-putride', 'sauf_drapeau' => 'cristal_detruit']]],
    ]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['relevements' => [['salle' => 'crypte', 'de' => 'gobelin', 'en' => 'crane-putride']]]])))->toBe([ValidationCampagne::SALLE_INCONNUE])
        ->and(codesE2(campagneE2(['regles' => ['relevements' => [['salle' => 'galerie', 'de' => 'gobelin', 'en' => 'licorne']]]])))->toBe([ValidationCampagne::CATALOGUE_INCONNU]);
    $partie = partieE2($this, $campagne);
    if ($cristalDetruit) {
        $partie['quete']->update(['drapeaux' => ['cristal_detruit' => true]]);
    }

    frapperE2($partie, []);
    $figures = $partie['quete']->instancesMonstres()->with('monstre')->orderBy('id')->get();

    expect($figures->first()->etat)->toBe('vaincu');
    if ($cristalDetruit) {
        expect($figures)->toHaveCount(1);
    } else {
        $releve = $figures->last();
        expect($figures)->toHaveCount(2)
            ->and($releve->monstre->nom_base)->toBe('Crâne putride')
            ->and([(int) $releve->position_x, (int) $releve->position_y, $releve->etat])->toBe([9, 3, 'actif']);
    }
})->with(['cristal debout : il se relève' => [false], 'cristal détruit : il reste à terre' => [true]]);

it('amas de cristal : +1 à Canaliser l\'Effroi d\'un monstre à son contact, en quête construite, tant qu\'il est debout', function () {
    $partie = partieE2($this, campagneE2([
        'mobilier' => [['slug' => 'amas-de-cristal', 'x' => 9, 'y' => 2, 'rotation' => 0]],
        'monstres' => [['slug' => 'orque', 'x' => 8, 'y' => 2], ['slug' => 'orque', 'x' => 7, 'y' => 5]],
    ]));
    $quete = $partie['quete']->fresh();
    [$contre, $loin] = $quete->instancesMonstres()->with('monstre')->orderBy('id')->get()->all();
    $canaliser = \App\Models\SortDread::where('nom', 'Canaliser l\'Effroi')->firstOrFail();
    $dread = app(\App\Partie\MoteurDread::class);

    expect($dread->bonusCristauxAdjacents($canaliser, $quete, $contre))->toBe(1)
        ->and($dread->bonusCristauxAdjacents($canaliser, $quete, $loin))->toBe(0)
        ->and($dread->bonusCristauxAdjacents(\App\Models\SortDread::where('nom', '!=', 'Canaliser l\'Effroi')->firstOrFail(), $quete, $contre))->toBe(0);

    // Détruit, il ne compte plus.
    app(\App\Partie\MoteurMobilier::class)->detruire($quete->carte, 0);
    expect($dread->bonusCristauxAdjacents($canaliser, $quete->fresh(), $contre))->toBe(0);
});

// ---------------------------------------------------------------- Jungles of Delthrak : la faille planaire (q. 14)

it('faille : à chaque tour du MJ un monstre tiré au hasard ; scellée, tous les monstres tombent et plus rien ne sort', function () {
    $liste = ['raptor', 'crane-putride', 'zombie', 'squelette'];
    $campagne = campagneE2([
        'monstres' => [['slug' => 'orque', 'x' => 10, 'y' => 5]],
        'notes' => [
            ['repere' => 'F', 'une_fois' => false, 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1], 'sauf_drapeau' => 'faille_scellee'],
                'alors' => [['effet' => 'apparition', 'au_hasard' => true, 'monstres' => array_map(fn ($s) => ['slug' => $s, 'x' => 9, 'y' => 3], $liste)]]],
            ['repere' => 'S', 'quand' => ['declencheur' => 'objet_jete', 'cible' => ['objet' => 'dague']],
                'alors' => [['effet' => 'drapeau', 'nom' => 'faille_scellee'], ['effet' => 'vaincre_tous']]],
        ],
    ]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $notes = app(\App\Partie\Classique\NotesDeQuete::class);

    $notes->signaler($quete->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 1]);
    $notes->signaler($quete->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 2]);
    $sortis = $quete->instancesMonstres()->with('monstre')->orderBy('id')->get()->slice(1);
    expect($sortis)->toHaveCount(2)
        ->and($sortis->every(fn ($m) => in_array(\App\Partie\Classique\CatalogueConstructeur::slug($m->monstre->nom_base), $liste, true)))->toBeTrue();

    $notes->signaler($quete->fresh(), \App\Engine\MotsClesQuete::OBJET_JETE, ['objet' => 'dague'], $partie['heros']);
    expect($quete->instancesMonstres()->where('etat', 'actif')->count())->toBe(0);

    $notes->signaler($quete->fresh(), \App\Engine\MotsClesQuete::TOUR_DU_MJ, ['tour' => 3]);
    expect($quete->instancesMonstres()->count())->toBe(3);
});

// ---------------------------------------------------------------- Jungles of Delthrak : le Tacticien (p. 48)

it('tacticien : +1 dé contre un héros au contact ou en diagonale d\'un autre monstre ; un monstre ordinaire n\'a pas ce dé', function (string $assaillant, int $bonus) {
    $partie = partieE2($this, campagneE2(['monstres' => [['slug' => $assaillant, 'x' => 9, 'y' => 3], ['slug' => 'orque', 'x' => 7, 'y' => 2]]]));
    $quete = $partie['quete']->fresh();
    $quete->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    $quete->instancesMonstres()->update(['revele' => true, 'etat' => 'actif']);
    $instance = $quete->instancesMonstres()->with('monstre')->orderBy('id')->firstOrFail();

    $frappe = app(ResolveurTour::class)->frappeDuSurgissement($partie['groupe'], $instance, $quete->etatsPersonnages()->firstOrFail());

    expect($frappe['bonus_flanc'])->toBe($bonus);
})->with(['Raptor (tacticien), flanqueur en diagonale' => ['raptor', 1], 'Orque ordinaire' => ['orque', 0]]);

// ---------------------------------------------------------------- The Crypt of Perpetual Darkness

it('jet de défense contre N points : « roll their Defend dice against 3 points of damage » — chaque bouclier blanc pare', function () {
    $campagne = campagneE2(['notes' => [['repere' => 'B', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
        'alors' => [['effet' => 'degats', 'body' => 3, 'jet_defense' => true]]]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['notes' => [['repere' => 'B', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'degats', 'body' => 3, 'jet_defense' => 'oui']]]]])))->toBe([ValidationCampagne::TYPE_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    $defense = app(\App\Partie\MoteurSorts::class)->desDefenseHeros($partie['heros']->fresh());
    // Un bouclier blanc (4), le reste des crânes (1).
    app()->instance(LanceurDes::class, new LanceurDeterministe([4, ...array_fill(0, 30, 1)]));

    $notes = app(\App\Partie\Classique\NotesDeQuete::class)->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::FOUILLE_TRESOR,
        ['salle' => 1], $partie['heros']);
    $effet = $notes[0]['effets'][0];

    expect($effet)->toMatchArray(['pares' => 1, 'degats' => 2, 'body' => 3])
        ->and($effet['defense'])->toHaveCount($defense)
        ->and((int) $partie['heros']->fresh()->pv_body)->toBe(28);
});

it('le plus proche : la fouille de pièges ne trouve que le plus proche des pièges marqués ; les autres, comme toujours', function () {
    $campagne = campagneE2(['pieges' => [
        ['slug' => 'fosse', 'x' => 9, 'y' => 3, 'le_plus_proche' => true],
        ['slug' => 'fosse', 'x' => 10, 'y' => 5, 'le_plus_proche' => true],
        ['slug' => 'fosse', 'x' => 7, 'y' => 5],
    ]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete']->fresh();
    $quete->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
    $grille = $quete->carte->grille;

    $reveles = app(\App\Partie\MoteurPieges::class)->revelerAutour($partie['groupe'], $quete->carte, $partie['heros'],
        \App\Partie\ZoneFouille::de($grille, 8, 3));

    expect(collect($reveles)->map(fn ($p) => [$p['x'], $p['y']])->sort()->values()->all())->toBe([[7, 5], [9, 3]]);
});

it('glissade : jusqu\'à la case X, déplacement fini, action restante ; X occupée : la case libre la plus proche, et 2 dés pour l\'occupant', function (bool $occupee) {
    $campagne = campagneE2([
        'regles' => ['glissades' => [['x' => 7, 'y' => 3, 'vers' => ['x' => 10, 'y' => 5]]]],
        'monstres' => $occupee ? [['slug' => 'orque', 'x' => 10, 'y' => 5, 'pv_body' => 9]] : [],
        'portes' => [['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale', 'ouverte' => true],
            ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'], ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete']],
    ]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['glissades' => [['x' => 7, 'y' => 3, 'vers' => ['x' => 7, 'y' => 3]]]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 5, 'position_y' => 3]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    // S'il bute : deux crânes pour l'occupant (tous les dés valent 1).
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 30, 1)));
    $resultat = $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => 8, 'y' => 3]])
        ->assertAccepted()->json('resultat');

    $etat = $partie['quete']->etatsPersonnages()->first();
    $annonce = collect($resultat['annonces_automatiques'] ?? [])->firstWhere('type', 'glissade');
    expect($annonce)->not->toBeNull()
        ->and((int) $etat->deplacement_restant)->toBe(0)->and((bool) $etat->a_agi)->toBeFalse();
    if ($occupee) {
        $orque = $partie['quete']->instancesMonstres()->first();
        expect([(int) $etat->position_x, (int) $etat->position_y])->not->toBe([10, 5])
            ->and(max(abs((int) $etat->position_x - 10), abs((int) $etat->position_y - 5)))->toBe(1)
            ->and($annonce['percute']['degats'])->toBe(2)->and((int) $orque->pv_body)->toBe(7);
    } else {
        expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([10, 5]);
    }
})->with(['libre' => [false], 'occupée par un orque' => [true]]);

// ---------------------------------------------------------------- Wizards of Morcar

it('increvable : jamais sous 1 PV ; le drapeau posé (le cœur détruit), il tombe', function () {
    $campagne = campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'pv_body' => 2, 'increvable' => ['sauf_drapeau' => 'coeur_detruit']]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'increvable' => ['sauf' => 'x']]]])))->not->toBe([]);
    $partie = partieE2($this, $campagne);
    $orque = $partie['quete']->instancesMonstres()->with('monstre')->firstOrFail();
    $degats = app(\App\Partie\MoteurDegats::class);

    $coup = $degats->infligerAMonstre($orque, 5, \App\Partie\MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($coup['survie_increvable'])->toBeTrue()->and((int) $orque->fresh()->pv_body)->toBe(1)->and($orque->fresh()->etat)->toBe('actif');

    $partie['quete']->update(['drapeaux' => ['coeur_detruit' => true]]);
    $degats->infligerAMonstre($orque->fresh()->setRelation('quete', $partie['quete']->fresh()), 5, \App\Partie\MoteurDegats::SOURCE_ATTAQUE_HEROS);
    expect($orque->fresh()->etat)->toBe('vaincu');
});

it('salle ténébreuse : −2 dés en attaque et en défense dans la salle, sauf les épargnés ; aucun tir depuis la salle', function () {
    $campagne = campagneE2([
        'regles' => ['salle_tenebreuse' => ['salle' => 'galerie', 'epargnes' => ['squelette']]],
        'monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'attaque' => 4, 'defense' => 3], ['slug' => 'squelette', 'x' => 10, 'y' => 3],
            ['slug' => 'archer-elfe', 'x' => 9, 'y' => 5], ['slug' => 'orque', 'x' => 4, 'y' => 3, 'attaque' => 4]],
    ]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['salle_tenebreuse' => ['salle' => 'crypte']]])))->toBe([ValidationCampagne::SALLE_INCONNUE]);
    $partie = partieE2($this, $campagne);
    [$orque, $squelette, $archer, $dehors] = $partie['quete']->instancesMonstres()->with('monstre')->orderBy('id')->get()->all();

    expect($orque->attaqueEffective())->toBe(2)->and($orque->defenseEffective())->toBe(1)
        ->and($squelette->attaqueEffective())->toBe((int) $squelette->monstre->attaque)
        ->and($dehors->attaqueEffective())->toBe(4)
        ->and($archer->attaqueDistanceEffective())->toBeNull();

    $sorts = app(\App\Partie\MoteurSorts::class);
    $partie['quete']->etatsPersonnages()->update(['position_x' => 4, 'position_y' => 4]);
    $dehorsDef = $sorts->desDefenseHeros($partie['heros']->fresh());
    expect($sorts->tirInterdit($partie['heros']->fresh()))->toBeFalse();
    $partie['quete']->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 4]);
    expect($sorts->desDefenseHeros($partie['heros']->fresh()))->toBe(max(min($dehorsDef, 1), $dehorsDef - 2))
        ->and($sorts->tirInterdit($partie['heros']->fresh()))->toBeTrue();
});

it('mur illusoire : un bloc qui bloque, jusqu\'à ce qu\'un héros à son contact le touche — gratuitement ; il se dissipe', function () {
    $campagne = campagneE2(['blocs' => [['x' => 8, 'y' => 7, 'illusoire' => true]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['blocs' => [['x' => 8, 'y' => 7, 'illusoire' => 'oui']]])))->toBe([ValidationCampagne::TYPE_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    expect(\App\Partie\FabriqueGrille::pour($quete->fresh())->estTraversable(8, 7))->toBeFalse();

    $quete->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 6]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $options = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id');
    expect($options)->toContain('toucher_mur_8_7');

    $resultat = $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'toucher_mur_8_7'])->assertAccepted()->json('resultat');
    $etat = $quete->etatsPersonnages()->first();
    expect($resultat['type'])->toBe('mur_illusoire')
        ->and(\App\Partie\FabriqueGrille::pour($quete->fresh())->estTraversable(8, 7))->toBeTrue()
        ->and((bool) $etat->a_agi)->toBeFalse()->and((bool) $etat->a_deplace)->toBeFalse();
});

it('coffres renforcés : loin du coffre, la carte du paquet et la note attend ; au contact, la note du coffre', function () {
    $campagne = campagneE2([
        'mobilier' => [['slug' => 'coffre', 'x' => 10, 'y' => 2, 'rotation' => 0]],
        'notes' => [['repere' => 'C', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']], 'alors' => [['effet' => 'or', 'or' => 400]]]],
    ]);
    $campagne['coffres_renforces'] = true;
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2([...$campagne, 'coffres_renforces' => 'oui']))->toBe([ValidationCampagne::TYPE_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $etat = $quete->etatsPersonnages()->first();
    $renforces = app(\App\Partie\Classique\CoffresRenforces::class);

    $etat->update(['position_x' => 10, 'position_y' => 3]);
    expect($renforces->noteRetenue($quete->fresh(), $etat->fresh(), 1))->toBeFalse();

    $resultat = fouillerE2($this, $partie); // en (8, 3), loin du coffre
    expect($resultat['issue'])->not->toBe('note')
        ->and((int) $partie['heros']->fresh()->or)->not->toBe(400)
        ->and((array) $quete->fresh()->notes_jouees)->toBe([]);
});

it('labyrinthe : le trajet s\'arrête au tunnel, deux dés, un menu forcé ; le dé de l\'entrée mène à la sortie, un autre au tunnel de ce numéro', function () {
    $campagne = campagneE2([
        'regles' => ['labyrinthe' => ['tunnels' => [['numero' => 1, 'x' => 8, 'y' => 3], ['numero' => 2, 'x' => 3, 'y' => 4]], 'sortie' => ['x' => 10, 'y' => 5]]],
        'portes' => [['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale', 'ouverte' => true],
            ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'], ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete']],
    ]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['labyrinthe' => ['tunnels' => [['numero' => 7, 'x' => 8, 'y' => 3]], 'sortie' => ['x' => 10, 'y' => 5]]]])))
            ->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $quete->etatsPersonnages()->update(['position_x' => 5, 'position_y' => 3]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 30, 1))); // deux 1 : le numéro de l'entrée
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => 10, 'y' => 3]])->assertAccepted();

    $etat = $quete->etatsPersonnages()->first();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([8, 3])->and((int) $etat->deplacement_restant)->toBe(0);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $options = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id')->all();
    expect($options)->toBe(['sortir_labyrinthe_1']);

    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'sortir_labyrinthe_1'])->assertAccepted();
    expect([(int) $etat->fresh()->position_x, (int) $etat->fresh()->position_y])->toBe([10, 5])
        ->and(\App\Partie\Classique\Labyrinthe::enAttente($quete->fresh(), $partie['heros']))->toBeNull();

    // Un autre numéro : au plus près du tunnel de ce numéro.
    app()->instance(LanceurDes::class, new LanceurDeterministe([2, 5, ...array_fill(0, 30, 1)]));
    $labyrinthe = app(\App\Partie\Classique\Labyrinthe::class);
    $labyrinthe->entrer($partie['groupe'], $quete->fresh(), $partie['heros'], ['x' => 8, 'y' => 3]);
    expect(collect($labyrinthe->choix($quete->fresh(), $partie['heros']))->pluck('de')->all())->toBe([2, 5]);
    $sortie = $labyrinthe->sortir($partie['groupe'], $quete->fresh(), $partie['heros'], $etat->fresh(), 2);
    expect([$sortie['x'], $sortie['y']])->toBe([3, 4]);
});

it('embuscade en coffre : déguisé au départ ; la fouille du trésor de sa salle le révèle au contact du fouilleur, qui est frappé', function () {
    $campagne = campagneE2(['monstres' => [['slug' => 'dreadshifter', 'x' => 10, 'y' => 2, 'deguise' => 'coffre']]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['monstres' => [['slug' => 'orque', 'x' => 10, 'y' => 2, 'deguise' => 'coffre']]])))->toBe([ValidationCampagne::VALEUR_INVALIDE])
        ->and(codesE2(campagneE2(['monstres' => [['slug' => 'dreadshifter', 'x' => 10, 'y' => 2, 'deguise' => ['porte' => 'x']]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $instance = $quete->instancesMonstres()->firstOrFail();
    expect(\App\Partie\MoteurEmbuscade::estDeguise($instance))->toBeTrue()
        ->and(collect($quete->fresh()->carte->grille['mobilier'])->contains(fn ($m) => (int) ($m['embuscade_instance_id'] ?? 0) === (int) $instance->id))->toBeTrue();

    $resultat = fouillerE2($this, $partie); // en (8, 3), dans la salle
    $instance->refresh();
    $annonces = collect($resultat['annonces_automatiques'] ?? []);
    expect($instance->revele)->toBeTrue()->and(\App\Partie\MoteurEmbuscade::estDeguise($instance))->toBeFalse()
        ->and(max(abs((int) $instance->position_x - 8), abs((int) $instance->position_y - 3)))->toBe(1)
        ->and($annonces->firstWhere('type', 'embuscade')['cause'] ?? null)->toBe('tresor')
        ->and($annonces->pluck('type'))->toContain('attaque_monstre');
});

it('embuscade en porte : une porte fermée publiée comme une vraie ; la fouille ou le tour du MJ la révèle', function (string $declencheur) {
    $campagne = campagneE2(['monstres' => [['slug' => 'dreadshifter', 'x' => 9, 'y' => 5, 'deguise' => ['porte' => 's']]]]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $instance = $quete->instancesMonstres()->firstOrFail();
    expect(\App\Partie\Classique\EmbuscadesClassiques::faussesPortes($quete->fresh()))->toBe([['x' => 9, 'y' => 5, 'cote' => 's']]);

    $embuscades = app(\App\Partie\Classique\EmbuscadesClassiques::class);
    if ($declencheur === 'fouille') {
        $quete->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);
        $embuscades->parFouille($partie['groupe'], $quete->fresh(), $partie['heros'], \App\Partie\ZoneFouille::de($quete->fresh()->carte->grille, 8, 3));
    } else {
        $quete->marquerSalleDecouverte(1);
        $embuscades->auTourDuMJ($partie['groupe'], $quete->fresh());
    }

    expect($instance->fresh()->revele)->toBeTrue()
        ->and(\App\Partie\Classique\EmbuscadesClassiques::faussesPortes($quete->fresh()))->toBe([]);
})->with(['fouille' => ['fouille'], 'tour du MJ' => ['tour_du_mj']]);

it('neutre : attaquable, elle s\'éveille au premier coup, elle seule ; une note « éveiller » retourne les autres', function () {
    $campagne = campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'pv_body' => 9, 'neutre' => true],
        ['slug' => 'orque', 'x' => 10, 'y' => 5, 'neutre' => true]],
        'notes' => [['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']], 'alors' => [['effet' => 'eveiller', 'monstres' => [1]]]]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'neutre' => true, 'inactif' => true]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $dormants = app(\App\Partie\MoteurDormants::class);
    [$krothgar, $autre] = $partie['quete']->instancesMonstres()->with('monstre')->orderBy('id')->get()->all();
    expect($dormants->estDormant($krothgar))->toBeTrue()->and($dormants->estIntouchable($krothgar))->toBeFalse();

    frapperE2($partie, []);
    expect($dormants->estDormant($krothgar->fresh()))->toBeFalse()->and($dormants->estDormant($autre->fresh()))->toBeTrue();

    app(\App\Partie\Classique\NotesDeQuete::class)->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::FOUILLE_TRESOR, ['salle' => 1], $partie['heros']);
    expect($dormants->estDormant($autre->fresh()))->toBeFalse();
});

it('golems et prière : un bouclier noir pare tout ; l\'artefact va au héros qui a prié', function () {
    $campagne = campagneE2([
        'monstres' => [['slug' => 'golem', 'x' => 9, 'y' => 3, 'capacites' => ['bouclier_noir_pare_tout'], 'pv_body' => 9]],
        'gestes' => [['libelle' => 'Prier', 'salle' => 'galerie', 'choix' => [['libelle' => 'la gloire', 'alors' => [['effet' => 'drapeau', 'nom' => 'gloire']]],
            ['libelle' => 'la sécurité', 'alors' => [['effet' => 'texte', 'texte' => 'Rien ne bouge.']]]]]],
        'notes' => [['repere' => 'G', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'objet', 'slug' => 'potion-de-soin', 'au_heros_du_geste' => 0]]]],
    ]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['notes' => [['repere' => 'G', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'objet', 'slug' => 'potion-de-soin', 'au_heros_du_geste' => 3]]]]])))->toBe([ValidationCampagne::CIBLE_INCONNUE]);
    $partie = partieE2($this, $campagne);
    $golem = $partie['quete']->instancesMonstres()->with('monstre')->firstOrFail();

    // Trois crânes d'attaque (1), puis la défense : un bouclier noir (6) parmi des crânes — tout est paré.
    $resultat = frapperE2($partie, [1, 1, 1, 6]);
    expect((int) $golem->fresh()->pv_body)->toBe(9);

    $partie['quete']->update(['drapeaux' => ['_gestes' => [0 => [(int) $partie['heros']->id]]]]);
    app(\App\Partie\Classique\NotesDeQuete::class)->signaler($partie['quete']->fresh(), \App\Engine\MotsClesQuete::FOUILLE_TRESOR, ['salle' => 1], null);
    expect($partie['heros']->inventaire()->whereHas('objet', fn ($q) => $q->where('nom', 'Potion de soin'))->exists())->toBeTrue();
});

it('sorts déjà lancés : « with Hammer of Ruin and Scrolls of Morcar already cast on her » — posés au départ', function () {
    $campagne = campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'sorts_actifs' => ['marteau-de-la-ruine', 'parchemins-de-morcar']]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['monstres' => [['slug' => 'orque', 'x' => 9, 'y' => 3, 'sorts_actifs' => ['eclair-de-chaos']]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    $gardienne = $partie['quete']->instancesMonstres()->with('monstre')->firstOrFail();

    expect((int) $gardienne->etatDread('jetons_ombre', 0))->toBe(3)
        ->and($gardienne->attaqueEffective())->toBe((int) $gardienne->monstre->attaque + 2);
});

it('errant « tour d\'une figure posée » : la carte fait jouer la plus proche encore debout ; aucune, rien', function (bool $disponible) {
    $campagne = campagneE2(['monstres' => [['slug' => 'orque', 'x' => 3, 'y' => 3]], 'monstre_errant' => ['tour_de' => [0]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['monstre_errant' => ['tour_de' => [3]]])))->toBe([ValidationCampagne::CIBLE_INCONNUE]);
    $partie = partieE2($this, $campagne);
    $orque = $partie['quete']->instancesMonstres()->firstOrFail();
    $orque->update($disponible ? ['revele' => true] : ['etat' => 'vaincu']);
    empilerCarteFouille($partie['quete'], ['issue' => 'errant']);

    $resultat = fouillerE2($this, $partie);

    expect($partie['quete']->instancesMonstres()->count())->toBe(1);
    if ($disponible) {
        expect($resultat['tour_errant']['instance_id'])->toBe((int) $orque->id)
            ->and(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($resultat, 'Albrecht'))->pluck('texte')->first())->toContain('joue un tour');
    } else {
        expect($resultat['errant_indisponible'] ?? false)->toBeTrue();
    }
})->with(['disponible' => [true], 'tombé' => [false]]);

it('deux cartes au choix : « may take two treasure cards and choose one to keep » — menu forcé, l\'autre sous le paquet', function () {
    $campagne = campagneE2(['notes' => [['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
        'alors' => [['effet' => 'cartes_au_choix']]]]]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['notes' => [['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'galerie']],
            'alors' => [['effet' => 'cartes_au_choix', 'nombre' => 5]]]]])))->toBe([ValidationCampagne::VALEUR_INVALIDE]);
    $partie = partieE2($this, $campagne);
    empilerCarteFouille($partie['quete'], ['issue' => 'tresor', 'or' => 120]);
    empilerCarteFouille($partie['quete'], ['issue' => 'tresor', 'or' => 50]);

    fouillerE2($this, $partie);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $options = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'));
    expect($options->pluck('libelle')->all())->toBe(['Garder : 50 pièces d\'or', 'Garder : 120 pièces d\'or']);

    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'garder_carte_1'])->assertAccepted();
    $deck = $partie['quete']->fresh()->deckFouille();
    expect((int) $partie['heros']->fresh()->or)->toBe(120)
        ->and(end($deck))->toBe(['issue' => 'tresor', 'or' => 50]);
});

it('labyrinthe, les monstres : hors de portée, il gagne un tunnel et surgit au contact d\'un héros d\'une zone découverte', function () {
    $campagne = campagneE2([
        'regles' => ['labyrinthe' => ['tunnels' => [['numero' => 1, 'x' => 3, 'y' => 4], ['numero' => 2, 'x' => 10, 'y' => 5]], 'sortie' => ['x' => 10, 'y' => 4]]],
        'monstres' => [['slug' => 'orque', 'x' => 3, 'y' => 3]],
    ]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $quete->etatsPersonnages()->update(['position_x' => 9, 'position_y' => 3]);
    $quete->marquerSalleDecouverte(1);
    $orque = $quete->instancesMonstres()->with('monstre')->firstOrFail();
    $grille = \App\Partie\FabriqueGrille::pour($quete->fresh(), exceptInstanceId: (int) $orque->id);
    $labyrinthe = app(\App\Partie\Classique\Labyrinthe::class);

    // À portée par le chemin normal (un chemin court) : il marche, il ne voyage pas.
    expect($labyrinthe->voyageDuMonstre($partie['groupe'], $quete->fresh(), $orque, $grille, [null, [['x' => 4, 'y' => 3]]]))->toBeNull();

    $voyage = $labyrinthe->voyageDuMonstre($partie['groupe'], $quete->fresh(), $orque, $grille, null);
    $orque->refresh();
    expect($voyage['depuis'])->toBe(1)
        ->and(\App\Partie\Grille::sontAdjacentes((int) $orque->position_x, (int) $orque->position_y, 9, 3))->toBeTrue();
});

// ---------------------------------------------------------------- First Light : les portails (q. 2)

it('portails : franchir l\'arête arrête la marche, menu forcé 1-7, sortie sur une case du portail choisi ; deux passages le même tour coûtent 1 PV et le tour', function () {
    $campagne = campagneE2([
        'portes' => [['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale', 'ouverte' => true],
            ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale', 'ouverte' => true], ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete']],
        'regles' => ['portails' => [['porte' => 0, 'numero' => 7], ['porte' => 1, 'numero' => 3]]],
    ]);
    expect(codesE2($campagne))->toBe([])
        ->and(codesE2(campagneE2(['regles' => ['portails' => [['porte' => 9, 'numero' => 7]]]])))->toBe([ValidationCampagne::CIBLE_INCONNUE]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $quete->etatsPersonnages()->update(['position_x' => 5, 'position_y' => 3]);
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk();
    $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => 8, 'y' => 3]])->assertAccepted();

    $etat = $quete->etatsPersonnages()->first();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([6, 3]);
    $restantAvant = (int) $etat->deplacement_restant;
    \Illuminate\Support\Facades\Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($partie['groupe'], $partie['heros']));
    $options = collect($this->getJson("/api/groupes/{$partie['code']}/menu")->assertOk()->json('menu.options'))->pluck('id')->all();
    expect($options)->toBe(['traverser_portail_3', 'traverser_portail_7']);

    $resultat = $this->postJson("/api/groupes/{$partie['code']}/choix", ['option_id' => 'traverser_portail_3'])->assertAccepted()->json('resultat');
    $etat->refresh();
    expect($resultat['numero'])->toBe(3)
        ->and(in_array([(int) $etat->position_x, (int) $etat->position_y], [[3, 1], [3, 2]], true))->toBeTrue()
        ->and((int) $etat->deplacement_restant)->toBe(max(0, $restantAvant - 1));

    // Un second passage le même tour : 1 PV, et le tour s'arrête.
    $portails = app(\App\Partie\Classique\Portails::class);
    $portails->interrompre($quete->fresh(), $partie['heros'], $etat->fresh(), ['numero' => 3], ['x' => 3, 'y' => 2], 0);
    $second = $portails->traverser($partie['groupe'], $quete->fresh(), $partie['heros'], $etat->fresh(), 7);
    expect($second['fin_de_tour'])->toBeTrue()->and($second['degats'])->toBe(1)
        ->and(\App\Partie\Classique\FinDeTourForcee::demandee($etat->fresh()))->toBeTrue();
});

it('portails, les monstres : hors de portée, il passe par un portail déjà utilisé et ressort au plus près des héros', function () {
    $campagne = campagneE2([
        'portes' => [['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale', 'ouverte' => true],
            ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale', 'ouverte' => true], ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete']],
        'regles' => ['portails' => [['porte' => 0, 'numero' => 7], ['porte' => 1, 'numero' => 3]]],
        'monstres' => [['slug' => 'orque', 'x' => 3, 'y' => 3]],
    ]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $quete->etatsPersonnages()->update(['position_x' => 9, 'position_y' => 3]);
    $orque = $quete->instancesMonstres()->with('monstre')->firstOrFail();
    $grille = \App\Partie\FabriqueGrille::pour($quete->fresh(), exceptInstanceId: (int) $orque->id);
    $portails = app(\App\Partie\Classique\Portails::class);

    // Aucun portail encore utilisé : il ne passe pas.
    expect($portails->voyageDuMonstre($partie['groupe'], $quete->fresh(), $orque, $grille, null))->toBeNull();

    $quete->update(['drapeaux' => ['_portails_utilises' => [3, 7]]]);
    $voyage = $portails->voyageDuMonstre($partie['groupe'], $quete->fresh(), $orque, $grille, null);
    expect($voyage['depuis'])->toBe(3)->and($voyage['numero'])->toBe(7)
        ->and([(int) $orque->fresh()->position_x, (int) $orque->fresh()->position_y])->toBe([7, 3]);
});

// ---------------------------------------------------------------- First Light : l'Oracle de Morlenis (q. 3)

it('q. 3 : l\'Oracle bénit ou maudit ; le geste au contact de la bibliothèque, son jet, le premier 1 blesse la salle une fois ; l\'Œil où l\'on ne s\'arrête pas', function () {
    $campagne = campagneE2([
        'mobilier' => [['slug' => 'bibliotheque', 'x' => 7, 'y' => 2, 'rotation' => 0]],
        'regles' => ['cases_sans_arret' => [['x' => 8, 'y' => 4]]],
        'gestes' => [
            ['libelle' => 'Fouiller la bibliothèque', 'salle' => 'galerie', 'pres_du_meuble' => 0, 'sauf_drapeau' => 'visions', 'alors' => [['effet' => 'jet', 'issues' => [
                ['de' => 1, 'a' => 1, 'alors' => [['effet' => 'en_ordre', 'etapes' => [['alors' => [['effet' => 'degats', 'body' => 1, 'toute_la_salle' => true]]]]]]],
                ['de' => 5, 'a' => 6, 'alors' => [['effet' => 'drapeau', 'nom' => 'visions']]],
            ]]]],
            ['libelle' => 'Interroger l\'Oracle', 'salle' => 'galerie', 'par_heros' => true, 'alors' => [['effet' => 'oracle', 'don' => 'benediction']]],
        ],
    ]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $etat = $quete->etatsPersonnages()->first();
    $gestes = app(\App\Partie\Classique\GestesDeQuete::class);

    // Loin de la bibliothèque : seul l'Oracle est offert.
    $etat->update(['position_x' => 7, 'position_y' => 5]);
    expect(collect($gestes->disponibles($quete->fresh(), $partie['heros'], $etat->fresh()))->pluck('geste')->all())->toBe([1]);

    $etat->update(['position_x' => 9, 'position_y' => 3]);
    app()->instance(LanceurDes::class, new LanceurDeterministe([1, 1, ...array_fill(0, 10, 3)]));
    $gestes->faire($quete->fresh(), $partie['heros'], $etat->fresh(), 0, null);
    $gestes->faire($quete->fresh(), $partie['heros'], $etat->fresh(), 0, null);
    expect((int) $partie['heros']->fresh()->pv_body)->toBe(29); // le second 1 ne blesse plus

    $gestes->faire($quete->fresh(), $partie['heros'], $etat->fresh(), 1, null);
    expect((bool) $partie['heros']->fresh()->benediction_oracle)->toBeTrue()
        ->and(\App\Partie\FabriqueGrille::pour($quete->fresh())->arretInterdit(8, 4))->toBeTrue();
});

// ---------------------------------------------------------------- First Light : les éclaireurs tombés (q. 1)

it('q. 1 : un marqueur se fouille au contact pour une action, son jet paie, le billet ne se lit qu\'une fois, puis il disparaît', function () {
    $campagne = campagneE2([
        'marqueurs' => ['libelle' => 'Fouiller l\'éclaireur', 'cases' => [['x' => 8, 'y' => 4], ['x' => 10, 'y' => 5]], 'alors' => [
            ['effet' => 'en_ordre', 'etapes' => [['alors' => [['effet' => 'drapeau', 'nom' => 'billet']]]]],
            ['effet' => 'jet', 'issues' => [
                ['de' => 1, 'a' => 2, 'alors' => [['effet' => 'texte', 'texte' => 'Rien.']]],
                ['de' => 3, 'a' => 4, 'alors' => [['effet' => 'or', 'or' => 15]]],
                ['de' => 5, 'a' => 6, 'alors' => [['effet' => 'or', 'or' => 40]]],
            ]],
        ]],
    ]);
    expect(codesE2($campagne))->toBe([]);
    $partie = partieE2($this, $campagne);
    $quete = $partie['quete'];
    $etat = $quete->etatsPersonnages()->first();
    $etat->update(['position_x' => 8, 'position_y' => 3]);
    $menu = app(\App\Partie\MenuMoteur::class);
    $ids = fn () => collect($menu->generer($partie['groupe']->fresh(), $partie['heros']->fresh())['options'] ?? [])->pluck('id')->all();
    expect($ids())->toContain('fouiller_marqueur_8_4')->not->toContain('fouiller_marqueur_10_5');

    $orAvant = (int) $partie['heros']->fresh()->or;
    app()->instance(LanceurDes::class, new LanceurDeterministe(array_fill(0, 10, 5)));
    $r = app(\App\Partie\Classique\MarqueursFouillables::class)->fouiller($quete->fresh(), $partie['heros'], $etat->fresh(), 8, 4);
    expect($r['type'])->toBe('fouille_marqueur')
        ->and(\App\Partie\Classique\MarqueursFouillables::restants($quete->fresh()))->toBe([['x' => 10, 'y' => 5]])
        ->and(data_get($quete->fresh()->drapeaux, 'billet'))->not->toBeNull()
        ->and((int) $partie['heros']->fresh()->or)->toBe($orAvant + 40)
        ->and($ids())->not->toContain('fouiller_marqueur_8_4');
    expect(fn () => app(\App\Partie\Classique\MarqueursFouillables::class)->fouiller($quete->fresh(), $partie['heros'], $etat->fresh(), 10, 5))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
