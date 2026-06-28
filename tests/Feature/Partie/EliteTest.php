<?php

declare(strict_types=1);

use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Quete;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TuileSeeder;
use Illuminate\Support\Facades\Http;

/*
 * Monstres élites (Phase 2, 3.6) : bonus fixe +1 attaque / +1 défense / +1 PV Body
 * appliqué à une fraction des monstres de base à l'apparition.
 */

beforeEach(function () {
    Http::fake();
    config(['services.anthropic.api_key' => null]);

    $this->seed([
        ClasseHerosSeeder::class, SortSeeder::class, ObjetSeeder::class,
        MonstreSeeder::class, SortDreadSeeder::class,
        TuileSeeder::class, GabaritQueteSeeder::class, PiegeSeeder::class,
    ]);
});

it('applique le bonus élite aux stats de combat effectives', function () {
    $monstre = new Monstre(['attaque' => 3, 'defense' => 2, 'attaque_distance' => 4]);

    $base = new InstanceMonstre(['elite' => false]);
    $base->setRelation('monstre', $monstre);

    expect($base->attaqueEffective())->toBe(3)
        ->and($base->defenseEffective())->toBe(2)
        ->and($base->attaqueDistanceEffective())->toBe(4);

    $elite = new InstanceMonstre(['elite' => true]);
    $elite->setRelation('monstre', $monstre);

    expect($elite->attaqueEffective())->toBe(4)
        ->and($elite->defenseEffective())->toBe(3)
        ->and($elite->attaqueDistanceEffective())->toBe(5);
});

it('ne crée aucun élite quand la variance est inactive', function () {
    config(['jeu.elite.actif' => false]);
    // Dés généreux (le démarrage tire un d6 pour le menu de déplacement) ;
    // la variance élite inactive ne tire AUCUN dé (retour anticipé).
    desFiges(array_fill(0, 50, 4));

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);
    expect($quete->instancesMonstres()->where('elite', true)->count())->toBe(0);
});

it('marque les monstres de base comme élites avec +1 PV quand la variance est active', function () {
    config(['jeu.elite.actif' => true, 'jeu.elite.seuil_d6' => 6]);
    // Que des 6 → chaque monstre de base devient élite (le boss/sous-boss ne tire pas).
    desFiges(array_fill(0, 50, 6));

    $alice = connecterJoueur('alice');
    $groupe = creerGroupe();
    creerHeros($alice, $groupe, 'Albrecht', 1);

    test()->postJson('/api/groupes/table-1/quetes')->assertCreated();

    $quete = Quete::findOrFail($groupe->fresh()->quete_courante_id);

    $elitesBase = $quete->instancesMonstres()
        ->where('elite', true)
        ->whereHas('monstre', fn ($q) => $q->where('tier', 'base'))
        ->with('monstre')
        ->get();

    expect($elitesBase)->not->toBeEmpty();

    foreach ($elitesBase as $instance) {
        // PV courant à l'apparition = PV catalogue + 1 (bonus élite).
        expect((int) $instance->pv_body)->toBe((int) $instance->monstre->pv_body + 1);
    }

    // Aucun sous-boss/boss n'est élite.
    expect(
        $quete->instancesMonstres()
            ->where('elite', true)
            ->whereHas('monstre', fn ($q) => $q->whereIn('tier', ['sous_boss', 'boss']))
            ->count()
    )->toBe(0);
});
