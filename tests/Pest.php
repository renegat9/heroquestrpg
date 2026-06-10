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
