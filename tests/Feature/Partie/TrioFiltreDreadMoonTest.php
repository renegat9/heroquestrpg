<?php

declare(strict_types=1);

use App\Models\Groupe;
use App\Partie\BestiaireGroupe;

/*
 * La campagne « Trio » passe au FILTRE Rise of the Dread Moon (René, 2026-11-06) — migration
 * `2026_11_06_120000_trio_filtre_dread_moon`. Elle ne vise que ce groupe, et seulement tant qu'il est en rotation
 * historique.
 */

function migrationTrio(): object
{
    return require base_path('database/migrations/2026_11_06_120000_trio_filtre_dread_moon.php');
}

function groupeTrio(): Groupe
{
    $groupe = new Groupe([
        'identifiant' => 'trio-7cb4', 'nom' => 'Trio', 'theme' => 'Donjon classique', 'longueur' => 'normale',
        'nb_quetes_total' => 7, 'phase' => 'hub', 'theme_bestiaire' => 'dread_moon',
    ]);
    $groupe->id = 120;
    $groupe->save();

    return $groupe;
}

it('passe Trio de la préférence Dread Moon au filtre Dread Moon, et elle seule', function () {
    $trio = groupeTrio();
    $autre = creerGroupe('autre');
    $autre->update(['theme_bestiaire' => 'dread_moon']);

    expect(BestiaireGroupe::duGroupe($trio)->autorise('jungles_delthrak'))->toBeTrue();

    migrationTrio()->up();
    $bestiaire = BestiaireGroupe::duGroupe($trio->fresh());

    expect($bestiaire->mode)->toBe(BestiaireGroupe::MODE_MANUEL)
        ->and($bestiaire->boites)->toBe(['dread_moon'])
        ->and($bestiaire->autorise('jungles_delthrak'))->toBeFalse()
        ->and($bestiaire->autorise('base'))->toBeTrue()
        ->and($bestiaire->autorise(null))->toBeTrue()
        // Archer et Guerrier elfes : The Mage of the Mirror les PARTAGE avec Dread Moon.
        ->and($bestiaire->autorise(['mage_du_miroir', 'dread_moon']))->toBeTrue()
        ->and($bestiaire->autorise('mage_du_miroir'))->toBeFalse()
        ->and($bestiaire->libelle())->toBe('HeroQuest Game System + Rise of the Dread Moon')
        ->and($trio->fresh()->theme_bestiaire)->toBe('dread_moon')
        ->and(BestiaireGroupe::duGroupe($autre->fresh())->mode)->toBe(BestiaireGroupe::MODE_AUTO);
});

it('ne touche pas un Trio déjà réglé, et down() rend la rotation historique', function () {
    $trio = groupeTrio();
    $trio->update(['boites_bestiaire' => ['horde_ogre'], 'origine_boites' => BestiaireGroupe::MODE_MJ]);

    migrationTrio()->up();
    expect($trio->fresh()->boites_bestiaire)->toBe(['horde_ogre']);

    $trio->update(['boites_bestiaire' => null, 'origine_boites' => null]);
    migrationTrio()->up();
    migrationTrio()->up();
    migrationTrio()->down();

    $bestiaire = BestiaireGroupe::duGroupe($trio->fresh());
    expect($bestiaire->mode)->toBe(BestiaireGroupe::MODE_AUTO)
        ->and($bestiaire->boites)->toBe(['dread_moon']);
});
