<?php

declare(strict_types=1);

namespace App\Partie\Marche;

use App\Models\ClasseHeros;
use App\Models\Inventaire;
use App\Models\Personnage;

/**
 * Capacité du sac à dos d'un héros (doc 01 « Sac à dos ») :
 * PV de Body MAX ÷ 2 (arrondi INFÉRIEUR — Barbare 4, Nain 3+1, Elfe 3,
 * Magicien 2) + bonus_sac de la classe + nœuds d'arbre acquis à mécanique
 * `bonus_capacite_sac` (Solides épaules — pas de colonne sur `personnages`,
 * la capacité est toujours DÉRIVÉE).
 *
 * Seuls les objets rangés au SAC comptent (emplacement « sac ») : les pièces
 * équipées ont leurs emplacements propres et les consommables / objets de
 * quête sont illimités (doc 01 §7).
 */
final class CapaciteSac
{
    public static function pour(Personnage $personnage): int
    {
        $bonus = (int) (ClasseHeros::query()
            ->where('nom', $personnage->classe)
            ->value('bonus_sac') ?? 0);

        $bonusCompetences = (int) $personnage->competences()
            ->where('type', 'passif')
            ->get()
            ->sum(fn ($c) => ($c->effet['mecanique'] ?? null) === 'bonus_capacite_sac'
                ? (int) ($c->effet['valeur'] ?? 0)
                : 0);

        return intdiv((int) $personnage->pv_body_max, 2) + $bonus + $bonusCompetences;
    }

    /** Occupation actuelle du sac (somme des quantités en emplacement sac). */
    public static function occupation(Personnage $personnage): int
    {
        return (int) Inventaire::query()
            ->where('personnage_id', $personnage->id)
            ->where('emplacement', 'sac')
            ->sum('quantite');
    }
}
