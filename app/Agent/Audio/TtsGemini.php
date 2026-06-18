<?php

declare(strict_types=1);

namespace App\Agent\Audio;

use App\Agent\Exceptions\AppelLlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Client de synthèse vocale (TTS) de l'API Gemini
 * (generativelanguage.googleapis.com, modèle gemini-*-tts).
 *
 * Sert UNIQUEMENT à la génération HORS-LIGNE des barks (commande
 * `barks:generer` + job par boss) : jamais appelé en cours de partie. La voix
 * et le style sont pilotés en langage naturel dans le prompt (un même modèle
 * sait incarner gobelin aigu, brute gutturale, mort-vivant sépulcral…).
 *
 * L'API renvoie du PCM brut (16-bit, mono) ; on l'emballe en WAV (lisible par
 * tous les navigateurs) via {@see self::pcmVersWav()}.
 */
final class TtsGemini
{
    /** Fréquence d'échantillonnage du PCM renvoyé par Gemini TTS. */
    private const TAUX_ECHANTILLONNAGE = 24000;

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

    /**
     * Synthétise `$texte` avec la `$voix` Gemini et le `$style` (consigne de
     * timbre) ; renvoie les octets d'un fichier WAV prêt à écrire.
     *
     * @throws AppelLlmException si non configuré ou si l'API échoue
     */
    public function synthetiser(string $texte, string $voix, string $style): string
    {
        if (! $this->estConfigure()) {
            throw new AppelLlmException('Gemini TTS non configuré (GEMINI_API_KEY absente).');
        }

        // Le pilotage du timbre se fait dans le prompt lui-même.
        $prompt = "Dis ceci avec {$style}, sans rien ajouter d'autre : {$texte}";

        $modele = $this->model ?? (string) config('services.gemini.model', 'gemini-2.5-flash-preview-tts');
        $base = rtrim($this->baseUrl ?? (string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com'), '/');
        $url = "{$base}/v1beta/models/{$modele}:generateContent";

        try {
            $reponse = Http::timeout($this->timeout ?? (int) config('services.gemini.timeout', 60))
                ->withHeaders(['x-goog-api-key' => $this->cle()])
                ->post($url, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => [
                        'responseModalities' => ['AUDIO'],
                        'speechConfig' => [
                            'voiceConfig' => ['prebuiltVoiceConfig' => ['voiceName' => $voix]],
                        ],
                    ],
                ]);
        } catch (ConnectionException $e) {
            throw new AppelLlmException('Gemini TTS injoignable : '.$e->getMessage(), previous: $e);
        }

        if ($reponse->failed()) {
            throw new AppelLlmException("Gemini TTS a répondu {$reponse->status()} : ".$reponse->body());
        }

        $b64 = $reponse->json('candidates.0.content.parts.0.inlineData.data');

        if (! is_string($b64) || $b64 === '') {
            throw new AppelLlmException('Gemini TTS : réponse sans données audio.');
        }

        $pcm = base64_decode($b64, true);

        if ($pcm === false) {
            throw new AppelLlmException('Gemini TTS : audio base64 invalide.');
        }

        return self::pcmVersWav($pcm);
    }

    private function cle(): string
    {
        return trim((string) ($this->apiKey ?? config('services.gemini.api_key', '')));
    }

    /**
     * Emballe du PCM brut (16-bit signé, mono, little-endian) dans un conteneur
     * WAV minimal (en-tête RIFF de 44 octets).
     */
    public static function pcmVersWav(string $pcm, int $taux = self::TAUX_ECHANTILLONNAGE): string
    {
        $canaux = 1;
        $bitsParEchantillon = 16;
        $blocAlign = (int) ($canaux * $bitsParEchantillon / 8);
        $debitOctets = $taux * $blocAlign;
        $tailleData = strlen($pcm);

        return 'RIFF'
            .pack('V', 36 + $tailleData)
            .'WAVE'
            .'fmt '
            .pack('V', 16)              // taille du sous-chunk fmt
            .pack('v', 1)               // format PCM
            .pack('v', $canaux)
            .pack('V', $taux)
            .pack('V', $debitOctets)
            .pack('v', $blocAlign)
            .pack('v', $bitsParEchantillon)
            .'data'
            .pack('V', $tailleData)
            .$pcm;
    }
}
