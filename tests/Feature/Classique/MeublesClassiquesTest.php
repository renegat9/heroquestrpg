<?php

declare(strict_types=1);

use App\Models\Mobilier;
use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\ValidationCampagne;
use App\Partie\MoteurMobilier;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Tests\Feature\Classique\DocumentExemple;

/*
 * LE CERCUEIL (2026-11-20, *Witch Lord* p. 4 : « The four coffins may be used as tombs »), la TABLE DU SORCIER ET LE CHEVALET (René, 2026-11-19 : « ajoute les 2 meubles, les livrets sont maîtres en mode
 * classique »). Légende du livret de base (F2847 p. 33) ; emprise mesurée sur les cartes : 3×2, comme la Table. Réservés
 * aux campagnes construites : la génération du mode RPG ne les tire jamais.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

it('la liste des meubles réservés au classique et le catalogue se répondent, dans les deux sens', function () {
    $catalogue = Mobilier::whereIn('nom', MoteurMobilier::RESERVES_AU_CLASSIQUE)->get()->keyBy('nom');

    expect($catalogue->keys()->sort()->values()->all())->toBe(collect(MoteurMobilier::RESERVES_AU_CLASSIQUE)->sort()->values()->all());
    $attendus = [
        // 3×2 mesurée sur les cartes ; la fouille est celle de la SALLE.
        'Table du sorcier' => [[3, 2], false],
        'Chevalet de torture' => [[3, 2], false],
        // « The four coffins may be used as tombs » (F4193 p. 4) : la fouille du tombeau, à l'emprise dessinée 2×1.
        'Cercueil' => [[2, 1], true],
    ];
    expect(array_keys($attendus))->toEqualCanonicalizing(MoteurMobilier::RESERVES_AU_CLASSIQUE);
    foreach ($attendus as $nom => [$emprise, $fouillable]) {
        $meuble = $catalogue[$nom];
        expect([(int) $meuble->largeur, (int) $meuble->hauteur])->toBe($emprise)
            ->and((bool) $meuble->fouillable)->toBe($fouillable)
            ->and($meuble->boite)->toBeNull();
    }
    expect(Mobilier::where('nom', 'Cercueil')->value('effet'))->toBe(Mobilier::where('nom', 'Tombeau')->value('effet'));
});

it('la génération du mode RPG ne les tire jamais (même liste, lue par AssembleurCarte)', function () {
    $source = file_get_contents(app_path('Partie/AssembleurCarte.php'));

    expect($source)->toContain('MoteurMobilier::RESERVES_AU_CLASSIQUE');
});

it('une campagne construite les pose : slug du catalogue, emprise tournée, validée et produite', function () {
    $doc = DocumentExemple::campagne();
    // `entree` : x 2-6, y 2-5. Une table du sorcier debout (rot 90 : 2×3) et un chevalet couché (rot 0 : 3×2).
    $doc['quetes'][0]['mobilier'] = [
        ['slug' => 'table-du-sorcier', 'x' => 5, 'y' => 2, 'rotation' => 90],
        ['slug' => 'chevalet-de-torture', 'x' => 7, 'y' => 4, 'rotation' => 0],
    ];
    $doc['quetes'][0]['monstres'] = [['slug' => 'gobelin', 'x' => 3, 'y' => 3]];
    $doc['quetes'][0]['terrain'] = [];

    expect(array_column(app(ValidationCampagne::class)->valider($doc), 'code'))->toBe([]);

    $carte = app(CarteConstruite::class)->produire($doc, $doc['quetes'][0])['carte'];
    $poses = collect($carte['mobilier'])->map(fn (array $m) => [Mobilier::find($m['mobilier_id'])->nom, $m['l'], $m['h']])->all();
    expect($poses)->toBe([['Table du sorcier', 2, 3], ['Chevalet de torture', 3, 2]]);
});
