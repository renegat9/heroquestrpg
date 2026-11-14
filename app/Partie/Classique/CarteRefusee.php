<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use RuntimeException;

/**
 * Un document de campagne que {@see CarteConstruite::produire()} refuse de transformer en carte (MODE CLASSIQUE, lot K2).
 *
 * Le producteur ne RÉPARE rien : un document qui ne passe pas {@see ValidationCampagne} ne donne aucune carte. Les refus
 * nommés `[{chemin, code, message}]` sont portés tels quels, pour qu'un appelant (route, MCP, test) les lise sans les
 * reconstruire depuis le message.
 */
final class CarteRefusee extends RuntimeException
{
    /** @param  list<array{chemin: string, code: string, message: string}>  $refus  jamais vide */
    public function __construct(public readonly array $refus)
    {
        $detail = array_map(
            fn (array $r): string => ($r['chemin'] !== '' ? $r['chemin'].' ' : '').'['.$r['code'].'] '.$r['message'],
            $refus,
        );

        parent::__construct('Document refusé, aucune carte produite : '.implode(' ; ', $detail));
    }
}
