<?php

declare(strict_types=1);

use App\Models\Monstre;
use Database\Seeders\MonstreSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * Les JETONS DE MONSTRES des livrets (phase 2 des symboles, 2026-10-29) : une tête dans un anneau, posée sur chaque
 * monstre de la carte. `config/symboles_monstres.php` relie le catalogue (MonstreSeeder, noms français) aux fichiers
 * public/images/livrets/monstres/{cle}.webp (noms des livrets, anglais). Comme pour les meubles et les terrains, la
 * couverture se vérifie DANS LES DEUX SENS : chaque monstre a un jeton OU une raison écrite ; chaque jeton sert un
 * monstre ; chaque nom déclaré existe au catalogue ; le manifeste déclare exactement les jetons utilisés.
 */

function dossierJetonsMonstres(): string
{
    return public_path('images/livrets/monstres');
}

/** Clé du jeton d'un monstre du catalogue : la correspondance écrite, ou Str::slug() du nom (convention). */
function cleJetonDe(string $nom): string
{
    return config('symboles_monstres.cles')[$nom] ?? Str::slug($nom);
}

/**
 * Les clés de jeton réellement utilisées : les monstres du catalogue qui ont un jeton (un monstre déclaré absent
 * n'en a pas, même si son slug tombait sur un fichier par hasard).
 */
function jetonsUtilises(): array
{
    $absents = array_keys(config('symboles_monstres.absents'));
    $cles = [];
    foreach (Monstre::query()->pluck('nom_base')->all() as $nom) {
        if (! in_array($nom, $absents, true)) {
            $cles[] = cleJetonDe($nom);
        }
    }

    return array_values(array_unique($cles));
}

/** Les entrées `monstres` du manifeste (un JSON des coordonnées, aucun pixel). */
function manifesteDesJetons(): array
{
    $donnees = json_decode(
        (string) file_get_contents(base_path('image-tools/symboles-livrets/manifeste.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return array_values(array_filter($donnees['symboles'], fn (array $s) => $s['type'] === 'monstres'));
}

it('la config a la forme attendue, et chaque absence de jeton dit pourquoi', function () {
    $config = config('symboles_monstres');

    expect($config)->toHaveKeys(['cles', 'absents']);
    foreach ($config['absents'] as $nom => $raison) {
        expect(trim((string) $raison))->not->toBe('', "absence sans raison : « {$nom} »");
    }
});

it('chaque monstre du catalogue a un jeton, ou une absence écrite — et l’absence n’est pas un jeton caché', function () {
    $this->seed(MonstreSeeder::class);
    $config = config('symboles_monstres');

    foreach (Monstre::query()->pluck('nom_base')->all() as $nom) {
        if (array_key_exists($nom, $config['absents'])) {
            expect(array_key_exists($nom, $config['cles']))->toBeFalse("« {$nom} » est à la fois absent et relié à un jeton");
            expect(is_file(dossierJetonsMonstres().'/'.Str::slug($nom).'.webp'))
                ->toBeFalse("« {$nom} » est déclaré absent, mais le jeton {$nom} existe sous sa propre clé");

            continue;
        }

        $cle = cleJetonDe($nom);
        expect(is_file(dossierJetonsMonstres()."/{$cle}.webp"))
            ->toBeTrue("« {$nom} » n'a ni jeton (monstres/{$cle}.webp) ni absence écrite");
    }
});

it('chaque clé de cles et chaque nom d’absents existe au catalogue, sans recouvrement', function () {
    $this->seed(MonstreSeeder::class);
    $config = config('symboles_monstres');
    $catalogue = Monstre::query()->pluck('nom_base')->all();

    foreach (array_keys($config['cles']) as $nom) {
        expect(in_array($nom, $catalogue, true))->toBeTrue("« {$nom} » (cles) n'existe pas au catalogue");
    }
    foreach (array_keys($config['absents']) as $nom) {
        expect(in_array($nom, $catalogue, true))->toBeTrue("« {$nom} » (absents) n'existe pas au catalogue");
    }
    expect(array_intersect(array_keys($config['cles']), array_keys($config['absents'])))->toBe([]);
});

it('chaque jeton relié par cles pointe sur un fichier existant, et chaque clé est un slug', function () {
    $config = config('symboles_monstres');

    foreach ($config['cles'] as $nom => $cle) {
        expect($cle)->toBe(Str::slug($cle), "clé de jeton « {$cle} » (pour {$nom}) n'est pas un slug");
        expect(is_file(dossierJetonsMonstres()."/{$cle}.webp"))->toBeTrue("« {$nom} » pointe sur monstres/{$cle}.webp, absent");
    }
});

it('aucun jeton n’est orphelin : chaque .webp de monstres/ sert à un monstre du catalogue', function () {
    $this->seed(MonstreSeeder::class);
    $utilises = jetonsUtilises();

    if (! is_dir(dossierJetonsMonstres())) {
        expect(true)->toBeTrue(); // aucun jeton extrait sur cette machine : le repli sur l’icône s’applique

        return;
    }
    foreach (File::allFiles(dossierJetonsMonstres()) as $fichier) {
        expect($fichier->getExtension())->toBe('webp', "fichier interdit : {$fichier->getRelativePathname()}");
        $cle = $fichier->getFilenameWithoutExtension();
        expect(in_array($cle, $utilises, true))->toBeTrue("jeton orphelin (aucun monstre ne le porte) : {$cle}.webp");
    }
});

it('le manifeste déclare exactement les jetons utilisés : chacun ROND (disque), OVALE (ovale) ou TUILE (tuile), un seul', function () {
    $this->seed(MonstreSeeder::class);
    $utilises = jetonsUtilises();
    $declares = [];

    foreach (manifesteDesJetons() as $jeton) {
        if (isset($jeton['absent'])) {
            continue;
        }
        $declares[] = $jeton['cle'];
        [$x0, $y0, $x1, $y1] = $jeton['cadre'];

        $formes = (int) array_key_exists('ovale', $jeton) + (int) array_key_exists('disque', $jeton) + (int) ! empty($jeton['tuile']);
        expect($formes)->toBe(1, "jeton « {$jeton['cle']} » : un disque, un ovale OU une tuile — ni deux, ni aucun");

        if (! empty($jeton['tuile'])) {
            // Une TUILE (2026-10-29) : le cadre EST la tuile, bord à bord — carrée (une case) ou longue (deux cases ; une tuile
            // imprimée n'est jamais tout à fait 2:1, d'où la marge).
            $rapport = max($x1 - $x0, $y1 - $y0) / min($x1 - $x0, $y1 - $y0);
            expect($rapport < 1.1 || ($rapport > 1.5 && $rapport < 2.2))->toBeTrue("tuile ni carrée ni 1×2 : {$jeton['cle']} ({$rapport})");

            continue;
        }

        if (array_key_exists('ovale', $jeton)) {
            // Un ovale : son cadre est centré sur l'ellipse, à 1,2 fois ses demi-axes (la couronne 1,15 y tient).
            [$cx, $cy, $rx, $ry] = $jeton['ovale'];
            expect($rx)->toBeGreaterThan(0.0, "demi-axe nul : {$jeton['cle']}");
            expect($ry)->toBeGreaterThan(0.0, "demi-axe nul : {$jeton['cle']}");
            expect(abs(($x0 + $x1) / 2 - $cx))->toBeLessThan(0.01, "cadre décentré : {$jeton['cle']}");
            expect(abs(($y0 + $y1) / 2 - $cy))->toBeLessThan(0.01, "cadre décentré : {$jeton['cle']}");
            expect(abs(($x1 - $x0) / 2 - 1.2 * $rx))->toBeLessThan(0.01, "cadre ≠ 1,2 × rx : {$jeton['cle']}");
            expect(abs(($y1 - $y0) / 2 - 1.2 * $ry))->toBeLessThan(0.01, "cadre ≠ 1,2 × ry : {$jeton['cle']}");

            continue;
        }

        [$cx, $cy, $rayon] = $jeton['disque'];

        expect(abs(($x1 - $x0) - ($y1 - $y0)))->toBeLessThan(0.01, "cadre non carré : {$jeton['cle']}");
        expect(abs(($x0 + $x1) / 2 - $cx))->toBeLessThan(0.01, "cadre décentré : {$jeton['cle']}");
        expect(abs(($y0 + $y1) / 2 - $cy))->toBeLessThan(0.01, "cadre décentré : {$jeton['cle']}");

        // La couronne (1,15 R, les oreilles de la tête) doit tenir dans le cadre, sans le déborder de beaucoup.
        $demi = ($x1 - $x0) / 2;
        expect($demi)->toBeGreaterThanOrEqual(1.15 * $rayon, "couronne rognée : {$jeton['cle']}");
        expect($demi)->toBeLessThanOrEqual(1.3 * $rayon, "cadre trop large : {$jeton['cle']}");
    }

    sort($declares);
    sort($utilises);
    expect($declares)->toBe($utilises, 'le manifeste ne déclare pas exactement les jetons du catalogue');
});

it('un jeton OVALE est une figure 1×2 du catalogue, porte un fichier 2:1 — et chaque fichier 2:1 est déclaré, dans les deux sens', function () {
    $this->seed(MonstreSeeder::class);
    $config = config('symboles_monstres');
    $catalogue = Monstre::query()->get()->keyBy('nom_base');
    $cles = [];

    foreach ($config['ovales'] as $nom) {
        expect($catalogue->has($nom))->toBeTrue("« {$nom} » (ovales) n'existe pas au catalogue");
        expect(array_key_exists($nom, $config['absents']))->toBeFalse("« {$nom} » est à la fois ovale et absent");

        $emprise = $catalogue[$nom]->emprise();
        expect($emprise['l'] * $emprise['h'])->toBe(2, "« {$nom} » : un jeton ovale est une figure de DEUX cases ({$emprise['l']}×{$emprise['h']})");
        expect($emprise['l'] === $emprise['h'])->toBeFalse("« {$nom} » : emprise carrée, pas un ovale");

        $cle = cleJetonDe($nom);
        $cles[] = $cle;
        $chemin = dossierJetonsMonstres()."/{$cle}.webp";
        expect(is_file($chemin))->toBeTrue("« {$nom} » (ovales) : monstres/{$cle}.webp absent");
        [$largeur, $hauteur] = getimagesize($chemin);
        expect($largeur)->toBe(2 * $hauteur, "« {$nom} » : le jeton ovale n'est pas 2:1 ({$largeur}×{$hauteur})");
    }

    // Réciproque : chaque fichier 2:1 du dossier est le jeton d'un nom de `ovales`, et réciproquement.
    $fichiersOvales = [];
    foreach (File::allFiles(dossierJetonsMonstres()) as $fichier) {
        [$largeur, $hauteur] = getimagesize($fichier->getPathname());
        if ($largeur === 2 * $hauteur) {
            $fichiersOvales[] = $fichier->getFilenameWithoutExtension();
        }
    }
    // Une TUILE de deux cases (le Golem, 2026-10-29) porte aussi un fichier 2:1 : elle n'est pas un ovale, et son propre
    // test la tient (ci-dessous). Deux noms peuvent partager une figurine (Rampant putride et Deathspinner : le Blightcrawler).
    $tuiles = array_map(fn (string $nom) => cleJetonDe($nom), $config['tuiles']);
    $fichiersOvales = array_values(array_diff($fichiersOvales, $tuiles));
    $cles = array_values(array_unique($cles));
    sort($cles);
    sort($fichiersOvales);
    expect($fichiersOvales)->toBe($cles, 'un jeton 2:1 n\'est déclaré par aucun nom de `ovales`, ou un nom de `ovales` n\'a pas de jeton 2:1');
});

it('le manifeste marque `ovale` exactement les jetons de `ovales` (et seulement eux)', function () {
    $this->seed(MonstreSeeder::class);
    $attendus = array_values(array_unique(array_map(fn (string $nom) => cleJetonDe($nom), config('symboles_monstres.ovales'))));
    $marques = [];

    foreach (manifesteDesJetons() as $jeton) {
        if (array_key_exists('ovale', $jeton)) {
            $marques[] = $jeton['cle'];
        }
    }
    sort($attendus);
    sort($marques);

    expect($marques)->toBe($attendus, 'le manifeste et `ovales` ne désignent pas les mêmes jetons ovales');
});

it('une TUILE porte un fichier à la taille de son emprise (carré : une case, 2:1 : deux), et le manifeste la marque `tuile` — dans les deux sens', function () {
    $this->seed(MonstreSeeder::class);
    $config = config('symboles_monstres');
    $catalogue = Monstre::query()->get()->keyBy('nom_base');
    $attendus = [];

    foreach ($config['tuiles'] as $nom) {
        expect($catalogue->has($nom))->toBeTrue("« {$nom} » (tuiles) n'existe pas au catalogue");
        expect(array_key_exists($nom, $config['absents']))->toBeFalse("« {$nom} » est à la fois tuile et absent");
        expect(in_array($nom, $config['ovales'], true))->toBeFalse("« {$nom} » est à la fois tuile et ovale");

        $cle = cleJetonDe($nom);
        $attendus[] = $cle;
        $chemin = dossierJetonsMonstres()."/{$cle}.webp";
        expect(is_file($chemin))->toBeTrue("« {$nom} » (tuiles) : monstres/{$cle}.webp absent");
        [$largeur, $hauteur] = getimagesize($chemin);
        $emprise = $catalogue[$nom]->emprise();
        $cases = $emprise['l'] * $emprise['h'];
        expect($cases)->toBeIn([1, 2], "« {$nom} » : une tuile couvre une ou deux cases");
        expect($largeur)->toBe($cases * $hauteur, "« {$nom} » : fichier {$largeur}×{$hauteur} pour une emprise de {$cases} case(s)");
    }

    $marques = [];
    foreach (manifesteDesJetons() as $jeton) {
        if (! empty($jeton['tuile'])) {
            $marques[] = $jeton['cle'];
        }
    }

    $attendus = array_values(array_unique($attendus));
    sort($attendus);
    sort($marques);
    expect($marques)->toBe($attendus, 'le manifeste et `tuiles` ne désignent pas les mêmes tuiles');
});

it('publie la forme `tuile` pour le Rejeton putride et le Golem (couché à 90° debout), `rond` pour un jeton ordinaire', function () {
    $this->seed(MonstreSeeder::class);
    $biblio = app(App\Partie\Images\BibliothequeImages::class);

    expect($biblio->formeSymboleMonstre('Rejeton putride'))->toBe('tuile')
        ->and($biblio->urlSymboleMonstre('Rejeton putride'))->toBe('/images/livrets/monstres/spawnling.webp')
        ->and($biblio->formeSymboleMonstre('Golem Cœur-de-Forge'))->toBe('tuile')
        ->and($biblio->urlSymboleMonstre('Golem Cœur-de-Forge'))->toBe('/images/livrets/monstres/forgeheart-golem.webp')
        ->and(App\Partie\Images\BibliothequeImages::rotationSymboleMonstre('tuile', ['l' => 1, 'h' => 2]))->toBe(90)
        ->and(App\Partie\Images\BibliothequeImages::rotationSymboleMonstre('tuile', ['l' => 1, 'h' => 1]))->toBe(0)
        ->and($biblio->formeSymboleMonstre('Gobelin'))->toBe('rond');
});
