<?php

declare(strict_types=1);

use App\Engine\MotsClesQuete;
use App\Jobs\GenererMenu;
use App\Models\Evenement;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Partie\Classique\CampagnesConstruites;
use App\Partie\Classique\NotesDeQuete;
use App\Partie\Fouille\DeckFouille;
use App\Partie\MonstreErrant;
use App\Partie\MoteurDegats;
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
 * MODE CLASSIQUE — LES NOTES D'UNE QUÊTE CONSTRUITE (`docs/plan-mode-classique.md` §2 à §4, lot K3), par les VRAIES
 * routes : « quand tel événement se produit, alors tels effets ».
 *
 * Chaque mot de `MotsClesQuete` est joué ici au moins une fois, EN JEU — c'est la condition pour qu'il existe :
 *   déclencheurs : fouille de trésor, entrée dans une salle, passage sur une case, ouverture d'une porte, mort ;
 *   effets : texte lu, or, objet, apparition, dégâts, drapeau ;
 *   et la fin « drapeau », le paquet de trésor du plateau, le monstre errant désigné par l'auteur.
 *
 * La carte est celle de `DocumentExemple` (plateau, quête et textes INVENTÉS) : `entree` (x 2–6, y 2–5) où l'on
 * arrive par l'escalier de (2, 4), `galerie` (x 7–10, y 2–5) derrière la porte n° 0, un gobelin (monstre n° 0) en (5, 2).
 */
beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null, 'services.gemini.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

/**
 * Ouvre un groupe classique sur la campagne d'exemple, dont la première quête reçoit ces notes (et, au besoin,
 * d'autres clés), puis démarre la quête. Pose `$t->heros`, `$t->code`, `$t->groupe`, `$t->quete`.
 *
 * @param  list<array<string, mixed>>  $notes
 * @param  array<string, mixed>  $surcharge  clés de la quête à remplacer (`fin`, `monstre_errant`…)
 */
function queteAvecNotes(object $t, array $notes, array $surcharge = [], bool $gobelinRetire = true): void
{
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0] = [...$campagne['quetes'][0], 'notes' => $notes, ...$surcharge];
    app(CampagnesConstruites::class)->enregistrer($campagne);

    $alice = connecterJoueur('alice');
    $t->heros = Personnage::create([
        'joueur_id' => $alice->id, 'nom' => 'Albrecht', 'classe' => 'barbare', 'niveau' => 1, 'or' => 0,
        'attribut_body' => 4, 'attribut_mind' => 2, 'pv_body_max' => 8, 'pv_body' => 8, 'pv_mind_max' => 2, 'pv_mind' => 2,
        'des_attaque' => 3, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $t->code = $t->postJson('/api/classique', ['nom' => 'Les Essayeurs', 'campagne' => 'campagne-d-essai', 'personnage_id' => $t->heros->id])
        ->assertCreated()->json('groupe.identifiant');
    $t->postJson("/api/groupes/{$t->code}/quetes")->assertCreated();
    $t->groupe = Groupe::where('identifiant', $t->code)->firstOrFail();
    $t->quete = Quete::findOrFail($t->groupe->quete_courante_id);

    // Le gobelin de l'entrée est écarté sans passer par le moteur (aucune note « mort » ne tire) : une salle occupée
    // ne se fouille pas, et ces tests portent sur les notes.
    if ($gobelinRetire) {
        $t->quete->instancesMonstres()->update(['etat' => 'vaincu']);
    }
}

/** Pose le héros sur une case et rend son menu, recalculé sur l'état présent. */
function menuDeNote(object $t, int $x, int $y): \Illuminate\Support\Collection
{
    $t->quete->etatsPersonnages()->where('personnage_id', $t->heros->id)->update(['position_x' => $x, 'position_y' => $y]);
    Cache::forget(GenererMenu::cleDuHeros($t->groupe, $t->heros));

    return collect($t->getJson("/api/groupes/{$t->code}/menu")->assertOk()->json('menu.options'));
}

function jouerNote(object $t, string $option, array $parametres = []): \Illuminate\Testing\TestResponse
{
    return $t->postJson("/api/groupes/{$t->code}/choix", ['option_id' => $option, ...($parametres === [] ? [] : ['parametres' => $parametres])]);
}

/** Les lignes du fil que les notes ont annoncées, dans l'ordre. */
function annoncesDeNotes(object $t): array
{
    return Evenement::where('groupe_id', $t->groupe->id)->where('type', 'action')->orderBy('sequence')->get()
        ->filter(fn (Evenement $e) => ($e->payload['type'] ?? null) === 'note_de_quete')
        ->map(fn (Evenement $e) => $e->payload)->values()->all();
}

it('une quête construite part avec le paquet de trésor du plateau : 24 cartes, mélangées', function () {
    queteAvecNotes($this, []);

    $deck = collect($this->quete->deckFouille());

    expect($deck)->toHaveCount(array_sum(DeckFouille::PAQUET_DE_TRESOR))->toHaveCount(24)
        ->and($deck->where('issue', 'errant'))->toHaveCount(6)
        ->and($deck->where('issue', 'piege'))->toHaveCount(4)
        ->and($deck->where('issue', 'potion'))->toHaveCount(6)
        ->and($deck->where('issue', 'tresor')->sum('or'))->toBe(2 * 35 + 2 * 25 + 2 * 15 + 2 * 50)
        // Le document de la quête est FIGÉ sur elle : c'est lui que les notes et la récompense lisent.
        ->and($this->quete->estConstruite())->toBeTrue()
        ->and($this->quete->documentConstruit()['slug'])->toBe('premiere-marche');
});

it('sans note, la fouille pioche une carte du paquet — et le monstre errant est celui que l\'auteur désigne', function () {
    queteAvecNotes($this, [], ['monstre_errant' => 'orque']);

    expect(app(MonstreErrant::class)->deLaQuete($this->quete)?->nom_base)->toBe('Orque');

    empilerCarteFouille($this->quete, ['issue' => 'errant']);
    menuDeNote($this, 4, 3);

    jouerNote($this, 'fouiller_tresor')->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'errant')
        ->assertJsonPath('resultat.monstre.nom', 'Orque');
});

it('une quête qui ne désigne aucun monstre errant n\'en a pas : sa carte ne fait rien sortir', function () {
    queteAvecNotes($this, []);

    expect(app(MonstreErrant::class)->deLaQuete($this->quete))->toBeNull();

    empilerCarteFouille($this->quete, ['issue' => 'errant']);
    menuDeNote($this, 4, 3);
    $avant = $this->quete->instancesMonstres()->count();

    jouerNote($this, 'fouiller_tresor')->assertStatus(202);

    expect($this->quete->instancesMonstres()->count())->toBe($avant);
});

it('FOUILLE → texte, or, drapeau : la note répond à la place de la carte, une seule fois', function () {
    queteAvecNotes($this, [[
        'repere' => 'A',
        'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
        'alors' => [
            ['effet' => 'texte', 'texte' => 'Sous une dalle descellée, une bourse de cuir.'],
            ['effet' => 'or', 'or' => 120],
            ['effet' => 'drapeau', 'nom' => 'bourse-trouvee'],
        ],
    ]]);
    menuDeNote($this, 4, 3);

    jouerNote($this, 'fouiller_tresor')->assertStatus(202)
        ->assertJsonPath('resultat.issue', 'note')
        ->assertJsonPath('resultat.notes', ['A']);

    $quete = $this->quete->fresh();

    expect((int) $this->heros->fresh()->or)->toBe(120)                       // à la bourse de celui qui fouille
        ->and($quete->deckFouille())->toHaveCount(24)                        // aucune carte tirée
        ->and($quete->aDrapeau('bourse-trouvee'))->toBeTrue()
        ->and($quete->notes_jouees)->toBe([0])
        // Le texte est LU à la table : une narration au journal.
        ->and(Evenement::where('groupe_id', $this->groupe->id)->where('type', 'narration')->get()
            ->contains(fn (Evenement $e) => ($e->payload['texte'] ?? null) === 'Sous une dalle descellée, une bourse de cuir.'))->toBeTrue();

    // Annoncée, effet par effet : un effet automatique que rien n'annonce est injouable.
    $annonce = annoncesDeNotes($this)[0];
    expect($annonce['repere'])->toBe('A')
        ->and(array_column($annonce['effets'], 'effet'))->toBe(['texte', 'or', 'drapeau']);

    // Le trésor spécial est pris : la salle n'en a plus, la note n'attend plus rien.
    expect(app(NotesDeQuete::class)->attend($quete, MotsClesQuete::FOUILLE_TRESOR, ['salle' => 0]))->toBeFalse();
});

it('la fin « drapeau » : la quête est accomplie quand une note a posé le drapeau, et la sortie s\'ouvre', function () {
    queteAvecNotes($this, [[
        'repere' => 'R',
        'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
        'alors' => [['effet' => 'drapeau', 'nom' => 'relique']],
    ]], ['fin' => ['type' => 'drapeau', 'drapeau' => 'relique', 'libelle' => 'Trouver la relique et ressortir.']], gobelinRetire: false);

    // Le gobelin reste DEBOUT, hors de vue dans la galerie : un donjon vidé de ses monstres laisse sortir quoi qu'il
    // arrive, et ce test veut voir la sortie s'ouvrir par le drapeau seul.
    $this->quete->instancesMonstres()->update(['position_x' => 9, 'position_y' => 4, 'revele' => false]);

    expect($this->quete->objectif())->toBe(Quete::OBJECTIF_DRAPEAU)
        ->and($this->quete->objectifLibelle())->toBe('Trouver la relique et ressortir.')
        ->and($this->quete->objectifAccompli())->toBeFalse()
        // Sur l'escalier, l'objectif non accompli : pas de sortie.
        ->and(menuDeNote($this, 2, 4)->pluck('id'))->not->toContain('sortir_donjon');

    menuDeNote($this, 4, 3);
    jouerNote($this, 'fouiller_tresor')->assertStatus(202)->assertJsonPath('resultat.issue', 'note');

    // Au tour SUIVANT (la fouille a pris l'action de ce tour-ci : quitter le donjon la demande aussi, lot X1).
    $this->quete->etatsPersonnages()->update(['a_agi' => false, 'a_joue' => false, 'a_deplace' => false]);
    expect($this->quete->fresh()->objectifAccompli())->toBeTrue()
        ->and(menuDeNote($this, 2, 4)->pluck('id'))->toContain('sortir_donjon');
});

it('ENTRÉE dans une salle → apparition et dégâts : en entrant, pas en s\'y promenant', function () {
    queteAvecNotes($this, [[
        'repere' => 'B',
        'quand' => ['declencheur' => 'entree', 'cible' => ['salle' => 'galerie']],
        'alors' => [
            ['effet' => 'apparition', 'monstres' => [['slug' => 'gobelin', 'x' => 8, 'y' => 4], ['slug' => 'orque', 'x' => 8, 'y' => 4]]],
            ['effet' => 'degats', 'body' => 1],
        ],
    ]]);

    menuDeNote($this, 6, 3);
    jouerNote($this, 'ouvrir_porte_6_3_e')->assertAccepted();
    $avant = $this->quete->instancesMonstres()->where('etat', 'actif')->count();

    // Un pas : de `entree` dans `galerie`.
    menuDeNote($this, 6, 3);
    jouerNote($this, 'se_deplacer', ['x' => 7, 'y' => 3])->assertStatus(202);

    $apparus = $this->quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get();

    expect($apparus)->toHaveCount($avant + 2)
        ->and($apparus->every(fn ($m) => (bool) $m->revele))->toBeTrue()
        // Le gobelin sur la case écrite ; l'orc, dont la case est prise, sur la plus proche.
        ->and($apparus->firstWhere('monstre.nom_base', 'Gobelin')->only(['position_x', 'position_y']))->toBe(['position_x' => 8, 'position_y' => 4])
        ->and(abs($apparus->firstWhere('monstre.nom_base', 'Orque')->position_x - 8) + abs($apparus->firstWhere('monstre.nom_base', 'Orque')->position_y - 4))->toBeLessThanOrEqual(2)
        ->and((int) $this->heros->fresh()->pv_body)->toBe(7);

    // Un second pas, toujours DANS la galerie : on n'y entre pas une seconde fois.
    $this->quete->instancesMonstres()->update(['etat' => 'vaincu']);
    menuDeNote($this, 7, 3);
    jouerNote($this, 'se_deplacer', ['x' => 7, 'y' => 2])->assertStatus(202);

    expect((int) $this->heros->fresh()->pv_body)->toBe(7)
        ->and(annoncesDeNotes($this))->toHaveCount(1);
});

it('PASSAGE sur une case, note répétée : elle tire à chaque passage, même sans s\'y arrêter', function () {
    queteAvecNotes($this, [[
        'repere' => 'C',
        'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 4, 'y' => 3]]],
        'alors' => [['effet' => 'degats', 'body' => 1]],
        'une_fois' => false,
    ]]);

    // De (3, 3) à (5, 3) : le trajet passe par (4, 3) sans s'y arrêter.
    menuDeNote($this, 3, 3);
    jouerNote($this, 'se_deplacer', ['x' => 5, 'y' => 3])->assertStatus(202);
    expect((int) $this->heros->fresh()->pv_body)->toBe(7);

    // Et au retour : la note n'est pas « une fois ».
    Cache::forget(GenererMenu::cleDuHeros($this->groupe, $this->heros));
    $this->getJson("/api/groupes/{$this->code}/menu")->assertOk();
    jouerNote($this, 'se_deplacer', ['x' => 3, 'y' => 3])->assertStatus(202);

    expect((int) $this->heros->fresh()->pv_body)->toBe(6)
        ->and($this->quete->fresh()->notes_jouees)->toBe([]);
});

it('OUVERTURE d\'une porte → objet : il entre dans le sac de celui qui ouvre', function () {
    queteAvecNotes($this, [[
        'repere' => 'D',
        'quand' => ['declencheur' => 'ouverture_porte', 'cible' => ['porte' => 0]],
        'alors' => [['effet' => 'objet', 'slug' => 'dague', 'quantite' => 2]],
    ]]);

    menuDeNote($this, 6, 3);
    jouerNote($this, 'ouvrir_porte_6_3_e')->assertAccepted();

    $dague = Objet::where('nom', 'Dague')->firstOrFail();

    expect((int) Inventaire::where('personnage_id', $this->heros->id)->where('objet_id', $dague->id)->sum('quantite'))->toBe(2)
        ->and(annoncesDeNotes($this)[0]['effets'][0])->toMatchArray(['effet' => 'objet', 'quantite' => 2, 'receveur' => 'Albrecht']);
});

it('MORT d\'une figure → drapeau, lu par une autre note : la condition `si_drapeau`', function () {
    queteAvecNotes($this, [
        [
            'repere' => 'E',
            'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]],
            'alors' => [['effet' => 'drapeau', 'nom' => 'gardien-tombe'], ['effet' => 'texte', 'texte' => 'Le gardien s\'effondre.']],
        ],
        [
            'repere' => 'F',
            'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree'], 'si_drapeau' => 'gardien-tombe'],
            'alors' => [['effet' => 'or', 'or' => 50]],
        ],
    ], gobelinRetire: false);

    $notes = app(NotesDeQuete::class);
    $gobelin = $this->quete->instancesMonstres()->firstOrFail();

    // Tant que le gardien est debout, la note F n'attend rien : son drapeau n'est pas posé.
    expect($notes->rangDuMonstre($this->quete, $gobelin))->toBe(0)
        ->and($notes->attend($this->quete, MotsClesQuete::FOUILLE_TRESOR, ['salle' => 0]))->toBeFalse();

    // Le gardien tombe sous les coups du héros : par le point de passage unique de la mort.
    app(MoteurDegats::class)->infligerAMonstre($gobelin, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $this->heros);

    expect($this->quete->fresh()->aDrapeau('gardien-tombe'))->toBeTrue()
        ->and($notes->attend($this->quete->fresh(), MotsClesQuete::FOUILLE_TRESOR, ['salle' => 0]))->toBeTrue();

    menuDeNote($this, 4, 3);
    jouerNote($this, 'fouiller_tresor')->assertStatus(202)->assertJsonPath('resultat.issue', 'note');

    expect((int) $this->heros->fresh()->or)->toBe(50)
        ->and(array_column(annoncesDeNotes($this), 'repere'))->toBe(['E', 'F']);
});

it('une figure qui n\'a pas été posée par l\'auteur ne déclenche aucune note « mort »', function () {
    queteAvecNotes($this, [[
        'repere' => 'E',
        'quand' => ['declencheur' => 'mort', 'cible' => ['monstre' => 0]],
        'alors' => [['effet' => 'drapeau', 'nom' => 'gardien-tombe']],
    ]]);

    // Une figure née en cours de quête (un errant, une apparition) : elle n'a pas de rang parmi celles de l'auteur.
    $intrus = $this->quete->instancesMonstres()->create([
        'monstre_id' => $this->quete->instancesMonstres()->firstOrFail()->monstre_id,
        'pv_body' => 1, 'pv_body_max' => 1, 'pv_mind' => 1, 'position_x' => 5, 'position_y' => 4, 'etat' => 'actif', 'elite' => false, 'revele' => true,
    ]);

    expect(app(NotesDeQuete::class)->rangDuMonstre($this->quete, $intrus))->toBeNull();

    app(MoteurDegats::class)->infligerAMonstre($intrus, 99, MoteurDegats::SOURCE_ATTAQUE_HEROS, [], $this->heros);

    expect($this->quete->fresh()->aDrapeau('gardien-tombe'))->toBeFalse();
});

it('le fil raconte une note effet par effet, et ne dit pas « fouille en vain »', function () {
    $journal = app(\App\Partie\JournalCombat::class);

    $lignes = $journal->depuisResultat([
        'type' => 'note_de_quete', 'repere' => 'A', 'declencheur' => 'fouille_tresor', 'personnage' => 'Albrecht',
        'effets' => [
            ['effet' => 'texte', 'texte' => 'Une bourse de cuir.'],
            ['effet' => 'or', 'or' => 120, 'parts' => [['personnage_id' => 1, 'nom' => 'Albrecht', 'or' => 120]]],
            ['effet' => 'objet', 'objet' => ['id' => 1, 'nom' => 'Dague'], 'quantite' => 2, 'receveur' => 'Albrecht'],
            ['effet' => 'apparition', 'monstres' => [['nom' => 'Gobelin', 'x' => 8, 'y' => 4], ['nom' => 'Orc', 'x' => 9, 'y' => 4]]],
            ['effet' => 'degats', 'degats' => 1, 'nom' => 'Albrecht'],
            ['effet' => 'drapeau', 'nom' => 'relique'],
        ],
    ], 'Albrecht');

    expect(array_column($lignes, 'texte'))->toBe([
        'Une bourse de cuir.',
        'Albrecht trouve 120 pièces d\'or',
        'Albrecht trouve Dague ×2',
        'Gobelin, Orc surgissent !',
        'Albrecht perd 1 point(s) de Corps',
    ]);

    // La fouille à laquelle une note a répondu ne dit rien de plus — surtout pas « en vain ».
    expect($journal->depuisResultat(['type' => 'fouille_tresor', 'issue' => 'note', 'notes' => ['A']], 'Albrecht'))->toBe([]);
});

/*
 * LA CASE LIBRE LA PLUS PROCHE NE TRAVERSE JAMAIS UN MUR (`App\Partie\CaseLibre`, 2026-11-15).
 *
 * Vu en jeu sur la vraie stack : une apparition dont la case était prise naissait dans le couloir, derrière le mur de
 * la salle. Sur une carte à murs en arêtes, la voisine « x + 1 » d'une case de bord est du SOL — le mur n'occupe plus
 * de case — et l'ancien calcul la prenait.
 */
it('la case libre la plus proche reste du même côté du mur', function () {
    queteAvecNotes($this, []);
    $grille = fn () => \App\Partie\FabriqueGrille::pour($this->quete->fresh());

    // (10, 4) est sur le bord EST de `galerie` (x 7–10) ; (11, 4) est une case de couloir, de l'autre côté du mur.
    expect($grille()->estTraversable(11, 4))->toBeTrue()
        ->and($grille()->areteBloquee(10, 4, 11, 4))->toBeTrue()
        ->and(\App\Partie\CaseLibre::proche($grille(), 10, 4))->toBe(['x' => 9, 'y' => 4]);

    // Les trois voisines orthogonales prises : on prend une diagonale DANS la salle, pas le couloir d'à côté.
    $pleine = $grille();
    $pleine->occuper([['x' => 9, 'y' => 4], ['x' => 10, 'y' => 3], ['x' => 10, 'y' => 5]]);

    expect(\App\Partie\CaseLibre::proche($pleine, 10, 4))->toBe(['x' => 9, 'y' => 5]);
});

it('une apparition dont la case est prise naît dans la salle, pas derrière son mur', function () {
    queteAvecNotes($this, [[
        'repere' => 'B',
        'quand' => ['declencheur' => 'entree', 'cible' => ['salle' => 'galerie']],
        'alors' => [['effet' => 'apparition', 'monstres' => [['slug' => 'gobelin', 'x' => 10, 'y' => 4], ['slug' => 'gobelin', 'x' => 10, 'y' => 4]]]],
    ]]);

    menuDeNote($this, 6, 3);
    jouerNote($this, 'ouvrir_porte_6_3_e')->assertAccepted();
    menuDeNote($this, 6, 3);
    jouerNote($this, 'se_deplacer', ['x' => 7, 'y' => 3])->assertStatus(202);

    $apparus = $this->quete->instancesMonstres()->where('etat', 'actif')->get();

    expect($apparus)->toHaveCount(2)
        // Tous deux DANS `galerie` (x 7–10, y 2–5) : aucun dans le couloir, à l'est du mur.
        ->and($apparus->every(fn ($m) => $m->position_x >= 7 && $m->position_x <= 10 && $m->position_y >= 2 && $m->position_y <= 5))->toBeTrue();
});

it('DERNIER HÉROS PASSÉ (D6) : le bloc ne tombe pas sous les pieds du héros, mais derrière lui dès qu\'il a franchi la case', function () {
    queteAvecNotes($this, [[
        'repere' => 'C',
        'quand' => ['declencheur' => 'dernier_heros_passe', 'cible' => ['case' => ['x' => 4, 'y' => 2]]],
        'alors' => [['effet' => 'bloc', 'x' => 4, 'y' => 2]],
    ]]);

    // Le héros s'arrête SUR (4, 2) : il n'a pas encore dépassé la case, rien ne s'effondre sur lui.
    desFiges([6, 6]);
    menuDeNote($this, 3, 2);
    jouerNote($this, 'se_deplacer', ['x' => 4, 'y' => 2])->assertStatus(202);
    expect(\App\Partie\FabriqueGrille::pour($this->quete->fresh(), exceptPersonnageId: (int) $this->heros->id)->estTraversable(4, 2))->toBeTrue();

    // Au pas suivant, il quitte (4, 2) pour (5, 2) : tous les héros debout ont franchi (4, 2), le bloc tombe derrière lui.
    $this->quete->etatsPersonnages()->update(['a_deplace' => false, 'deplacement_tour' => 6, 'deplacement_restant' => 6]);
    menuDeNote($this, 4, 2);
    jouerNote($this, 'se_deplacer', ['x' => 5, 'y' => 2])->assertStatus(202);
    expect(\App\Partie\FabriqueGrille::pour($this->quete->fresh(), exceptPersonnageId: (int) $this->heros->id)->estTraversable(4, 2))->toBeFalse();
});

it('DERNIER HÉROS PASSÉ (D6) : un compagnon À TERRE ou hors du plateau ne retient pas le bloc', function () {
    queteAvecNotes($this, []);
    $bob = Personnage::create([
        'joueur_id' => $this->heros->joueur_id, 'nom' => 'Brunehaut', 'classe' => 'nain', 'niveau' => 1, 'or' => 0,
        'attribut_body' => 4, 'attribut_mind' => 3, 'pv_body_max' => 7, 'pv_body' => 0, 'pv_mind_max' => 3, 'pv_mind' => 3,
        'des_attaque' => 2, 'des_defense' => 2, 'deplacement_base' => 4,
    ]);
    $this->quete->etatsPersonnages()->create(['personnage_id' => $bob->id, 'position_x' => 1, 'position_y' => 1, 'tombe' => true]);
    $this->quete->etatsPersonnages()->where('personnage_id', $this->heros->id)->update(['position_x' => 5, 'position_y' => 2]);
    $this->quete->noterPassageSurCase(4, 2, (int) $this->heros->id);

    // Brunehaut est à terre : seul Albrecht, debout, compte — et il a franchi (4, 2).
    expect($this->quete->fresh()->tousLesHerosOntFranchi(4, 2))->toBeTrue();

    // Relevée sans avoir foulé la case : elle retient le bloc. Hors du plateau (attend dehors) : elle ne compte plus.
    $this->quete->etatsPersonnages()->where('personnage_id', $bob->id)->update(['tombe' => false]);
    expect($this->quete->fresh()->tousLesHerosOntFranchi(4, 2))->toBeFalse();
    $this->quete->etatsPersonnages()->where('personnage_id', $bob->id)->update(['position_x' => null, 'position_y' => null]);
    expect($this->quete->fresh()->tousLesHerosOntFranchi(4, 2))->toBeTrue();
});

it('E5, TUILE POSÉE AU PREMIER PAS : une glace `cachee` manque à la carte jusqu\'à ce qu\'un héros y marche', function () {
    queteAvecNotes($this, [], ['terrain' => [['slug' => 'glace-glissante', 'x' => 4, 'y' => 2, 'cachee' => true]]]);
    $terrain = fn () => collect($this->getJson("/api/groupes/{$this->code}/etat")->json('carte.terrain'))
        ->map(fn ($t) => "{$t['x']},{$t['y']}")->all();

    menuDeNote($this, 3, 2);
    expect($terrain())->not->toContain('4,2'); // sa case est visible, la tuile non

    desFiges([6, 6, 6, 6]);
    jouerNote($this, 'se_deplacer', ['x' => 4, 'y' => 2])->assertStatus(202);
    expect($terrain())->toContain('4,2'); // le pas l'a posée
});

it('E5 : `cachee` n\'accepte que true', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['terrain'][0]['cachee'] = 'oui';

    expect(collect(app(\App\Partie\Classique\ValidationCampagne::class)->valider($campagne))->pluck('chemin')->all())
        ->toContain('quetes.0.terrain.0.cachee');
});

it('OBSCURITÉ MAGIQUE (K6) : −2 dés aux deux premières attaques dans la salle obscure, jamais moins d\'un ; la Couronne la lève', function () {
    queteAvecNotes($this, [], ['regles' => ['obscurite_magique' => ['salle' => 'entree']]], gobelinRetire: false);
    $obscurite = app(\App\Partie\Classique\ObscuriteMagique::class);
    $etat = fn () => $this->quete->etatsPersonnages()->where('personnage_id', $this->heros->id)->firstOrFail();

    // Une vraie attaque, depuis l'entrée obscure, au contact du gobelin en (5, 2) : 3 dés − 2, le malus est dit.
    $gobelin = $this->quete->instancesMonstres()->firstOrFail();
    menuDeNote($this, 4, 2);
    $attaque = jouerNote($this, 'attaquer', ['cible_id' => $gobelin->id])->assertStatus(202)->json('resultat');
    expect($attaque['obscurite'] ?? null)->toBe(2)
        ->and($attaque['des_attaque_effectifs'])->toBe(1);

    // La deuxième attaque est encore gênée, la troisième non.
    expect($obscurite->malus($this->quete->fresh(), $etat(), $this->heros))->toBe(2);
    $obscurite->compter($etat());
    expect($obscurite->malus($this->quete->fresh(), $etat(), $this->heros))->toBe(0);

    // Hors de la salle obscure, rien ; la Couronne des Ténèbres portée, rien non plus.
    $etat()->update(['capacites_utilisees' => []]);
    $etat()->update(['position_x' => 8, 'position_y' => 3]);
    expect($obscurite->malus($this->quete->fresh(), $etat(), $this->heros))->toBe(0);
    $etat()->update(['position_x' => 4, 'position_y' => 2]);
    // La ligne du catalogue (migrée le 2026-11-19 : `vision_nocturne`).
    $couronne = \App\Models\Objet::where('nom', 'Couronne des Ténèbres')->firstOrFail();
    expect($couronne->effet['vision_nocturne'] ?? null)->toBeTrue();
    $this->heros->inventaire()->create(['objet_id' => $couronne->id, 'emplacement' => 'casque', 'quantite' => 1]);
    expect($obscurite->malus($this->quete->fresh(), $etat(), $this->heros->fresh()))->toBe(0);
});

it('GLYPHES (K6) : découvert, détruit au parchemin-clé (qui disparaît), et la note du trésor lit `si_drapeau` / `sauf_drapeau`', function () {
    queteAvecNotes($this, [
        ['repere' => 'D', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree'], 'sauf_drapeau' => 'autel_libre'],
            'alors' => [['effet' => 'degats', 'body' => 2]]],
        ['repere' => 'E', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree'], 'si_drapeau' => 'autel_libre'],
            'alors' => [['effet' => 'or', 'or' => 10]]],
    ], ['glyphes' => [['id' => 'autel', 'salle' => 'entree', 'detruit_par' => 'parchemin', 'drapeau' => 'autel_libre']]]);
    $glyphes = app(\App\Partie\Classique\Glyphes::class);
    $parchemin = \App\Models\Objet::where('nom', 'Parchemin-clé des glyphes')->first()
        ?? \App\Models\Objet::create(['nom' => 'Parchemin-clé des glyphes', 'categorie' => 'tresor', 'rarete' => 'unique', 'prix_base' => 0,
            'emplacement' => 'sac', 'effet' => ['hors_marche' => true, 'objet_de_quete' => true, 'detruit_glyphe' => true]]);
    $this->heros->inventaire()->create(['objet_id' => $parchemin->id, 'emplacement' => 'sac', 'quantite' => 1]);

    // Caché : rien à détruire. Découvert par la fouille de la zone : le menu l'offre.
    expect(menuDeNote($this, 4, 3)->pluck('id'))->not->toContain('detruire_glyphe_autel');
    $entree = \App\Partie\Salles::indexDe($this->quete->carte->grille['salles'], 4, 3);
    expect($glyphes->decouvrir($this->quete->fresh(), $entree, $this->heros))->toHaveCount(1);
    expect(menuDeNote($this, 4, 3)->firstWhere('id', 'detruire_glyphe_autel')['libelle'] ?? null)->toBe('Détruire le glyphe (parchemin-clé)');

    jouerNote($this, 'detruire_glyphe_autel', ['glyphe' => 'autel'])->assertStatus(202)->assertJsonPath('resultat.type', 'glyphe_detruit');
    expect($this->quete->fresh()->aDrapeau('autel_libre'))->toBeTrue()
        ->and(app(\App\Partie\Equipement::class)->parcheminDeGlyphe($this->heros->fresh()))->toBeNull();

    // La fouille du trésor : la note « si » joue (10 po), la note « sauf » non (aucun dégât).
    $avant = (int) $this->heros->fresh()->pv_body;
    $notes = app(\App\Partie\Classique\NotesDeQuete::class)->signaler($this->quete->fresh(), 'fouille_tresor', ['salle' => $entree], $this->heros);
    expect(array_column($notes, 'repere'))->toBe(['E'])
        ->and((int) $this->heros->fresh()->pv_body)->toBe($avant);
});

it('GLYPHES (K6) : la cheminée doit être dans la salle ; un `sauf_drapeau` jamais posé est refusé', function () {
    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['glyphes'] = [['id' => 'buche', 'salle' => 'entree', 'detruit_par' => 'cheminee', 'drapeau' => 'feu']];
    $campagne['quetes'][0]['notes'] = [['repere' => 'A', 'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree'], 'sauf_drapeau' => 'jamais'],
        'alors' => [['effet' => 'or', 'or' => 5]]]];
    $refus = collect(app(\App\Partie\Classique\ValidationCampagne::class)->valider($campagne))->map(fn ($r) => $r['chemin'].' '.$r['code'])->all();

    expect($refus)->toContain('quetes.0.glyphes.0.detruit_par '.\App\Partie\Classique\ValidationCampagne::VALEUR_INVALIDE)
        ->toContain('quetes.0.notes.0.quand.sauf_drapeau '.\App\Partie\Classique\ValidationCampagne::DRAPEAU_JAMAIS_POSE);
});

it('DÉGUISEMENT (K6) : jeton au départ ; arme voyante, armure, sort ou geste libre le font tomber ; `si_deguise` en note', function () {
    queteAvecNotes($this, [
        ['repere' => 'A', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 5, 'y' => 3]], 'si_deguise' => false],
            'alors' => [['effet' => 'or', 'or' => 7]]],
    ], ['regles' => ['deguisement' => true]]);
    $deguisement = app(\App\Partie\Classique\Deguisement::class);
    $etat = fn () => $this->quete->etatsPersonnages()->where('personnage_id', $this->heros->id)->firstOrFail();

    // Au départ, sans armure interdite : déguisé, et publié.
    expect($etat()->deguise)->toBeTrue()
        ->and(collect($this->getJson("/api/groupes/{$this->code}/etat")->json('entites'))->firstWhere('id', $this->heros->id)['deguise'] ?? null)->toBeTrue();

    // Une dague ne le trahit pas ; une épée large, si.
    $deguisement->surAttaque($this->groupe, $etat(), $this->heros, \App\Models\Objet::where('nom', 'Dague')->first());
    expect($etat()->deguise)->toBeTrue();
    $deguisement->surAttaque($this->groupe, $etat(), $this->heros, \App\Models\Objet::where('nom', 'Épée large')->first());
    expect($etat()->deguise)->toBeFalse();

    // Un sort, une armure de plates équipée : même chute.
    $etat()->update(['deguise' => true]);
    $deguisement->surSort($this->groupe, $etat(), $this->heros);
    expect($etat()->deguise)->toBeFalse();
    $etat()->update(['deguise' => true]);
    $plates = \App\Models\Objet::where('nom', 'Armure de plates')->first();
    $this->heros->inventaire()->create(['objet_id' => $plates->id, 'emplacement' => 'armure', 'quantite' => 1]);
    $deguisement->surEquipement($this->groupe, $etat(), $this->heros->fresh());
    expect($etat()->deguise)->toBeFalse();

    // Le geste libre « Retirer son déguisement » n'existe que déguisé.
    $this->heros->inventaire()->where('objet_id', $plates->id)->delete();
    $etat()->update(['deguise' => true]);
    expect(menuDeNote($this, 4, 3)->pluck('id'))->toContain('retirer_deguisement');
    jouerNote($this, 'retirer_deguisement')->assertStatus(202)->assertJsonPath('resultat.type', 'deguisement_perdu');
    expect($etat()->deguise)->toBeFalse();

    // `si_deguise: false` : la note ne joue que pour un héros à découvert.
    $etat()->update(['deguise' => true]);
    $notes = app(\App\Partie\Classique\NotesDeQuete::class);
    expect($notes->signaler($this->quete->fresh(), 'entree', ['case' => ['x' => 5, 'y' => 3], 'salle' => null], $this->heros))->toBe([]);
    $etat()->update(['deguise' => false]);
    expect(array_column($notes->signaler($this->quete->fresh(), 'entree', ['case' => ['x' => 5, 'y' => 3], 'salle' => null], $this->heros), 'repere'))->toBe(['A']);
});

it('PLACE PUBLIQUE (K6) : un monstre de la place dort ; il s\'éveille à la vue d\'un héros non déguisé, ou attaqué', function () {
    queteAvecNotes($this, [], [
        'regles' => ['deguisement' => true],
        'salles_speciales' => [['id' => 'place', 'x' => 13, 'y' => 2, 'l' => 3, 'h' => 3, 'place' => true]],
        'monstres' => [['slug' => 'gobelin', 'x' => 5, 'y' => 2], ['slug' => 'orque', 'x' => 14, 'y' => 3]],
    ], gobelinRetire: false);
    $dormants = app(\App\Partie\MoteurDormants::class);
    $orque = $this->quete->instancesMonstres()->where('position_x', 14)->firstOrFail();
    $gobelin = $this->quete->instancesMonstres()->where('position_x', 5)->firstOrFail();
    $etat = fn () => $this->quete->etatsPersonnages()->where('personnage_id', $this->heros->id)->firstOrFail();

    expect($dormants->estDormant($orque))->toBeTrue()
        ->and($dormants->estDormant($gobelin))->toBeFalse();

    // Déguisé, sur la place, en vue de l'orque : il reste inactif. À découvert : il s'éveille. (Depuis le couloir, le mur de
    // la place coupe la vue — rien ne se passe.)
    $etat()->update(['position_x' => 12, 'position_y' => 3, 'deguise' => false]);
    expect($dormants->reveilDuTourMJ($this->quete->fresh(), $orque->fresh()))->toBeNull();
    $etat()->update(['position_x' => 13, 'position_y' => 4, 'deguise' => true]);
    expect($dormants->reveilDuTourMJ($this->quete->fresh(), $orque->fresh()))->toBeNull();
    $etat()->update(['deguise' => false]);
    expect($dormants->reveilDuTourMJ($this->quete->fresh(), $orque->fresh())['cause'] ?? null)->toBe('vue_non_deguise')
        ->and($dormants->estDormant($orque->fresh()))->toBeFalse();
});

it('RÉPUTATION (K6) : des jetons par note ; une offre payée d\'un jeton joue ses effets ; deux offres d\'un groupe s\'excluent', function () {
    queteAvecNotes($this, [
        ['repere' => 'B', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 5, 'y' => 3]]], 'alors' => [
            ['effet' => 'reputation', 'jetons' => 2],
            ['effet' => 'offre', 'libelle' => 'Graisser la patte du garde', 'cout' => ['reputation' => 1], 'groupe' => 'garde', 'alors' => [['effet' => 'or', 'or' => 10]]],
            ['effet' => 'offre', 'libelle' => 'Menacer le garde', 'groupe' => 'garde', 'alors' => [['effet' => 'texte', 'texte' => 'Il recule.']]],
        ]],
    ]);
    $reputation = app(\App\Partie\Classique\Reputation::class);
    app(\App\Partie\Classique\NotesDeQuete::class)->signaler($this->quete->fresh(), 'entree', ['case' => ['x' => 5, 'y' => 3], 'salle' => null], $this->heros);

    expect($reputation->jetons($this->groupe))->toBe(2)
        ->and($this->getJson("/api/groupes/{$this->code}/etat")->json('groupe.reputation'))->toBe(2);
    $options = menuDeNote($this, 4, 3);
    expect($options->firstWhere('id', 'offre_0')['libelle'] ?? null)->toBe('Graisser la patte du garde (1 jeton(s) de réputation)')
        ->and($options->pluck('id'))->toContain('offre_1');

    $avant = (int) $this->heros->fresh()->or;
    jouerNote($this, 'offre_0', ['offre' => 0])->assertStatus(202)->assertJsonPath('resultat.type', 'offre_prise');
    expect($reputation->jetons($this->groupe))->toBe(1)
        ->and((int) $this->heros->fresh()->or)->toBe($avant + 10)
        ->and(menuDeNote($this, 4, 3)->pluck('id'))->not->toContain('offre_1'); // même groupe : fermée
});

it('RÉPUTATION (K6) : `recompense.reputation` à la victoire ; une offre n\'en déclare pas une autre', function () {
    queteAvecNotes($this, [], ['recompense' => ['or' => 0, 'partage' => 'groupe', 'reputation' => 1]]);
    app(\App\Partie\Classique\FinDeQueteConstruite::class)->regler($this->groupe->refresh(), $this->quete->fresh());
    expect(app(\App\Partie\Classique\Reputation::class)->jetons($this->groupe))->toBe(1);

    $campagne = DocumentExemple::campagne();
    $campagne['quetes'][0]['notes'] = [['repere' => 'A', 'quand' => ['declencheur' => 'entree', 'cible' => ['case' => ['x' => 5, 'y' => 3]]], 'alors' => [
        ['effet' => 'offre', 'libelle' => 'Choix', 'alors' => [['effet' => 'offre', 'libelle' => 'Encore', 'alors' => [['effet' => 'or', 'or' => 1]]]]],
        ['effet' => 'reputation', 'jetons' => 0],
        ['effet' => 'debloquer_mercenaire', 'slug' => 'eclaireur'],
    ]]];
    $refus = collect(app(\App\Partie\Classique\ValidationCampagne::class)->valider($campagne))->map(fn ($r) => $r['chemin'].' '.$r['code'])->all();
    expect($refus)->toContain('quetes.0.notes.0.alors.0.alors.0 '.\App\Partie\Classique\ValidationCampagne::VALEUR_INVALIDE)
        ->toContain('quetes.0.notes.0.alors.1.jetons '.\App\Partie\Classique\ValidationCampagne::VALEUR_INVALIDE)
        ->toContain('quetes.0.notes.0.alors.2.slug '.\App\Partie\Classique\ValidationCampagne::CATALOGUE_INCONNU);
});

it('PROFIL DE MONSTRE (R9) et PRIMES (K5n) : nom, stats, arme requise et prime par monstre abattu', function () {
    queteAvecNotes($this, [], [
        'monstres' => [[
            'slug' => 'seigneur-sorcier-de-barak-tor', 'x' => 5, 'y' => 2,
            'nom' => 'Seigneur Sorcier éveillé', 'deplacement' => 1, 'attaque' => 2, 'defense' => 5, 'pv_body' => 2, 'pv_mind' => 6,
            'vulnerable_uniquement_a' => ['lame-des-esprits'],
        ]],
        'regles' => ['primes_monstres' => ['seigneur-sorcier-de-barak-tor' => 50]],
    ], gobelinRetire: false);

    $boss = $this->quete->instancesMonstres()->firstOrFail();
    expect($boss->nomAffiche())->toBe('Seigneur Sorcier éveillé')
        ->and($boss->deplacementEffectif())->toBe(1)
        ->and($boss->attaqueEffective())->toBe(2)
        ->and($boss->defenseEffective())->toBe(5)
        ->and((int) $boss->pv_body)->toBe(2);

    // Sans la Lame des Esprits, un coup à mains nues est sans effet (`source`).
    desFiges([1, 1, 1, 1, 1, 1, 1, 1]);
    menuDeNote($this, 5, 3);
    $sansEffet = jouerNote($this, 'attaquer', ['cible_id' => $boss->id])->assertStatus(202)->json('resultat');
    expect($sansEffet['sans_effet'])->toBe('source')
        ->and((int) $boss->fresh()->pv_body)->toBe(2);

    // Équipé de la Lame des Esprits, le coup porte et la prime de 50 po est versée à sa mort.
    $lame = Objet::where('nom', 'Lame des Esprits')->firstOrFail();
    \App\Partie\RangementObjet::ranger($lame, (int) $this->heros->id, 1);
    $ligneLame = Inventaire::where('personnage_id', $this->heros->id)->where('objet_id', $lame->id)->firstOrFail();
    app(\App\Partie\Equipement::class)->equiper($this->heros, $ligneLame);
    $this->quete->etatsPersonnages()->update(['a_agi' => false]);
    desFiges([1, 1, 1, 1, 1, 1, 4, 4, 4, 4, 4]);
    menuDeNote($this, 5, 3);
    jouerNote($this, 'attaquer', ['cible_id' => $boss->id])->assertStatus(202);

    expect($boss->fresh()->etat)->toBe('vaincu')
        ->and((int) $this->heros->fresh()->or)->toBe(50);
});

it('joue detruire_objet, toute_la_salle, destinations_d6, fouille_piege_un_par_un, monstres_pacifies_par_drapeau et dangers', function () {
    queteAvecNotes($this, [
        [
            'repere' => 'A',
            'quand' => ['declencheur' => 'fouille_tresor', 'cible' => ['salle' => 'entree']],
            'alors' => [
                ['effet' => 'detruire_objet', 'slug' => 'dague', 'toute_la_salle' => true],
                ['effet' => 'degats', 'body' => 1, 'toute_la_salle' => true],
                ['effet' => 'teleporter', 'x' => 8, 'y' => 3, 'destinations_d6' => [
                    '1' => ['x' => 8, 'y' => 3],
                    '2' => ['x' => 9, 'y' => 3],
                    '3' => ['x' => 10, 'y' => 3],
                    '4' => ['x' => 8, 'y' => 4],
                    '5' => ['x' => 9, 'y' => 4],
                    '6' => ['x' => 10, 'y' => 4],
                ]],
                ['effet' => 'drapeau', 'nom' => 'pacifie'],
            ],
        ],
    ], [
        'regles' => [
            'fouille_piege_un_par_un' => true,
            'monstres_pacifies_par_drapeau' => ['gobelin' => 'pacifie'],
        ],
        'dangers' => [
            ['type' => 'rocher', 'run' => [['x' => 7, 'y' => 1], ['x' => 8, 'y' => 1]]],
            ['type' => 'brume', 'x' => 7, 'y' => 8],
        ],
    ]);

    $dague = Objet::where('nom', 'Dague')->firstOrFail();
    \App\Partie\RangementObjet::ranger($dague, $this->heros->id);

    $pvAvant = (int) $this->heros->fresh()->pv_body;
    desFiges([4]); // d6 = 4 -> (8, 4)
    menuDeNote($this, 2, 2);
    jouerNote($this, 'fouiller_tresor')->assertStatus(202);

    expect($this->heros->inventaire()->where('objet_id', $dague->id)->exists())->toBeFalse()
        ->and((int) $this->heros->fresh()->pv_body)->toBe($pvAvant - 1)
        ->and($this->quete->etatsPersonnages()->first()->only(['position_x', 'position_y']))->toBe(['position_x' => 8, 'position_y' => 4])
        ->and(count($this->quete->carte->grille['dangers']))->toBe(2);
});


