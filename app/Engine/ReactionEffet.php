<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Vocabulaire des RÉACTIONS — ce qu'un héros peut activer **hors de son tour**.
 *
 * Tout, chez nous, se résout dans le tour de l'acteur. Deux cartes officielles
 * en sortent, et toutes deux au même moment — quand leur porteur encaisse :
 *
 *  - *Dark Wings* (Warlock) : « Cast this spell on an enemies turn **after you
 *    have suffered damage**. Reduce that damage to zero and move instantly to
 *    any unoccupied square you can see. »
 *  - *Twisting Torrent* (Moine) : « Activate this technique **when you take
 *    damage** to cancel that damage. »
 *
 * Un effet déclare `effet.reaction = ['sur' => …, 'action' => …]`.
 *
 * ⚠ Le choix est POSTÉRIEUR au coup, et c'est fidèle plutôt que dégradé : à la
 * table on annonce les dégâts, *puis* le joueur dit « j'annule ». Le moteur ne
 * peut de toute façon pas faire autrement — la phase des monstres se résout
 * dans la requête d'un autre joueur, à l'intérieur d'une transaction, et rien
 * ne peut l'y suspendre le temps d'un aller-retour HTTP. La proposition est
 * donc déposée sur l'état du héros, et la réaction **défait** le coup.
 */
final class ReactionEffet
{
    /** Déclencheur : le porteur vient de subir des dégâts. Le seul pour l'instant. */
    public const SUR_DEGATS_SUBIS = 'degats_subis';

    /** Action : les dégâts qui viennent d'être subis sont rendus. */
    public const ANNULE_DEGATS = 'annule_degats';

    /** @return list<string> */
    public static function declencheurs(): array
    {
        return [self::SUR_DEGATS_SUBIS];
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return [self::ANNULE_DEGATS];
    }

    /**
     * Sources de dégâts auxquelles une réaction « annule les dégâts » répond.
     *
     * ⚠ Volontairement restreint aux COUPS. Les deux cartes parlent d'un coup
     * encaissé (« after you have suffered damage » pendant le tour d'un
     * ennemi) ; les rejetons, eux, sont une hémorragie automatique à la fin de
     * son PROPRE tour, et les faire annuler par une réaction hors tour
     * viderait la mécanique du jeton de son intérêt.
     *
     * @var list<string>
     */
    public const SOURCES_REACTIVES = ['attaque_monstre', 'sort_dread', 'tir_ami'];

    /**
     * Fenêtre de décision, en secondes. Courte volontairement : la partie
     * continue pendant ce temps, et une proposition qui traîne finirait par
     * annuler un coup que tout le monde a oublié.
     */
    public const FENETRE_SECONDES = 45;
}
