<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * « Quelle salle contient cette case ? » — la seule réponse du projet.
 *
 * ⚠ La question était posée à QUATRE endroits, chacun avec sa propre boucle :
 * `DemarreurQuete` (pour révéler la salle 0), `AssembleurCarte` (pour placer
 * pièges et épreuves), une closure du brouillard dans `EtatGroupe`, et le filtre
 * des leviers. Elles disaient toutes la même chose, ce qui est précisément le
 * problème : une salle est un rectangle, la règle est trop simple pour qu'on
 * remarque qu'une copie a dérivé — un `<=` au lieu d'un `<` sur un seul des
 * quatre sites, et une case de bord change de salle selon qui la regarde.
 *
 * Le rectangle est en coordonnées de coin haut-gauche + dimensions, comme la
 * grille les stocke ; la borne haute est EXCLUE.
 */
final class Salles
{
    /**
     * Index de la salle contenant (x, y), ou `null` pour un couloir.
     *
     * ⚠ `null` n'est pas une erreur : un couloir n'appartient à aucune salle,
     * et c'est ce qui distingue « hors de toute salle » de « salle 0 ».
     *
     * @param  array<int|string, array{x: int|string, y: int|string, largeur: int|string, hauteur: int|string}>  $salles
     */
    public static function indexDe(array $salles, int $x, int $y): ?int
    {
        foreach ($salles as $i => $salle) {
            if ($x >= (int) $salle['x'] && $x < (int) $salle['x'] + (int) $salle['largeur']
                && $y >= (int) $salle['y'] && $y < (int) $salle['y'] + (int) $salle['hauteur']) {
                return (int) $i;
            }
        }

        return null;
    }

    /**
     * Vrai si (x, y) tombe dans la salle d'index donné.
     *
     * @param  array<int|string, array{x: int|string, y: int|string, largeur: int|string, hauteur: int|string}>  $salles
     */
    public static function contient(array $salles, int $index, int $x, int $y): bool
    {
        return self::indexDe($salles, $x, $y) === $index;
    }
}
