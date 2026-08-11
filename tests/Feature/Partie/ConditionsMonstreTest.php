<?php

declare(strict_types=1);

use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;

/*
 * Conditions posées sur un MONSTRE par un sort (2026-08-12).
 *
 * Jusqu'ici chaque condition de monstre était câblée en dur dans
 * `ResolveurTour::sortMental()` — un `if` par nom de sort — et aucune ne
 * touchait ses DÉS. Deux cartes le demandent : Terreur (Warlock) plafonne
 * l'attaque à 1, Ralentissement (répertoire elfique) retire un dé à l'attaque
 * ET à la défense. Ce sont les premières conditions qui changent ce qu'un
 * monstre lance.
 */

it('TERREUR plafonne l\'attaque à 1 dé, quelle que soit la créature', function () {
    $ogre = new Monstre(['attaque' => 4, 'defense' => 4]);

    $instance = new InstanceMonstre(['elite' => false]);
    $instance->setRelation('monstre', $ogre);

    expect($instance->attaqueEffective())->toBe(4);

    // ⚠ C'est un PLAFOND, pas un malus : « attacks are reduced TO 1 combat
    // die ». Un malus de 3 aurait laissé le gobelin à 2 dés au-dessus de lui.
    $instance->habillage = ['conditions' => ['terrifie' => true]];

    expect($instance->attaqueEffective())->toBe(1)
        // …et la DÉFENSE n'est pas touchée : la carte ne parle que d'attaque.
        ->and($instance->defenseEffective())->toBe(4);
});

it('RALENTI retire un dé à l\'attaque ET à la défense, sans jamais descendre sous 1', function () {
    $gobelin = new Monstre(['attaque' => 2, 'defense' => 1]);

    $instance = new InstanceMonstre(['elite' => false]);
    $instance->setRelation('monstre', $gobelin);
    $instance->habillage = ['conditions' => ['ralenti' => true]];

    // « rolls 1 less combat die when it attacks or defends […] cannot be less
    // than 1 » : la défense à 1 ne tombe donc pas à 0.
    expect($instance->attaqueEffective())->toBe(1)
        ->and($instance->defenseEffective())->toBe(1);
});

it('cumule les deux sans jamais annuler les dés', function () {
    $ogre = new Monstre(['attaque' => 4, 'defense' => 4]);

    $instance = new InstanceMonstre(['elite' => false]);
    $instance->setRelation('monstre', $ogre);
    $instance->habillage = ['conditions' => ['terrifie' => true, 'ralenti' => true]];

    // Plafond à 1 PUIS −1 : le plancher rattrape, sinon un monstre à 0 dé
    // d'attaque ne pourrait plus jamais toucher personne.
    expect($instance->attaqueEffective())->toBe(1)
        ->and($instance->defenseEffective())->toBe(3);
});

it('laisse le bonus ÉLITE s\'appliquer avant la condition', function () {
    $gobelin = new Monstre(['attaque' => 2, 'defense' => 1]);

    $instance = new InstanceMonstre(['elite' => true]);
    $instance->setRelation('monstre', $gobelin);

    $sansCondition = $instance->attaqueEffective();

    $instance->habillage = ['conditions' => ['ralenti' => true]];

    expect($instance->attaqueEffective())->toBe($sansCondition - 1);
});
