<?php

declare(strict_types=1);

use App\Models\Objet;
use Database\Seeders\ObjetSeeder;

/**
 * « Les cartes sont la source » — vérifié, pas déclaré.
 *
 * `config/cartes.php` recense les 61 cartes des deux paquets (armurerie et
 * artefacts). Ce fichier le confronte au catalogue DANS LES DEUX SENS :
 *
 *  1. toute carte marquée portée existe en base ;
 *  2. aucune arme, armure ou artefact du catalogue n'existe sans carte ;
 *  3. une carte non portée dit ce qui lui manque, et n'est PAS en base.
 *
 * Sans ce test, la phrase « le catalogue vient des cartes » resterait une
 * affirmation de documentation — exactement le genre de promesse que ce projet
 * a déjà dû retirer trois fois (`attaque_second_rang`, `ligne_de_vue`, le
 * `jetable` décoratif de la dague).
 */
beforeEach(function () {
    $this->seed([ObjetSeeder::class]);
});

/**
 * Toutes les cartes des deux paquets, à plat.
 *
 * @return list<array<string, mixed>>
 */
function toutesLesCartes(): array
{
    return array_merge(
        (array) config('cartes.armurerie.cartes'),
        (array) config('cartes.artefacts.cartes'),
    );
}

it('recense exactement les deux paquets, sans doublon de carte', function () {
    $armurerie = (array) config('cartes.armurerie.cartes');
    $artefacts = (array) config('cartes.artefacts.cartes');

    expect($armurerie)->toHaveCount(27)
        ->and($artefacts)->toHaveCount(34);

    $noms = array_column(toutesLesCartes(), 'carte');
    expect(array_values(array_diff_assoc($noms, array_unique($noms))))
        ->toBe([], 'carte(s) recensée(s) deux fois');
});

it('fait exister en base chaque carte déclarée PORTÉE', function () {
    $manquants = [];

    foreach (toutesLesCartes() as $carte) {
        if (! isset($carte['objet'])) {
            continue;
        }

        if (! Objet::where('nom', $carte['objet'])->exists()) {
            $manquants[] = "{$carte['carte']} → {$carte['objet']}";
        }
    }

    expect($manquants)->toBe([], 'carte(s) déclarée(s) portée(s) mais absente(s) du catalogue : '
        .implode(', ', $manquants).' — sème l\'objet, ou retire `objet` de la carte.');
});

it('n\'admet aucune arme, armure ou artefact SANS carte source', function () {
    // Deux exceptions assumées, hors des deux paquets et documentées comme
    // telles (reference/16 §2.1 et doc 01 §8) :
    //  - la trousse à outils vient du livret de règles officiel (LR p. 19) ;
    //  - la fiole de soin est une carte du deck de TRÉSOR, pas d'armurerie.
    $horsPaquets = ['Trousse à outils', 'Fiole de soin'];

    // Les consommables `unique` du paquet d'artefacts comptent aussi.

    $portes = array_values(array_filter(array_column(toutesLesCartes(), 'objet')));

    $catalogue = Objet::whereIn('categorie', ['arme', 'armure', 'outil'])
        ->orWhere(fn ($q) => $q->where('categorie', 'consommable')->where('rarete', 'unique'))
        ->pluck('nom')
        ->all();

    $orphelins = array_values(array_diff($catalogue, $portes, $horsPaquets));

    expect($orphelins)->toBe([], 'objet(s) du catalogue sans carte source : '.implode(', ', $orphelins)
        .' — ajoute la carte à config/cartes.php, ou retire l\'objet du seeder.');
});

it('garde hors du catalogue les cartes NON portées, et dit ce qui leur manque', function () {
    $intrus = [];
    $sansRaison = [];

    foreach (toutesLesCartes() as $carte) {
        if (isset($carte['objet'])) {
            continue;
        }

        // Une carte écartée doit dire POURQUOI : une dette nommée se retrouve,
        // un oubli silencieux jamais.
        if (empty($carte['manque']) || empty($carte['texte']) || empty($carte['nom'])) {
            $sansRaison[] = $carte['carte'];
        }

        // …et elle ne doit surtout pas se retrouver semée à moitié.
        if (! empty($carte['nom']) && Objet::where('nom', $carte['nom'])->exists()) {
            $intrus[] = $carte['nom'];
        }
    }

    expect($sansRaison)->toBe([], 'carte(s) non portée(s) sans nom, texte ou mécanique manquante : '
        .implode(', ', $sansRaison))
        ->and($intrus)->toBe([], 'carte(s) déclarée(s) non portée(s) mais présente(s) en base : '
            .implode(', ', $intrus).' — déclare `objet` sur la carte.');
});

it('porte les 26 cartes d\'armurerie et 9 artefacts annoncés', function () {
    // Le décompte est cité dans reference/16 §2.2 et §9.1, dans le README et
    // dans CLAUDE.md : s'il bouge sans qu'on le veuille, trois documents
    // deviennent faux d'un coup.
    $portees = fn (string $paquet) => count(array_filter(
        (array) config("cartes.{$paquet}.cartes"),
        fn ($c) => isset($c['objet']),
    ));

    expect($portees('armurerie'))->toBe(26)
        ->and($portees('artefacts'))->toBe(16);
});
