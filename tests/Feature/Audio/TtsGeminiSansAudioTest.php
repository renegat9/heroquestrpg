<?php

declare(strict_types=1);

use App\Agent\Audio\TtsGemini;
use App\Agent\Exceptions\AppelLlmException;
use Illuminate\Support\Facades\Http;

// Gemini TTS répond parfois 200 SANS audio, sur des phrases ordinaires
// (« Inefficace. », barks:generer du 2026-10-21) : un aléa du modèle. On retente
// deux fois — chaque essai coûte une requête du quota de 100/jour.

function reponseAudio(): array
{
    return ['candidates' => [['content' => ['parts' => [['inlineData' => ['data' => base64_encode('PCM')]]]]]]];
}

function reponseVide(): array
{
    return ['candidates' => [['finishReason' => 'OTHER']]];
}

beforeEach(fn () => config(['services.gemini.api_key' => 'cle-test']));

it('retente quand la réponse arrive sans audio, et rend l\'audio du second essai', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(reponseVide())
        ->push(reponseAudio())]);

    $wav = app(TtsGemini::class)->synthetiser('Inefficace.', 'Rasalgethi', 'une voix mécanique');

    expect($wav)->toStartWith('RIFF')->toEndWith('PCM');
    Http::assertSentCount(2);
});

it('abandonne après trois essais vides, en nommant le finishReason', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
        ->push(reponseVide())
        ->push(reponseVide())
        ->push(reponseVide())
        ->push(reponseAudio())]);

    expect(fn () => app(TtsGemini::class)->synthetiser('Pas… juste…', 'Algenib', 'une voix d\'ogre'))
        ->toThrow(AppelLlmException::class, 'finishReason: OTHER, 3 essais');

    Http::assertSentCount(3);
});

it('ne retente pas une réponse qui porte l\'audio', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(reponseAudio())]);

    app(TtsGemini::class)->synthetiser('Grrraou !', 'Enceladus', 'une bête');

    Http::assertSentCount(1);
});
