<?php

declare(strict_types=1);

namespace App\Agent\Exceptions;

use RuntimeException;

/**
 * Échec d'un appel à l'API Anthropic (HTTP, clé manquante, réponse
 * inexploitable). Distinct d'une sortie syntaxiquement valide mais
 * rejetée par la validation (SortieInvalideException).
 */
class AppelLlmException extends RuntimeException
{
}
