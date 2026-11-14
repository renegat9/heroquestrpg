<?php

declare(strict_types=1);

namespace Tests\Feature\Classique;

/**
 * Un document de campagne construite VALIDE, entièrement INVENTÉ pour les tests du lot K1 : plateau, salles, quêtes et
 * textes ne reprennent rien d'un livret ni d'un plateau de jeu de société. Seuls les slugs de catalogue sont réels (ceux
 * des seeders) : les tests qui s'en servent seedent le catalogue avant.
 *
 * Plan inventé : trois salles sur un plateau de 12 × 8. `entree` et `galerie` sont au nord, `crypte` au sud ; tout le
 * reste est couloir. La première quête ouvre `entree` ; la seconde ouvre `crypte` et ferme donc `entree`.
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
                'largeur' => 12,
                'hauteur' => 8,
                'salles' => [
                    ['id' => 'entree', 'x' => 1, 'y' => 1, 'l' => 4, 'h' => 3],
                    ['id' => 'galerie', 'x' => 7, 'y' => 1, 'l' => 4, 'h' => 3],
                    ['id' => 'crypte', 'x' => 4, 'y' => 5, 'l' => 4, 'h' => 2],
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
            'salles_ouvertes' => ['entree'],
            'portes' => [['x' => 4, 'y' => 2, 'cote' => 'e', 'type' => 'normale']],
            'blocs' => [['x' => 6, 'y' => 0]],
            'mobilier' => [['slug' => 'table', 'x' => 2, 'y' => 2, 'rotation' => 90]],
            'monstres' => [['slug' => 'gobelin', 'x' => 2, 'y' => 1]],
            'pieges' => [['slug' => 'fosse', 'x' => 3, 'y' => 3]],
            'terrain' => [['slug' => 'glace-glissante', 'x' => 5, 'y' => 0]],
            'depart' => ['x' => 1, 'y' => 1],
            'recompense' => ['or' => 10, 'partage' => 'groupe'],
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
            'salles_ouvertes' => ['crypte'],
            'portes' => [['x' => 4, 'y' => 5, 'cote' => 'n', 'type' => 'secrete']],
            'blocs' => [],
            'mobilier' => [],
            'monstres' => [['slug' => 'squelette', 'x' => 5, 'y' => 6]],
            'pieges' => [],
            'terrain' => [],
            'depart' => ['x' => 4, 'y' => 4],
            'recompense' => null,
        ];
    }
}
