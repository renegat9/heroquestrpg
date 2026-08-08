<?php

declare(strict_types=1);

use App\Models\Objet;
use Database\Seeders\ObjetSeeder;

/**
 * Conformité de l'armurerie au JEU DE PLATEAU (reference/16_armurerie.md,
 * extrait sourcé des livrets Avalon Hill 2021).
 *
 * Deux ensembles, comme pour les clés d'effet : ce que la source ATTESTE (et
 * qui doit tenir), et ce dont on sait qu'on DIVERGE (et qui doit rester la
 * liste exacte qu'on a acceptée). Ajouter une arme au seeder casse donc le
 * test tant qu'on n'a pas tranché : sourcée, ou divergence assumée.
 *
 * ⚠ Rappel du doc : AUCUN PRIX n'apparaît dans les deux livrets, et presque
 * aucun nombre de dés — ils vivaient sur les cartes équipement, jamais
 * numérisées. Les prix ne sont donc jamais testés ici : il n'y a rien à quoi
 * les comparer.
 */
beforeEach(function () {
    $this->seed([ObjetSeeder::class]);
});

/** Effet d'un objet du catalogue, par son nom. */
function effetDe(string $nom): array
{
    return (array) Objet::where('nom', $nom)->firstOrFail()->effet;
}

it('respecte les effets ATTESTÉS par les livrets', function () {
    // Dague : 1 dé, déduit de la carte magicien (LR p. 6).
    expect(effetDe('Dague')['des_attaque'])->toBe(1);

    // Bâton : « Some long weapons, like the staff and the longsword, allow you
    // to attack diagonally » (LR p. 14).
    expect(effetDe('Bâton')['attaque_diagonale'])->toBeTrue();

    // Épée large = Broadsword, « the most powerful starting weapon » (LR p. 13)
    // — donc au-dessus de l'épée courte, seule comparaison que le texte permet.
    expect(effetDe('Épée large')['des_attaque'])
        ->toBeGreaterThan(effetDe('Épée courte')['des_attaque']);

    // …et elle n'est PAS citée parmi les armes longues à diagonale (LR p. 14) :
    // le diagramme lui oppose justement le bâton.
    expect(effetDe('Épée large')['attaque_diagonale'] ?? false)->toBeFalse();

    // Arbalète : « daggers and crossbows [...] hit a monster from a distance ».
    expect(effetDe('Arbalète')['portee'])->toBe('distance');

    // Armure de plates : +2 dés de défense (valeur de Borin's Armor, LR p. 7),
    // et elle RALENTIT son porteur — « unlike normal plate mail, this [...]
    // does not slow down its wearer » dit en creux que la normale, si.
    expect(effetDe('Armure de plates')['des_defense'])->toBe(2)
        ->and(effetDe('Armure de plates')['deplacement_sans_d6'])->toBeTrue();

    // Trousse à outils : permet le désamorçage (LR p. 19).
    expect(effetDe('Trousse à outils')['permet_desamorcage'])->toBeTrue();
});

it('donne aux héros l\'arme de départ des livrets', function () {
    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();

    // « The shortsword is the starting weapon of the dwarf and the elf »,
    // « the broadsword is the most powerful starting weapon » (barbare),
    // dague pour le magicien (LR p. 6, p. 13).
    $attendu = [
        'barbare' => 'Épée large',
        'nain' => 'Épée courte',   // donnait une Hachette, arme inexistante au plateau
        'elfe' => 'Épée courte',
        'magicien' => 'Dague',
    ];

    $reflet = new ReflectionClass(App\Http\Controllers\Api\GroupeController::class);
    $depart = $reflet->getConstant('EQUIPEMENT_DEPART');

    foreach ($attendu as $classe => $arme) {
        expect($depart[$classe][0] ?? null)->toBe($arme, "arme de départ du {$classe}");
    }
});

it('ne s\'écarte du plateau que sur les objets DÉJÀ recensés', function () {
    // Objets absents des 43 pages des deux livrets (reference/16 §10). Ils
    // restent — décision de conception —, mais la liste ne doit pas s'allonger
    // sans qu'on le veuille : c'est ce que ce test garde.
    $horsSource = ['Hachette', 'Lance', 'Hache de bataille', 'Cotte de mailles'];

    // Attestés par leur nom dans les livrets (§2).
    $sources = ['Dague', 'Bâton', 'Épée courte', 'Épée large', 'Arbalète',
        'Bouclier', 'Casque', 'Armure de plates', 'Trousse à outils'];

    $catalogue = Objet::whereIn('categorie', ['arme', 'armure', 'outil'])
        ->where('rarete', '!=', 'unique')   // les artefacts sont un autre débat (§10)
        ->pluck('nom')->all();

    $inconnus = array_values(array_diff($catalogue, $sources, $horsSource));

    expect($inconnus)->toBe([], 'objet ni sourcé ni recensé comme écart : '.implode(', ', $inconnus));

    // Et l'inverse : un objet attesté ne doit pas disparaître du catalogue.
    expect(array_values(array_diff($sources, $catalogue)))->toBe([]);
});

it('recense les mécaniques que le plateau n\'atteste pas', function () {
    // Ni l'une ni l'autre n'apparaît dans les livrets (reference/16 §10) :
    //  - la dague officielle est groupée avec l'arbalète comme arme à distance,
    //    et rien ne dit qu'elle se perd — le parallèle va même contre ;
    //  - « inutilisable au contact » n'est écrit nulle part pour l'arbalète.
    // On les conserve (choix de jeu), mais elles sont NOMMÉES ici pour qu'un
    // relecteur sache que ce ne sont pas des lectures du livret.
    expect(effetDe('Dague')['jetable'] ?? false)->toBeTrue()
        ->and(effetDe('Arbalète')['inutilisable_adjacent'] ?? false)->toBeTrue();
});
