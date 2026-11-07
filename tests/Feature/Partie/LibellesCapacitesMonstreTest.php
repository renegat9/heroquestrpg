<?php

declare(strict_types=1);

use App\Engine\MotsClesAdversaires;
use App\Models\Monstre;
use App\Partie\LibellesCapacitesMonstre;
use Database\Seeders\MonstreSeeder;

/*
 * Ce que les capacités d'un monstre disent au joueur — UNE liste, lue par la fiche de la table, le Guide et le livret
 * (René, 2026-11-06 : « partout où on affiche les stats des monstres »). Registre testé dans les deux sens.
 */

beforeEach(function () {
    $this->seed([MonstreSeeder::class]);
});

/** @return list<string> les mots portés par le bestiaire */
function motsDuBestiaire(): array
{
    return Monstre::all()
        ->flatMap(fn (Monstre $m) => array_map(fn (array $e) => $e[0], LibellesCapacitesMonstre::entrees($m->capacites)))
        ->unique()->sort()->values()->all();
}

it('dit tout mot porté par le bestiaire, en phrase ET en forme courte — jamais la clé brute', function () {
    foreach (motsDuBestiaire() as $mot) {
        if (isset(LibellesCapacitesMonstre::MASQUES[$mot])) {
            continue;
        }

        expect(isset(LibellesCapacitesMonstre::LIBELLES[$mot]) || isset(MotsClesAdversaires::CAPACITES[$mot]))
            ->toBeTrue("« {$mot} » n'a pas de phrase")
            ->and(isset(LibellesCapacitesMonstre::COURTS[$mot]))->toBeTrue("« {$mot} » n'a pas de forme courte");
    }
});

it('ne garde aucun libellé que le bestiaire ne porte pas, et ne recopie pas ceux du vocabulaire des adversaires', function () {
    $portes = motsDuBestiaire();

    foreach ([...array_keys(LibellesCapacitesMonstre::LIBELLES), ...array_keys(LibellesCapacitesMonstre::COURTS)] as $mot) {
        expect(in_array($mot, $portes, true))->toBeTrue("« {$mot} » : un libellé qu'aucune créature ne porte");
    }

    // Une seule source pour les mots du vocabulaire des adversaires : leur libellé déclaré.
    expect(array_intersect(array_keys(LibellesCapacitesMonstre::LIBELLES), MotsClesAdversaires::cles()))->toBe([]);
});

it('dit l\'Assassin qui frappe en diagonale, et la valeur des capacités paramétrées', function () {
    $phrases = fn (string $nom) => LibellesCapacitesMonstre::pour(Monstre::where('nom_base', $nom)->firstOrFail()->capacites);

    expect($phrases('Assassin'))->toContain('Attaque en diagonale')
        ->and($phrases('Spectre'))->toContain("Sort à volonté : Canaliser l'Effroi (sans limite d'usage)")
        ->and($phrases('Seigneur Sorcier de Barak Tor'))->toContain('Sorts du Dread (chacun une seule fois par quête, sauf Frayeur 2 fois)')
        ->and($phrases('Kessandria'))->toContain('Immunisé à tous les sorts, sauf ceux de feu');

    // Une règle de GÉNÉRATION ne se montre pas au joueur.
    $crypte = Monstre::all()->first(fn (Monstre $m) => in_array(MotsClesAdversaires::RESERVE_A_SON_THEME, array_map(
        fn (array $e) => $e[0], LibellesCapacitesMonstre::entrees($m->capacites)), true));
    expect(LibellesCapacitesMonstre::pour($crypte->capacites))
        ->not->toContain(MotsClesAdversaires::CAPACITES[MotsClesAdversaires::RESERVE_A_SON_THEME]['libelle']);
});
