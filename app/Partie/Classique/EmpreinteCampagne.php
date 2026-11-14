<?php

declare(strict_types=1);

namespace App\Partie\Classique;

/**
 * L'empreinte d'une campagne construite : sha256 de son document CANONISÉ (MODE CLASSIQUE, lot K1, `docs/contrat-api.md`
 * « Empreinte et export »). Un seul calcul, utilisé à l'enregistrement (colonne `empreinte`) et à l'import (vérification).
 *
 * Canonisé = clés d'objet triées récursivement, listes dans leur ordre, JSON compact sans échappement d'Unicode ni de
 * barres obliques. Deux documents qui ne diffèrent que par l'ordre de leurs clés ont donc la même empreinte.
 */
final class EmpreinteCampagne
{
    /** @param  array<string, mixed>  $document */
    public static function de(array $document): string
    {
        $json = json_encode(
            self::canonique($document),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $json);
    }

    private static function canonique(mixed $valeur): mixed
    {
        if (!is_array($valeur)) {
            return $valeur;
        }
        if (array_is_list($valeur)) {
            return array_map(self::canonique(...), $valeur);
        }

        ksort($valeur, SORT_STRING);

        return array_map(self::canonique(...), $valeur);
    }
}
