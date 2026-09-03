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
 * Référence complète et exemples : `reference/19_mots_cles_effets.md`.
 */
final class DureeEffet
{
    /** Jusqu'à la prochaine ATTAQUE du porteur (Courage, Potion de force). */
    public const PROCHAINE_ATTAQUE = 'prochaine_attaque';

    /** Jusqu'à la prochaine DÉFENSE du porteur (Potion de défense). */
    public const PROCHAINE_DEFENSE = 'prochaine_defense';

    /**
     * Jusqu'au PREMIER DÉGÂT réellement subi par le porteur — se défendre sans
     * rien encaisser ne le consomme pas (Peau de Pierre).
     *
     * Distinct de `PROCHAINE_DEFENSE` : là, c'est le jet qui dépense le buff ;
     * ici, c'est le sang versé. Texte officiel : « 1 dé de défense
     * supplémentaire **jusqu'au premier dégât subi** » (Witch Lord / Kellar's
     * Keep, reference/18_extensions.md §3).
     */
    public const PREMIER_DEGAT_SUBI = 'premier_degat_subi';

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
     * Jusqu'à ce qu'il ne reste plus aucun monstre ENGAGÉ — ni vaincu, ni
     * encore dormant derrière une porte close (décision de René, 2026-08-06).
     *
     * ⚠ Ce n'est PAS « plus aucun monstre dans le donjon » : `etat = actif`
     * signifie seulement « pas encore vaincu », et une quête garde des monstres
     * actifs mais `revele = 0` dans les salles jamais ouvertes. Voir
     * `ResolveurTour::combatTermine()`.
     */
    public const FIN_DU_COMBAT = 'fin_du_combat';

    /**
     * Tant qu'un monstre est EN LIGNE DE VUE du porteur — « As soon as there
     * are no monsters in the Barbarian's line of sight, this potion's effect
     * wears off » (Potion de rage guerrière et Potion de peau de givre, cartes
     * © 2022, doc 16 §2.1bis).
     *
     * ⚠ Ce n'est PAS `FIN_DU_COMBAT`, qui raisonne au niveau de la QUÊTE (plus
     * aucun monstre révélé et actif nulle part). Ici c'est la vue du porteur :
     * un ennemi vivant dans la salle d'à côté ne prolonge rien.
     *
     * ⚠ Évalué au DÉBUT DU TOUR du porteur (et à la génération de son menu),
     * pas en continu — c'est le seul crochet de ce genre dans le moteur, celui
     * qui sert déjà au Moine. Conséquence assumée : la peau de givre protège
     * encore pendant la phase de monstres qui suit la mort du dernier ennemi.
     *
     * Lecteur : `MoteurSorts::rythmerBuffsDeVue()`.
     */
    public const PLUS_DE_MONSTRE_EN_VUE = 'plus_de_monstre_en_vue';

    /** @return list<string> */
    public static function toutes(): array
    {
        return [
            self::PROCHAINE_ATTAQUE,
            self::PROCHAINE_DEFENSE,
            self::PREMIER_DEGAT_SUBI,
            self::CE_TOUR,
            self::PROCHAIN_TOUR,
            self::FIN_DU_COMBAT,
            self::PLUS_DE_MONSTRE_EN_VUE,
        ];
    }

    /**
     * La valeur est-elle un mot-clé connu ? Un ENTIER n'en est pas un : c'est
     * un décompte de tours, traité par un autre chemin.
     *
     * ⚠ Une LISTE en est un si chacun de ses termes en est un : depuis la carte
     * *Courage* (2026-09-02), un effet peut déclarer PLUSIEURS déclencheurs
     * d'expiration — « the next time that hero attacks » ET « broken the moment
     * a monster is no longer in the hero's line of sight ». Le premier des deux
     * qui survient retire le buff.
     */
    public static function estMotCle(mixed $duree): bool
    {
        if (is_array($duree)) {
            return $duree !== [] && array_reduce(
                $duree, fn (bool $ok, mixed $d) => $ok && self::estMotCle($d), true,
            );
        }

        return is_string($duree) && in_array($duree, self::toutes(), true);
    }

    /**
     * Ce déclencheur retire-t-il un buff portant cette `duree` ?
     *
     * Point de passage UNIQUE de la comparaison : elle se faisait par `===` sur
     * deux sites (`expirerBuffs`, `rythmerBuffsDeVue`), et une durée à plusieurs
     * termes y aurait été silencieusement ignorée — le buff n'expirant alors
     * JAMAIS, exactement ce que le projet reproche à un mot-clé sans
     * déclencheur.
     */
    public static function correspond(mixed $duree, string $declencheur): bool
    {
        return is_array($duree)
            ? in_array($declencheur, $duree, true)
            : $duree === $declencheur;
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
