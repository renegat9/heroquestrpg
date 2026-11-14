<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use RuntimeException;

/**
 * Une demande du constructeur qui ne peut pas être exécutée : campagne ou quête introuvable, `rang` hors de son intervalle
 * (MODE CLASSIQUE, lot P1 — `docs/contrat-api.md` « Serveur MCP du constructeur »).
 *
 * Ce n'est PAS un refus de validation : ceux-là sont des {@see RefusCampagne}, rendus comme données. Ici la demande elle-même
 * n'a pas de sens. Le message est en français et lisible tel quel par un client MCP.
 */
final class ErreurConstructeur extends RuntimeException
{
    public static function campagneInconnue(string $slug): self
    {
        return new self("Aucune campagne construite « $slug ».");
    }

    public static function queteInconnue(string $campagne, string $quete): self
    {
        return new self("La campagne « $campagne » n'a pas de quête « $quete ».");
    }

    public static function rangHorsBornes(int $rang, int $maximum): self
    {
        return new self("Le rang $rang est hors de l'intervalle 1 à $maximum pour cette campagne.");
    }
}
