<?php

declare(strict_types=1);

use App\Engine\MotsClesQuete;
use App\Models\Groupe;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\MoteurPortes;
use App\Partie\RangementObjet;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Classique\DocumentExemple;

/*
 * PORTES VERROUILLÉES ET TOUR DU MJ (lot K5a, `docs/plan-mode-classique.md` §2 — E6, D5). Les verrous que le moteur joue
 * déjà sur une carte générée (`MoteurPortes`), offerts à l'auteur d'une quête construite, plus la porte que seule une
 * note ouvre ; le déclencheur `tour_du_mj` et l'effet `ouvrir_porte`.
 *
 * Plateau et quête : ceux de `DocumentExemple`, INVENTÉS. La porte 0 est le bord commun d'`entree` et de `galerie`, en
 * (6, 3) côté est ; le héros se tient contre elle, en (6, 3).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

/** La campagne d'exemple, la porte 0 de la quête 1 verrouillée par `$verrou`, et ces notes. */
function campagneVerrouillee(array $verrou, array $notes = []): array
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['portes'][0]['verrou'] = $verrou;
    if ($notes !== []) {
        $campagne['quetes'][0]['notes'] = $notes;
    }

    return $campagne;
}

function partieVerrouillee(object $test, array $campagne): array
{
    app(CampagnesConstruites::class)->enregistrer($campagne);
    $alice = connecterJoueur('alice');
    $heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $code = $test->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $groupe = Groupe::where('identifiant', $code)->firstOrFail();
    $test->postJson("/api/groupes/{$code}/quetes")->assertCreated();
    $quete = Quete::findOrFail($groupe->refresh()->quete_courante_id);
    $quete->etatsPersonnages()->update(['position_x' => 6, 'position_y' => 3]);

    return compact('code', 'groupe', 'quete', 'heros');
}

function optionsPorteVerrouillee(object $test, Groupe $groupe, Personnage $heros): \Illuminate\Support\Collection
{
    Cache::forget(\App\Jobs\GenererMenu::cleDuHeros($groupe, $heros));

    return collect($test->getJson("/api/groupes/{$groupe->identifiant}/menu")->assertOk()->json('menu.options'));
}

it('accepte chaque verrou, et refuse ce qui cloche en le nommant', function (?array $verrou, ?string $code, ?string $chemin = null, string $type = 'normale') {
    $campagne = campagneVerrouillee($verrou ?? []);
    $campagne['quetes'][0]['portes'][0]['type'] = $type;
    if ($verrou === null) {
        unset($campagne['quetes'][0]['portes'][0]['verrou']);
    }

    $refus = app(ValidationCampagne::class)->valider($campagne);
    if ($code === null) {
        expect($refus)->toBe([]);

        return;
    }
    expect(collect($refus)->contains(fn (array $r) => $r['code'] === $code && $r['chemin'] === $chemin))
        ->toBeTrue(json_encode($refus, JSON_UNESCAPED_UNICODE));
})->with([
    'sans verrou' => [null, null],
    'note' => [['type' => 'note'], null],
    'clé' => [['type' => 'cle', 'objet' => 'dague'], null],
    'pierre' => [['type' => 'pierre'], null],
    'condition' => [['type' => 'condition', 'condition' => 'porte_verrouillee'], null],
    'verrou sur une porte secrète' => [['type' => 'note'], ValidationCampagne::VERROU_SUR_PORTE_SECRETE, 'quetes.0.portes.0.verrou', 'secrete'],
    'type inconnu' => [['type' => 'magique'], ValidationCampagne::VALEUR_INVALIDE, 'quetes.0.portes.0.verrou.type'],
    'type absent' => [['objet' => 'dague'], ValidationCampagne::CLE_MANQUANTE, 'quetes.0.portes.0.verrou.type'],
    'clé hors catalogue' => [['type' => 'cle', 'objet' => 'cle-de-nulle-part'], ValidationCampagne::CATALOGUE_INCONNU, 'quetes.0.portes.0.verrou.objet'],
    'clé sans objet' => [['type' => 'cle'], ValidationCampagne::CLE_MANQUANTE, 'quetes.0.portes.0.verrou.objet'],
    'condition inconnue' => [['type' => 'condition', 'condition' => 'pleine_lune'], ValidationCampagne::VALEUR_INVALIDE, 'quetes.0.portes.0.verrou.condition'],
    'paramètre d\'un autre verrou' => [['type' => 'pierre', 'objet' => 'dague'], ValidationCampagne::CLE_INCONNUE, 'quetes.0.portes.0.verrou.objet'],
]);

it('refuse un tour du MJ nul et une porte à ouvrir qui n\'existe pas', function () {
    $refus = app(ValidationCampagne::class)->valider(campagneVerrouillee(['type' => 'note'], [
        ['repere' => 'A', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 0]], 'alors' => [['effet' => 'ouvrir_porte', 'porte' => 9]]],
    ]));

    expect(collect($refus)->map(fn (array $r) => $r['chemin'].' '.$r['code'])->all())
        ->toContain('quetes.0.notes.0.quand.cible.tour '.ValidationCampagne::VALEUR_INVALIDE)
        ->toContain('quetes.0.notes.0.alors.0.porte '.ValidationCampagne::CIBLE_INCONNUE);
});

it('pose chaque verrou sur la carte dans la forme que le moteur lit déjà', function (array $verrou, string $etat, array $attendu) {
    $campagne = campagneVerrouillee($verrou);
    $porte = app(CarteConstruite::class)->produire($campagne, $campagne['quetes'][0])['carte']['portes'][0];

    expect($porte['etat'])->toBe($etat);
    foreach ($attendu as $cle => $valeur) {
        expect($porte['verrou'][$cle])->toBe($valeur === 'id-dague' ? (int) Objet::where('nom', 'Dague')->value('id') : $valeur);
    }
})->with([
    'note' => [['type' => 'note'], MoteurPortes::ETAT_VERROUILLEE, ['type' => 'note']],
    'clé' => [['type' => 'cle', 'objet' => 'dague'], MoteurPortes::ETAT_VERROUILLEE, ['type' => 'cle', 'objet_id' => 'id-dague']],
    'pierre' => [['type' => 'pierre'], MoteurPortes::ETAT_FERMEE, ['type' => 'pierre']],
    'condition' => [['type' => 'condition', 'condition' => 'porte_verrouillee'], MoteurPortes::ETAT_FERMEE,
        ['type' => 'condition', 'nom' => 'porte_verrouillee', 'attribut' => 'corps', 'comparaison' => 'strict', 'des' => 2]],
]);

it('EN JEU : la porte « note » ne s\'ouvre pas à la main ; au 2e tour du MJ la note l\'ouvre et la galerie se révèle', function () {
    $campagne = campagneVerrouillee(['type' => 'note'], [
        ['repere' => 'A', 'quand' => ['declencheur' => MotsClesQuete::TOUR_DU_MJ, 'cible' => ['tour' => 2]],
            'alors' => [['effet' => 'texte', 'texte' => 'Un grincement inventé.'], ['effet' => MotsClesQuete::OUVRIR_PORTE, 'porte' => 0]]],
    ]);
    ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieVerrouillee($this, $campagne);

    // Contre la porte : aucune option ne l'ouvre (le menu n'offre pas ce que le résolveur refuserait).
    expect(optionsPorteVerrouillee($this, $groupe, $heros)->where('type', 'ouvrir_porte')->all())->toBe([]);
    $galerie = collect($quete->carte->grille['salles'])->search(fn (array $s) => $s['id'] === 'galerie');
    expect(in_array($galerie, (array) $quete->refresh()->salles_decouvertes, true))->toBeFalse();

    // Premier tour du MJ : rien.
    $this->postJson("/api/groupes/{$groupe->identifiant}/choix", ['option_id' => 'attendre'])->assertAccepted();
    expect((int) $quete->refresh()->tours_mj)->toBe(1)
        ->and(data_get($quete->carte->fresh()->grille, 'portes.0.etat'))->toBe(MoteurPortes::ETAT_VERROUILLEE);

    // Deuxième : la note joue — la porte s'ouvre d'elle-même, la galerie se révèle, l'annonce le dit.
    optionsPorteVerrouillee($this, $groupe, $heros);
    $this->postJson("/api/groupes/{$groupe->identifiant}/choix", ['option_id' => 'attendre'])->assertAccepted();
    $quete->refresh();
    expect((int) $quete->tours_mj)->toBe(2)
        ->and(data_get($quete->carte->fresh()->grille, 'portes.0.etat'))->toBe(MoteurPortes::ETAT_OUVERTE)
        ->and(in_array($galerie, (array) $quete->salles_decouvertes, true))->toBeTrue()
        ->and($quete->notes_jouees)->toBe([0]);

    $note = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()
        ->first(fn ($e) => ($e->payload['type'] ?? null) === 'note_de_quete');
    expect($note?->payload['declencheur'])->toBe('tour_du_mj')
        ->and($note?->payload['effets'][1])->toBe(['effet' => 'ouvrir_porte', 'porte' => 0, 'ouverte' => true])
        ->and(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($note->payload, 'Le maître du jeu'))->pluck('texte')->all())
        ->toBe(['Un grincement inventé.', 'Une porte s\'ouvre d\'elle-même']);

    // Troisième tour : la note ne rejoue pas (une fois par défaut).
    optionsPorteVerrouillee($this, $groupe, $heros);
    $this->postJson("/api/groupes/{$groupe->identifiant}/choix", ['option_id' => 'attendre'])->assertAccepted();
    expect($quete->refresh()->notes_jouees)->toBe([0]);
});

it('EN JEU : la porte à clé ne s\'ouvre qu\'avec l\'objet, et son ouverture déclenche la note de la porte', function () {
    $campagne = campagneVerrouillee(['type' => 'cle', 'objet' => 'dague'], [
        ['repere' => 'B', 'quand' => ['declencheur' => 'ouverture_porte', 'cible' => ['porte' => 0]], 'alors' => [['effet' => 'or', 'or' => 5]]],
    ]);
    ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieVerrouillee($this, $campagne);
    $orAvant = (int) $heros->fresh()->or;

    expect(optionsPorteVerrouillee($this, $groupe, $heros)->where('type', 'ouvrir_porte')->all())->toBe([]);

    RangementObjet::ranger(Objet::where('nom', 'Dague')->firstOrFail(), (int) $heros->id, 1);
    $option = optionsPorteVerrouillee($this, $groupe, $heros)->firstWhere('type', 'ouvrir_porte');
    expect($option['libelle'] ?? null)->toEndWith('(clé)');

    $this->postJson("/api/groupes/{$groupe->identifiant}/choix", ['option_id' => $option['id']])->assertAccepted()
        ->assertJsonPath('resultat.cause', 'cle');
    expect(data_get($quete->carte->fresh()->grille, 'portes.0.etat'))->toBe(MoteurPortes::ETAT_OUVERTE)
        ->and((int) $heros->fresh()->or)->toBe($orAvant + 5);
});

it('EN JEU : la porte de pierre s\'offre à forcer, pas à ouvrir', function () {
    ['groupe' => $groupe, 'heros' => $heros] = partieVerrouillee($this, campagneVerrouillee(['type' => 'pierre']));

    $options = optionsPorteVerrouillee($this, $groupe, $heros);
    expect($options->where('type', 'ouvrir_porte')->all())->toBe([])
        ->and($options->where('type', 'forcer_porte_pierre')->count())->toBe(1);
});

/*
 * LOT K5b — un BLOC qui tombe (E6) et une porte secrète RÉVÉLÉE (E5), par note. Au premier tour du MJ : un bloc en
 * (8, 7), dans le couloir ; un second sur la case du héros, (6, 3) — prise, rien n'y tombe ; et la porte secrète 2,
 * en (9, 5) côté sud, se dévoile.
 */
it('refuse un bloc dans la roche et une porte à révéler qui n\'est pas secrète', function () {
    $refus = app(ValidationCampagne::class)->valider(campagneVerrouillee(['type' => 'note'], [
        ['repere' => 'A', 'quand' => ['declencheur' => 'tour_du_mj', 'cible' => ['tour' => 1]],
            'alors' => [['effet' => 'bloc', 'x' => 15, 'y' => 3], ['effet' => 'reveler_porte', 'porte' => 1]]],
    ]));

    expect(collect($refus)->map(fn (array $r) => $r['chemin'].' '.$r['code'])->all())
        ->toContain('quetes.0.notes.0.alors.0 '.ValidationCampagne::DANS_SALLE_FERMEE)
        ->toContain('quetes.0.notes.0.alors.1.porte '.ValidationCampagne::VALEUR_INVALIDE);
});

it('EN JEU : un bloc tombe sur la case libre et ferme le passage, pas sur la case prise ; la porte secrète se dévoile', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['notes'] = [
        ['repere' => 'A', 'quand' => ['declencheur' => MotsClesQuete::TOUR_DU_MJ, 'cible' => ['tour' => 1]], 'alors' => [
            ['effet' => MotsClesQuete::BLOC, 'x' => 8, 'y' => 7],
            ['effet' => MotsClesQuete::BLOC, 'x' => 6, 'y' => 3],
            ['effet' => MotsClesQuete::REVELER_PORTE, 'porte' => 2],
        ]],
    ];
    expect(app(ValidationCampagne::class)->valider($campagne))->toBe([]);
    ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieVerrouillee($this, $campagne);
    expect(data_get($quete->carte->grille, 'portes.2.etat'))->toBe(MoteurPortes::ETAT_SECRETE);

    optionsPorteVerrouillee($this, $groupe, $heros);
    $this->postJson("/api/groupes/{$groupe->identifiant}/choix", ['option_id' => 'attendre'])->assertAccepted();

    $quete->refresh();
    $grilleMoteur = \App\Partie\FabriqueGrille::pour($quete);
    expect($grilleMoteur->estTraversable(8, 7))->toBeFalse()
        ->and(collect($quete->carte->grille['pieges'])->where('etat', \App\Partie\MoteurPieges::ETAT_BLOC)->map(fn ($p) => [$p['x'], $p['y']])->values()->all())->toBe([[8, 7]])
        ->and(data_get($quete->carte->grille, 'portes.2.etat'))->toBe(MoteurPortes::ETAT_FERMEE)
        ->and(data_get($quete->carte->grille, 'portes.2.revele'))->toBeTrue();

    $note = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()->first(fn ($e) => ($e->payload['type'] ?? null) === 'note_de_quete');
    expect(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($note->payload, 'Le maître du jeu'))->pluck('texte')->all())->toBe([
        'Un bloc de pierre s\'abat et ferme le passage',
        'Le plafond gronde, mais aucun bloc ne tombe : la case est occupée',
        'Une porte secrète se dévoile',
    ]);

    // Publié : le bloc reste sur la carte, à jamais (la table et la manette le dessinent).
    $pieges = $this->getJson("/api/groupes/{$groupe->identifiant}/etat")->assertOk()->json('carte.pieges');
    expect(collect($pieges)->contains(fn ($p) => $p['x'] === 8 && $p['y'] === 7))->toBeTrue();
});

/*
 * LOT K5f — TÉLÉPORTATION (E8). La case (4, 3) de `entree` porte une note `entree` → `teleporter` en (9, 4), dans la
 * `galerie`. Le héros marche de (2, 3) à (5, 3) : il passe sur (4, 3), est emporté ; la case (5, 3), qu'il n'a pas
 * foulée, a sa propre note qui ne doit PAS jouer.
 */
it('valide la case d\'arrivée d\'une téléportation comme celle d\'une apparition', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['notes'] = [
        ['repere' => 'A', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 4, 'y' => 3]]], 'alors' => [['effet' => 'teleporter', 'x' => 15, 'y' => 3]]],
        ['repere' => 'B', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 3, 'y' => 3]]], 'alors' => [['effet' => 'teleporter', 'x' => 4, 'y' => 4]]],
    ];
    $refus = collect(app(ValidationCampagne::class)->valider($campagne))->map(fn (array $r) => $r['chemin'].' '.$r['code'])->all();

    expect($refus)->toContain('quetes.0.notes.0.alors.0 '.ValidationCampagne::DANS_SALLE_FERMEE)
        ->toContain('quetes.0.notes.1.alors.0 '.ValidationCampagne::CASE_PARTAGEE);
});

it('EN JEU : la case piégée téléporte le héros dans la galerie, révélée ; la suite du trajet ne déclenche rien', function () {
    // Les deux dés rouges du déplacement classique, imposés : un double 1 ne laisse pas atteindre la case visée
    // (test instable jusqu'au 2026-11-23).
    app()->instance(\App\Engine\Des\LanceurDes::class, new \App\Engine\Des\LanceurDeterministe(array_fill(0, 40, 6)));
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['notes'] = [
        ['repere' => 'A', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 4, 'y' => 3]]], 'alors' => [['effet' => MotsClesQuete::TELEPORTER, 'x' => 9, 'y' => 4]]],
        ['repere' => 'B', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 5, 'y' => 3]]], 'alors' => [['effet' => 'or', 'or' => 50]]],
    ];
    expect(app(ValidationCampagne::class)->valider($campagne))->toBe([]);
    ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieVerrouillee($this, $campagne);
    $quete->etatsPersonnages()->update(['position_x' => 2, 'position_y' => 3]);
    $orAvant = (int) $heros->fresh()->or;
    $galerie = collect($quete->carte->grille['salles'])->search(fn (array $s) => $s['id'] === 'galerie');

    $deplacer = optionsPorteVerrouillee($this, $groupe, $heros)->firstWhere('id', 'se_deplacer');
    expect(collect($deplacer['parametres']['destinations'])->contains(fn ($d) => $d['x'] === 5 && $d['y'] === 3))->toBeTrue();
    $this->postJson("/api/groupes/{$groupe->identifiant}/choix", ['option_id' => 'se_deplacer', 'parametres' => ['x' => 5, 'y' => 3]])->assertAccepted();

    $etat = $quete->etatsPersonnages()->firstOrFail();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([9, 4])
        ->and(in_array($galerie, (array) $quete->refresh()->salles_decouvertes, true))->toBeTrue()
        ->and($quete->notes_jouees)->toBe([0])
        ->and((int) $heros->fresh()->or)->toBe($orAvant);

    $note = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()->first(fn ($e) => ($e->payload['type'] ?? null) === 'note_de_quete');
    expect(collect(app(\App\Partie\JournalCombat::class)->depuisResultat($note->payload, 'Albrecht'))->pluck('texte')->all())
        ->toBe(['Albrecht disparaît, et reparaît ailleurs']);
});

it('sans héros déclencheur, la téléportation ne fait rien et le dit', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['notes'] = [
        ['repere' => 'A', 'quand' => ['declencheur' => MotsClesQuete::TOUR_DU_MJ, 'cible' => ['tour' => 1]], 'alors' => [['effet' => MotsClesQuete::TELEPORTER, 'x' => 9, 'y' => 4]]],
    ];
    ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieVerrouillee($this, $campagne);

    optionsPorteVerrouillee($this, $groupe, $heros);
    $this->postJson("/api/groupes/{$groupe->identifiant}/choix", ['option_id' => 'attendre'])->assertAccepted();

    $etat = $quete->etatsPersonnages()->firstOrFail();
    expect([(int) $etat->position_x, (int) $etat->position_y])->toBe([6, 3]);
    $note = \App\Models\Evenement::where('groupe_id', $groupe->id)->get()->first(fn ($e) => ($e->payload['type'] ?? null) === 'note_de_quete');
    expect($note->payload['effets'][0]['teleporte'])->toBeFalse();
});

/*
 * LOT K5g — LA FOUILLE DE ZONE À RÉSULTAT IMPOSÉ (D7). La porte secrète 2, en (9, 5) côté sud, est dans la `galerie`.
 * Une note sur la fouille de zone de la galerie : sans elle, un jet réussi trouve la porte ; avec une note « rien »,
 * il ne trouve rien ; avec une note `reveler_porte`, c'est la note qui la révèle.
 */
it('EN JEU : la note de fouille de zone remplace ce que le jet aurait trouvé', function (?array $alors, bool $revelee) {
    $campagne = DocumentExemple::campagne();
    if ($alors !== null) {
        $campagne['quetes'][0]['notes'] = [
            ['repere' => 'Z', 'quand' => ['declencheur' => MotsClesQuete::FOUILLE_ZONE, 'cible' => ['salle' => 'galerie']], 'alors' => $alors],
        ];
    }
    expect(app(ValidationCampagne::class)->valider($campagne))->toBe([]);
    ['groupe' => $groupe, 'quete' => $quete, 'heros' => $heros] = partieVerrouillee($this, $campagne);
    $quete->etatsPersonnages()->update(['position_x' => 8, 'position_y' => 3]);

    optionsPorteVerrouillee($this, $groupe, $heros);
    desFiges([1, 4]); // Mind 2 dés : un crâne, réussite
    $reponse = $this->postJson("/api/groupes/{$groupe->identifiant}/choix", ['option_id' => 'fouiller'])->assertAccepted();

    expect($reponse->json('resultat.issue'))->toBe($alors === null ? 'reussite' : 'note')
        ->and($reponse->json('resultat.portes_revelees'))->toHaveCount($alors === null ? 1 : 0)
        ->and(data_get($quete->carte->fresh()->grille, 'portes.2.revele') ?? false)->toBe($revelee);
    if ($alors !== null) {
        expect($reponse->json('resultat.notes'))->toBe(['Z'])
            ->and(app(\App\Partie\JournalCombat::class)->depuisResultat($reponse->json('resultat'), 'Albrecht')[0]['texte'])->toBe('Albrecht fouille la zone…');
    }
})->with([
    'sans note : le jet trouve la porte' => [null, true],
    'note « rien »' => [[['effet' => 'texte', 'texte' => 'Rien qu\'une odeur de moisi.']], false],
    'note qui révèle la porte' => [[['effet' => 'reveler_porte', 'porte' => 2]], true],
]);
