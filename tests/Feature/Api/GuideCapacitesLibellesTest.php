<?php

declare(strict_types=1);

use App\Engine\MotsClesAdversaires;
use App\Partie\LibellesCapacitesMonstre;
use Database\Seeders\MonstreSeeder;

/*
 * LE GUIDE NOMME CHAQUE CAPACITÉ D'ADVERSAIRE (vu le 2026-10-31).
 *
 * Une clé que le guide ne connaissait pas retombait sur la clé brute (« immunite sorts sauf ») : c'est ce que montrait
 * le guide pour Kessandria, Balur et le Seigneur Sorcier, et il disait « chacun une seule fois » d'un boss qui lance la
 * Frayeur deux fois. Depuis le 2026-11-06, les phrases viennent du SERVEUR (`LibellesCapacitesMonstre`, publiées en
 * `monstres[].capacites_libelles`), la même liste que la fiche de la table et le livret : `compendium.js` n'en tient
 * plus de copie, et ce fichier le garde.
 */

it('dit chaque mot du vocabulaire des adversaires — sinon il est masqué, pour une raison écrite', function () {
    foreach (MotsClesAdversaires::cles() as $mot) {
        expect(isset(LibellesCapacitesMonstre::MASQUES[$mot]) || LibellesCapacitesMonstre::pour([$mot]) !== [str_replace('_', ' ', $mot)])
            ->toBeTrue("« {$mot} » retomberait sur sa clé brute");
    }
});

it('publie au guide les phrases du serveur, valeur des capacités paramétrées comprise', function () {
    $this->seed([MonstreSeeder::class]);

    $monstres = collect($this->getJson('/api/guide')->assertOk()->json('monstres'))->keyBy('nom_base');

    expect($monstres['Kessandria']['capacites_libelles'])->toContain('Immunisé à tous les sorts, sauf ceux de feu')
        ->and($monstres['Seigneur Sorcier de Barak Tor']['capacites_libelles'])
        ->toContain('Sorts du Dread (chacun une seule fois par quête, sauf Frayeur 2 fois)')
        ->and($monstres['Assassin']['capacites_libelles'])->toContain('Attaque en diagonale');
});

it('ne garde AUCUNE copie des libellés de capacités côté client', function () {
    $traducteur = file_get_contents(resource_path('js/compendium.js'));

    expect($traducteur)->not->toContain('const CAPACITES = {')
        ->and($traducteur)->not->toContain('capacitesVersChips');
});
