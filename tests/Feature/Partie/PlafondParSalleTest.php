<?php

declare(strict_types=1);

use App\Models\Monstre;
use App\Models\Quete;
use App\Partie\DemarreurQuete;
use App\Partie\MonstreErrant;
use App\Partie\ResolveurTour;
use App\Partie\Salles;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Plafond de monstres par salle et monstre errant qui monte (René, 2026-11-08 : « ajouter plus de monstres n'est
 * pas nécessairement plus difficile car souvent ils sont coincés entre des meubles ou le cadre de porte. Il
 * faudrait revoir les ratios de monstres plus gros vs la quantité » ; « ajuste aussi les monstres errants pour des
 * monstres de plus en plus forts »).
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, ConditionSeeder::class,
        SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);

    config(['jeu.rencontres.forts_par_quete' => 1, 'jeu.rencontres.forts_escalade_arc' => 2]);
});

/** @return list<Monstre> */
function plafondAcheter(int $budget, int $places, int $positionArc): array
{
    $demarreur = app(DemarreurQuete::class);
    $methode = new ReflectionMethod($demarreur, 'acheterMonstres');

    return $methode->invoke($demarreur, [], $budget, $places, $positionArc);
}

/** @param list<Monstre> $achats */
function plafondForts(array $achats): array
{
    return array_values(array_filter($achats, fn (Monstre $m) => $m->tier === 'base' && (int) $m->cout > 3));
}

it('à gros budget, les places manquent : le budget achète des forts au lieu de la masse', function () {
    // Le budget de la prochaine quête du Trio (3 héros niveau 5, quête 5), et assez de cases pour tout acheter.
    $sansPlafond = plafondAcheter(budget: 114, places: 99, positionArc: 5);
    // Six salles de quatre places (héros + 1).
    $avecPlafond = plafondAcheter(budget: 114, places: 24, positionArc: 5);

    // Sans plafond : le minimum de forts (1 + (5 − 1) / 2 = 3), tout le reste en masse.
    expect(plafondForts($sansPlafond))->toHaveCount(3)
        ->and(count($sansPlafond))->toBeGreaterThan(40);

    // Avec : jamais plus que les places, et la majorité de forts.
    expect(count($avecPlafond))->toBeLessThanOrEqual(24)
        ->and(count(plafondForts($avecPlafond)))->toBeGreaterThan(count($avecPlafond) - count(plafondForts($avecPlafond)))
        ->and(array_sum(array_map(fn (Monstre $m) => (int) $m->cout, $avecPlafond)))->toBeLessThanOrEqual(114);
});

it('à petit budget, rien ne change : le minimum de forts, puis la masse', function () {
    // Une première quête de trois héros niveau 1 : quatre salles de quatre places.
    $achats = plafondAcheter(budget: 23, places: 16, positionArc: 1);

    expect(plafondForts($achats))->toHaveCount(1)
        ->and(count($achats))->toBeGreaterThan(5);
});

it('fait tourner les forts : aucun ne revient avant que les autres soient passés', function () {
    $forts = plafondForts(plafondAcheter(budget: 114, places: 24, positionArc: 5));
    $parNom = array_count_values(array_map(fn (Monstre $m) => $m->nom_base, $forts));

    // Plus de forts que de sortes de forts : la rotation fait au plus un écart d'un exemplaire entre deux sortes.
    expect(count($forts))->toBeGreaterThan(count($parNom))
        ->and(max($parNom) - min($parNom))->toBeLessThanOrEqual(1);
});

it('réordonne les cases de spawn : au plus N par salle en tête, la salle finale d\'abord, rien de perdu', function () {
    $salles = [
        ['x' => 0, 'y' => 0, 'largeur' => 5, 'hauteur' => 5],
        ['x' => 10, 'y' => 0, 'largeur' => 5, 'hauteur' => 5],
        ['x' => 20, 'y' => 0, 'largeur' => 5, 'hauteur' => 5],
    ];
    // Round-robin d'`AssembleurCarte::spawnsMonstres()` : salle finale (2), puis 1, puis 2, 1, 2, 2, 2.
    $spawns = [
        ['x' => 21, 'y' => 1], ['x' => 11, 'y' => 1], ['x' => 22, 'y' => 1], ['x' => 12, 'y' => 1],
        ['x' => 23, 'y' => 1], ['x' => 21, 'y' => 2], ['x' => 22, 'y' => 2],
    ];

    $methode = new ReflectionMethod(DemarreurQuete::class, 'spawnsPlafonnes');
    [$liste, $places] = $methode->invoke(app(DemarreurQuete::class), ['salles' => $salles, 'spawn_monstres' => $spawns], 2);

    expect($places)->toBe(4)
        ->and(array_slice($liste, 0, 4))->toBe([$spawns[0], $spawns[1], $spawns[2], $spawns[3]])
        ->and($liste[0])->toBe($spawns[0])
        ->and($liste)->toHaveCount(count($spawns));
});

it('en jeu : aucune salle ne reçoit plus de monstres que de héros + 1', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    // Des héros solides : le budget dépasse ce que la carte peut loger, c'est là que le plafond mord.
    foreach (['Albrecht', 'Brunhild', 'Cyrielle'] as $i => $nom) {
        creerHeros($alice, $groupe, $nom, $i + 1, ['niveau' => 6, 'des_attaque' => 5, 'des_defense' => 5]);
    }

    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $salles = $quete->carte->grille['salles'];

    $parSalle = [];
    foreach ($quete->instancesMonstres()->get() as $instance) {
        $salle = Salles::indexDe($salles, (int) $instance->position_x, (int) $instance->position_y);
        $parSalle[$salle ?? 'hors salle'] = ($parSalle[$salle ?? 'hors salle'] ?? 0) + 1;
    }

    expect($parSalle)->not->toBeEmpty()
        ->and(max($parSalle))->toBeLessThanOrEqual(4);
});

it('l\'errant monte avec la quête : Orque, Squelette ou Zombie à la 1, Guerrier du Chaos à la 5, Gargouille ensuite', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['boites_bestiaire' => []]); // jeu de base seul
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $errant = function (int $arc) use ($quete): ?string {
        $quete->position_arc = $arc;

        return app(MonstreErrant::class)->deLaQuete($quete)?->nom_base;
    };

    expect($errant(1))->toBeIn(['Orque', 'Squelette', 'Zombie'])
        ->and($errant(2))->toBe('Fimir')
        ->and($errant(4))->toBeIn(['Momie', 'Abomination'])
        ->and($errant(5))->toBe('Guerrier du Chaos')
        ->and($errant(6))->toBe('Gargouille')
        ->and($errant(12))->toBe('Gargouille')
        // Le MÊME pour une quête donnée : aucun tirage.
        ->and($errant(4))->toBe($errant(4));

    $couts = array_map(fn (int $arc) => (int) Monstre::query()->where('nom_base', $errant($arc))->value('cout'), range(1, 8));
    expect($couts)->toBe(collect($couts)->sort()->values()->all());
});

it('l\'errant n\'est jamais un tireur, une Attaque 0, une embuscade ni une figure passive', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['boites_bestiaire' => Monstre::query()->whereNotNull('boite')->distinct()->pluck('boite')->all()]);
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    foreach (range(1, 10) as $arc) {
        foreach ([0, 1, 2, 3] as $decalage) {
            $quete->position_arc = $arc;
            $quete->groupe_id = $groupe->id + $decalage; // fait tourner la rotation à coût égal
            $quete->setRelation('groupe', $groupe);
            $monstre = app(MonstreErrant::class)->deLaQuete($quete);

            expect($monstre)->not->toBeNull()
                ->and($monstre->portee)->toBe('corps_a_corps')
                ->and((int) $monstre->attaque)->toBeGreaterThan(0)
                ->and($monstre->aCapaciteEmbuscade())->toBeFalse()
                ->and(array_key_exists('passif_jusqua', (array) $monstre->capacites))->toBeFalse()
                ->and($monstre->variante_distance_de)->toBeNull();
        }
    }
});

it('en jeu : la carte « monstre errant » fait surgir l\'errant de la quête', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $groupe->update(['boites_bestiaire' => []]);
    creerHeros($alice, $groupe, 'Albrecht', 1);
    $this->postJson('/api/groupes/table-1/quetes')->assertCreated();
    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    $quete->update(['position_arc' => 5]);

    $methode = new ReflectionMethod(ResolveurTour::class, 'spawnErrant');
    $errant = $methode->invoke(app(ResolveurTour::class), $quete->fresh(), $quete->etatsPersonnages()->first());

    expect($errant)->not->toBeNull()
        ->and($errant->monstre->nom_base)->toBe('Guerrier du Chaos')
        ->and($errant->revele)->toBeTrue();
});
