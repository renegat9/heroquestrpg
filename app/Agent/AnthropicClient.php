<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Exceptions\AppelLlmException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Client minimal de l'API Anthropic Messages (https://api.anthropic.com/v1/messages).
 *
 * Toutes les sorties du MJ IA passent par le "tool use forcé" (tool_choice
 * {type: tool}) : on déclare un outil dont l'input_schema est le schéma JSON
 * du skill, et on force le modèle à l'appeler — la réponse est alors un bloc
 * tool_use dont l'input est un objet conforme (en forme) au schéma.
 * La véracité (références au catalogue…) est vérifiée ensuite par
 * ValidationSortie + le moteur (doc 08 §2).
 */
class AnthropicClient implements ClientLLM
{
    private const VERSION_API = '2023-06-01';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
        private readonly ?string $baseUrl = null,
        private readonly ?int $maxTokens = null,
        private readonly ?int $timeout = null,
        private readonly ?TraceurConsommation $traceur = null,
    ) {}

    public function modeleParDefaut(): string
    {
        return $this->model ?? (string) config('services.anthropic.model', 'claude-sonnet-4-6');
    }

    /**
     * Appel avec sortie structurée forcée.
     *
     * @param  string  $system  prompt système (consignes du MJ)
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  array{name: string, description: string, input_schema: array<string, mixed>}  $outil
     * @return array<string, mixed> l'input du bloc tool_use (la sortie structurée)
     *
     * @throws AppelLlmException
     */
    public function genererStructure(string $system, array $messages, array $outil, ?string $model = null, ?int $maxTokens = null): array
    {
        $reponse = $this->appeler([
            'model' => $model ?? $this->modeleParDefaut(),
            'max_tokens' => $maxTokens ?? $this->maxTokens ?? (int) config('services.anthropic.max_tokens', 4096),
            'system' => $system,
            'messages' => $messages,
            'tools' => [$outil],
            'tool_choice' => ['type' => 'tool', 'name' => $outil['name']],
        ]);

        foreach ($reponse['content'] ?? [] as $bloc) {
            if (($bloc['type'] ?? null) === 'tool_use' && ($bloc['name'] ?? null) === $outil['name']) {
                if (! is_array($bloc['input'] ?? null)) {
                    throw new AppelLlmException('Bloc tool_use sans input exploitable.');
                }

                return $bloc['input'];
            }
        }

        throw new AppelLlmException(
            'Aucun bloc tool_use dans la réponse (stop_reason: '.($reponse['stop_reason'] ?? 'inconnu').').'
        );
    }

    /**
     * Appel texte libre (résumés de clôture, etc.).
     *
     * @param  list<array{role: string, content: mixed}>  $messages
     *
     * @throws AppelLlmException
     */
    public function genererTexte(string $system, array $messages, ?string $model = null): string
    {
        $reponse = $this->appeler([
            'model' => $model ?? $this->modeleParDefaut(),
            'max_tokens' => $this->maxTokens ?? (int) config('services.anthropic.max_tokens', 4096),
            'system' => $system,
            'messages' => $messages,
        ]);

        $texte = '';
        foreach ($reponse['content'] ?? [] as $bloc) {
            if (($bloc['type'] ?? null) === 'text') {
                $texte .= $bloc['text'] ?? '';
            }
        }

        return $texte;
    }

    /**
     * Appel brut POST /v1/messages.
     *
     * @param  array<string, mixed>  $corps
     * @return array<string, mixed>
     *
     * @throws AppelLlmException
     */
    private function appeler(array $corps): array
    {
        $cle = $this->apiKey ?? config('services.anthropic.api_key');

        if (blank($cle)) {
            throw new AppelLlmException('ANTHROPIC_API_KEY absente (services.anthropic.api_key).');
        }

        $base = rtrim($this->baseUrl ?? (string) config('services.anthropic.base_url', 'https://api.anthropic.com'), '/');

        try {
            $reponse = Http::withHeaders([
                'x-api-key' => $cle,
                'anthropic-version' => self::VERSION_API,
            ])
                ->timeout($this->timeout ?? (int) config('services.anthropic.timeout', 120))
                ->retry(2, 1000, when: function ($exception) {
                    // Réessaie réseau + 429/5xx/529 ; jamais les 4xx de requête.
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    $statut = $exception->response?->status();

                    return $statut === 429 || $statut >= 500;
                }, throw: false)
                ->post($base.'/v1/messages', $corps);
        } catch (ConnectionException $e) {
            throw new AppelLlmException('Connexion à l\'API Anthropic impossible : '.$e->getMessage(), previous: $e);
        }

        if ($reponse->failed()) {
            throw new AppelLlmException(sprintf(
                'API Anthropic %d : %s',
                $reponse->status(),
                $reponse->json('error.message') ?? mb_substr($reponse->body(), 0, 500),
            ));
        }

        $json = $reponse->json() ?? throw new AppelLlmException('Réponse Anthropic non-JSON.');

        $this->tracerUsage((string) ($corps['model'] ?? $this->modeleParDefaut()), $json);

        return $json;
    }

    /**
     * Verse l'usage de CETTE réponse HTTP au traceur de consommation
     * (best-effort, voir {@see TraceurConsommation}) — `appeler()` est le
     * point de passage UNIQUE de toutes les requêtes (genererStructure ET
     * genererTexte), donc de tous les retries de `Skill::generer()` et de
     * tous les appels rejoués par `ClientLLMAvecRepli` : chaque réponse ici
     * est une ligne facturée distincte.
     *
     * @param  array<string, mixed>  $corps  réponse JSON décodée de /v1/messages
     */
    private function tracerUsage(string $modele, array $corps): void
    {
        if ($this->traceur === null) {
            return;
        }

        $usage = $corps['usage'] ?? null;
        if (! is_array($usage)) {
            return; // réponse sans usage exploitable — rien à verser
        }

        $this->traceur->enregistrer('anthropic', $modele, [
            'entree' => (int) ($usage['input_tokens'] ?? 0),
            'sortie' => (int) ($usage['output_tokens'] ?? 0),
            'cache' => isset($usage['cache_read_input_tokens']) ? (int) $usage['cache_read_input_tokens'] : null,
        ]);
    }
}
