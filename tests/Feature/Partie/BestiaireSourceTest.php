<?php

declare(strict_types=1);

use App\Models\Monstre;
use Database\Seeders\MonstreSeeder;

/**
 * Provenance du BESTIAIRE (reference/16_armurerie.md §4.4-4.6).
 *
 * Trois origines, et elles ne doivent pas se mélanger :
 *
 *  1. les **8 monstres de base**, dont les stats viennent des cartes monstre
 *     (`sjeng-monsters.pdf`) et sont recoupées par deux passages des livrets ;
 *  2. les **créatures d'extension**, dont les stats viennent des LIVRETS
 *     officiels via `reference/18_extensions.md` — meilleure source que les
 *     cartes de fans, qui divergent sur plusieurs valeurs ;
 *  3. nos **créations** (Champion, Liche, Seigneur…), qui n'ont pas de carte et
 *     n'en auront pas.
 *
 * Ce fichier fige (1) et (2) : ce sont les seules valeurs opposables, et rien
 * ne doit les faire dériver en silence.
 */
beforeEach(function () {
    $this->seed([MonstreSeeder::class]);
});

/** [déplacement, attaque, défense, body, mind] du catalogue, par nom. */
function statsDe(string $nom): array
{
    $m = Monstre::where('nom_base', $nom)->firstOrFail();

    return [$m->deplacement, $m->attaque, $m->defense, $m->pv_body, $m->pv_mind];
}

it('porte les 8 monstres de base exactement comme leurs cartes', function () {
    $cartes = [
        'Gobelin' => [10, 2, 1, 1, 1],
        'Squelette' => [6, 2, 2, 1, 0],
        'Zombie' => [4, 2, 3, 1, 0],
        'Orque' => [8, 3, 2, 1, 2],
        'Fimir' => [6, 3, 3, 1, 3],
        'Momie' => [4, 3, 4, 1, 0],
        'Guerrier du Chaos' => [6, 3, 4, 1, 3],
        'Gargouille' => [6, 4, 4, 1, 4],
    ];

    foreach ($cartes as $nom => $attendu) {
        expect(statsDe($nom))->toBe($attendu, "{$nom} : bloc de stats");
    }
});

it('donne 1 SEUL point de Body à tout monstre de base, comme au plateau', function () {
    // C'est le cœur du design : les héros encaissent (4 à 8 Body), la piétaille
    // tombe d'un coup réussi. On donnait 2 ou 3 aux plus costauds, ce qui
    // écrasait la lisibilité des paliers sous_boss/boss.
    //
    // Plus d'exception : le Troll y a séjourné un jour, et un test de jeu a
    // montré qu'un monstre à 3 PV dans un palier où tout le monde en a 1
    // transforme la première rencontre en anéantissement (2026-08-10).
    $trop = Monstre::where('tier', 'base')
        ->where('pv_body', '>', 1)
        ->pluck('nom_base')
        ->all();

    // Les créatures d'extension du palier `base` sont, elles, plus robustes :
    // leurs fiches officielles le disent (Archer elfe 3 Body, Raptor 2…).
    $extensions = ['Gremlin des glaces', 'Archer elfe', 'Guerrier elfe', 'Assassin',
        'Raptor', 'Crâne putride'];

    expect(array_values(array_diff($trop, $extensions)))->toBe([]);
});

it('porte les créatures d\'extension telles que les livrets les chiffrent', function () {
    // Valeurs de reference/18_extensions.md, tirées des livrets Hasbro. Quand
    // les cartes de fans divergent (Gremlin des glaces à 2 Body, Ours polaire à
    // 3+3), c'est le LIVRET qui gagne — règle du doc 16.
    $livrets = [
        // Rise of the Dread Moon
        'Cultiste du Dread' => [7, 2, 2, 1, 2],
        'Assassin' => [10, 5, 3, 2, 3],
        'Garde-mage' => [8, 4, 4, 3, 3],
        'Spectre' => [8, 3, 3, 1, 0],
        'Ombre du Dread' => [9, 6, 4, 5, 5],
        // The Mage of the Mirror
        'Guerrier elfe' => [6, 4, 3, 3, 2],
        'Loup géant' => [9, 6, 3, 5, 1],
        'Ogre' => [4, 6, 4, 5, 2],
        // The Frozen Horror
        'Gremlin des glaces' => [10, 2, 3, 3, 3],
        'Yéti' => [8, 3, 3, 5, 2],
        'Horreur des Glaces' => [8, 5, 4, 6, 4],
        // Against the Ogre Horde
        'Ogre guerrier' => [6, 5, 4, 5, 1],
        'Ogre champion' => [6, 5, 4, 6, 1],
        'Ogre commandant' => [4, 6, 5, 6, 2],
        'Seigneur ogre' => [4, 6, 6, 10, 5],
        // Jungles of Delthrak
        'Rejeton putride' => [3, 0, 0, 1, 0],
        'Tisseur putride' => [7, 2, 2, 1, 2],
        'Crâne putride' => [6, 3, 2, 2, 0],
        'Raptor' => [8, 3, 2, 2, 3],
        'Rampant putride' => [7, 4, 4, 3, 4],
        'Serpent géant' => [8, 4, 3, 6, 3],
        'Singe géant' => [8, 4, 3, 7, 5],
    ];

    foreach ($livrets as $nom => $attendu) {
        expect(statsDe($nom))->toBe($attendu, "{$nom} : bloc de stats");
    }
});

it('donne aux créatures à distance leur attaque de tir ET leur malus au contact', function () {
    // « Attack 4 (1 si adjacent) » : deux valeurs distinctes, pas une.
    foreach (['Archer elfe' => 4, 'Gobelin archer' => 2, 'Archer squelette' => 2] as $nom => $tir) {
        $m = Monstre::where('nom_base', $nom)->firstOrFail();

        expect($m->portee)->toBe('distance', "{$nom} : portée")
            ->and((int) $m->attaque_distance)->toBe($tir, "{$nom} : dés en tir")
            ->and((int) $m->attaque)->toBeLessThan($tir, "{$nom} : doit perdre des dés au contact");
    }
});

it('n\'accorde aucune capacité que le moteur n\'applique pas', function () {
    // Même règle que pour les clés d'objet : une capacité déclarée sans lecteur
    // est une promesse faite au joueur que rien ne tient. Les traits des
    // extensions qu'on ne sait pas porter (Agile, Venomous, Spawn…) sont donc
    // ABSENTS du seeder, et documentés en reference/16 §4.6.
    $implementees = ['invocation', 'frappe_de_zone', 'regeneration',
        'resistance_magique', 'charge', 'choix_attaque', 'vol', 'peur',
        // Mots-clés de Jungles of Delthrak, portés le 2026-08-10…
        'agile', 'venimeux', 'tacticien', 'racines_entravantes', 'spawn', 's_accroche',
        // …et l'éthéré de Rise of the Dread Moon.
        'ethere'];

    $inconnues = collect(Monstre::all())
        ->flatMap(fn (Monstre $m) => array_map(
            fn ($cle, $valeur) => is_int($cle) ? $valeur : $cle,
            array_keys((array) $m->capacites),
            (array) $m->capacites,
        ))
        ->unique()
        ->reject(fn ($c) => in_array($c, $implementees, true))
        ->values()
        ->all();

    expect($inconnues)->toBe([], 'capacité(s) sans lecteur : '.implode(', ', $inconnues));
});

it('donne à chaque créature de Jungles le trait que son livret lui prête', function () {
    // Les 4 mots-clés portés (p. 48-49). *Spawn* et la double-action du
    // tacticien restent dehors, faute de mécanique — reference/16 §4.6.
    $traits = [
        // Attaque 0 : il ne frappe pas, il s'accroche (jeton sur la fiche).
        'Rejeton putride' => ['agile', 's_accroche'],
        'Crâne putride' => ['racines_entravantes'],
        'Raptor' => ['tacticien'],
        'Rampant putride' => ['agile', 'venimeux'],
        'Serpent géant' => ['venimeux'],
        'Singe géant' => ['agile'],
    ];

    foreach ($traits as $nom => $attendus) {
        $capacites = (array) Monstre::where('nom_base', $nom)->firstOrFail()->capacites;

        foreach ($attendus as $trait) {
            expect(in_array($trait, $capacites, true))->toBeTrue("{$nom} devrait porter « {$trait} »");
        }
    }
});

it('interdit au palier `base` la créature ENDURANTE — celle qu\'on ne peut pas tuer', function () {
    // Ce qui a fait un anéantissement le 2026-08-10 n'est pas la puissance de
    // frappe : c'est l'ENDURANCE. Le Troll cumulait 3 PV et 4 dés de défense
    // dans un palier où tout le monde a 1 PV — un barbare lui arrachait 0,98 PV
    // par attaque là où il tue n'importe quel autre monstre de base d'un coup,
    // pendant que le troll rendait 1,40 PV par coup.
    //
    // À l'inverse, l'Assassin frappe à 5 dés et c'est très bien : avec 2 PV et
    // 3 dés de défense, il meurt en deux coups. Une brute de verre est un
    // danger jouable ; une brute qui encaisse est un sous-boss déguisé, et le
    // budget de rencontre la lâche sur des héros de niveau 1 sans talent.
    $endurants = Monstre::where('tier', 'base')
        ->where('pv_body', '>=', 3)
        ->where('defense', '>=', 4)
        ->pluck('nom_base')
        ->all();

    expect($endurants)->toBe([], 'créature(s) trop endurantes pour le palier base : '.implode(', ', $endurants));

    // …et le coût le plus cher du palier reste sous celui du palier au-dessus.
    $maxBase = (int) Monstre::where('tier', 'base')->max('cout');
    $minSousBoss = (int) Monstre::where('tier', 'sous_boss')->min('cout');

    expect($maxBase)->toBeLessThan($minSousBoss, 'les paliers se chevauchent en coût');
});
