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
