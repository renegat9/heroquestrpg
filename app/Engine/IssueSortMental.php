<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Issue d'un sort mental (décision S2 : effet binaire, doc 02 §5).
 *
 * - Immunise   : cible à Mind 0 (mort-vivant) — aucun jet, le sort est sans effet
 *                (règle clé du doc 09 §2).
 * - Resiste    : le jet de Mind atteint la difficulté, l'effet est annulé.
 * - SubitEffet : le jet échoue, la cible subit l'effet (sans dégâts de PV de Mind au MVP).
 */
enum IssueSortMental: string
{
    case Immunise = 'immunise';
    case Resiste = 'resiste';
    case SubitEffet = 'subit_effet';
}
