<?php

declare(strict_types=1);

namespace App\Agent\Exceptions;

use RuntimeException;

/**
 * Sortie d'IA rejetée après épuisement des retries : non conforme au
 * schéma du skill ou références invalides au catalogue (doc 08 §2, §5).
 *
 * Les skills qui disposent d'un repli générique l'utilisent à la place ;
 * sinon l'exception remonte au job (retry/échec géré par la file).
 */
class SortieInvalideException extends RuntimeException
{
    /**
     * @param  list<string>  $erreurs
     */
    public function __construct(
        string $message,
        public readonly array $erreurs = [],
    ) {
        parent::__construct($message);
    }
}
