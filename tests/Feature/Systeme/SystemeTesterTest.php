<?php

declare(strict_types=1);

use App\Agent\SanteServices;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * POST /api/systeme/tester — test de connectivité RÉEL et PAYANT, un service
 * à la fois (docs/contrat-api.md « Système »). Route PUBLIQUE.
 */
beforeEach(function () {
    Cache::flush();
    config([
        'services.anthropic.api_key' => null,
        'services.gemini.api_key' => null,
        'services.voyage.api_key' => null,
    ]);
    // ⚠ PAS de `Http::fake()` générique ici — voir SystemeEndpointTest.php :
    // une règle sans argument enregistrée avant un `Http::fake([...])` plus
    // précis d'un test la rendrait inopérante (Laravel retient la PREMIÈRE
    // règle qui correspond). Chaque test pose la sienne si besoin.
});

it('refuse gemini_tts et gemini_image : quota protégé, 422 explicite', function () {
    Http::fake();
    $r1 = $this->postJson('/api/systeme/tester', ['service' => 'gemini_tts'])->assertStatus(422)->json();
    $r2 = $this->postJson('/api/systeme/tester', ['service' => 'gemini_image'])->assertStatus(422)->json();

    expect($r1['ok'])->toBeFalse()->and($r1['erreur'])->not->toBeEmpty()
        ->and($r2['ok'])->toBeFalse()->and($r2['erreur'])->not->toBeEmpty();

    // Aucun appel HTTP réel déclenché par ces deux services refusés.
    Http::assertNothingSent();
});

it('rejette qdrant : service interne, pas de test payant possible (422 de validation)', function () {
    Http::fake();
    $this->postJson('/api/systeme/tester', ['service' => 'qdrant'])
        ->assertStatus(422)->assertJsonValidationErrors('service');
});

it('rejette un service inconnu (422 de validation)', function () {
    Http::fake();
    $this->postJson('/api/systeme/tester', ['service' => 'openai'])
        ->assertStatus(422)->assertJsonValidationErrors('service');
});

it('refuse anthropic sans clé serveur (422)', function () {
    Http::fake();
    $this->postJson('/api/systeme/tester', ['service' => 'anthropic'])
        ->assertStatus(422)->assertJsonValidationErrors('service');
});

it('teste anthropic avec succès : max_tokens=1, marque l\'origine "test"', function () {
    config(['services.anthropic.api_key' => 'sk-ant-TEST']);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'OK']],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 1],
        ]),
    ]);

    $data = $this->postJson('/api/systeme/tester', ['service' => 'anthropic'])->assertOk()->json();

    expect($data['ok'])->toBeTrue()
        ->and($data['service'])->toBe('anthropic')
        ->and($data['extrait'])->toBe('OK');

    Http::assertSent(fn ($r) => ($r['max_tokens'] ?? null) === 1);
    expect(SanteServices::etat('anthropic')['derniere_reussite_origine'])->toBe('test');
});

it('teste anthropic en échec : ok=false, origine "test" enregistrée, jamais la clé dans la réponse', function () {
    config(['services.anthropic.api_key' => 'sk-ant-SECRET-NE-JAMAIS-FUITER']);
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'error' => ['type' => 'billing_error', 'message' => 'Your credit balance is too low'],
        ], 400),
    ]);

    $reponse = $this->postJson('/api/systeme/tester', ['service' => 'anthropic'])->assertOk();
    $data = $reponse->json();

    expect($data['ok'])->toBeFalse()->and($data['erreur'])->not->toBeEmpty();
    expect(SanteServices::etat('anthropic')['dernier_echec']['origine'])->toBe('test')
        ->and(SanteServices::etat('anthropic')['dernier_echec']['categorie'])->toBe('credit_epuise');

    expect($reponse->getContent())->not->toContain('sk-ant-SECRET-NE-JAMAIS-FUITER');
});

it('teste gemini_texte avec succès', function () {
    config(['services.gemini.api_key' => 'sk-gem-TEST']);
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'OK']]]]],
            'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 1],
        ]),
    ]);

    $data = $this->postJson('/api/systeme/tester', ['service' => 'gemini_texte'])->assertOk()->json();

    expect($data['ok'])->toBeTrue()->and($data['extrait'])->toBe('OK');
    expect(SanteServices::etat('gemini_texte')['derniere_reussite_origine'])->toBe('test');
});

it('teste voyage avec succès (embedding d\'une courte chaîne)', function () {
    config(['services.voyage.api_key' => 'voy-TEST-KEY']);
    Http::fake([
        'api.voyageai.com/*' => Http::response([
            'data' => [['embedding' => array_fill(0, 1024, 0.01)]],
        ]),
    ]);

    $data = $this->postJson('/api/systeme/tester', ['service' => 'voyage'])->assertOk()->json();

    expect($data['ok'])->toBeTrue()
        ->and($data['extrait'])->toContain('1024');
    expect(SanteServices::etat('voyage')['derniere_reussite_origine'])->toBe('test');
});

it('refuse voyage sans clé (422)', function () {
    Http::fake();
    $this->postJson('/api/systeme/tester', ['service' => 'voyage'])
        ->assertStatus(422)->assertJsonValidationErrors('service');
});

it('teste voyage en échec : ok=false, jamais la clé dans la réponse', function () {
    config(['services.voyage.api_key' => 'voy-SECRET-NE-JAMAIS-FUITER']);
    Http::fake([
        'api.voyageai.com/*' => Http::response(['error' => 'invalid api key'], 401),
    ]);

    $reponse = $this->postJson('/api/systeme/tester', ['service' => 'voyage'])->assertOk();
    $data = $reponse->json();

    expect($data['ok'])->toBeFalse();
    expect($reponse->getContent())->not->toContain('voy-SECRET-NE-JAMAIS-FUITER');
    expect(SanteServices::etat('voyage')['dernier_echec']['categorie'])->toBe('cle_invalide');
});
