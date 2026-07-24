<?php

declare(strict_types=1);

use App\Models\Monstre;
use App\Models\Parametre;
use App\Partie\DemarreurQuete;
use Database\Seeders\MonstreSeeder;

/*
 * Composition des rencontres (correctifs §3, config jeu.rencontres) : le budget
 * achète « BEAUCOUP de faibles + QUELQUES forts » au lieu de se remplir avec les
 * monstres de base les plus chers. On teste directement acheterMonstres (méthode
 * privée) pour un résultat déterministe, indépendant de la carte.
 */

beforeEach(function () {
    $this->seed(MonstreSeeder::class);
});

/** @return list<App\Models\Monstre> */
function acheter(int $budget, int $maxSpawns, int $positionArc, array $structure = []): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');
    $methode->setAccessible(true);

    return $methode->invoke($demarreur, $structure, $budget, $maxSpawns, $positionArc);
}

$forts = fn (array $a) => array_values(array_filter($a, fn ($m) => $m->tier === 'base' && (int) $m->cout > 3));
$faibles = fn (array $a) => array_values(array_filter($a, fn ($m) => $m->tier === 'base' && (int) $m->cout <= 3));

it('sème beaucoup de faibles et peu de forts (quête normale, arc 1)', function () use ($forts, $faibles) {
    $achats = acheter(budget: 20, maxSpawns: 20, positionArc: 1);

    expect(count($faibles($achats)))->toBeGreaterThan(count($forts($achats)))
        ->and(count($forts($achats)))->toBeLessThanOrEqual(1) // forts_par_quete = 1 à l'arc 1
        // Beaucoup plus d'ennemis que l'ancien glouton (qui prenait ~3 Gargouilles).
        ->and(count($achats))->toBeGreaterThanOrEqual(6)
        // Le budget n'est jamais dépassé.
        ->and(array_sum(array_map(fn ($m) => (int) $m->cout, $achats)))->toBeLessThanOrEqual(20);
});

it('la surcharge Parametre::rencontres_forts_par_quete prime sur config(jeu.rencontres.forts_par_quete)', function () use ($forts) {
    // Baseline déterministe (indépendante de .env) : à l'arc 1, forts_escalade_arc
    // n'ajoute rien (numérateur nul), donc fortsSouhaites == forts_par_quete pile.
    config(['jeu.rencontres.forts_par_quete' => 1, 'jeu.rencontres.forts_escalade_arc' => 0]);

    $sansSurcharge = count($forts(acheter(budget: 30, maxSpawns: 20, positionArc: 1)));
    expect($sansSurcharge)->toBe(1);

    Parametre::actuel()->update(['rencontres_forts_par_quete' => 5]);

    $avecSurcharge = count($forts(acheter(budget: 30, maxSpawns: 20, positionArc: 1)));
    expect($avecSurcharge)->toBe(5);
});

it('escalade le nombre de forts avec la progression d\'arc', function () use ($forts) {
    $arc1 = count($forts(acheter(budget: 30, maxSpawns: 20, positionArc: 1)));
    $arc5 = count($forts(acheter(budget: 30, maxSpawns: 20, positionArc: 5)));

    // forts_escalade_arc = 2 → +1 fort tous les 2 crans : l'arc 5 en a plus que l'arc 1.
    expect($arc5)->toBeGreaterThan($arc1);
});

it('garde la rencontre finale en tête et n\'empêche pas la masse de faibles', function () use ($faibles) {
    $achats = acheter(budget: 30, maxSpawns: 20, positionArc: 1, structure: [
        'rencontre_finale' => ['tier' => 'boss'],
    ]);

    expect($achats[0]->tier)->toBe('boss')            // le boss d'abord (inchangé)
        ->and(count($faibles($achats)))->toBeGreaterThan(0); // et de la piétaille autour
});

it('ne bloque jamais : budget serré → au moins un ennemi faible', function () {
    $achats = acheter(budget: 1, maxSpawns: 5, positionArc: 1);

    expect($achats)->not->toBeEmpty()
        ->and((int) $achats[0]->cout)->toBeLessThanOrEqual(3);
});

/*
 * PV des boss adaptés à la taille du groupe (correctifs §3, config jeu.rencontres).
 * Un Seigneur figé à 10 PV punit un duo ; il doit fondre pour eux et enfler pour
 * une grande table. La piétaille (Gobelin) ne s'adapte jamais.
 */
function pvAdapte(Monstre $monstre, int $nbHeros): int
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'pvAdapte');
    $methode->setAccessible(true);

    return $methode->invoke($demarreur, $monstre, $nbHeros);
}

it('adapte les PV du boss à la taille du groupe', function () {
    $seigneur = Monstre::where('nom_base', 'Seigneur')->firstOrFail(); // boss, 10 PV

    expect(pvAdapte($seigneur, 4))->toBe(10)  // référence : PV catalogue inchangés
        ->and(pvAdapte($seigneur, 2))->toBe(5) // duo : 10 × 2/4
        ->and(pvAdapte($seigneur, 6))->toBe(15); // grande table : 10 × 6/4
});

it('ne descend jamais un boss sous 40 % de ses PV (plancher)', function () {
    $seigneur = Monstre::where('nom_base', 'Seigneur')->firstOrFail();

    // Solo : 10 × 1/4 = 2.5 → plancher ceil(10 × 0.4) = 4.
    expect(pvAdapte($seigneur, 1))->toBe(4);
});

it('n\'adapte que les pivots : la piétaille garde ses PV catalogue', function () {
    $gobelin = Monstre::where('nom_base', 'Gobelin')->firstOrFail(); // base, 1 PV

    expect(pvAdapte($gobelin, 6))->toBe(1)
        ->and(pvAdapte($gobelin, 2))->toBe(1);
});

/*
 * Facteur de jalon du boss ADOUCI à bas niveau (§3) : un groupe débutant
 * affronte un boss avec moins de serviteurs autour ; le facteur monte vers son
 * plein au fil des niveaux.
 */
function facteurJalon(App\Models\Groupe $groupe, string $type): float
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'facteurJalon');
    $methode->setAccessible(true);

    return (float) $methode->invoke($demarreur, $groupe->fresh(), $type);
}

it('adoucit le facteur boss à bas niveau et le rend plein à niveau élevé (§3)', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1); // niveau 1 → facteur de début

    expect(facteurJalon($groupe, 'boss_final'))->toEqualWithDelta(1.1, 0.001)  // adouci
        ->and(facteurJalon($groupe, 'normale'))->toBe(1.0);                     // pas de facteur hors boss

    // Héros montés au niveau plein → facteur boss_final plein (1.5).
    $groupe->personnages()->update(['niveau' => 3]);
    expect(facteurJalon($groupe, 'boss_final'))->toEqualWithDelta(1.5, 0.001)
        ->and(facteurJalon($groupe, 'sous_boss'))->toEqualWithDelta(1.25, 0.001);
});
