<?php

declare(strict_types=1);

use App\Mcp\ServeurConstructeur;
use Laravel\Mcp\Server\Tool;

/*
 * Le registre des outils du constructeur, DANS LES DEUX SENS (CLAUDE.md, « registre testé dans les deux sens ») :
 *  - toute classe d'outil de app/Mcp est déclarée sur le serveur, et tout outil déclaré existe comme classe ;
 *  - chaque outil déclaré est servi par tools/list, sous son nom public, et a son fichier de test `Outil<Nom>Test.php`.
 * Les noms publics sont ceux du contrat (docs/contrat-api.md, « Serveur MCP du constructeur ») : les changer est un changement
 * de contrat, pas une retouche.
 */

/** @return list<class-string<Tool>> les outils déclarés sur le serveur, lus dans sa déclaration */
function outilsDeclares(): array
{
    return (new ReflectionClass(ServeurConstructeur::class))->getDefaultProperties()['tools'];
}

/** @return list<class-string<Tool>> toute classe qui hérite de Tool sous app/Mcp, trouvée dans le système de fichiers */
function classesOutilsTrouvees(): array
{
    $racine = app_path('Mcp');
    $trouvees = [];
    $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    foreach ($fichiers as $fichier) {
        if ($fichier->getExtension() !== 'php') {
            continue;
        }
        $relatif = substr($fichier->getPathname(), strlen($racine) + 1, -strlen('.php'));
        $classe = 'App\\Mcp\\'.str_replace('/', '\\', $relatif);
        if (class_exists($classe) && is_subclass_of($classe, Tool::class)) {
            $trouvees[] = $classe;
        }
    }
    sort($trouvees);

    return $trouvees;
}

it('déclare sur le serveur chaque classe d\'outil de app/Mcp, et aucune autre', function () {
    $declares = outilsDeclares();
    sort($declares);
    $trouvees = classesOutilsTrouvees();

    expect(array_values(array_diff($trouvees, $declares)))->toBe([]) // une classe d'outil sans déclaration
        ->and(array_values(array_diff($declares, $trouvees)))->toBe([]) // une déclaration sans classe
        ->and($trouvees)->not->toBeEmpty();
});

it('sert chaque outil déclaré sous son nom public, exactement ceux du contrat', function () {
    $noms = array_map(fn (string $classe): string => (new $classe)->name(), outilsDeclares());

    expect($noms)->toBe([
        'catalogues', 'campagnes', 'campagne_lire', 'quete_lire', 'apercu', 'campagne_ecrire',
        'quete_ecrire', 'quete_modifier', 'valider', 'exporter', 'importer',
    ]);
    ServeurConstructeur::tools()->assertRegistered(outilsDeclares());
});

it('a un fichier de test par outil déclaré', function () {
    $sansTest = array_values(array_filter(
        outilsDeclares(),
        fn (string $classe): bool => ! file_exists(__DIR__.'/'.class_basename($classe).'Test.php'),
    ));

    expect($sansTest)->toBe([]);
});
