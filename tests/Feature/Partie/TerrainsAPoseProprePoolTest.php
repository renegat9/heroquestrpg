<?php

declare(strict_types=1);

use App\Models\GabaritQuete;
use App\Models\Terrain;
use App\Partie\AssembleurCarte;
use App\Partie\BestiaireGroupe;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\ConditionSeeder;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\GabaritQueteSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;
use Database\Seeders\TuileSeeder;

/*
 * UN TERRAIN À POSE PROPRE N'ENTRE PAS DANS LE POOL ORDINAIRE (vu à l'écran le 2026-10-30).
 *
 * Les terrains des tours (lot H des restes) ont leur propre règle de pose (`terrains.pose` : une salle ENTIÈRE, ou tous les
 * couloirs), dosée par le gabarit (`courants`, `sol_meuble`, `courants_porteurs`, `vents`). La pose ORDINAIRE de
 * `placerTerrains()` les tirait AUSSI, case par case, comme n'importe quel terrain du thème : une partie de test montrait une
 * case isolée de courant contraire, une de sol meuble, et une de vent au milieu d'une salle. Les tests du lot H ne le voyaient
 * pas : ils assemblaient avec un pool ordinaire vide (`terrains.min/max = 0`).
 */

beforeEach(function () {
    config(['services.anthropic.api_key' => null]);
    $this->seed([ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class, TuileSeeder::class,
        GabaritQueteSeeder::class, PiegeSeeder::class, ObjetSeeder::class, SortSeeder::class,
        ConditionSeeder::class, MobilierSeeder::class, EpreuveSeeder::class, TerrainSeeder::class]);
});

/** Une carte au pool ORDINAIRE généreux, sans aucune pose propre demandée par le gabarit. */
function carteAuPoolOrdinaire(int $graine, BestiaireGroupe $bestiaire): array
{
    $gabarit = GabaritQuete::query()->where('type_jalon', 'normale')->firstOrFail()->replicate();
    $gabarit->structure = [...$gabarit->structure, 'terrains' => [
        'min' => 6, 'max' => 6,
        'courants' => ['min' => 0, 'max' => 0], 'sol_meuble' => ['min' => 0, 'max' => 0],
        'courants_porteurs' => ['min' => 0, 'max' => 0], 'vents' => ['min' => 0, 'max' => 0],
    ]];

    return app(AssembleurCarte::class)->assembler($gabarit, $graine, bestiaire: $bestiaire);
}

it('ne tire jamais un terrain à pose propre dans le pool ordinaire, même sous son thème', function () {
    $aPosePropre = Terrain::query()->whereNotNull('pose')->pluck('nom', 'id');
    expect($aPosePropre)->not->toBeEmpty();

    // Spirit Queen pour ses terrains à pose propre, Delthrak pour un pool ordinaire qui pose bien quelque chose.
    $bestiaire = BestiaireGroupe::manuel(['spirit_queen', 'jungles_delthrak']);
    $ordinairesPoses = 0;

    foreach (range(1, 25) as $graine) {
        $carte = carteAuPoolOrdinaire($graine * 7919, $bestiaire);

        foreach ((array) $carte['terrain'] as $t) {
            expect($aPosePropre->has($t['terrain_id']))
                ->toBeFalse("graine {$graine} : « {$aPosePropre->get($t['terrain_id'])} » tiré case par case en ({$t['x']},{$t['y']})");
            $ordinairesPoses++;
        }
    }

    expect($ordinairesPoses)->toBeGreaterThan(0, 'le pool ordinaire n\'a rien posé : le test ne prouve rien');
});
