<?php

declare(strict_types=1);

use App\Models\Monstre;
use Database\Seeders\MonstreSeeder;

/*
 * LA DROITE DES BOSS SANS LE SEIGNEUR (René, 2026-10-31 : « Oui applique cette droite »).
 *
 * Trois repères, aucun lu sur le Seigneur retiré : Ogre commandant (N 9,0 → 15), Seigneur ogre (N 20,0 → 22) et Gruzbella
 * (N 21,25 → 23). Droite : cout = (13335 + 940 × N) / 1454, arrondi au plus proche. `coutDroiteBoss()` (tests/Pest.php) est
 * la SEULE source de la formule. `docs/regles/bestiaire-et-rencontres.md`, « La droite des boss sans le Seigneur ».
 *
 * La table ci-dessous épingle, pour CHAQUE boss du catalogue, son N (et d'où vient ce N) et son cout = droite(N), repères
 * compris : ils tombent dessus à ±0 après arrondi. Le registre se teste dans les deux sens.
 */

beforeEach(function () {
    $this->seed([MonstreSeeder::class]);
});

/**
 * Une entrée par boss. `noms` : les lignes du catalogue (une chaîne de phases partage UN cout, lu sur la somme de ses N) ;
 * `N` : le N déclaré ; `cout` : le cout attendu après le chantier ; `repere` : un des trois repères de la droite ; `source` :
 * d'où vient N. Un N = `Body / (1,5 − Défense/6)` par forme, sommé sur la chaîne.
 *
 * @return array<string, array{noms: list<string>, N: float, cout: int, repere: bool, source: string}>
 */
function tableDesBossDroite(): array
{
    return [
        'Ogre commandant' => ['noms' => ['Ogre commandant'], 'N' => 9.0, 'cout' => 15, 'repere' => true,
            'source' => 'Fiche 6/5 (reference/20 p. 23, 4/6/5/6/2). Cout posé au 2026-08-10 (commit e31e288), sans mesure.'],
        'Seigneur ogre' => ['noms' => ['Seigneur ogre'], 'N' => 20.0, 'cout' => 22, 'repere' => true,
            'source' => 'Fiche 10/6 (reference/18 §4.6). Cout posé au 2026-08-10, au-dessus de l\'ancien Seigneur.'],
        'Gruzbella' => ['noms' => ['Gruzbella Hammerhand', 'Gruzbella Déterminée', 'Gruzbella Imprudente'],
            'N' => 10.0 + 7.5 + 3.75, 'cout' => 23, 'repere' => true,
            'source' => 'Trois phases 5/6, 5/5, 5/1 (Against the Ogre Horde q. 3 p. 21). Cout posé le 2026-10-05.'],
        'Sir Ragnar' => ['noms' => ['Sir Ragnar'], 'N' => 6.0, 'cout' => 13, 'repere' => false,
            'source' => 'Fiche 4/5 (Rise of the Dread Moon q. 9 p. 31). L\'increvable ne compte pas dans N.'],
        'Horreur des Glaces' => ['noms' => ['Horreur des Glaces'], 'N' => 7.2, 'cout' => 14, 'repere' => false,
            'source' => 'Fiche 6/4 (The Frozen Horror p. 37). La résistance magique ne joue que contre les sorts.'],
        'Magrian' => ['noms' => ['Magrian'], 'N' => 14.4, 'cout' => 18, 'repere' => false,
            'source' => 'Base 6,0 (fiche 5/4) + Consume Magic 2,4 + sort perdu 1 + Reflection 1 + Terror 1 + trois sorts 3 (F6646 p. 33).'],
        'Archimage elfe' => ['noms' => ['Archimage elfe'], 'N' => 4.8, 'cout' => 12, 'repere' => false,
            'source' => 'Fiche 4/4 (The Mage of the Mirror p. 30). Les sorts ne sont pas comptés.'],
        'Festral' => ['noms' => ['Festral'], 'N' => 4.5, 'cout' => 12, 'repere' => false,
            'source' => 'Fiche 3/5 (Against the Ogre Horde q. 8 p. 35).'],
        'Venim le Dragon' => ['noms' => ['Venim le Dragon'], 'N' => 6.0, 'cout' => 13, 'repere' => false,
            'source' => 'Fiche 4/5 (The Crypt of Perpetual Darkness q. 10 p. 25).'],
        'Gretzl' => ['noms' => ['Gretzl la Porte-Fléau', 'Demonspider', 'Demonape'],
            'N' => 5 + 4.8 + 36 / 7, 'cout' => 19, 'repere' => false,
            'source' => 'Trois phases 5/3, 4/4, 6/2 (Jungles of Delthrak q. 12A).'],
        'Gruulob' => ['noms' => ['Gruulob, Sorcier Gobelin Corrompu', 'Gruulob, Forme Démoniaque'],
            'N' => 4.8 + 4.5, 'cout' => 15, 'repere' => false,
            'source' => 'Deux formes 4/4 et 3/5 (Jungles of Delthrak q. 8 p. 27).'],
        'Scion Fleur-Pourrie' => ['noms' => ['Scion Fleur-Pourrie'], 'N' => 18.0, 'cout' => 21, 'repere' => false,
            'source' => 'Fiche 12/5 (Jungles of Delthrak q. 14 voie B p. 39). Le sacrifice de Vrille n\'est pas compté.'],
        'Dragon' => ['noms' => ['Dragon'], 'N' => 10.5, 'cout' => 16, 'repere' => false,
            'source' => 'Fiche 7/5 (carte Dragon, © 2024 ; reference/18 §6.1bis).'],
        'Qwindrak the Warlock' => ['noms' => ['Qwindrak the Warlock'],
            'N' => (6 + 2 + 4.5 + 3.6 + 0.75 + 6 / 7 + 10.5) * 5 / 3 + 3, 'cout' => 42, 'repere' => false,
            'source' => 'Sept formes (28,207) × 5/3 pour le météore, + 3 sorts (First Light p. 7 et p. 31, note C). N exact 50,012 : '
                .'arrondi à 50,0, il donnerait 41.'],
        'Artificière' => ['noms' => ['Artificière'], 'N' => 5.0, 'cout' => 12, 'repere' => false,
            'source' => 'Fiche 5/3 (Wizards of Morcar G1504 p. 41).'],
        'Fellmarak le Roi Sorcier' => ['noms' => ['Fellmarak le Roi Sorcier'], 'N' => 7.2, 'cout' => 14, 'repere' => false,
            'source' => 'Fiche 6/4 (Prophecy of Telor G0052 p. 31).'],
        'Gargouille gardienne' => ['noms' => ['Gargouille gardienne'], 'N' => 6.0, 'cout' => 13, 'repere' => false,
            'source' => 'Fiche 4/5 (Kellar\'s Keep q. 10 n.C p. 27). L\'immunité aux sorts n\'est pas chiffrée.'],
        'Nelath, la Reine des Esprits' => ['noms' => ['Nelath, la Reine des Esprits'], 'N' => 7.2, 'cout' => 14, 'repere' => false,
            'source' => 'Fiche 6/4 (Spirit Queen\'s Torment G0053 q. 14 p. 33).'],
        'Seigneur Sorcier' => ['noms' => ['Seigneur Sorcier'], 'N' => 8.0, 'cout' => 14, 'repere' => false,
            'source' => 'Fiche 4/6 (Return of the Witch Lord F4193 p. 27).'],
        'Seigneur Sorcier de Barak Tor' => ['noms' => ['Seigneur Sorcier de Barak Tor'], 'N' => 8.0, 'cout' => 14, 'repere' => false,
            'source' => 'Fiche 4/6 (jeu de base, F2847 quête 14, LQ p. 31).'],
    ];
}

it('la table couvre TOUS les boss du catalogue, et seulement eux : registre dans les deux sens', function () {
    $declares = collect(tableDesBossDroite())->flatMap(fn (array $e) => $e['noms'])->sort()->values()->all();
    $catalogue = Monstre::where('tier', 'boss')->pluck('nom_base')->sort()->values()->all();

    expect(array_diff($declares, $catalogue))->toBe([], 'boss déclaré absent du catalogue')
        ->and(array_diff($catalogue, $declares))->toBe([], 'boss du catalogue non déclaré')
        ->and(count($catalogue))->toBe(25, 'vingt boss, vingt-cinq lignes avec leurs phases');
});

it('chaque N déclaré est la somme des N de ses fiches (une entrée sans ajout)', function () {
    $fiche = function (string $nom): float {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();

        return $m->pv_body / (1.5 - $m->defense / 6);
    };

    foreach (tableDesBossDroite() as $cle => $e) {
        if (in_array($cle, ['Magrian', 'Qwindrak the Warlock'], true)) {
            continue; // composition écrite, testée à part
        }

        $somme = array_sum(array_map($fiche, $e['noms']));

        expect(abs($somme - $e['N']))->toBeLessThan(1e-6, "{$cle} : N déclaré {$e['N']}, fiches {$somme}");
    }
});

it('Magrian : N = base 6,0 + Consume Magic 2,4 + sort perdu 1 + Reflection 1 + Terror 1 + trois sorts 3', function () {
    $m = Monstre::where('nom_base', 'Magrian')->firstOrFail();
    $base = $m->pv_body / (1.5 - $m->defense / 6);
    $compose = $base + 2 / (1.5 - $m->defense / 6) + 1 + 1 + 1 + 3;

    expect(abs($base - 6.0))->toBeLessThan(1e-6)
        ->and(abs($compose - tableDesBossDroite()['Magrian']['N']))->toBeLessThan(1e-6);
});

it('Qwindrak : N = sept formes lues sur leurs fiches × 5/3 (météore), + 3 sorts', function () {
    $somme = 0.0;

    foreach (['Qwindrak the Warlock', 'Abomination', 'Gargouille', 'Guerrier du Chaos', 'Gobelin', 'Orque', 'Dragon'] as $nom) {
        $forme = Monstre::where('nom_base', $nom)->firstOrFail();
        $somme += $forme->pv_body / (1.5 - $forme->defense / 6);
    }

    expect(abs($somme * (1 + 2 / 3) + 3 - tableDesBossDroite()['Qwindrak the Warlock']['N']))->toBeLessThan(1e-6);
});

it('les trois repères tombent sur la droite à ±0 après arrondi, et les coefficients sont les moindres carrés exacts', function () {
    $xs = [9.0, 20.0, 21.25];
    $ys = [15, 22, 23];
    $moyX = array_sum($xs) / 3;
    $moyY = array_sum($ys) / 3;
    $pente = array_sum(array_map(fn ($x, $y) => ($x - $moyX) * ($y - $moyY), $xs, $ys))
        / array_sum(array_map(fn ($x) => ($x - $moyX) ** 2, $xs));
    $ordonnee = $moyY - $pente * $moyX;

    expect(abs($pente - 940 / 1454))->toBeLessThan(1e-9)
        ->and(abs($ordonnee - 13335 / 1454))->toBeLessThan(1e-9);

    foreach ($xs as $i => $x) {
        expect(abs($pente * $x + $ordonnee - $ys[$i]))->toBeLessThan(0.5)
            ->and(coutDroiteBoss($x))->toBe($ys[$i]);
    }
});

it('chaque boss porte le cout de la droite à son N, repères compris, et le cout figé par la table', function () {
    foreach (tableDesBossDroite() as $cle => $e) {
        foreach ($e['noms'] as $nom) {
            $m = Monstre::where('nom_base', $nom)->firstOrFail();

            expect($m->tier)->toBe('boss', $nom)
                ->and((int) $m->cout)->toBe(coutDroiteBoss($e['N']), "{$nom} : cout ≠ droite({$e['N']})")
                ->and((int) $m->cout)->toBe($e['cout'], "{$nom} : cout figé");
        }
    }
});

it('la migration 2026_10_31_130000 remet les lignes existantes à EXACTEMENT ce que le seeder écrit, et se défait', function () {
    $avant = [
        'Sir Ragnar' => 14, 'Horreur des Glaces' => 16, 'Magrian' => 20, 'Festral' => 11, 'Venim le Dragon' => 16,
        'Gretzl la Porte-Fléau' => 18, 'Demonspider' => 18, 'Demonape' => 18, 'Scion Fleur-Pourrie' => 22,
        'Qwindrak the Warlock' => 38, 'Fellmarak le Roi Sorcier' => 16, 'Nelath, la Reine des Esprits' => 16,
    ];

    $ecrit = [];
    foreach (array_keys($avant) as $nom) {
        $ecrit[$nom] = (int) Monstre::where('nom_base', $nom)->firstOrFail()->cout;
    }

    // Les lignes telles qu'elles étaient AVANT le chantier.
    foreach ($avant as $nom => $cout) {
        Monstre::where('nom_base', $nom)->update(['cout' => $cout]);
    }

    $migration = require database_path('migrations/2026_10_31_130000_droite_des_boss_sans_le_seigneur.php');
    $migration->up();
    $migration->up(); // rejouer ne change rien

    foreach ($ecrit as $nom => $cout) {
        expect((int) Monstre::where('nom_base', $nom)->firstOrFail()->cout)->toBe($cout, $nom);
    }

    $migration->down();

    foreach ($avant as $nom => $cout) {
        expect((int) Monstre::where('nom_base', $nom)->firstOrFail()->cout)->toBe($cout, "{$nom} : retour arrière");
    }
});
