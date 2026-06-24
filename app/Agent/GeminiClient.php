<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Exceptions\AppelLlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client LLM Google Gemini (generativelanguage.googleapis.com, generateContent),
 * alternative à {@see AnthropicClient} pour TOUT le texte du MJ IA quand
 * LLM_PROVIDER=gemini (services.llm.provider).
 *
 * Sortie structurée = FORCED FUNCTION CALLING : on déclare une fonction dont
 * `parameters` est le schéma JSON du skill (sous-ensemble OpenAPI), et on force
 * son appel via tool_config.mode=ANY + allowed_function_names. La réponse est
 * un functionCall dont `args` est l'objet conforme (en forme) au schéma — la
 * véracité (catalogue…) reste vérifiée par ValidationSortie + le moteur.
 *
 * Le client de Skill (boucle de retry) parle le format Anthropic
 * (tool_use/tool_result) : on le traduit en `contents` Gemini (cf.
 * {@see self::traduireMessages()}).
 */
final class GeminiClient implements ClientLLM
{
    /** Réessais sur 429/5xx (rate limit) ; au-delà d'une attente trop longue, on abandonne. */
    private const MAX_REESSAIS = 6;
    private const ATTENTE_MAX_SECONDES = 65;

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
        private readonly ?string $baseUrl = null,
        private readonly ?int $timeout = null,
    ) {}

    public function modeleParDefaut(): string
    {
        return $this->model ?? (string) config('services.gemini.model_texte', 'gemini-2.5-flash');
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  array{name: string, description: string, input_schema: array<string, mixed>}  $outil
     * @return array<string, mixed>
     *
     * @throws AppelLlmException
     */
    public function genererStructure(string $system, array $messages, array $outil, ?string $model = null): array
    {
        $reponse = $this->appeler($model ?? $this->modeleParDefaut(), [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => $this->traduireMessages($messages),
            'tools' => [[
                'function_declarations' => [[
                    'name' => $outil['name'],
                    'description' => $outil['description'],
                    'parameters' => self::assainirSchema($outil['input_schema']),
                ]],
            ]],
            // Force l'appel de l'outil (équivalent du tool_choice Anthropic).
            'tool_config' => [
                'function_calling_config' => [
                    'mode' => 'ANY',
                    'allowed_function_names' => [$outil['name']],
                ],
            ],
        ]);

        foreach ((array) $reponse->json('candidates.0.content.parts', []) as $part) {
            $appel = $part['functionCall'] ?? null;
            if (is_array($appel) && ($appel['name'] ?? null) === $outil['name']) {
                if (! is_array($appel['args'] ?? null)) {
                    throw new AppelLlmException('functionCall Gemini sans args exploitables.');
                }

                return $appel['args'];
            }
        }

        throw new AppelLlmException(
            'Aucun functionCall dans la réponse Gemini (finishReason: '
            .($reponse->json('candidates.0.finishReason') ?? 'inconnu').').'
        );
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     *
     * @throws AppelLlmException
     */
    public function genererTexte(string $system, array $messages, ?string $model = null): string
    {
        $reponse = $this->appeler($model ?? $this->modeleParDefaut(), [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => $this->traduireMessages($messages),
        ]);

        $texte = '';
        foreach ((array) $reponse->json('candidates.0.content.parts', []) as $part) {
            if (isset($part['text']) && is_string($part['text'])) {
                $texte .= $part['text'];
            }
        }

        return $texte;
    }

    /**
     * Traduit les messages au format Anthropic (produits par Skill::generer)
     * vers le format `contents` de Gemini. La boucle de retry de Skill
     * réinjecte des blocs tool_use (assistant) / tool_result (user) ; on les
     * APLATIT en texte plutôt que d'utiliser functionCall/functionResponse
     * natifs (appariement fragile) : robuste, conserve l'alternance user/model,
     * et `mode:ANY` force de toute façon un nouvel appel structuré à chaque tour.
     *
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return list<array<string, mixed>>
     */
    private function traduireMessages(array $messages): array
    {
        $contents = [];

        foreach ($messages as $msg) {
            $role = ($msg['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
            $content = $msg['content'] ?? '';

            if (is_string($content)) {
                $contents[] = ['role' => $role, 'parts' => [['text' => $content]]];

                continue;
            }

            $texte = '';
            foreach ((array) $content as $bloc) {
                $texte .= match ($bloc['type'] ?? null) {
                    'tool_use' => "Sortie précédente (rejetée) :\n"
                        .json_encode($bloc['input'] ?? [], JSON_UNESCAPED_UNICODE),
                    'tool_result' => (string) ($bloc['content'] ?? ''),
                    'text' => (string) ($bloc['text'] ?? ''),
                    default => '',
                };
            }
            $contents[] = ['role' => $role, 'parts' => [['text' => $texte]]];
        }

        return $contents;
    }

    /**
     * Sanitise un schéma JSON pour `parameters` Gemini (sous-ensemble OpenAPI) :
     * retire les mots-clés non reconnus et recurse sur properties / items.
     * (Les schémas des skills n'utilisent que des mots-clés compatibles —
     * surtout défensif/à l'épreuve d'éditions futures.)
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function assainirSchema(array $schema): array
    {
        $aRetirer = ['$schema', 'additionalProperties', 'const', 'pattern', 'format',
            'oneOf', 'anyOf', 'allOf', 'not', 'default', '$ref', 'examples'];

        foreach ($aRetirer as $cle) {
            unset($schema[$cle]);
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $nom => $sousSchema) {
                if (is_array($sousSchema)) {
                    $schema['properties'][$nom] = self::assainirSchema($sousSchema);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::assainirSchema($schema['items']);
        }

        return $schema;
    }

    /**
     * POST generateContent avec réessais 429/5xx honorant RetryInfo. Jamais de
     * réessai sur les autres 4xx.
     *
     * @param  array<string, mixed>  $corps
     *
     * @throws AppelLlmException
     */
    private function appeler(string $modele, array $corps): Response
    {
        $cle = trim((string) ($this->apiKey ?? config('services.gemini.api_key', '')));

        if ($cle === '') {
            throw new AppelLlmException('GEMINI_API_KEY absente (services.gemini.api_key).');
        }

        $base = rtrim($this->baseUrl ?? (string) config('services.gemini.base_url', 'https://generativelanguage.googleapis.com'), '/');
        $url = "{$base}/v1beta/models/{$modele}:generateContent";

        for ($tentative = 0; ; $tentative++) {
            try {
                $reponse = Http::timeout($this->timeout ?? (int) config('services.gemini.timeout', 60))
                    ->withHeaders(['x-goog-api-key' => $cle])
                    ->post($url, $corps);
            } catch (ConnectionException $e) {
                throw new AppelLlmException('Gemini injoignable : '.$e->getMessage(), previous: $e);
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
                'API Gemini %d : %s',
                $reponse->status(),
                $reponse->json('error.message') ?? mb_substr($reponse->body(), 0, 500),
            ));
        }

        if ($reponse->json() === null) {
            throw new AppelLlmException('Réponse Gemini non-JSON.');
        }

        return $reponse;
    }

    /**
     * Délai avant réessai : le `retryDelay` (details.RetryInfo) s'il est
     * présent, sinon un backoff exponentiel. +1 s de marge. (cf. TtsGemini.)
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
