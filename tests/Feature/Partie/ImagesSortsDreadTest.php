<?php

declare(strict_types=1);

use App\Engine\MotsClesSortDread;
use App\Models\SortDread;
use App\Partie\Images\BibliothequeImages;
use Database\Seeders\ClasseHerosSeeder;
use Database\Seeders\CompetenceSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\MonstreSeeder;
use Database\Seeders\ObjetSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\SortDreadSeeder;
use Database\Seeders\SortSeeder;

/*
 * Sorts de Dread illustrés (2026-10-16). La magie du MJ n'avait AUCUN chemin
 * d'image : la scène de table publiait l'emblème générique du genre `sort`, et
 * le guide ne montrait rien. Une carte a désormais son illustration, son
 * emblème `sort_dread` en repli, et deux lecteurs (scène de table, guide).
 *
 * ⚠ Bac à sable : aucun de ces tests n'écrit dans le vrai `public/` — la purge
 * des orphelins y effacerait toutes les illustrations (voir ImagesOrphelinesTest).
 */

beforeEach(function () {
    $this->seed(SortDreadSeeder::class);

    $GLOBALS['public_dread'] = sys_get_temp_dir().'/hq-dread-'.uniqid();
    mkdir($GLOBALS['public_dread'], 0775, true);
    app()->usePublicPath($GLOBALS['public_dread']);
});

afterEach(function () {
    $racine = $GLOBALS['public_dread'] ?? null;

    if ($racine !== null && is_dir($racine)) {
        exec('rm -rf '.escapeshellarg($racine));
    }
});

it('rend l\'emblème `sort_dread` pour une carte sans image, et rien sans carte', function () {
    $sommeil = SortDread::where('nom', 'Sommeil')->firstOrFail();
    $biblio = new BibliothequeImages;

    expect($biblio->urlSortDread($sommeil->id, $sommeil->nom))->toBe("/api/placeholder/sort_dread/{$sommeil->id}")
        // Sans carte à montrer, il n'y a rien à attendre : un emblème prétendrait « image manquante ».
        ->and($biblio->urlSortDread(null, 'Sommeil'))->toBeNull();
});

it('préfère l\'illustration générée, son jumeau .webp d\'abord', function () {
    $sommeil = SortDread::where('nom', 'Sommeil')->firstOrFail();
    $biblio = new BibliothequeImages;

    $rel = $biblio->relatifCatalogue('sorts_dread', $sommeil->id, $sommeil->nom);
    $png = public_path("images/{$rel}");
    $webp = preg_replace('/\.png$/', '.webp', $png);

    @mkdir(dirname($png), 0775, true);
    file_put_contents($png, 'PNG-temoin');

    expect($biblio->urlSortDread($sommeil->id, $sommeil->nom))->toBe("/images/{$rel}");

    file_put_contents($webp, 'WEBP-temoin');

    expect($biblio->urlSortDread($sommeil->id, $sommeil->nom))
        ->toBe('/images/'.preg_replace('/\.png$/', '.webp', $rel));
});

it('nomme l\'illustration {id}-{slug} comme les autres sorts du catalogue', function () {
    expect((new BibliothequeImages)->relatifCatalogue('sorts_dread', 62, 'Malédiction du loup-garou'))
        ->toBe('catalogue/sorts_dread/62-malediction-du-loup-garou.png');
});

it('dit la forme du meuble en mots, axe compris', function () {
    // Une table 3 × 2 : couchée, son côté long HORIZONTAL dans l'image (la v1 est sortie dressée).
    expect(BibliothequeImages::formeMobilier(3, 2))
        ->toContain('une fois et demie plus large que haut')
        ->toContain('HORIZONTAL')
        // Un râtelier 1 × 3 : dressé, son côté long VERTICAL.
        ->and(BibliothequeImages::formeMobilier(1, 3))
        ->toContain('trois fois plus haut que large')
        ->toContain('VERTICAL')
        // Le carré se dit carré, sans multiplicateur.
        ->and(BibliothequeImages::formeMobilier(2, 2))->toBe('le meuble est carré : aussi large que haut')
        // Un rapport sans nom rond garde sa décimale, à la française.
        ->and(BibliothequeImages::formeMobilier(7, 3))->toContain('2,3 fois');
});

it('ne porte aucun chiffre d\'emprise dans le prompt du meuble', function () {
    $prompt = (new BibliothequeImages)->prompt('mobilier', [
        'nom' => 'Table',
        'description' => '',
        'forme' => BibliothequeImages::formeMobilier(3, 2),
    ]);

    // Un chiffre se fait peindre sur le meuble : l'emprise passe en mots.
    expect($prompt)->not->toMatch('/\d/')
        ->and($prompt)->toContain('plan de jeu de société')
        ->and($prompt)->toContain('posé à plat sur le sol')
        // Le style à torches n'y est plus : c'est lui qui peignait un brasero dans la bibliothèque.
        ->and($prompt)->not->toContain('éclairage de torche');
});

it('garde chaque type d\'effet de Dread illustrable, dans les deux sens', function () {
    $phrases = array_keys((array) config('images.sorts_dread_effets'));
    $moteur = MotsClesSortDread::TYPES;

    // Un type déclaré au moteur sans phrase tomberait sur un prompt vide ; une phrase sans type
    // ne sert à rien. Les deux listes doivent être les mêmes.
    expect(collect($phrases)->sort()->values()->all())->toBe(collect($moteur)->sort()->values()->all());

    // Et toute carte RÉELLEMENT semée porte un type qui a sa phrase.
    expect(SortDread::query()->pluck('type')->unique()->diff($phrases)->values()->all())->toBe([]);
});

it('a un gabarit de prompt qui nomme la carte et son effet, sans règle chiffrée', function () {
    $gabarit = (string) config('images.gabarits.sort_dread');

    expect($gabarit)->toContain('{nom}')
        ->and($gabarit)->toContain('{effet}')
        ->and($gabarit)->toContain('Zargon');
});

it('publie l\'illustration de chaque carte de Dread dans GET /api/guide, et rien hors de ce paquet', function () {
    $this->seed([
        ClasseHerosSeeder::class, CompetenceSeeder::class, MonstreSeeder::class,
        ObjetSeeder::class, SortSeeder::class, PiegeSeeder::class, MobilierSeeder::class,
    ]);

    $paquets = collect($this->getJson('/api/guide')->assertOk()->json('cartes'))->keyBy('cle');

    $sommeil = collect($paquets['dread']['cartes'])->firstWhere('nom', 'Sommeil');
    expect($sommeil['image_url'])->toBe('/api/placeholder/sort_dread/'.SortDread::where('nom', 'Sommeil')->value('id'));

    // Hors du paquet Dread, la clé existe mais reste nulle : aucune image n'y est attendue.
    expect(collect($paquets['equipement']['cartes'])->every(fn (array $c) => array_key_exists('image_url', $c) && $c['image_url'] === null))
        ->toBeTrue();
});
