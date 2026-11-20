<?php

declare(strict_types=1);

use App\Partie\Classique\CatalogueConstructeur;
use App\Partie\Classique\ValidationCampagne;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\MercenaireSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortSeeder;
use Database\Seeders\TerrainSeeder;

/*
 * LE CONSTRUCTEUR SUIT SA VALIDATION (2026-11-20). Le matin même, quatre réglages ajoutés pour les livrets étaient
 * acceptés par `ValidationCampagne` et joués par le moteur, mais l'écran n'avait aucune commande pour `captif` ni
 * `inactif`, et le catalogue publié ne donnait pas les sorts que `vulnerable_uniquement_a` accepte : une IA branchée
 * sur le serveur MCP ne pouvait pas trouver le slug. Deux contrôles, du côté où la dérive arrive :
 *  - chaque clé de document que la validation accepte est NOMMÉE dans le code de l'écran ;
 *  - chaque catalogue que la validation consulte est PUBLIÉ par `CatalogueConstructeur::payload()`.
 * ⚠ Le premier ne prouve que la présence du nom, pas un éditeur juste (il n'aurait pas vu l'effet `eveiller` affiché
 * avec l'éditeur des apparitions) : la checklist de la skill `constructeur-de-campagnes` reste la règle.
 */

/** Les listes de clés de la validation, lues par réflexion : la seule source, jamais recopiée ici. */
function clesDeLaValidation(): array
{
    $cles = [];
    foreach ((new ReflectionClass(ValidationCampagne::class))->getReflectionConstants() as $constante) {
        if (str_starts_with($constante->getName(), 'CLES_') && is_array($constante->getValue())) {
            // `CLES_FIN` est rangée par type de fin : on n'en garde que les noms de clés, à plat.
            $valeurs = [];
            $tableau = $constante->getValue();
            array_walk_recursive($tableau, function ($v) use (&$valeurs) { $valeurs[] = $v; });
            $cles[$constante->getName()] = array_values(array_unique(array_filter($valeurs, 'is_string')));
        }
    }

    return $cles;
}

it('chaque clé que la validation accepte est nommée dans l\'écran du constructeur', function () {
    $ecran = implode("\n", array_map('file_get_contents', [
        resource_path('js/views/ConstructeurView.vue'),
        ...glob(resource_path('js/components/constructeur/*.vue')),
    ]));
    // Les clés sans commande à l'écran, chacune avec sa raison : un choix écrit, jamais un oubli.
    $horsEcran = [];

    $manquantes = [];
    foreach (clesDeLaValidation() as $liste => $cles) {
        foreach ($cles as $cle) {
            if (! isset($horsEcran[$cle]) && ! preg_match('/\b'.preg_quote($cle, '/').'\b/', $ecran)) {
                $manquantes[] = "$liste.$cle";
            }
        }
    }

    expect(clesDeLaValidation())->toHaveKeys(['CLES_QUETE_OPTIONNELLES', 'CLES_MONSTRE_OPTIONNELLES', 'CLES_REGLES'])
        ->and($manquantes)->toBe([]);
});

it('chaque catalogue que la validation consulte est publié aux deux clients (écran et MCP)', function () {
    $this->seed([ClasseHerosSeeder::class, MobilierSeeder::class, MonstreSeeder::class, PiegeSeeder::class, TerrainSeeder::class,
        ObjetSeeder::class, MercenaireSeeder::class, SortSeeder::class]);
    $payload = app(CatalogueConstructeur::class)->payload();
    $definitions = (new ReflectionClassConstant(CatalogueConstructeur::class, 'DEFINITIONS'))->getValue();

    $nonPublies = array_values(array_filter(array_keys($definitions), fn (string $couche) => ! array_key_exists($couche, $payload['couches']) && ! array_key_exists($couche, $payload)));

    expect($nonPublies)->toBe([])
        // `mercenaires` publie les recrutables ; les captifs à escorter ont leur propre liste.
        ->and($payload)->toHaveKey('captifs');
});
