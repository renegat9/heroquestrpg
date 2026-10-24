<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * FORMES DE TERRAIN EN PLAQUE — le vocabulaire FERMÉ des motifs tirés au hasard (Jungles of Delthrak, 2026-10-24).
 *
 * René (2026-10-24, après comparaison avec la carte de la quête 1) : « la jungle et la toile pourraient avoir différents
 * patterns tirés au hasard ? ». Le livret pose ces terrains en PLAQUES : une case seule, deux ou trois en ligne, un carré
 * 2×2, un L, une bande de quatre. Une forme n'entre ici QUE si elle a été relevée à l'image sur un rendu du livret (source
 * écrite à côté de chaque forme) — jamais une forme imaginée (« jamais une valeur non sourcée », CLAUDE.md).
 *
 * Chaque forme est une liste de cases `[dx, dy]` relatives au coin haut-gauche de sa boîte : `[0, 0]` y figure toujours,
 * et la forme est connexe (vérifié par `TerrainPlaquesTest`).
 *
 * Un motif n'est PAS un effet : aucune clé de `MotsClesTerrain` ne porte cette information. C'est l'EMPRISE d'une pose,
 * comme `mobiliers.largeur/hauteur`. Le catalogue la référence par `terrains.motif` (une clé de `MOTIFS`) ; le test les
 * confronte DANS LES DEUX SENS, et vérifie que chaque forme est effectivement tirée (une forme déclarée jamais posée
 * serait exactement la clé décorative que CLAUDE.md interdit).
 *
 * ⚠ Ce que le livret ne montre PAS, et qu'on n'a donc pas inventé : une ligne horizontale de TROIS de jungle ou de toile
 * (seule une ligne de trois SABLE est relevée, ci-dessous) ; un 3×3 ou un anneau de huit autour d'un cocon (la case
 * centrale est masquée par l'objet — la forme pleine n'est pas attestée) ; une forme en plus-signe autour d'un cocon.
 *
 * ⚠ Le L de trois ne figure que dans UNE orientation : les deux occurrences relevées sont celle-ci. Les autres rotations
 * ne sont pas attestées, donc pas tirées.
 *
 * ⚠ Plusieurs de ces formes sont relevées avec une case MASQUÉE par un jeton (Spawnling, cocon) : la 4ᵉ case du L n'est
 * donc pas prouvée non-plaque. Le motif porte la forme VISIBLE ; c'est la lecture la plus prudente qui reste fidèle au
 * dessin.
 *
 * @see \App\Partie\AssembleurCarte::placerTerrains()      tire la forme (`positionsDeTerrain()`), la pose en entier ou rien
 * @see \App\Models\Terrain::$motif                         la colonne qui la désigne
 */
final class FormesTerrain
{
    /**
     * @var array<string, array{libelle: string, formes: list<array{nom: string, cases: list<array{0: int, 1: int}>, source: string}>}>
     */
    public const MOTIFS = [
        // Jungle, Toile, Sable entravants : UN seul patron, puisque le livret ne distingue les trois que par l'image.
        'plaque' => [
            'libelle' => 'une plaque de terrain gênant : une forme relevée sur le livret, tirée au hasard',
            'formes' => [
                [
                    'nom' => 'une case',
                    'cases' => [[0, 0]],
                    'source' => 'Jungle, F9907 p. 13 (PDF p07) : case isolée, partie centrale de la carte ; Toile isolée, même page.',
                ],
                [
                    'nom' => 'deux en ligne horizontale',
                    'cases' => [[0, 0], [1, 0]],
                    'source' => 'Jungle, F9907 p. 17 (PDF p09) : deux cases contiguës dans la salle nord-est ; Toile, F9907 p. 13 (PDF p07) : deux cases de toile contiguës.',
                ],
                [
                    'nom' => 'deux en ligne verticale',
                    'cases' => [[0, 0], [0, 1]],
                    'source' => 'Jungle, F9907 p. 13 (PDF p07) : deux cases superposées, au centre-ouest de la carte.',
                ],
                [
                    'nom' => 'trois en ligne horizontale',
                    'cases' => [[0, 0], [1, 0], [2, 0]],
                    'source' => 'Sable, F9907 p. 29 (PDF p15) : trois cases contiguës, relevées à l\'image. Aucune ligne de trois jungle ou toile horizontale n\'est relevée.',
                ],
                [
                    'nom' => 'trois en ligne verticale',
                    'cases' => [[0, 0], [0, 1], [0, 2]],
                    'source' => 'Jungle, F9907 p. 13 (PDF p07) : trois cases superposées, partie haute de la carte ; Jungle, F9907 p. 15 (PDF p08).',
                ],
                [
                    'nom' => 'carré 2×2',
                    'cases' => [[0, 0], [1, 0], [0, 1], [1, 1]],
                    'source' => 'Jungle, F9907 p. 17 (PDF p09) : carré 2×2 au centre de la carte ; Sable, F9907 p. 25 (PDF p13) ; Toile géante, F9907 p. 13 (PDF p07).',
                ],
                [
                    'nom' => 'L de trois',
                    'cases' => [[0, 0], [0, 1], [1, 1]],
                    'source' => 'Jungle, F9907 p. 13 (PDF p07) : L de trois, quatrième case masquée par un jeton ; même forme, F9907 p. 15 (PDF p08).',
                ],
                [
                    'nom' => 'bande de quatre horizontale',
                    'cases' => [[0, 0], [1, 0], [2, 0], [3, 0]],
                    'source' => 'Jungle, F9907 p. 31 (PDF p16) : quatre cases contiguës, sans jeton (relevé à l\'image).',
                ],
                [
                    'nom' => 'bande de quatre verticale',
                    'cases' => [[0, 0], [0, 1], [0, 2], [0, 3]],
                    'source' => 'Jungle, F9907 p. 25 (PDF p13) : quatre cases superposées, à côté d\'autres cases de jungle.',
                ],
            ],
        ],
    ];

    /** Le motif `$motif` existe-t-il dans le vocabulaire fermé ? `null` (une case, un bloc) n'est pas un motif. */
    public static function connu(?string $motif): bool
    {
        return $motif !== null && isset(self::MOTIFS[$motif]);
    }

    /**
     * Les formes d'un motif, dans l'ordre déclaré (le tirage du PRNG indexe cette liste).
     *
     * @return list<array{nom: string, cases: list<array{0: int, 1: int}>, source: string}>
     */
    public static function formesDe(string $motif): array
    {
        if (! self::connu($motif)) {
            throw new \LogicException("Motif de terrain inconnu : « {$motif} » (clé absente de FormesTerrain::MOTIFS).");
        }

        return self::MOTIFS[$motif]['formes'];
    }
}
