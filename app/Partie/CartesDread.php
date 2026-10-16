<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\InstanceMonstre;

/**
 * LA RÉSERVE DE CARTES d'un Sorcier du Dread — *Against the Ogre Horde* (F9528
 * p. 10-11, 31, 35, 37).
 *
 * Le livret ne compte pas les lancers d'un Sorcier comme le reste du catalogue
 * (un budget global par palier, `usages_dread`) ni comme les Sorciers de Morcar
 * (chaque sort une fois, `sorts_uniques`) : il lui donne **une réserve de cartes
 * PAR SORT**, écrite dans les notes de quête.
 *
 *  - Nexrael, p. 31 : « Mind Burst and uses a reserve of 4 cards » ;
 *  - Festral, p. 35 : « Mind Burst, Mind Lock, and Dominate, and uses 3 cards for each spell » ;
 *  - Xenloth, p. 37 : « the spells Mind Lock and Mind Burst. He has 5 cards for each spell ».
 *
 * Une carte quitte la main de Zargon de DEUX façons, et c'est la donnée du SORT
 * (`effet.carte`, {@see \App\Engine\MotsClesSortDread::CARTES}) qui dit laquelle :
 *  - `defausse` — *Mind Burst* : « Each time they cast Mind Burst, they discard one of
 *    their spell cards » (p. 11). Perdue pour la quête ;
 *  - `prete` — *Mind Lock* (« That hero takes a Mind Lock card for each turn they are
 *    affected, returning one card to Zargon at the end of each of their turns spent
 *    frozen », p. 10) et *Dominate* (« Zargon hands the affected hero a Dominate card »,
 *    jusqu'à la fin du tour du Sorcier). La carte est PRÊTÉE : elle revient en main —
 *    d'où {@see self::rendre()} — et c'est ce prêt qui borne combien de héros un Sorcier
 *    peut tenir gelés AU MÊME MOMENT.
 *
 * L'état est durable (`habillage.dread_etat.cartes`, la colonne JSON qui voyage déjà dans
 * le snapshot — jamais le cache) : une reprise ne rend pas au Sorcier les cartes qu'il avait
 * dépensées, et ne lui reprend pas celles qu'un héros gelé tient encore.
 *
 * La RÉSERVE DÉCLARÉE est la capacité `reserve_cartes` du monstre (`['Explosion mentale' => 4]`) :
 * lue ICI, et nulle part ailleurs — `SortsOgreHordeTest` confronte les deux sens (chaque sort
 * du répertoire d'un tel Sorcier a une réserve, chaque réserve nomme un sort de son répertoire).
 */
final class CartesDread
{
    /** La capacité de monstre qui porte la réserve : `['<nom du sort>' => <nombre de cartes>]`. */
    public const CAPACITE = 'reserve_cartes';

    /** Clé de `habillage.dread_etat` où vit le stock courant (cartes encore EN MAIN). */
    private const CLE_ETAT = 'cartes';

    /**
     * La réserve déclarée au catalogue pour ce monstre — `[]` s'il n'en a pas.
     *
     * @return array<string, int>
     */
    public static function reserveDeclaree(InstanceMonstre $instance): array
    {
        $reserve = $instance->capaciteParametree(self::CAPACITE);

        if (! is_array($reserve)) {
            return [];
        }

        return array_map('intval', $reserve);
    }

    /** Ce Sorcier compte-t-il ses lancers en CARTES (et non en budget par palier) ? */
    public static function aUneReserve(InstanceMonstre $instance): bool
    {
        return self::reserveDeclaree($instance) !== [];
    }

    /**
     * Pose la réserve PLEINE — à chaque placement, jamais à un changement de phase.
     * Sans effet pour un monstre qui n'en déclare pas.
     */
    public static function armer(InstanceMonstre $instance): void
    {
        $reserve = self::reserveDeclaree($instance);

        if ($reserve !== []) {
            $instance->poserEtatDread(self::CLE_ETAT, $reserve);
        }
    }

    /**
     * Le stock courant, sort par sort. Un instantané antérieur à la réserve (ou une
     * instance jamais armée) retombe sur la réserve DÉCLARÉE : c'est ce que le Sorcier
     * tiendrait s'il était né aujourd'hui.
     *
     * @return array<string, int>
     */
    public static function stock(InstanceMonstre $instance): array
    {
        $courant = $instance->etatDread(self::CLE_ETAT);

        return is_array($courant)
            ? array_map('intval', $courant)
            : self::reserveDeclaree($instance);
    }

    /** Cartes encore en main pour ce sort (0 s'il n'est pas dans la réserve). */
    public static function restantes(InstanceMonstre $instance, string $sort): int
    {
        return max(0, (int) (self::stock($instance)[$sort] ?? 0));
    }

    /** Toutes les cartes en main, tous sorts confondus : « peut-il encore lancer quoi que ce soit ? » */
    public static function total(InstanceMonstre $instance): int
    {
        return array_sum(array_map(fn (int $n) => max(0, $n), self::stock($instance)));
    }

    /** Une carte quitte la main (défaussée, ou prêtée à un héros). Jamais sous zéro. */
    public static function depenser(InstanceMonstre $instance, string $sort): void
    {
        $stock = self::stock($instance);
        $stock[$sort] = max(0, (int) ($stock[$sort] ?? 0) - 1);
        $instance->poserEtatDread(self::CLE_ETAT, $stock);
    }

    /**
     * Des cartes PRÊTÉES reviennent en main. Jamais au-delà de la réserve déclarée : une carte
     * rendue qui n'a jamais été prêtée (état périmé) ne fabrique pas une carte de plus.
     */
    public static function rendre(InstanceMonstre $instance, string $sort, int $nombre = 1): void
    {
        $plafond = (int) (self::reserveDeclaree($instance)[$sort] ?? 0);
        $stock = self::stock($instance);
        $stock[$sort] = min($plafond, (int) ($stock[$sort] ?? 0) + max(0, $nombre));
        $instance->poserEtatDread(self::CLE_ETAT, $stock);
    }
}
