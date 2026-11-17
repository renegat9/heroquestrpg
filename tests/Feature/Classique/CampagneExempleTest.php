<?php

declare(strict_types=1);

use App\Partie\Classique\CarteConstruite;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;

/*
 * LA CAMPAGNE D'EXEMPLE (lot X1, `docs/exemples/`) : ORIGINALE, livrée avec le dépôt pour montrer le format de bout en
 * bout. Elle doit rester valide et produire ses cartes à chaque évolution du format — un exemple qui ne passe plus la
 * validation enseignerait un format mort.
 */
beforeEach(function () {
    $this->seed([ClasseHerosSeeder::class, ObjetSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class]);
});

it('valide chaque campagne d\'exemple et produit la carte de chacune de ses quêtes', function (string $fichier) {
    $campagne = json_decode((string) file_get_contents($fichier), true, flags: JSON_THROW_ON_ERROR);

    expect(app(ValidationCampagne::class)->valider($campagne))->toBe([]);
    foreach ($campagne['quetes'] as $quete) {
        expect(app(CarteConstruite::class)->produire($campagne, $quete)['carte']['cases'])->not->toBeEmpty();
    }
})->with(glob(dirname(__DIR__, 3).'/docs/exemples/*.json'));
