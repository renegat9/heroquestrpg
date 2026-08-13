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
    /** Déclencheur : le porteur vient de subir des dégâts. Le premier écrit. */
    public const SUR_DEGATS_SUBIS = 'degats_subis';

    /**
     * Déclencheur : un monstre ERRANT vient de surgir d'une fouille — le seul
     * qui ne parte pas d'un coup encaissé, et il fallait bien qu'il en existe
     * un pour *Défi du chevalier*, « use this skill when a Wandering Monster is
     * revealed in the same room as you ».
     */
    public const SUR_ERRANT_REVELE = 'errant_revele';

    /** Action : les dégâts qui viennent d'être subis sont rendus. */
    public const ANNULE_DEGATS = 'annule_degats';

    /**
     * Action : au lieu d'annuler, on RIPOSTE — *Représailles* du Berserker,
     * « you may use this skill when you take damage from an adjacent monster.
     * Immediately make an attack against that monster. »
     *
     * ⚠ Le coup subi n'est PAS annulé : le Berserker encaisse et rend. C'est
     * tout l'esprit de la classe, dont deux capacités sur trois exigent d'être
     * blessé.
     */
    public const RIPOSTE = 'riposte';

    /**
     * Action : les PV ne tombent pas sous 1 — *Inébranlable* du Chevalier,
     * « use this skill when your Body Points are reduced to 0 to instead
     * reduce them to 1 ».
     *
     * ⚠ Ne se propose QUE si le coup est mortel : proposer un plancher à un
     * héros qui garde des PV gaspillerait une capacité « once per quest ».
     */
    public const PLANCHER_PV = 'plancher_pv';

    /**
     * Action : annuler les dégâts d'un héros VOISIN — *Parade au bouclier* du
     * Chevalier, « when a hero next to you takes damage to cancel that damage ».
     *
     * ⚠ C'est la seule réaction proposée à quelqu'un d'AUTRE que la victime, et
     * elle a coûté une extension : la proposition porte donc un protecteur en
     * plus du blessé.
     */
    public const ANNULE_DEGATS_VOISIN = 'annule_degats_voisin';

    /**
     * Action : détourner sur soi le monstre errant qui vient de surgir — *Défi
     * du chevalier*, « the Wandering Monster is placed next to you and
     * immediately attacks you ».
     *
     * ⚠ La seule réaction qui AGGRAVE volontairement la situation de celui qui
     * l'active : il prend le coup à la place d'un compagnon plus fragile.
     */
    public const DEFI_ERRANT = 'defi_errant';

    /**
     * Action : le héros vient de TOMBER et dépense une potion ou un sort de
     * soin pour rester debout (demande de René, 2026-08-13).
     *
     * ⚠ La seule réaction qui n'annule, ne rende ni ne détourne rien : elle
     * PAIE. C'est aussi la seule qui vaille quelle que soit la cause de la
     * chute — `SOURCES_REACTIVES` dit quel coup peut être défait, pas qui a le
     * droit de se soigner. Mourir avec une potion au sac est la frustration la
     * plus bête du jeu ; au plateau, on la boit.
     */
    public const SOIN_URGENCE = 'soin_urgence';

    /** @return list<string> */
    public static function actionsToutes(): array
    {
        return [self::ANNULE_DEGATS, self::RIPOSTE, self::PLANCHER_PV,
            self::ANNULE_DEGATS_VOISIN, self::DEFI_ERRANT, self::SOIN_URGENCE];
    }

    /**
     * Actions dont l'acceptation peut REMETTRE UN HÉROS DEBOUT — et qui, tant
     * qu'elles attendent une réponse, SUSPENDENT le verdict de TPK.
     *
     * ⚠ C'est la liste qui empêche la partie la plus dramatique du jeu de se
     * jouer sans son joueur : le coup qui achève le dernier héros debout
     * concluait le round en `echouee` avant que le téléphone ait sonné, et
     * l'offre arrivait sur une quête déjà perdue. La riposte du Berserker et le
     * défi du Chevalier n'y sont pas : ils frappent, ils ne relèvent personne.
     *
     * @var list<string>
     */
    public const ACTIONS_RELEVANTES = [
        self::ANNULE_DEGATS,
        self::PLANCHER_PV,
        self::ANNULE_DEGATS_VOISIN,
        self::SOIN_URGENCE,
    ];

    /** @return list<string> */
    public static function declencheurs(): array
    {
        return [self::SUR_DEGATS_SUBIS, self::SUR_ERRANT_REVELE];
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
