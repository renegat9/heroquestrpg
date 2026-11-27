<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Monstre;
use App\Models\Personnage;
use App\Partie\Equipement;

/**
 * LA MÉTAMORPHOSE (mode classique, lot E2 — *Prophecy of Telor* q. 5, « Melar's Chambers ») : « all other heroes, regardless
 * of where they are, have been turned into Orcs! The bearer is unaffected. Each affected hero […] now roll Attack and Defend
 * dice as Orcs. They may still use any armor, weapons, or equipment they may have. Each affected hero has 2 Body Points,
 * and may not use any potions, magic, or other items to raise their Body Points above 2. They may not cast spells. » Puis
 * la note D : « all heroes are returned to their former selves and restored to their full Body Points ».
 *
 * L'état vit en colonne (`personnages.metamorphose {en, pv_body_max, plafond}`), point de passage unique de ses lecteurs :
 *  - les DÉS DE BASE sont ceux du monstre (`Equipement::desDeBase()`), les armes et armures s'y appliquent comme à la classe ;
 *  - le MAXIMUM de Corps devient celui du livret : aucun soin ne le dépasse, quelle que soit sa source ;
 *  - aucun SORT (`MoteurSorts::sortsInterdits()`, menu et résolution) ;
 *  - la fiche le montre parmi ses conditions (`EtatGroupe`).
 * Le livret rend la transformation « permanent unless you can find the blue tome » ; la sortie de la q. 5 passe par la salle
 * du tome : on la lève aussi au départ de la quête suivante (`DemarreurQueteConstruite`, décision écrite).
 */
final class Metamorphose
{
    /** @return array{en: string, pv_body_max: int, plafond: int}|null */
    public static function de(Personnage $heros): ?array
    {
        $m = $heros->metamorphose;

        return is_array($m) && isset($m['en']) ? $m : null;
    }

    /** Le héros devient la figure `$monstre`, à `$pvBody` points de Corps. Rend `false` s'il l'était déjà. */
    public function metamorphoser(Personnage $heros, Monstre $monstre, int $pvBody): bool
    {
        if (self::de($heros) !== null) {
            return false;
        }
        $heros->update([
            'metamorphose' => ['en' => (string) $monstre->nom_base, 'pv_body_max' => (int) $heros->pv_body_max, 'plafond' => max(1, $pvBody)],
            'pv_body_max' => max(1, $pvBody),
            'pv_body' => max(1, $pvBody),
        ]);
        app(Equipement::class)->recalculerCombat($heros->fresh());

        return true;
    }

    /**
     * Le héros reprend sa forme, à son maximum de Corps (celui d'avant, plus ce que l'équipement a changé depuis) et plein.
     * Rend `false` s'il n'était pas changé.
     */
    public function rendre(Personnage $heros): bool
    {
        $m = self::de($heros);
        if ($m === null) {
            return false;
        }
        $max = max(1, (int) $m['pv_body_max'] + ((int) $heros->pv_body_max - (int) $m['plafond']));
        $heros->update(['metamorphose' => null, 'pv_body_max' => $max, 'pv_body' => $max]);
        app(Equipement::class)->recalculerCombat($heros->fresh());

        return true;
    }
}
