<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Vocabulaire des DURÉES d'effet (buffs de sorts et de potions).
 *
 * La clé `duree` d'un sort ou d'un objet dit QUAND son buff prend fin. Elle
 * existait dans les données depuis le début — `prochaine_attaque`, `ce_tour`,
 * `fin_du_combat`… — mais **aucun lecteur ne l'appliquait** : les seuls effets
 * qui expiraient le faisaient par des appels codés en dur sur leur CLÉ D'EFFET
 * (`consommerBuffs($p, 'bonus_des_attaque')`), pas sur leur durée. Deux
 * conséquences mesurées au test du 2026-08-05 :
 *
 *  - la **Potion de défense** (+2 dés, 150 or) et **Peau de Pierre** ne
 *    s'arrêtaient JAMAIS — aucun `consommerBuffs('bonus_des_defense')`
 *    n'existait, et une durée 0 n'est pas décrémentée : bonus permanent ;
 *  - la **Potion de rage**, annoncée « un combat », disparaissait dès la
 *    première attaque, comme Courage.
 *
 * Ces constantes sont désormais l'autorité : `MoteurSorts::expirerBuffs()` les
 * lit sur la source du buff. Confondre le déclencheur et l'effet interdisait
 * d'exprimer « +2 en défense jusqu'à la prochaine défense » ; les deux sont
 * maintenant séparés.
 *
 * ⚠ Une `duree` ENTIÈRE reste possible et signifie un décompte en TOURS,
 * géré par `MoteurSorts::decrementerDurees()` (Empoisonné 3 tours…). Les
 * mots-clés ci-dessous couvrent ce qu'un compteur de tours ne sait pas dire.
 *
 * Référence complète et exemples : `reference/19_durees_effets.md`.
 */
final class DureeEffet
{
    /** Jusqu'à la prochaine ATTAQUE du porteur (Courage, Potion de force). */
    public const PROCHAINE_ATTAQUE = 'prochaine_attaque';

    /** Jusqu'à la prochaine DÉFENSE du porteur (Potion de défense). */
    public const PROCHAINE_DEFENSE = 'prochaine_defense';

    /**
     * Jusqu'à la fin du tour du porteur — il décide lui-même quand son tour
     * s'arrête (« Terminer le tour »). N'atteint donc PAS la phase des monstres.
     */
    public const CE_TOUR = 'ce_tour';

    /**
     * Jusqu'au début du prochain tour du porteur : l'effet SURVIT à la phase
     * des monstres, ce qui est tout l'intérêt d'une protection (Voile de Brume).
     * C'est la seule différence avec `CE_TOUR`, et elle est mécanique.
     */
    public const PROCHAIN_TOUR = 'prochain_tour';

    /**
     * Jusqu'à ce qu'il ne reste plus AUCUN monstre actif dans la quête
     * (décision de René, 2026-08-05). Le moteur ne connaît pas d'« engagement »
     * plus fin : `donjon_nettoye` est le seul événement de fin de combat.
     */
    public const FIN_DU_COMBAT = 'fin_du_combat';

    /** @return list<string> */
    public static function toutes(): array
    {
        return [
            self::PROCHAINE_ATTAQUE,
            self::PROCHAINE_DEFENSE,
            self::CE_TOUR,
            self::PROCHAIN_TOUR,
            self::FIN_DU_COMBAT,
        ];
    }

    /**
     * La valeur est-elle un mot-clé connu ? Un ENTIER n'en est pas un : c'est
     * un décompte de tours, traité par un autre chemin.
     */
    public static function estMotCle(mixed $duree): bool
    {
        return is_string($duree) && in_array($duree, self::toutes(), true);
    }

    /**
     * Décompte en tours porté par la valeur, ou 0 si c'est un mot-clé (ou rien).
     * 0 = « pas de compteur » : l'expiration passe par un déclencheur.
     */
    public static function tours(mixed $duree): int
    {
        return is_int($duree) || (is_string($duree) && ctype_digit($duree)) ? max(0, (int) $duree) : 0;
    }
}
