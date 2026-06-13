<?php

declare(strict_types=1);

use App\Agent\Exceptions\AppelLlmException;
use App\Agent\Memoire\Embeddings;
use App\Agent\Memoire\EmbeddingsNuls;
use App\Agent\Memoire\EmbeddingsVoyage;
use Illuminate\Support\Facades\Http;

it('appelle Voyage AI avec le bon payload et parse le vecteur', function () {
    config()->set('services.voyage.api_key', 'cle-test');
    config()->set('services.voyage.dimension', 4);

    Http::fake([
        'api.voyageai.com/v1/embeddings' => Http::response([
            'data' => [['embedding' => [0.1, 0.2, 0.3, 0.4], 'index' => 0]],
            'model' => 'voyage-3.5',
        ]),
    ]);

    $vecteur = (new EmbeddingsVoyage)->vecteur('Le forgeron du hub', requete: false);

    expect($vecteur)->toBe([0.1, 0.2, 0.3, 0.4]);

    Http::assertSent(function ($req) {
        return $req->url() === 'https://api.voyageai.com/v1/embeddings'
            && $req->hasHeader('Authorization', 'Bearer cle-test')
            && $req['model'] === 'voyage-3.5'
            && $req['input'] === ['Le forgeron du hub']
            && $req['input_type'] === 'document'
            && $req['output_dimension'] === 4;
    });
});

it("distingue les textes de recherche (input_type query)", function () {
    config()->set('services.voyage.api_key', 'cle-test');
    config()->set('services.voyage.dimension', 4);

    Http::fake([
        'api.voyageai.com/*' => Http::response([
            'data' => [['embedding' => [1.0, 0.0, 0.0, 0.0]]],
        ]),
    ]);

    (new EmbeddingsVoyage)->vecteur('Qui est le forgeron ?', requete: true);

    Http::assertSent(fn ($req) => $req['input_type'] === 'query');
});

it('lève une AppelLlmException sur erreur API ou vecteur de mauvaise dimension', function () {
    config()->set('services.voyage.api_key', 'cle-test');
    config()->set('services.voyage.dimension', 4);

    Http::fake(['api.voyageai.com/*' => Http::response(['error' => 'quota'], 429)]);
    expect(fn () => (new EmbeddingsVoyage)->vecteur('x'))->toThrow(AppelLlmException::class);

    Http::fake(['api.voyageai.com/*' => Http::response(['data' => [['embedding' => [0.1, 0.2]]]])]);
    expect(fn () => (new EmbeddingsVoyage)->vecteur('x'))->toThrow(AppelLlmException::class);
});

it("binde Voyage quand la clé est présente, le repli lexical sinon", function () {
    config()->set('services.voyage.api_key', 'cle-test');
    expect(app(Embeddings::class))->toBeInstanceOf(EmbeddingsVoyage::class);

    config()->set('services.voyage.api_key', null);
    expect(app(Embeddings::class))->toBeInstanceOf(EmbeddingsNuls::class);
});
