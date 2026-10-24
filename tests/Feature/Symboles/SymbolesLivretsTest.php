<?php

declare(strict_types=1);

use App\Models\Danger;
use App\Models\Mobilier;
use App\Models\Piege;
use App\Models\Terrain;
use App\Partie\MoteurPortes;
use Database\Seeders\DangerSeeder;
use Database\Seeders\MobilierSeeder;
use Database\Seeders\PiegeSeeder;
use Database\Seeders\TerrainSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * Le manifeste des symboles des livrets (image-tools/symboles-livrets/manifeste.json) : des
 * COORDONNÉES, jamais une image. Ce test vérifie la couverture du catalogue dans les deux sens,
 * la convention de nommage que l'affichage lit (Str::slug() partout, y compris les libellés
 * fixes), et la présence des .webp quand ils ont été extraits sur cette machine.
 * Décision de René (2026-10-24) : les symboles extraits entrent dans le dépôt, sous
 * public/images/livrets/ ; les PDF et les pages entières n'y entrent jamais.
 */

function manifesteSymboles(): array
{
    $donnees = json_decode(
        (string) file_get_contents(base_path('image-tools/symboles-livrets/manifeste.json')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    return $donnees['symboles'];
}

function typesSymboles(): array
{
    return ['mobiliers', 'terrains', 'pieges', 'portes', 'leviers', 'escalier', 'dangers', 'monstres'];
}

it('le manifeste est un JSON valide et déclare des symboles', function () {
    expect(manifesteSymboles())->not->toBeEmpty();
});

it('chaque entrée a un type connu, une clé unique par type, et une clé = Str::slug(nom_catalogue)', function () {
    $vus = [];
    foreach (manifesteSymboles() as $s) {
        expect(in_array($s['type'], typesSymboles(), true))->toBeTrue("type inconnu : {$s['type']}");
        expect($s['cle'])->toBe(Str::slug($s['nom_catalogue']), "clé ≠ Str::slug() pour « {$s['nom_catalogue']} »");
        $cle = $s['type'].'/'.$s['cle'];
        expect(isset($vus[$cle]))->toBeFalse("clé en double : {$cle}");
        $vus[$cle] = true;
    }
});

it('le catalogue semé et le manifeste se recouvrent exactement, dans les deux sens', function () {
    $this->seed([MobilierSeeder::class, TerrainSeeder::class, PiegeSeeder::class, DangerSeeder::class]);

    $catalogue = [
        'mobiliers' => Mobilier::query()->pluck('nom')->all(),
        'terrains' => Terrain::query()->pluck('nom')->all(),
        'pieges' => Piege::query()->pluck('nom')->all(),
        'dangers' => Danger::query()->pluck('nom')->all(),
    ];

    foreach ($catalogue as $type => $noms) {
        $declares = collect(manifesteSymboles())->where('type', $type)->pluck('nom_catalogue')->all();
        sort($noms);
        sort($declares);
        expect($declares)->toBe($noms, "le type « {$type} » ne couvre pas exactement le catalogue");
    }
});

it('les portes couvrent les quatre états du moteur et la porte de pierre ; le levier et l’escalier existent', function () {
    $portes = collect(manifesteSymboles())->where('type', 'portes')->pluck('nom_catalogue')->all();
    expect($portes)->toEqualCanonicalizing([
        MoteurPortes::ETAT_OUVERTE,
        MoteurPortes::ETAT_FERMEE,
        MoteurPortes::ETAT_VERROUILLEE,
        MoteurPortes::ETAT_SECRETE,
        'verrou pierre',
    ]);

    $fixes = collect(manifesteSymboles())->whereIn('type', ['leviers', 'escalier'])->pluck('cle')->all();
    expect($fixes)->toEqualCanonicalizing(['levier', 'escalier']);
});

it('un symbole absent dit pourquoi ; un symbole extrait dit d’où il vient', function () {
    foreach (manifesteSymboles() as $s) {
        if (isset($s['absent'])) {
            expect(trim($s['absent']))->not->toBe('', "absence sans raison : « {$s['nom_catalogue']} »");
            expect(isset($s['livret']))->toBeFalse("« {$s['nom_catalogue']} » est absent et pourtant rattaché à un livret");

            continue;
        }

        expect($s['livret'])->toEndWith('.pdf');
        expect($s['page'])->toBeGreaterThanOrEqual(1);
        expect($s['cadre'])->toHaveCount(4);
        expect(in_array($s['rotation'], [0, 90, 180, 270], true))->toBeTrue("rotation inconnue : « {$s['nom_catalogue']} »");
        expect($s['emprise'])->toHaveCount(2);
        expect(trim($s['source']))->not->toBe('', "source absente : « {$s['nom_catalogue']} »");

        // Un seuil bas RELEVÉ (papier marbré, `extraire.py`) doit dépasser le défaut (28) sans atteindre la couleur du trait.
        if (array_key_exists('seuil', $s)) {
            expect($s['seuil'])->toBeGreaterThan(28, "seuil sans effet : « {$s['nom_catalogue']} »");
            expect($s['seuil'])->toBeLessThan(100, "seuil qui efface le trait : « {$s['nom_catalogue']} »");
        }
    }
});

it('les symboles extraits existent en .webp — la vérification se tait si le dossier est vide', function () {
    $dossier = public_path('images/livrets');
    if (! is_dir($dossier) || File::allFiles($dossier) === []) {
        expect(true)->toBeTrue(); // rien d’extrait sur cette machine : le repli sur l’icône s’applique

        return;
    }

    foreach (manifesteSymboles() as $s) {
        if (isset($s['absent'])) {
            continue;
        }
        $chemin = "{$dossier}/{$s['type']}/{$s['cle']}.webp";
        expect(is_file($chemin))->toBeTrue("symbole extrait manquant : {$s['type']}/{$s['cle']}.webp");
    }
});

it('public/images/livrets ne contient que des .webp (pas de PNG, pas de PDF, pas de page entière)', function () {
    $dossier = public_path('images/livrets');
    if (! is_dir($dossier)) {
        expect(true)->toBeTrue();

        return;
    }

    foreach (File::allFiles($dossier) as $fichier) {
        expect($fichier->getExtension())->toBe('webp', "fichier interdit dans le dépôt : {$fichier->getRelativePathname()}");
    }
});
