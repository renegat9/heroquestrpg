<?php

declare(strict_types=1);

namespace App\Agent;

use App\Agent\Exceptions\AppelLlmException;

/**
 * Contrat commun des clients LLM du MJ IA (sortie structurée forcée + texte
 * libre). Permet de substituer le fournisseur — Anthropic (Claude) ou Google
 * (Gemini) — pour TOUTE la génération de texte du MJ (histoire, narration,
 * menus, habillage, résumé), piloté globalement par `LLM_PROVIDER`
 * (config services.llm.provider). Le TTS reste à part (App\Agent\Audio).
 *
 * Implémentations : {@see AnthropicClient}, {@see GeminiClient}.
 */
interface ClientLLM
{
    /**
     * Appel avec sortie structurée forcée (tool use / function calling).
     *
     * @param  string  $system  prompt système (consignes du MJ)
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  array{name: string, description: string, input_schema: array<string, mixed>}  $outil
     * @param  int|null  $maxTokens  plafond de sortie de CET appel (null = défaut du
     *                               fournisseur). ⚠ Sous-dimensionné, il ne raccourcit
     *                               pas la sortie : il la TRONQUE, et un tool_use tronqué
     *                               est un JSON invalide — la génération entière est
     *                               perdue. Voir `services.llm.max_tokens_par_skill`.
     * @return array<string, mixed> l'objet structuré conforme (en forme) au schéma
     *
     * @throws AppelLlmException
     */
    public function genererStructure(string $system, array $messages, array $outil, ?string $model = null, ?int $maxTokens = null): array;

    /**
     * Appel texte libre.
     *
     * @param  list<array{role: string, content: mixed}>  $messages
     *
     * @throws AppelLlmException
     */
    public function genererTexte(string $system, array $messages, ?string $model = null): string;

    /** Identifiant du modèle par défaut du fournisseur. */
    public function modeleParDefaut(): string;
}
