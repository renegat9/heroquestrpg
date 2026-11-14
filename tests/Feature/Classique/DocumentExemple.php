<?php

declare(strict_types=1);

namespace Tests\Feature\Classique;

/**
 * Un document de campagne construite VALIDE, entièrement INVENTÉ pour les tests des lots K1 et K2 : plateau, salles, quêtes
 * et textes ne reprennent rien d'un livret ni d'un plateau de jeu de société. Seuls les slugs de catalogue sont réels (ceux
 * des seeders) : les tests qui s'en servent seedent le catalogue avant.
 *
 * Plan inventé, géométrie du moteur (un mur est une case, `docs/contrat-api.md` « Géométrie »), plateau de 20 × 14 :
 * - `entree` : intérieur x 2–6, y 2–5 ; `galerie` : intérieur x 8–11, y 2–5. Elles PARTAGENT le mur x = 7.
 * - `crypte` : intérieur x 3–6, y 9–11, séparée de `entree` par une rangée de couloir (y = 7).
 * - `reserve` : intérieur x 14–16, y 2–4, toujours fermée (roche).
 * - Tout le reste est couloir.
 *
 * La première quête ouvre `entree` et `galerie` ; la seconde ouvre `entree` et `crypte`, et ferme donc `galerie`.
 */
final class DocumentExemple
{
    /** @return array<string, mixed> */
    public static function campagne(): array
    {
        return [
            'format' => 1,
            'slug' => 'campagne-d-essai',
            'titre' => 'Campagne d\'essai',
            'auteur' => 'Auteur inventé',
            'mention' => 'Mention de crédit inventée',
            'prerequis_heros' => ['barbare'],
            'plateau' => [
                'largeur' => 20,
                'hauteur' => 14,
                'salles' => [
                    ['id' => 'entree', 'x' => 2, 'y' => 2, 'l' => 5, 'h' => 4],
                    ['id' => 'galerie', 'x' => 8, 'y' => 2, 'l' => 4, 'h' => 4],
                    ['id' => 'crypte', 'x' => 3, 'y' => 9, 'l' => 4, 'h' => 3],
                    ['id' => 'reserve', 'x' => 14, 'y' => 2, 'l' => 3, 'h' => 3],
                ],
            ],
            'quetes' => [
                self::premiereQuete(),
                self::secondeQuete(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function premiereQuete(): array
    {
        return [
            'slug' => 'premiere-marche',
            'titre' => 'La première marche',
            'ouverture' => 'Un texte inventé, lu à la table.',
            'monstre_errant' => null,
            'salles_ouvertes' => ['entree', 'galerie'],
            'portes' => [
                // Sur le mur partagé : de `entree` vers `galerie`, la case juste dehors est l'intérieur de `galerie`.
                ['x' => 6, 'y' => 3, 'cote' => 'e', 'type' => 'normale'],
                // Vers le couloir du nord.
                ['x' => 3, 'y' => 2, 'cote' => 'n', 'type' => 'normale'],
                // Vers le couloir du sud, en passage secret.
                ['x' => 9, 'y' => 5, 'cote' => 's', 'type' => 'secrete'],
            ],
            'blocs' => [['x' => 11, 'y' => 7]],
            'mobilier' => [['slug' => 'table', 'x' => 4, 'y' => 4, 'rotation' => 0]],
            'monstres' => [['slug' => 'gobelin', 'x' => 5, 'y' => 2]],
            'pieges' => [['slug' => 'fosse', 'x' => 2, 'y' => 2]],
            'terrain' => [['slug' => 'glace-glissante', 'x' => 9, 'y' => 3]],
            'depart' => ['x' => 2, 'y' => 4],
            'recompense' => ['or' => 10, 'partage' => 'groupe'],
            'fin' => ['type' => 'vaincre', 'cibles' => [0], 'libelle' => 'Vaincre le gobelin qui garde l\'entrée.'],
        ];
    }

    /** @return array<string, mixed> */
    public static function secondeQuete(): array
    {
        return [
            'slug' => 'la-crypte',
            'titre' => 'La crypte',
            'ouverture' => 'Autre texte inventé.',
            'monstre_errant' => 'squelette',
            'salles_ouvertes' => ['entree', 'crypte'],
            'portes' => [
                // `entree` vers le couloir du sud, et `crypte` vers le même couloir : deux portes, une rangée de couloir.
                ['x' => 4, 'y' => 5, 'cote' => 's', 'type' => 'normale'],
                ['x' => 4, 'y' => 9, 'cote' => 'n', 'type' => 'normale'],
                // Vers l'ouest, en passage secret.
                ['x' => 3, 'y' => 10, 'cote' => 'o', 'type' => 'secrete'],
            ],
            'blocs' => [],
            'mobilier' => [['slug' => 'bibliotheque', 'x' => 6, 'y' => 9, 'rotation' => 90]],
            'monstres' => [['slug' => 'squelette', 'x' => 5, 'y' => 10]],
            'pieges' => [],
            'terrain' => [],
            'depart' => ['x' => 5, 'y' => 2],
            'recompense' => null,
            'fin' => ['type' => 'vaincre', 'cibles' => [0], 'libelle' => 'Vaincre le squelette de la crypte.'],
        ];
    }
}
