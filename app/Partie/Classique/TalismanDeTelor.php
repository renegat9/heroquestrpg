<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Models\Personnage;

/**
 * LE TALISMAN DE TELOR (mode classique, lot E2 — *Prophecy of Telor*, G0052). Ce que le livret fait au porteur d'une quête
 * à l'autre, en deux états DURABLES du héros (migration `talisman_de_telor`) :
 *  - les SORTS DE DREAD APPRIS par note (q. 4 D : « You can now use the Dread spell Lightning Bolt » ; q. 6 C : « learns
 *    the Dread spell Firestorm »), lus par `SortsDreadPorteur::entrees()` à côté de ceux d'un objet porté, une fois par
 *    quête chacun comme eux ; oubliés dans l'arène de la q. 10 (« can no longer cast Dread spells ») ;
 *  - l'ESPRIT DU LIVRET : « Gain 1 Mind Point » (q. 4 D, q. 6 C, chaque sort lancé — p. 10) relève le TOTAL du héros, au-delà
 *    de son maximum de départ (René, 2026-11-26 : « dépasse le maximum ») et d'une quête à l'autre ; la q. 10 le défait
 *    (« their Mind Points revert to their total before starting this quest pack »). Le compteur `esprit_de_livret` garde
 *    l'écart, seul moyen de revenir au total d'origine sans stocker une « base » que les niveaux et les talismans bougent.
 *
 * Le fardeau et les deux sorts des q. 7 à 10 viennent de l'objet `Talisman du Savoir maudit`, échangé contre le talisman
 * ordinaire par l'effet `remplacer_objet` (et rendu ordinaire dans l'arène). Point de passage unique de ces deux états.
 */
final class TalismanDeTelor
{
    /** @return list<string> */
    public function sortsAppris(Personnage $heros): array
    {
        return array_values(array_map('strval', (array) ($heros->sorts_dread_appris ?? [])));
    }

    /** Le héros apprend ce sort de Dread. Rend `false` s'il le savait déjà. */
    public function apprendre(Personnage $heros, string $sort): bool
    {
        $appris = $this->sortsAppris($heros);
        if (in_array($sort, $appris, true)) {
            return false;
        }
        $heros->sorts_dread_appris = [...$appris, $sort];
        $heros->save();

        return true;
    }

    /** @return list<string> les sorts oubliés */
    public function oublier(Personnage $heros): array
    {
        $appris = $this->sortsAppris($heros);
        if ($appris !== []) {
            $heros->sorts_dread_appris = null;
            $heros->save();
        }

        return $appris;
    }

    /**
     * Le total d'Esprit du héros bouge de `$points` (un gain relève le maximum ET la valeur courante ; une perte les baisse,
     * maximum jamais sous 1, valeur jamais sous 0). Rend l'écart réellement appliqué au maximum.
     */
    public function esprit(Personnage $heros, int $points): int
    {
        $max = max(1, (int) $heros->pv_mind_max + $points);
        $ecart = $max - (int) $heros->pv_mind_max;
        $heros->pv_mind_max = $max;
        $heros->pv_mind = min($max, max(0, (int) $heros->pv_mind + $points));
        $heros->esprit_de_livret = (int) $heros->esprit_de_livret + $ecart;
        $heros->save();

        return $ecart;
    }

    /** Le total d'Esprit revient à celui d'avant le livret, et l'Esprit courant à ce total. Rend `false` sans écart. */
    public function retablir(Personnage $heros): bool
    {
        $ecart = (int) $heros->esprit_de_livret;
        if ($ecart === 0) {
            return false;
        }
        $heros->pv_mind_max = max(1, (int) $heros->pv_mind_max - $ecart);
        $heros->pv_mind = (int) $heros->pv_mind_max;
        $heros->esprit_de_livret = 0;
        $heros->save();

        return true;
    }
}
