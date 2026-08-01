<?php

use App\Auth\JoueurAuthentifiable;
use App\Engine\Des\LanceurDes;
use App\Engine\Des\LanceurDeterministe;
use App\Models\Groupe;
use App\Models\Joueur;
use App\Models\Personnage;
use App\Models\Quete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Joueur authentifiable connecté sur le guard `joueur` (tests Feature).
 */
function connecterJoueur(string $pseudo = 'rene'): JoueurAuthentifiable
{
    $joueur = JoueurAuthentifiable::create([
        'pseudo' => $pseudo,
        'identifiant' => $pseudo,
        'mot_de_passe' => 'secret',
    ]);

    test()->actingAs($joueur, 'joueur');

    return $joueur;
}

/**
 * Groupe au hub, prêt à recevoir des héros (création directe en base —
 * la création via l'API est couverte par les tests du flux complet).
 */
function creerGroupe(string $identifiant = 'table-1', int $nbQuetes = 3): Groupe
{
    return Groupe::create([
        'identifiant' => $identifiant,
        'nom' => 'Les Lames du Crépuscule',
        'theme' => 'Cryptes maudites sous la cité',
        'longueur' => 'courte',
        'nb_quetes_total' => $nbQuetes,
        'phase' => 'hub',
    ]);
}

/**
 * Héros actif du groupe (stats barbare niveau 1 par défaut, doc 01 §4),
 * attaché au pivot avec son ordre d'initiative.
 *
 * @param  array<string, mixed>  $attributs
 */
function creerHeros(
    Joueur $joueur,
    Groupe $groupe,
    string $nom,
    int $ordre,
    array $attributs = [],
): Personnage {
    $personnage = Personnage::create(array_merge([
        'joueur_id' => $joueur->id,
        'groupe_actif_id' => $groupe->id,
        'nom' => $nom,
        'classe' => 'barbare',
        'niveau' => 1,
        'attribut_body' => 4,
        'attribut_mind' => 2,
        'pv_body_max' => 8,
        'pv_body' => 8,
        'pv_mind_max' => 2,
        'pv_mind' => 2,
        'des_attaque' => 3,
        'des_defense' => 2,
        'deplacement_base' => 4,
    ], $attributs));

    $groupe->personnages()->attach($personnage->id, ['ordre_initiative' => $ordre, 'actif' => true]);

    return $personnage;
}

/**
 * Fige la file de dés servie au moteur (LanceurDeterministe au conteneur).
 *
 * @param  list<int>  $valeurs  valeurs de d6 (1-3 = crâne, 4-5 = bouclier blanc, 6 = noir)
 */
function desFiges(array $valeurs): LanceurDeterministe
{
    $lanceur = new LanceurDeterministe($valeurs);
    app()->instance(LanceurDes::class, $lanceur);

    return $lanceur;
}

/**
 * Démarre une quête (un héros « alice/Albrecht »), remplace le premier monstre
 * par le bloc catalogue `$nomMonstre` placé au contact du héros, réinitialise ses
 * usages de Dread et révèle tout. Setup générique réutilisable par les tests de
 * comportement des monstres (capacités tactiques, sorciers nommés…).
 *
 * @param  array<string, mixed>  $herosAttrs  surcharges de stats du héros
 * @return array{alice: \App\Auth\JoueurAuthentifiable, groupe: \App\Models\Groupe, heros: \App\Models\Personnage, quete: \App\Models\Quete, instance: \App\Models\InstanceMonstre, etatHeros: \App\Models\EtatPersonnageQuete}
 */
function demarrerQueteAvecMonstre(string $nomMonstre, array $herosAttrs = []): array
{
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $heros = creerHeros($alice, $groupe, 'Albrecht', 1, $herosAttrs);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = \App\Models\Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->instancesMonstres()->update(['revele' => true]);

    $catalogue = \App\Models\Monstre::where('nom_base', $nomMonstre)->firstOrFail();

    $instance = $quete->instancesMonstres()->orderBy('id')->firstOrFail();
    $quete->instancesMonstres()->whereKeyNot($instance->id)->update(['etat' => 'vaincu']);
    $instance->update([
        'monstre_id' => $catalogue->id,
        'pv_body' => $catalogue->pv_body,
        'pv_mind' => $catalogue->pv_mind,
        'etat' => 'actif',
        'elite' => false,
    ]);
    $instance->refresh()->load('monstre');

    app(\App\Partie\MoteurDread::class)->reinitialiserUsagesInstance($instance, $quete);

    $etatHeros = \App\Models\EtatPersonnageQuete::where('quete_id', $quete->id)
        ->where('personnage_id', $heros->id)->firstOrFail();

    $contact = caseAdjacenteLibre($quete, (int) $etatHeros->position_x, (int) $etatHeros->position_y);
    $instance->update(['position_x' => $contact['x'], 'position_y' => $contact['y']]);
    $instance->refresh();

    return compact('alice', 'groupe', 'heros', 'quete', 'instance', 'etatHeros');
}

/**
 * La case (x, y) de la carte de la quête est-elle traversable et inoccupée ?
 */
function caseQueteLibre(Quete $quete, int $x, int $y): bool
{
    $cases = $quete->carte->grille['cases'];

    if (! in_array($cases[$y][$x] ?? 'm', ['s', 'p'], true)) {
        return false;
    }

    foreach ($quete->etatsPersonnages()->get() as $e) {
        if ((int) $e->position_x === $x && (int) $e->position_y === $y) {
            return false;
        }
    }

    foreach ($quete->instancesMonstres()->where('etat', 'actif')->get() as $i) {
        if ((int) $i->position_x === $x && (int) $i->position_y === $y) {
            return false;
        }
    }

    return true;
}

/**
 * Ouvre TOUTES les portes de la carte de la quête. Les portes inter-salles sont
 * `fermee` par défaut (correctifs E2) : une salle n'est normalement découverte
 * qu'une fois sa porte ouverte par un héros. Ce raccourci sert aux scénarios qui
 * partent « voie déjà ouverte » (sinon des monstres révélés resteraient — à
 * raison — bloqués derrière leur porte).
 */
function ouvrirToutesLesPortes(Quete $quete): void
{
    $carte = $quete->carte;
    $grille = $carte->grille;

    foreach ($grille['portes'] ?? [] as $i => $porte) {
        $grille['portes'][$i]['etat'] = 'ouverte';
        $grille['portes'][$i]['revele'] = true;
    }

    $carte->update(['grille' => $grille]);
    $quete->refresh();
}

/**
 * Première case traversable LIBRE orthogonalement adjacente à (x, y).
 *
 * @return array{x: int, y: int}
 */
function caseAdjacenteLibre(Quete $quete, int $x, int $y): array
{
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if (caseQueteLibre($quete, $x + $dx, $y + $dy)) {
            return ['x' => $x + $dx, 'y' => $y + $dy];
        }
    }

    throw new RuntimeException('Aucune case libre adjacente — scénario de test invalide.');
}

/**
 * Pose une carte de fouille AU SOMMET du deck de la quête, pour rendre la
 * prochaine « Fouiller — trésor » déterministe.
 *
 * Remplace le pilotage par `desFiges()` de l'ancien tirage pondéré : la fouille
 * ne consomme plus aucun dé, et cette forme dit explicitement ce qu'on teste
 * (`['issue' => 'piege']`) au lieu d'un « d6=6 » à décoder.
 *
 * @param  array<string, mixed>  $carte
 */
function empilerCarteFouille(Quete $quete, array $carte): void
{
    $quete->update(['deck_fouille' => [$carte, ...$quete->deckFouille()]]);
    $quete->refresh();
}

/**
 * Désigne la salle-coffre (celle qui abrite l'artefact) et l'arme qu'elle
 * contient. `$objetId = null` force le repli en or.
 */
function poserCoffreArtefact(Quete $quete, int $salle, ?int $objetId): void
{
    $quete->update(['salle_artefact' => $salle, 'artefact_objet_id' => $objetId]);
    $quete->refresh();
}

/**
 * Achève la quête courante comme le ferait le vote de sortie.
 *
 * Depuis que la quête ne se termine plus d'elle-même à la mort du dernier
 * monstre (les héros gardent la main pour fouiller), les tests qui portent sur
 * les EFFETS de fin de quête — butin, montée de niveau, purge des snapshots,
 * clôture — doivent la clore explicitement. Le rituel du vote, lui, a son
 * propre test.
 *
 * @return array<string, mixed>
 */
function acheverLaQuete(Groupe $groupe): array
{
    $groupe = $groupe->fresh();
    $quete = $groupe->queteCourante;

    if ($quete === null) {
        return [];
    }

    return app(App\Partie\ResolveurTour::class)->terminerQuete($groupe, $quete);
}
