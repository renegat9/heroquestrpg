<?php

declare(strict_types=1);

use App\Agent\Exceptions\AppelLlmException;
use App\Agent\Image\ImageGemini;
use App\Jobs\GenererImageHub;
use App\Models\Parametre;
use App\Partie\Images\BibliothequeImages;
use App\Partie\Images\ConvertisseurWebp;
use Illuminate\Support\Facades\Http;

it('appelle Gemini image et renvoie les octets PNG décodés', function () {
    config()->set('services.gemini.api_key', 'cle-test');
    config()->set('services.gemini.model_image', 'gemini-2.5-flash-image');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNGBYTES')]],
            ]]]],
        ]),
    ]);

    $octets = (new ImageGemini)->generer('un orque féroce');

    expect($octets)->toBe('PNGBYTES');

    Http::assertSent(function ($req) {
        return str_contains($req->url(), '/v1beta/models/gemini-2.5-flash-image:generateContent')
            && $req->hasHeader('x-goog-api-key', 'cle-test')
            && $req['generationConfig']['responseModalities'] === ['IMAGE']
            && $req['contents'][0]['parts'][0]['text'] === 'un orque féroce';
    });
});

it('lève AppelLlmException sans clé (aucun appel)', function () {
    config()->set('services.gemini.api_key', null);
    Http::fake();
    expect(fn () => (new ImageGemini)->generer('x'))->toThrow(AppelLlmException::class);
    Http::assertNothingSent();
});

it('lève AppelLlmException sur erreur HTTP et si pas d\'image', function () {
    config()->set('services.gemini.api_key', 'cle-test');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);
    expect(fn () => (new ImageGemini)->generer('x'))->toThrow(AppelLlmException::class);
    Http::assertSentCount(1); // pas de réessai sur 400

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'désolé']]], 'finishReason' => 'STOP']],
    ])]);
    expect(fn () => (new ImageGemini)->generer('x'))->toThrow(AppelLlmException::class);
});

it('résout les URLs catalogue/dyn par existence de fichier', function () {
    $b = new BibliothequeImages;

    // Chemins relatifs déterministes (slug).
    expect($b->relatifClasse('Barbare'))->toBe('catalogue/classes/barbare.png')
        ->and($b->relatifCatalogue('monstres', 5, 'Gardien de Pierre'))->toBe('catalogue/monstres/5-gardien-de-pierre.png')
        ->and($b->relatifDyn('quete', 12))->toBe('dyn/quete/12.png');

    // `urlDyn` est l'accesseur BRUT : il garde son `null`, c'est lui qui permet
    // aux chaînes de repli d'exister (portrait → classe, boss → archétype).
    expect($b->urlDyn('hub', 999999))->toBeNull();

    // Les accesseurs PUBLICS, eux, ne rendent plus jamais de cadre vide :
    // à défaut d'illustration, une vignette SVG (René, 2026-08-21).
    expect($b->urlClasse('paladin'))->toBe('/api/placeholder/classe/paladin')
        ->and($b->urlMonstreCatalogue(99999, 'Créature Absente'))->toBe('/api/placeholder/monstre/99999')
        ->and($b->urlHeros(999999, 'paladin'))->toBe('/api/placeholder/classe/paladin');

    // ⚠ Sans SUJET, il n'y a rien à montrer : un `null` reste un `null`. Une
    // vignette voudrait dire « image manquante » là où il n'y a pas d'image
    // à attendre.
    expect($b->urlClasse(null))->toBeNull()
        ->and($b->urlObjet(null, 'Truc'))->toBeNull();
});

/**
 * ⚠ La vignette se pose en BOUT de chaîne, jamais au milieu : une vraie image,
 * même générique, vaut mieux qu'un emblème. Un portrait de héros absent doit
 * d'abord retomber sur l'illustration de sa CLASSE.
 */
it('laisse toujours gagner une vraie image sur la vignette', function () {
    $b = new BibliothequeImages;
    $rel = $b->relatifClasse('paladin');
    $absolu = public_path("images/{$rel}");

    if (! is_dir(dirname($absolu))) {
        mkdir(dirname($absolu), 0775, true);
    }
    file_put_contents($absolu, 'PNG-témoin');

    try {
        expect($b->urlClasse('paladin'))->toBe("/images/{$rel}")
            // Le portrait individuel n'existe pas : on descend d'un cran vers
            // la classe, et on ne va PAS jusqu'à la vignette.
            ->and($b->urlHeros(999999, 'paladin'))->toBe("/images/{$rel}");
    } finally {
        @unlink($absolu);
    }
});

it('construit le prompt en interpolant le style et les champs', function () {
    config()->set('images.style', 'STYLE-X');
    config()->set('images.gabarits.monstre', 'Monstre {nom} (tier {tier}). {style}');

    $prompt = (new BibliothequeImages)->prompt('monstre', ['nom' => 'Orque', 'tier' => 'base']);

    expect($prompt)->toBe('Monstre Orque (tier base). STYLE-X');
});

it('GenererImageHub sort avant tout appel HTTP quand images_actif=false, même avec une clé Gemini valide', function () {
    config()->set('services.gemini.api_key', 'cle-test');
    Parametre::actuel()->update(['images_actif' => false]);

    $groupe = creerGroupe('table-images-1');

    Http::fake(); // aucune requête ne doit partir

    app()->call([new GenererImageHub($groupe->id), 'handle']);

    Http::assertNothingSent();
});

it('préfère le jumeau WebP quand il existe, sans casser le repli PNG', function () {
    $lib = app(BibliothequeImages::class);
    $dossier = public_path('images/catalogue/classes');
    @mkdir($dossier, 0775, true);

    $png = $dossier.'/testwebp.png';
    $webp = $dossier.'/testwebp.webp';
    file_put_contents($png, 'png');

    // Sans jumeau : le PNG est servi.
    expect($lib->url('catalogue/classes/testwebp.png'))->toBe('/images/catalogue/classes/testwebp.png');

    // Avec jumeau : le WebP l'emporte. Les images générées sont des PNG de
    // 1024×1024 à ~1,3 Mo affichés sur quelques dizaines de pixels — trois
    // suffisaient à faire télécharger 4 Mo à la tablette du narrateur.
    file_put_contents($webp, 'webp');
    expect($lib->url('catalogue/classes/testwebp.png'))->toBe('/images/catalogue/classes/testwebp.webp');

    // Rien du tout : null, comme avant.
    @unlink($png);
    @unlink($webp);
    expect($lib->url('catalogue/classes/testwebp.png'))->toBeNull();
});

/**
 * ⚠ Le jumeau .webp naît AVEC l'image, il n'est plus une étape à penser
 * (René, 2026-09-13 : « ne faudrait-il pas toujours convertir les images quand
 * elles sont générées ? »). Les scènes des quêtes 98 et 99 sont restées servies
 * en 1,3 Mo parce que `image-tools/webp.sh` n'avait pas été relancé derrière.
 *
 * On teste que le CONVERTISSEUR EST APPELÉ, pas que le fichier existe : la suite
 * tourne dans un conteneur `composer:2` qui n'a pas `cwebp`, et c'est justement
 * pour ça que la conversion doit rester best-effort côté production.
 */
function espionWebp(): object
{
    $espion = new class extends ConvertisseurWebp
    {
        /** @var list<string> */
        public array $jumeles = [];

        public function jumeler(string $absolu): bool
        {
            $this->jumeles[] = $absolu;

            return true;
        }
    };
    app()->instance(ConvertisseurWebp::class, $espion);

    return $espion;
}

/**
 * Détourne `public_path()` vers un dossier jetable.
 *
 * ⚠ INDISPENSABLE, et payé cash : la première version de ces tests lançait
 * `images:generer --force` sur le VRAI public/, et a écrasé les quatre PNG de
 * portes du catalogue avec les 8 octets factices de la fausse réponse HTTP.
 * Rien ne l'a signalé — l'écran restait juste, `BibliothequeImages::url()`
 * servant le jumeau .webp intact. Un test qui écrit dans l'arbre de travail est
 * un test qui peut le détruire.
 */
function publicJetable(): string
{
    $dossier = sys_get_temp_dir().'/hq-images-'.bin2hex(random_bytes(4));
    mkdir($dossier.'/images', 0775, true);
    app()->usePublicPath($dossier);

    return $dossier;
}

it('jumelle en .webp toute image écrite par la bibliothèque', function () {
    publicJetable();
    $espion = espionWebp();

    $url = (new BibliothequeImages)->enregistrer('dyn/quete/4242.png', 'PNGBYTES');

    expect($url)->toBe('/images/dyn/quete/4242.png')
        ->and($espion->jumeles)->toBe([public_path('images/dyn/quete/4242.png')]);
});

it('jumelle aussi les images du catalogue (images:generer passe par la bibliothèque)', function () {
    // ⚠ La commande posait son PNG avec un `file_put_contents` à elle, donc sans
    // jumeau : deux écritures pour une seule règle, et celle-ci n'en avait pas.
    publicJetable();
    config()->set('services.gemini.api_key', 'cle-test');
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('PNGBYTES')]],
            ]]]],
        ]),
    ]);
    $espion = espionWebp();

    $this->artisan('images:generer', ['--type' => 'portes', '--force' => true])->assertSuccessful();

    expect($espion->jumeles)->not->toBeEmpty();
    foreach ($espion->jumeles as $chemin) {
        expect($chemin)->toEndWith('.png')->and($chemin)->toStartWith(public_path('images/'));
    }
});

it('sans cwebp, la conversion échoue en silence et le PNG reste servi', function () {
    // Le conteneur de test n'a pas le binaire : c'est le cas nominal ici, et il
    // doit rendre false sans lever — une génération d'image ne doit jamais
    // échouer parce qu'un outil de compression manque.
    publicJetable();
    $conv = new ConvertisseurWebp;
    $png = public_path('images/dyn/quete/4243.png');
    @mkdir(dirname($png), 0775, true);
    file_put_contents($png, 'PNGBYTES');

    expect($conv->jumeler($png))->toBe($conv->disponible() && is_file(public_path('images/dyn/quete/4243.webp')))
        ->and(is_file($png))->toBeTrue(); // le PNG survit dans tous les cas
});
