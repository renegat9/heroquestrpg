<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;

/**
 * Les JALONS de l'arc — quelles quêtes de la campagne finissent sur un
 * sous-boss, laquelle sur le boss final. Point de passage UNIQUE (René,
 * 2026-10-02).
 *
 * La règle vivait en trois endroits qui devaient se répondre sans se lire :
 * la cadence demandée à l'IA (`SqueletteCampagne::nbSousBossAttendu()`), le
 * repli de `DemarreurQuete::typeJalon()` et sa copie dans
 * `CadenceNiveaux::positionsJalons()`, qu'un commentaire suppliait de « répondre
 * pareil ». Et le repli était FAUX : sans plan de campagne — c'est-à-dire sans
 * clé d'API, une manière de jouer à part entière (CLAUDE.md) — il ne plaçait
 * que le boss final. Une campagne de vingt quêtes ne croisait AUCUN sous-boss,
 * là où l'IA est tenue d'en placer quatre.
 *
 * Le repli applique désormais la MÊME cadence que celle exigée de l'IA, avec
 * le même espacement régulier avant le final.
 *
 * ⚠ C'est un PLACEMENT, pas un tirage : il ne dépend que du plan et du nombre
 * de quêtes, tous deux figés à la création du groupe — recommencer une quête ou
 * recharger un instantané ne déplace jamais un sous-boss.
 */
final class JalonsCampagne
{
    public const SOUS_BOSS = 'sous_boss';

    public const BOSS_FINAL = 'boss_final';

    public const NORMALE = 'normale';

    /**
     * Combien de sous-boss pour un arc de cette longueur (doc 06 §4, table à
     * ajuster en playtest). C'est la cadence demandée à l'IA ET celle du repli.
     */
    public static function nbSousBossAttendu(int $nbQuetes): int
    {
        return match (true) {
            $nbQuetes <= 1 => 0,
            $nbQuetes <= 5 => 1,
            $nbQuetes <= 10 => 2,
            $nbQuetes <= 15 => 3,
            default => 4,
        };
    }

    /**
     * Positions des sous-boss du REPLI : espacées régulièrement avant le final
     * — la consigne donnée à l'IA (« espacés régulièrement AVANT le final »).
     * 5 quêtes → [3] ; 10 → [3, 7] ; 15 → [4, 8, 11] ; 20 → [4, 8, 12, 16].
     *
     * @return list<int>
     */
    public static function positionsSousBossParDefaut(int $nbQuetes): array
    {
        $nombre = self::nbSousBossAttendu($nbQuetes);
        $positions = [];

        for ($i = 1; $i <= $nombre; $i++) {
            $position = (int) round($i * $nbQuetes / ($nombre + 1));

            // Jamais la dernière quête (celle du boss), jamais avant la première.
            if ($position >= 1 && $position < $nbQuetes) {
                $positions[] = $position;
            }
        }

        return array_values(array_unique($positions));
    }

    /** Type du jalon à cette position de l'arc : `sous_boss`, `boss_final` ou `normale`. */
    public function type(Groupe $groupe, int $position): string
    {
        $total = (int) $groupe->nb_quetes_total;
        $jalons = (array) data_get($groupe->plan_campagne, 'jalons', []);

        if ($jalons !== []) {
            foreach ($jalons as $jalon) {
                if ((int) ($jalon['position'] ?? 0) === $position) {
                    return in_array($jalon['type'] ?? null, [self::SOUS_BOSS, self::BOSS_FINAL], true)
                        ? $jalon['type']
                        : self::NORMALE;
                }
            }

            return $position >= $total ? self::BOSS_FINAL : self::NORMALE;
        }

        // REPLI — aucun plan (pas de clé d'API, ou squelette indisponible).
        if ($position >= $total) {
            return self::BOSS_FINAL;
        }

        return in_array($position, self::positionsSousBossParDefaut($total), true)
            ? self::SOUS_BOSS
            : self::NORMALE;
    }

    /**
     * Toutes les positions de l'arc qui portent un jalon (sous-boss ou boss).
     *
     * @return list<int>
     */
    public function positions(Groupe $groupe): array
    {
        $total = max(0, (int) $groupe->nb_quetes_total);
        $positions = [];

        for ($position = 1; $position <= $total; $position++) {
            if ($this->type($groupe, $position) !== self::NORMALE) {
                $positions[] = $position;
            }
        }

        return $positions;
    }
}
