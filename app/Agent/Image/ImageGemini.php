<?php

declare(strict_types=1);

namespace App\Agent\Image;

use App\Agent\Exceptions\AppelLlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client de génération d'IMAGES de l'API Gemini
 * (generativelanguage.googleapis.com, modèle gemini-*-image — « Nano Banana »).
 *
 * Sert à illustrer le jeu (héros, monstres, objets, pièges, scènes, boss). Les
 * images FIXES (catalogue) sont pré-générées hors-ligne (commande
 * `images:generer`) ; les DYNAMIQUES (boss/scène/hub/portrait) en arrière-plan
 * (jobs). Jamais bloquant en jeu ; sans clé/asset, le front retombe sur les
 * icônes. Calqué sur {@see \App\Agent\Audio\TtsGemini} (même HTTP + retry 429).
 *
 * L'API renvoie une image inline (base64, généralement PNG) dans
 * `candidates[0].content.parts[].inlineData.data` — on renvoie les octets bruts.
 */
final class ImageGemini
{
    /** Réessais sur 429/5xx ; au-delà d'une attente trop longue, on abandonne (quota). */
    private const MAX_REESSAIS = 6;
    private const ATTENTE_MAX_SECONDES = 65;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
        private readonly ?string $baseUrl = null,
        private readonly ?int $timeout = null,
    ) {}

    /** Une clé est-elle configurée ? (sinon : pas de génération possible). */
    public function estConfigure(): bool
    {
        return $this->cle() !== '';
    }

    public function modele(): string
    {
        return $this->model ?? (string) config('services.gemini.model_image', 'gemini-2.5-flash-image');
    }

    /**
     * Génère une image depuis `$prompt` ; renvoie les octets bruts (PNG).
     *
     * @throws AppelLlmException si non configuré ou si l'API échoue
     */
    public function generer(string $prompt): string
    {
        if (! $this->estConfigure()) {
            throw new AppelLlmException('Gemini Image non configuré (GEMINI_API_KEY absente).');
        }

        $base = rtrim($this->baseUrl ?? (string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com'), '/');
        $url = "{$base}/v1beta/models/{$this->modele()}:generateContent";

        $corps = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['responseModalities' => ['IMAGE']],
        ];

        // Réessais sur 429 (rate limit) en respectant le retryDelay renvoyé.
        for ($tentative = 0; ; $tentative++) {
            try {
                $reponse = Http::timeout($this->timeout ?? (int) config('services.gemini.timeout', 120))
                    ->withHeaders(['x-goog-api-key' => $this->cle()])
                    ->post($url, $corps);
            } catch (ConnectionException $e) {
                throw new AppelLlmException('Gemini Image injoignable : '.$e->getMessage(), previous: $e);
            }

            $statut = $reponse->status();

            if (($statut === 429 || $statut >= 500) && $tentative < self::MAX_REESSAIS) {
                $attente = $this->delaiReessai($reponse, $tentative);

                if ($attente <= self::ATTENTE_MAX_SECONDES) {
                    sleep($attente);

                    continue;
                }
            }

            break;
        }

        if ($reponse->failed()) {
            throw new AppelLlmException(sprintf(
                'API Gemini Image %d : %s',
                $reponse->status(),
                $reponse->json('error.message') ?? mb_substr($reponse->body(), 0, 300),
            ));
        }

        foreach ((array) $reponse->json('candidates.0.content.parts', []) as $part) {
            $b64 = $part['inlineData']['data'] ?? null;
            if (is_string($b64) && $b64 !== '') {
                $octets = base64_decode($b64, true);
                if ($octets === false) {
                    throw new AppelLlmException('Gemini Image : base64 invalide.');
                }

                return $octets;
            }
        }

        throw new AppelLlmException(
            'Gemini Image : réponse sans image (finishReason: '
            .($reponse->json('candidates.0.finishReason') ?? 'inconnu').').'
        );
    }

    private function cle(): string
    {
        return trim((string) ($this->apiKey ?? config('services.gemini.api_key', '')));
    }

    /**
     * Délai avant réessai sur 429/5xx : le `retryDelay` (details.RetryInfo)
     * s'il est présent, sinon un backoff exponentiel. +1 s de marge.
     * (Repris de {@see \App\Agent\Audio\TtsGemini}.)
     */
    private function delaiReessai(Response $reponse, int $tentative): int
    {
        foreach ((array) $reponse->json('error.details', []) as $detail) {
            if (($detail['@type'] ?? '') === 'type.googleapis.com/google.rpc.RetryInfo'
                && preg_match('/(\d+(?:\.\d+)?)s/', (string) ($detail['retryDelay'] ?? ''), $m)) {
                return (int) ceil((float) $m[1]) + 1;
            }
        }

        return min(self::ATTENTE_MAX_SECONDES, (2 ** $tentative) + 1);
    }
}
