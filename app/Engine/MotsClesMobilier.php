<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * VOCABULAIRE FERMÉ de `mobiliers.effet` — le même garde-fou que
 * `MotsClesTerrain`, `MotsClesEpreuve`, `MotsClesTalent`, posé le 2026-10-09 en
 * portant le *Cocon* de *Jungles of Delthrak* : `mobiliers.effet` n'avait
 * jusqu'ici qu'UNE clé (`fouille`, la table de butin) et n'avait donc jamais eu
 * besoin d'un registre ; la deuxième le rend nécessaire (une clé sans lecteur
 * est une règle promise au joueur et jamais tenue — CLAUDE.md, « Hard rules »).
 *
 * Testé DANS LES DEUX SENS (`MobilierCocoonTest`) : toute clé portée par une
 * ligne du catalogue est déclarée ICI avec son lecteur, et toute clé déclarée
 * est portée par au moins une ligne.
 *
 * Les pièces ATTAQUABLES (`pv_body`/`defense_dice`) et destructibles par jet de
 * Body (`difficulte_destruction`) ont leurs PROPRES colonnes et n'entrent pas
 * ici : ce vocabulaire ne couvre que ce qui vit dans la colonne `effet`.
 *
 * @see \App\Partie\MoteurMobilier
 */
final class MotsClesMobilier
{
    /**
     * @var array<string, array{lecteur: string, libelle: string}>
     */
    public const VOCABULAIRE = [
        // Table de butin d'une pièce fouillable (coffre, tombeau, armoire…),
        // tirée PONDÉRÉE par `poids`.
        'fouille' => [
            'lecteur' => 'App\Partie\MoteurMobilier::tirerButin()',
            'libelle' => 'se fouille : table de butin propre à la pièce',
        ],

        // COCON (Jungles of Delthrak p. 4) : « A hero adjacent to a cocoon can
        // spend an action to destroy it. » UNE action, AUCUN jet — ni les PV du
        // mobilier attaquable, ni le jet de Body du mobilier fracassable.
        'detruit_par_action' => [
            'lecteur' => 'App\Partie\MoteurMobilier::detruisiblesParActionAdjacents()',
            'libelle' => 'se détruit en dépensant une action, sans jet',
        ],
    ];

    /** Une clé déclarée porte-t-elle un lecteur RÉEL ? */
    public static function connue(?string $cle): bool
    {
        return $cle !== null && isset(self::VOCABULAIRE[$cle]);
    }

    /** Le mobilier de ce type est-il détruit d'UNE action, sans jet ? */
    public static function detruitParAction(?array $effet): bool
    {
        return ! empty($effet['detruit_par_action']);
    }
}
