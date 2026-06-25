<?php

declare(strict_types=1);

use App\Agent\Exceptions\AppelLlmException;
use App\Agent\Image\ImageGemini;
use App\Partie\Images\BibliothequeImages;
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

it('résout les URLs catalogue/dyn par existence de fichier (null sinon)', function () {
    $b = new BibliothequeImages;

    // Chemins relatifs déterministes (slug).
    expect($b->relatifClasse('Barbare'))->toBe('catalogue/classes/barbare.png')
        ->and($b->relatifCatalogue('monstres', 5, 'Gardien de Pierre'))->toBe('catalogue/monstres/5-gardien-de-pierre.png')
        ->and($b->relatifDyn('quete', 12))->toBe('dyn/quete/12.png');

    // Assets INEXISTANTS (classe/ids absents) → toutes les URLs sont nulles.
    expect($b->urlClasse('paladin'))->toBeNull()
        ->and($b->urlMonstreCatalogue(99999, 'Créature Absente'))->toBeNull()
        ->and($b->urlDyn('hub', 999999))->toBeNull()
        ->and($b->urlHeros(999999, 'paladin'))->toBeNull();
});

it('construit le prompt en interpolant le style et les champs', function () {
    config()->set('images.style', 'STYLE-X');
    config()->set('images.gabarits.monstre', 'Monstre {nom} (tier {tier}). {style}');

    $prompt = (new BibliothequeImages)->prompt('monstre', ['nom' => 'Orque', 'tier' => 'base']);

    expect($prompt)->toBe('Monstre Orque (tier base). STYLE-X');
});
