<?php

declare(strict_types=1);

use App\Agent\SanteServices;

/**
 * Table de classification de `SanteServices::classer()` (voir docs/contrat-api.md
 * « Système ») — seule la forme Anthropic (`error.type` explicite) est un
 * contrat fournisseur SOURCÉ ; la distinction quota-journalier vs
 * débit-transitoire chez Gemini est une HEURISTIQUE assumée (voir le
 * commentaire de la méthode). Ces tests pinnent le comportement actuel,
 * y compris ses limites documentées.
 */

// --- Anthropic : error.type explicite (doc officielle) ---

it('Anthropic : authentication_error et permission_error → cle_invalide', function () {
    $corpsAuth = json_encode(['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]);
    $corpsPerm = json_encode(['error' => ['type' => 'permission_error', 'message' => 'not allowed']]);

    expect(SanteServices::classer('anthropic', 401, $corpsAuth))->toBe('cle_invalide')
        ->and(SanteServices::classer('anthropic', 403, $corpsPerm))->toBe('cle_invalide');
});

it('Anthropic : billing_error → credit_epuise', function () {
    $corps = json_encode(['error' => ['type' => 'billing_error', 'message' => 'Your credit balance is too low']]);

    expect(SanteServices::classer('anthropic', 400, $corps))->toBe('credit_epuise');
});

it('Anthropic : invalid_request_error mentionnant le crédit → credit_epuise', function () {
    $corps = json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Claude API']]);

    expect(SanteServices::classer('anthropic', 400, $corps))->toBe('credit_epuise');
});

it('Anthropic : rate_limit_error → limite_debit', function () {
    $corps = json_encode(['error' => ['type' => 'rate_limit_error', 'message' => 'rate limited']]);

    expect(SanteServices::classer('anthropic', 429, $corps))->toBe('limite_debit');
});

it('Anthropic : overloaded_error (529) et api_error (500) → indisponible', function () {
    $corpsSurcharge = json_encode(['error' => ['type' => 'overloaded_error', 'message' => 'overloaded']]);
    $corpsApi = json_encode(['error' => ['type' => 'api_error', 'message' => 'internal error']]);

    expect(SanteServices::classer('anthropic', 529, $corpsSurcharge))->toBe('indisponible')
        ->and(SanteServices::classer('anthropic', 500, $corpsApi))->toBe('indisponible');
});

it('Anthropic : type inconnu sans correspondance → autre', function () {
    expect(SanteServices::classer('anthropic', 404, '{}'))->toBe('autre');
});

// --- Gemini : error.status textuel + RESOURCE_EXHAUSTED (heuristique quota) ---

it('Gemini : API_KEY_INVALID ou 401/403 → cle_invalide', function () {
    $corps = json_encode(['error' => ['status' => 'API_KEY_INVALID', 'message' => 'API key not valid']]);

    expect(SanteServices::classer('gemini_texte', 400, $corps))->toBe('cle_invalide')
        ->and(SanteServices::classer('gemini_image', 403, '{}'))->toBe('cle_invalide');
});

it('Gemini : RESOURCE_EXHAUSTED avec un quotaMetric "par jour" → quota_atteint', function () {
    $corps = json_encode(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'quota exceeded', 'details' => [
        ['violations' => [['quotaMetric' => 'generativelanguage.googleapis.com/generate_content_free_tier_requests_PerDay']]],
    ]]]);

    expect(SanteServices::classer('gemini_texte', 429, $corps))->toBe('quota_atteint');
});

it('Gemini : 429 RESOURCE_EXHAUSTED sans métrique identifiable → limite_debit (repli documenté)', function () {
    $corps = json_encode(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'rate limited']]);

    expect(SanteServices::classer('gemini_texte', 429, $corps))->toBe('limite_debit');
});

it('Gemini : 5xx → indisponible', function () {
    expect(SanteServices::classer('gemini_tts', 503, '{}'))->toBe('indisponible');
});

it('Gemini : message évoquant le crédit/billing → credit_epuise', function () {
    $corps = json_encode(['error' => ['message' => 'Billing account not found for this project']]);

    expect(SanteServices::classer('gemini_image', 400, $corps))->toBe('credit_epuise');
});

// --- Générique (Voyage, Qdrant, services futurs) : code HTTP seul ---

it('générique : 401/403 → cle_invalide, 429 → limite_debit, 5xx → indisponible', function () {
    expect(SanteServices::classer('voyage', 401, '{}'))->toBe('cle_invalide')
        ->and(SanteServices::classer('voyage', 429, '{}'))->toBe('limite_debit')
        ->and(SanteServices::classer('voyage', 500, '{}'))->toBe('indisponible');
});

it('générique : aucune réponse HTTP (réseau/timeout) → indisponible', function () {
    expect(SanteServices::classer('anthropic', 0, null))->toBe('indisponible')
        ->and(SanteServices::classer('voyage', 0, null))->toBe('indisponible');
});

// --- Enregistrement : jamais la clé, toujours tronqué, origine correcte ---

it('signalerEchec/marquerDernierCommeTest : trace l\'origine et ne garde jamais le corps brut non parsé au-delà de la troncature', function () {
    SanteServices::signalerEchec('anthropic', 401, json_encode(['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key SECRET_SHOULD_NOT_LEAK']]));

    $etat = SanteServices::etat('anthropic');
    expect($etat['dernier_echec']['categorie'])->toBe('cle_invalide')
        ->and($etat['dernier_echec']['origine'])->toBe('jeu');

    SanteServices::marquerDernierCommeTest('anthropic');
    expect(SanteServices::etat('anthropic')['dernier_echec']['origine'])->toBe('test');
});

it('signalerSucces : marque la dernière réussite et son origine par défaut', function () {
    SanteServices::signalerSucces('voyage');

    $etat = SanteServices::etat('voyage');
    expect($etat['derniere_reussite'])->not->toBeNull()
        ->and($etat['derniere_reussite_origine'])->toBe('jeu');
});
