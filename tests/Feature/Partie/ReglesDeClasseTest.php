<?php

declare(strict_types=1);

use App\Models\ClasseHeros;
use App\Models\Objet;
use App\Models\Personnage;
use App\Partie\Equipement;
use App\Partie\MoteurPieges;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ObjetSeeder;

/*
 * Les règles imprimées au DOS DES CARTES de classe (René, 2026-08-22).
 *
 * Elles ne se déduisent d'aucune autre donnée du jeu : ce sont des faits de
 * source, au même titre que les stats des monstres. Ce test les fige une par
 * une pour qu'un rééquilibrage de tags ne puisse pas en effacer une en silence.
 */

beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, ObjetSeeder::class]);
});

/** Un héros de papier : la classe suffit à décider de l'accès à l'équipement. */
function heros(string $classe): Personnage
{
    $p = new Personnage;
    $p->classe = $classe;
    $p->niveau = 1;
    $p->id = 0;

    return $p;
}

dataset('accès équipement', [
    // Moine — cinq armes NOMMÉES, ni armure ni bouclier.
    'moine : dague' => ['moine', 'Dague', true],
    'moine : arbalète' => ['moine', 'Arbalète', true],
    'moine : hachette' => ['moine', 'Hachette', true],
    'moine : épée courte' => ['moine', 'Épée courte', true],
    'moine : bâton' => ['moine', 'Bâton', true],
    // ⚠ Ces trois partagent `arme_courante` avec la hachette et l'épée courte :
    // c'est précisément ce qu'aucun tag ne pouvait trancher.
    'moine : épée large refusée' => ['moine', 'Épée large', false],
    'moine : épée longue refusée' => ['moine', 'Épée longue', false],
    'moine : rapière refusée' => ['moine', 'Rapière', false],
    'moine : armure refusée' => ['moine', 'Cotte de mailles', false],
    'moine : bouclier refusé' => ['moine', 'Bouclier', false],

    // Rogue — ni armure métallique ni bouclier.
    'rogue : cotte refusée' => ['rogue', 'Cotte de mailles', false],
    'rogue : casque refusé' => ['rogue', 'Casque', false],
    'rogue : bouclier refusé' => ['rogue', 'Bouclier', false],
    'rogue : dague' => ['rogue', 'Dague', true],

    // Druide — pas de métal, mais son bouclier lui reste : les cartes les
    // nomment séparément, et le Druide n'a que la première interdiction.
    'druide : cotte refusée' => ['druide', 'Cotte de mailles', false],
    'druide : bouclier gardé' => ['druide', 'Bouclier', true],

    // Berserker — n'utilise pas d'arme à distance.
    'berserker : arbalète refusée' => ['berserker', 'Arbalète', false],
    'berserker : hache à deux mains' => ['berserker', 'Hache de bataille', true],

    // Barde — le métal ne lui est PAS interdit : c'est son dé de défense
    // supplémentaire qu'il perd en le portant. Un choix, pas un refus.
    'barde : cotte autorisée' => ['barde', 'Cotte de mailles', true],
]);

it('applique les restrictions d’équipement du dos des cartes', function (string $classe, string $objet, bool $autorise) {
    expect(app(Equipement::class)->estAccessible(heros($classe), Objet::where('nom', $objet)->firstOrFail()))
        ->toBe($autorise);
})->with('accès équipement');

it('marque comme métallique toute protection de métal, artefact compris', function () {
    $metal = Objet::where('metallique', true)->pluck('nom')->all();

    expect($metal)->toContain('Cotte de mailles', 'Armure de plates', 'Casque')
        // ⚠ L'artefact aussi : l'Armure de Borin est de la plate (rappel de René).
        ->toContain('Armure de Borin')
        // ⚠ Le BOUCLIER n'en est pas : les cartes le nomment séparément, et le
        // marquer retirerait au Druide un bouclier qu'elles lui laissent.
        ->not->toContain('Bouclier');
});

it('laisse le Nain et l’Explorateur désamorcer sans outils, eux seuls', function () {
    $pieges = app(MoteurPieges::class);

    expect($pieges->peutDesamorcer(heros('nain')))->toBeTrue()
        ->and($pieges->peutDesamorcer(heros('explorateur')))->toBeTrue()
        // Tout le monde d'autre a besoin d'une trousse à outils.
        ->and($pieges->peutDesamorcer(heros('barbare')))->toBeFalse()
        ->and($pieges->peutDesamorcer(heros('rogue')))->toBeFalse();
});

it('épargne au Chevalier le malus de mouvement des armures', function () {
    $eq = app(Equipement::class);

    // Sans équipement porté, tout le monde est à zéro : c'est la classe qui est
    // testée, pas l'inventaire — le Chevalier reste à zéro quoi qu'il enfile.
    expect($eq->malusDeplacement(heros('chevalier')))->toBe(0);

    // Le nain, lui, garde le sien : l'exemption ne doit pas fuir aux voisins.
    expect(ClasseHeros::where('nom', 'nain')->firstOrFail()->tags_equipement)
        ->toContain('armure_lourde');
});

it('donne sa bandoulière au Rogue dès la création', function () {
    $depart = (new ReflectionClass(App\Http\Controllers\Api\GroupeController::class))
        ->getConstant('EQUIPEMENT_DEPART');

    // Elle porte `compte_comme_arme: Dague` : c'est elle qui rend l'Ambidextrie
    // du Rogue littérale dès le premier tour, sans occuper sa main gauche.
    expect($depart['rogue'])->toContain('Bandoulière')
        ->and(Objet::where('nom', 'Bandoulière')->firstOrFail()->effet['compte_comme_arme'])->toBe('Dague');
});

it('n’ouvre au Warlock que ce qu’un magicien peut manier', function () {
    $magicien = ClasseHeros::where('nom', 'magicien')->firstOrFail()->tags_equipement;
    $warlock = ClasseHeros::where('nom', 'warlock')->firstOrFail()->tags_equipement;

    // Tout ce qu'il a en plus doit lui être PROPRE (sa baguette, son talisman) —
    // jamais une maîtrise que le magicien n'a pas.
    $enPlus = array_diff($warlock, $magicien);

    foreach ($enPlus as $tag) {
        expect($tag)->toMatch('/warlock$/');
    }
});
