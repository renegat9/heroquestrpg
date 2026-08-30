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

it('couvre chaque ÉTAT DE PORTE par une illustration, et pas un de plus', function () {
    // ⚠ Les états viennent de `MoteurPortes::ETAT_*` et le contrat les publie
    // tels quels. Un état ajouté au moteur sans entrée de config retomberait en
    // silence sur l'emblème SVG ; une entrée que plus aucun état ne porte ferait
    // générer une image que rien n'irait jamais chercher.
    $reflet = new ReflectionClass(App\Partie\MoteurPortes::class);
    $etats = array_values(array_filter(
        $reflet->getConstants(),
        fn ($v, $c) => str_starts_with($c, 'ETAT_'),
        ARRAY_FILTER_USE_BOTH,
    ));

    $declares = array_keys((array) config('images.portes'));

    expect(array_diff($etats, $declares))->toBe([], 'états de porte sans illustration')
        ->and(array_diff($declares, $etats))->toBe([], 'illustrations orphelines');
});

it('publie une illustration pour le levier et pour chaque porte', function () {
    $biblio = app(App\Partie\Images\BibliothequeImages::class);

    // ⚠ Ces deux-là n'ont PAS de table de catalogue : leur accesseur ne prend
    // aucun id, et c'est pour ça qu'il est facile d'oublier de le brancher.
    // Ils sont TOTAUX — jamais `null`, l'emblème SVG prend le relais.
    expect($biblio->urlLevier())->not->toBeNull()
        ->and($biblio->urlPorte('verrouillee'))->not->toBeNull()
        // Un état inconnu ne doit pas rendre `null` : la porte existe quand même.
        ->and($biblio->urlPorte(null))->not->toBeNull()
        // ⚠ Deux états, deux images : une seule les rendrait indiscernables.
        ->and($biblio->urlPorte('fermee'))->not->toBe($biblio->urlPorte('secrete'));
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
