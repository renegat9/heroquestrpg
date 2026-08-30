<?php

declare(strict_types=1);

use App\Partie\EtatGroupe;
use App\Partie\Salles;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * « QUELLE SALLE CONTIENT CETTE CASE ? » — un seul lecteur, et un rectangle
 * publié sous brouillard (2026-08-29).
 *
 * ⚠ La question était posée à SIX endroits, chacun avec sa boucle : deux dans
 * `ResolveurTour`, une dans `DemarreurQuete`, une dans `AssembleurCarte`, une
 * closure de brouillard et le filtre des leviers dans `EtatGroupe`. Elles
 * disaient toutes la même chose, ce qui est exactement le risque : la règle est
 * trop simple pour qu'on remarque qu'une copie a dérivé — un `<=` au lieu d'un
 * `<` sur un seul site, et une case de bord change de salle selon qui regarde.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([ClasseHerosSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class]);
});

it('borne la salle au rectangle, haute exclue', function () {
    $salles = [
        ['x' => 10, 'y' => 20, 'largeur' => 3, 'hauteur' => 2],
        ['x' => 40, 'y' => 5, 'largeur' => 4, 'hauteur' => 4],
    ];

    expect(Salles::indexDe($salles, 10, 20))->toBe(0)          // coin haut-gauche : dedans
        ->and(Salles::indexDe($salles, 12, 21))->toBe(0)       // coin bas-droit : dedans
        // ⚠ La borne haute est EXCLUE : (13,20) et (10,22) sont la case d'APRÈS.
        ->and(Salles::indexDe($salles, 13, 20))->toBeNull()
        ->and(Salles::indexDe($salles, 10, 22))->toBeNull()
        ->and(Salles::indexDe($salles, 41, 6))->toBe(1)
        // Un couloir n'appartient à aucune salle, et `null` n'est pas « salle 0 ».
        ->and(Salles::indexDe($salles, 25, 25))->toBeNull()
        ->and(Salles::indexDe([], 0, 0))->toBeNull();
});

it('ne publie que les rectangles des salles DÉCOUVERTES', function () {
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $groupe = $ctx['groupe'];
    $grille = $ctx['quete']->carte->grille;

    $publiees = app(EtatGroupe::class)->payload($groupe->fresh())['carte']['salles'];

    // Au premier tour, seule la salle de départ est découverte.
    expect($publiees)->toHaveCount(1)
        ->and($publiees[0]['index'])->toBe(0)
        ->and(count($grille['salles']))->toBeGreaterThan(1, 'donjon à une seule salle : le test ne prouve rien');

    // ⚠ C'est une fuite de BROUILLARD qu'on empêche : `cases` est masqué, mais
    // publier tous les rectangles donnerait le nombre, la taille et la position
    // des salles jamais ouvertes — le brouillard contourné par la porte de
    // derrière.
    $rectangle = $publiees[0];
    $depart = $grille['salles'][0];

    expect([$rectangle['x'], $rectangle['y'], $rectangle['largeur'], $rectangle['hauteur']])
        ->toBe([(int) $depart['x'], (int) $depart['y'], (int) $depart['largeur'], (int) $depart['hauteur']]);
});

it('place le héros de départ dans la salle publiée', function () {
    // Ce que l'aperçu de salle fait à l'écran : trouver la salle du héros actif.
    // Sans cette correspondance, le panneau afficherait « dans un couloir ».
    $ctx = demarrerQueteAvecMonstre('Gobelin');
    $etat = $ctx['heros']->etatsQuete()->where('quete_id', $ctx['quete']->id)->firstOrFail();

    $publiees = app(EtatGroupe::class)->payload($ctx['groupe']->fresh())['carte']['salles'];

    expect(Salles::indexDe($publiees, (int) $etat->position_x, (int) $etat->position_y))->toBe(0);
});
