<?php

declare(strict_types=1);

use App\Models\Inventaire;
use App\Models\Objet;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\SortSeeder;

/*
 * Migration 2026_11_13_100000 : le doublon « Parchemin : Unlearn » (reste du renommage du 2026-10-08) disparaît du
 * catalogue, et toute ligne de sac qui le portait passe sur « Parchemin : Désapprentissage » — rien n'est perdu.
 */

beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, SortSeeder::class, ObjetSeeder::class]);
});

it('retire le parchemin fantôme et reporte le sac sur Désapprentissage', function () {
    $bon = Objet::where('nom', 'Parchemin : Désapprentissage')->firstOrFail();
    $fantome = Objet::create([...collect($bon->getAttributes())->except(['id', 'created_at', 'updated_at'])->all(), 'nom' => 'Parchemin : Unlearn']);

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    $hero = creerHeros($alice, $groupe, 'Aldric', 1, ['classe' => 'magicien']);
    $ligne = Inventaire::create(['personnage_id' => $hero->id, 'objet_id' => $fantome->id, 'emplacement' => 'consommable']);

    (require database_path('migrations/2026_11_13_100000_retirer_parchemin_unlearn_fantome.php'))->up();

    expect(Objet::where('nom', 'Parchemin : Unlearn')->exists())->toBeFalse()
        ->and((int) $ligne->fresh()->objet_id)->toBe((int) $bon->id)
        ->and(Objet::where('categorie', 'parchemin')->where('effet->sort_nom', 'Désapprentissage')->count())->toBe(1);
});

it('ne fait rien sans fantôme', function () {
    $avant = Objet::count();

    (require database_path('migrations/2026_11_13_100000_retirer_parchemin_unlearn_fantome.php'))->up();

    expect(Objet::count())->toBe($avant);
});
