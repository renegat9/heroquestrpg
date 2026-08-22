<?php

declare(strict_types=1);

use App\Partie\Images\BibliothequeImages;

/*
 * Vignette de remplacement quand aucune illustration n'a pu être générée
 * (demande de René, 2026-08-21).
 *
 * Le jeu tourne sans clé d'IA — règle du projet, pas mode dégradé — et les
 * crédits Gemini peuvent s'épuiser en pleine campagne : une quête sur deux se
 * retrouvait alors sans image, et l'écran affichait un trou qu'on lisait comme
 * un bug.
 */

it('sert un SVG, pas une page ni un fichier introuvable', function () {
    // ⚠ L'URL n'a PAS d'extension `.svg`, à dessein : nginx sert les fichiers
    // statiques par extension et ne passerait jamais la main à PHP. C'est le
    // `Content-Type` qui fait le travail dans un `<img src>`.
    $reponse = $this->get('/api/placeholder/quete/37');

    $reponse->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect($reponse->getContent())->toStartWith('<svg')->toContain('</svg>');
});

it('donne une teinte stable pour un sujet, différente d’un sujet à l’autre', function () {
    $teinte = fn (string $url) => preg_match('/hsl\((\d+)/', $this->get($url)->getContent(), $m) ? $m[1] : null;

    // Stable : la même quête garde sa vignette d'un affichage à l'autre —
    // sinon l'écran donnerait l'impression de se rafraîchir sans raison.
    expect($teinte('/api/placeholder/quete/37'))->toBe($teinte('/api/placeholder/quete/37'));

    // Distincte : deux quêtes d'une même campagne ne se ressemblent pas.
    $teintes = collect([35, 36, 37, 38])->map(fn ($q) => $teinte("/api/placeholder/quete/{$q}"));
    expect($teintes->unique())->toHaveCount(4);
});

it('donne son propre emblème à chaque type de sujet', function () {
    $svg = fn (string $type) => $this->get("/api/placeholder/{$type}/1")->getContent();

    expect($svg('quete'))->not->toBe($svg('hub'))
        ->and($svg('hub'))->not->toBe($svg('monstre'));

    // Un type inconnu ne casse pas : il retombe sur l'arche de quête.
    expect($this->get('/api/placeholder/inconnu/1'))->assertOk();
});

/**
 * ⚠ `urlDyn()` garde son `null`, et ce n'est pas un oubli : un portrait de
 * héros absent retombe sur l'illustration de CLASSE, ce qui vaut mieux qu'un
 * emblème générique. La vignette n'est servie que là où un cadre vide se lit
 * comme un bug — carte d'ouverture de quête, panneau de hub.
 */
it('ne remplace que là où on le demande', function () {
    $biblio = app(BibliothequeImages::class);

    expect($biblio->urlDyn('quete', 999999))->toBeNull()
        ->and($biblio->urlDynOuVignette('quete', 999999))->toBe('/api/placeholder/quete/999999');
});
