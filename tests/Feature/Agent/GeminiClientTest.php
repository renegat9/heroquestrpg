<?php

declare(strict_types=1);

use App\Agent\AnthropicClient;
use App\Agent\ClientLLM;
use App\Agent\Exceptions\AppelLlmException;
use App\Agent\GeminiClient;
use Illuminate\Support\Facades\Http;

/** Outil/schéma minimal réutilisé par les cas de génération structurée. */
function outilTest(): array
{
    return [
        'name' => 'mon_outil',
        'description' => 'Outil de test',
        'input_schema' => [
            'type' => 'object',
            'required' => ['titre'],
            'properties' => [
                'titre' => ['type' => 'string', 'minLength' => 2],
                'n' => ['type' => 'integer'],
            ],
        ],
    ];
}

it('appelle Gemini en function-calling forcé et renvoie les args', function () {
    config()->set('services.gemini.api_key', 'cle-test');

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'functionCall' => ['name' => 'mon_outil', 'args' => ['titre' => 'Le Tombeau', 'n' => 3]],
                ]]],
                'finishReason' => 'STOP',
            ]],
        ]),
    ]);

    $sortie = (new GeminiClient)->genererStructure(
        'Tu es le MJ.',
        [['role' => 'user', 'content' => 'Propose une campagne.']],
        outilTest(),
    );

    expect($sortie)->toBe(['titre' => 'Le Tombeau', 'n' => 3]);

    Http::assertSent(function ($req) {
        return str_contains($req->url(), '/v1beta/models/gemini-2.5-flash:generateContent')
            && $req->hasHeader('x-goog-api-key', 'cle-test')
            && $req['system_instruction']['parts'][0]['text'] === 'Tu es le MJ.'
            && $req['tool_config']['function_calling_config']['mode'] === 'ANY'
            && $req['tool_config']['function_calling_config']['allowed_function_names'] === ['mon_outil']
            && $req['tools'][0]['function_declarations'][0]['name'] === 'mon_outil'
            && $req['contents'][0]['role'] === 'user'
            && $req['contents'][0]['parts'][0]['text'] === 'Propose une campagne.';
    });
});

it('utilise GEMINI_MODEL pour le modèle texte (distinct du modèle TTS)', function () {
    config()->set('services.gemini.api_key', 'cle-test');
    config()->set('services.gemini.model_texte', 'gemini-3-pro');
    config()->set('services.gemini.model', 'gemini-2.5-flash-preview-tts'); // TTS — ne doit PAS être utilisé

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'mon_outil', 'args' => ['titre' => 'X']]]]]]],
    ])]);

    (new GeminiClient)->genererStructure('s', [['role' => 'user', 'content' => 'u']], outilTest());

    Http::assertSent(fn ($req) => str_contains($req->url(), 'models/gemini-3-pro:generateContent'));
});

it('lève AppelLlmException si aucun functionCall (et ne réessaie pas sur 4xx)', function () {
    config()->set('services.gemini.api_key', 'cle-test');

    // Réponse 200 sans functionCall → exception.
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'bla']]], 'finishReason' => 'STOP']],
    ])]);
    expect(fn () => (new GeminiClient)->genererStructure('s', [['role' => 'user', 'content' => 'u']], outilTest()))
        ->toThrow(AppelLlmException::class);

    // 400 → exception, un seul appel (pas de réessai hors 429/5xx).
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'bad request']], 400)]);
    expect(fn () => (new GeminiClient)->genererStructure('s', [['role' => 'user', 'content' => 'u']], outilTest()))
        ->toThrow(AppelLlmException::class);
    Http::assertSentCount(1);
});

it('lève AppelLlmException sans clé', function () {
    config()->set('services.gemini.api_key', null);
    Http::fake();
    expect(fn () => (new GeminiClient)->genererTexte('s', [['role' => 'user', 'content' => 'u']]))
        ->toThrow(AppelLlmException::class);
    Http::assertNothingSent();
});

it('concatène le texte des parts (genererTexte)', function () {
    config()->set('services.gemini.api_key', 'cle-test');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Il était '], ['text' => 'une fois.']]]]],
    ])]);

    expect((new GeminiClient)->genererTexte('s', [['role' => 'user', 'content' => 'u']]))
        ->toBe('Il était une fois.');
});

it('traduit la boucle de retry (tool_use/tool_result) en tours de texte', function () {
    config()->set('services.gemini.api_key', 'cle-test');

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['functionCall' => ['name' => 'mon_outil', 'args' => ['titre' => 'ok']]]]]]],
    ])]);

    // Messages au format Anthropic comme les réinjecte Skill::generer.
    $messages = [
        ['role' => 'user', 'content' => 'Premier essai.'],
        ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'r0', 'name' => 'mon_outil', 'input' => ['titre' => 'X']]]],
        ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'r0', 'is_error' => true, 'content' => 'Erreur : titre trop court.']]],
    ];

    (new GeminiClient)->genererStructure('s', $messages, outilTest());

    Http::assertSent(function ($req) {
        $c = $req['contents'];

        return count($c) === 3
            && $c[0]['role'] === 'user'
            && $c[1]['role'] === 'model'   // assistant → model
            && str_contains($c[1]['parts'][0]['text'], 'Sortie précédente')
            && $c[2]['role'] === 'user'
            && str_contains($c[2]['parts'][0]['text'], 'titre trop court');
    });
});

it('assainit le schéma (retire les mots-clés non OpenAPI, récursivement)', function () {
    $schema = [
        'type' => 'object',
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'additionalProperties' => false,
        'required' => ['nom', 'liste'],
        'properties' => [
            'nom' => ['type' => 'string', 'minLength' => 2, 'additionalProperties' => false],
            'liste' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => ['x' => ['type' => 'integer', 'enum' => [1, 2, 3]]],
                ],
            ],
        ],
    ];

    $propre = GeminiClient::assainirSchema($schema);

    expect($propre)->not->toHaveKey('$schema')
        ->and($propre)->not->toHaveKey('additionalProperties')
        ->and($propre['properties']['nom'])->toHaveKey('minLength')   // conservé
        ->and($propre['properties']['nom'])->not->toHaveKey('additionalProperties')
        ->and($propre['properties']['liste']['items'])->not->toHaveKey('additionalProperties')
        ->and($propre['properties']['liste']['items']['properties']['x']['enum'])->toBe([1, 2, 3]);
});

it('binde le fournisseur globalement selon LLM_PROVIDER (repli Anthropic)', function () {
    // gemini demandé + clé présente → GeminiClient pour TOUS les skills.
    config()->set('services.llm.provider', 'gemini');
    config()->set('services.gemini.api_key', 'cle-test');
    expect(app(ClientLLM::class))->toBeInstanceOf(GeminiClient::class);

    // défaut anthropic → AnthropicClient.
    config()->set('services.llm.provider', 'anthropic');
    expect(app(ClientLLM::class))->toBeInstanceOf(AnthropicClient::class);

    // gemini demandé mais clé absente → repli Anthropic (jouabilité préservée).
    config()->set('services.llm.provider', 'gemini');
    config()->set('services.gemini.api_key', null);
    expect(app(ClientLLM::class))->toBeInstanceOf(AnthropicClient::class);
});
