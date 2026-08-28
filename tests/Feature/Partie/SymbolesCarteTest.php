<?php

declare(strict_types=1);

use App\Models\Epreuve;
use App\Models\Mobilier;
use App\Models\Piege;
use Database\Seeders\EpreuveSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\PiegeSeeder;

/*
 * CHAQUE SYMBOLE DE LA CARTE A SON ICÔNE, ET RÉCIPROQUEMENT (René, 2026-08-27).
 *
 * `resources/js/components/carte/symboles.js` porte la table des icônes, lue à
 * la fois par le RENDU (`DungeonGrid`) et par la LÉGENDE (`LegendeCarte`).
 *
 * ⚠ Ce fichier confronte cette table au CATALOGUE, dans les deux sens — même
 * discipline que `MotsClesEpreuve`/`MotsClesTalent` pour les effets :
 *  - une entrée de catalogue sans icône retomberait sur le glyphe générique
 *    SANS ERREUR, et la légende annoncerait deux pièges sous le même symbole ;
 *  - une icône qui ne correspond plus à rien traîne dans la table et finit par
 *    donner son glyphe à une entrée renommée.
 *
 * ⚠ On ne teste PAS que le glyphe existe dans Material Symbols — la police est
 * distante, et un nom inconnu s'affiche en toutes lettres. C'est la seule chose
 * que ce test ne peut pas attraper ; elle se voit à l'écran.
 */

/** Clés d'une table `export const NOM = { 'clé': 'valeur', … };` de symboles.js. */
function iconesDeclarees(string $table): array
{
    $source = file_get_contents(base_path('resources/js/components/carte/symboles.js'));

    expect($source)->toContain("export const {$table} = {");

    $debut = strpos($source, "export const {$table} = {");
    $fin = strpos($source, '};', $debut);
    $bloc = substr($source, $debut, $fin - $debut);

    // ⚠ Les deux styles de guillemets : une clé qui porte une apostrophe
    // (« Râtelier d'armes ») est écrite en guillemets doubles. Ne lire que le
    // simple quote faisait passer le test pour les pièges et échouer pour le
    // mobilier — en accusant à tort la table d'être incomplète.
    preg_match_all('/^\s*(?:\'([^\']+)\'|"([^"]+)")\s*:/m', $bloc, $m);

    return array_map(fn (string $simple, string $double) => $simple !== '' ? $simple : $double, $m[1], $m[2]);
}

it('donne une icône à chaque PIÈGE du catalogue, et pas une de plus', function () {
    $this->seed(PiegeSeeder::class);

    $catalogue = Piege::pluck('nom')->all();
    $declarees = iconesDeclarees('PIEGE_ICONES');

    expect(array_diff($catalogue, $declarees))->toBe([], 'pièges sans icône propre')
        ->and(array_diff($declarees, $catalogue))->toBe([], 'icônes orphelines');
});

it('donne une icône à chaque ÉPREUVE du catalogue, et pas une de plus', function () {
    $this->seed(EpreuveSeeder::class);

    $catalogue = Epreuve::pluck('nom')->all();
    $declarees = iconesDeclarees('EPREUVE_ICONES');

    expect(array_diff($catalogue, $declarees))->toBe([], 'épreuves sans icône propre')
        ->and(array_diff($declarees, $catalogue))->toBe([], 'icônes orphelines');
});

it('donne une icône à chaque MEUBLE du catalogue, et pas une de plus', function () {
    $this->seed(MobilierSeeder::class);

    $catalogue = Mobilier::pluck('nom')->all();
    $declarees = iconesDeclarees('MOBILIER_ICONES');

    expect(array_diff($catalogue, $declarees))->toBe([], 'meubles sans icône propre')
        ->and(array_diff($declarees, $catalogue))->toBe([], 'icônes orphelines');
});

it('fait lire la MÊME table au rendu et à la légende', function () {
    // ⚠ C'est l'invariant qui rend la légende fiable : si l'un des deux fichiers
    // se remettait à déclarer ses icônes en propre, la légende décrirait des
    // symboles que la carte ne dessine plus — et elle le ferait avec l'autorité
    // d'une légende.
    foreach (['DungeonGrid.vue', 'LegendeCarte.vue'] as $fichier) {
        $source = file_get_contents(base_path("resources/js/components/carte/{$fichier}"));

        expect(str_contains($source, "from './symboles.js'"))->toBeTrue("{$fichier} n'importe pas symboles.js")
            ->and(str_contains($source, 'const PIEGE_ICONES = {'))->toBeFalse("{$fichier} redéclare une table d'icônes");
    }
});
