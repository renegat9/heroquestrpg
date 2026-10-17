<?php

declare(strict_types=1);

use App\Partie\Images\BibliothequeImages;

/**
 * Détourne `public_path()` vers un dossier jetable.
 *
 * ⚠ Ces tests POSENT des fichiers : le vrai `public/images/livret/` contient des découpages
 * du livret officiel (art Hasbro, hors dépôt) qu'aucun test ne doit toucher. Même règle que
 * `publicJetable()` dans ImagesTest.
 */
function dossierLivretJetable(): string
{
    $dossier = sys_get_temp_dir().'/hq-livret-'.bin2hex(random_bytes(4));
    mkdir($dossier.'/images', 0775, true);
    app()->usePublicPath($dossier);

    return $dossier;
}

/** Pose un fichier sous `public/images/` du dossier jetable. */
function poserImageLivret(string $relatif): void
{
    $chemin = public_path('images/'.$relatif);
    if (! is_dir(dirname($chemin))) {
        mkdir(dirname($chemin), 0775, true);
    }
    file_put_contents($chemin, 'WEBP');
}

it('sert le découpage du livret quand il existe, avant l\'image générée', function () {
    dossierLivretJetable();
    poserImageLivret('catalogue/mobiliers/17-table.webp');
    poserImageLivret('livret/mobiliers/17-table.webp');

    expect((new BibliothequeImages)->urlMobilier(17, 'Table'))
        ->toBe('/images/livret/mobiliers/17-table.webp');
});

it('retombe sur l\'image générée quand le découpage du livret est absent', function () {
    dossierLivretJetable();
    poserImageLivret('catalogue/mobiliers/17-table.webp');

    expect((new BibliothequeImages)->urlMobilier(17, 'Table'))
        ->toBe('/images/catalogue/mobiliers/17-table.webp');
});

it('retombe sur l\'emblème quand ni le découpage ni l\'image générée n\'existent', function () {
    dossierLivretJetable();

    expect((new BibliothequeImages)->urlMobilier(17, 'Table'))
        ->toBe('/api/placeholder/mobilier/17');
});

it('suit le slug d\'un nom accentué (Établi d\'alchimiste)', function () {
    dossierLivretJetable();
    poserImageLivret('livret/mobiliers/20-etabli-dalchimiste.webp');

    expect((new BibliothequeImages)->urlMobilier(20, 'Établi d\'alchimiste'))
        ->toBe('/images/livret/mobiliers/20-etabli-dalchimiste.webp');
});

it('ne reprend le découpage que de SON id : un autre meuble n\'hérite pas de l\'image', function () {
    dossierLivretJetable();
    poserImageLivret('livret/mobiliers/17-table.webp');

    expect((new BibliothequeImages)->urlMobilier(18, 'Coffre'))
        ->toBe('/api/placeholder/mobilier/18');
});

it('un meuble sans id n\'a pas d\'image, même si un découpage traîne', function () {
    dossierLivretJetable();
    poserImageLivret('livret/mobiliers/17-table.webp');

    expect((new BibliothequeImages)->urlMobilier(null, 'Table'))->toBeNull();
});
