<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Groupe;
use App\Models\Personnage;

/**
 * Score de puissance du groupe (doc 06 §2) : calculé par le MOTEUR à chaque
 * démarrage de quête, il pilote le budget de rencontres — l'IA ne décide
 * jamais de la difficulté (P3).
 *
 * Formule de DÉPART, simple et à régler en playtest (question ouverte 06 §10) :
 *
 *   score = Σ par héros actif [ 2 × niveau + dés d'attaque + dés de défense
 *                               + 1 par objet ÉQUIPÉ (hors sac/consommables)
 *                               + 1 par point de talent DÉPENSÉ ]
 *
 * Le dernier terme (2026-10-22, pouvoir final) compte la somme des coûts des nœuds
 * ACHETÉS (`Personnage::pointsDepenses()`), jamais les capacités de carte. Au niveau 1
 * il vaut 0 ; au niveau 6 avec 5 points dépensés, +5 par héros.
 *
 * avec un plancher (groupe minimal d'un héros niveau 1 mal équipé).
 * Couvre les trois entrées du doc 06 §2 : nombre de héros (somme), niveaux,
 * stats (dés, qui intègrent déjà la progression) et équipement.
 */
final class ScorePuissance
{
    public const PLANCHER = 4;

    /**
     * Emplacements d'inventaire comptés comme équipement porté — la liste de
     * `Equipement::SLOTS`, pas une copie : un slot ajouté ici et oublié là
     * (le `casque` du 2026-08-08) sous-évalue le groupe et lui envoie des
     * rencontres trop faciles.
     */
    private const EMPLACEMENTS_EQUIPES = Equipement::SLOTS;

    public function calculer(Groupe $groupe): int
    {
        $score = 0;

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->with('inventaire')
            ->get();

        foreach ($heros as $personnage) {
            $score += $this->puissanceHeros($personnage);
        }

        return max($score, self::PLANCHER);
    }

    private function puissanceHeros(Personnage $personnage): int
    {
        $equipement = $personnage->inventaire
            ->whereIn('emplacement', self::EMPLACEMENTS_EQUIPES)
            ->count();

        return 2 * (int) $personnage->niveau
            + (int) $personnage->des_attaque
            + (int) $personnage->des_defense
            + $equipement
            + $personnage->pointsDepenses();
    }
}
