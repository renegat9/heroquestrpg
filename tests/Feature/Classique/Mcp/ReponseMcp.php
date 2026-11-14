<?php

declare(strict_types=1);

namespace Tests\Feature\Classique\Mcp;

use Closure;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Lit le `structuredContent` d'une réponse de test MCP. Le paquet ne l'expose qu'à travers ses assertions ; les tests du
 * constructeur veulent comparer la forme exacte (une quête résumée n'a que `rang`, `slug`, `titre`), d'où cette lecture.
 * Outil de TEST seulement : rien de la production ne passe par ici.
 */
final class ReponseMcp
{
    /** @return array<string, mixed>|null */
    public static function structure(TestResponse $reponse): ?array
    {
        return Closure::bind(fn (): ?array => $this->structuredContent(), $reponse, TestResponse::class)();
    }
}
