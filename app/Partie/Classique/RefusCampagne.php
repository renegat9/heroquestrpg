<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Le refus d'une campagne construite : une `ValidationException` (ce qu'attend Laravel pour un 422) qui PORTE en plus la
 * liste des refus nommés `[{chemin, code, message}]`, pour que la réponse HTTP — et un client MCP — les lisent sans les
 * reconstruire depuis les messages.
 */
final class RefusCampagne extends ValidationException
{
    /** @var list<array{chemin: string, code: string, message: string}> */
    public array $refus = [];

    /** @param  list<array{chemin: string, code: string, message: string}>  $refus  jamais vide */
    public static function depuis(array $refus): self
    {
        $validateur = Validator::make([], []);
        foreach ($refus as $refusNomme) {
            $validateur->errors()->add(
                $refusNomme['chemin'] !== '' ? $refusNomme['chemin'] : 'document',
                $refusNomme['message'],
            );
        }

        $exception = new self($validateur);
        $exception->refus = $refus;

        return $exception;
    }
}
