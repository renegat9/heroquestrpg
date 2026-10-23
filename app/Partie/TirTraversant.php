<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use Illuminate\Support\Collection;

/**
 * LA LIGNE DE LA FLÈCHE TRAVERSANTE — lot A (2026-10-23), elfe, Œil.
 *
 * Un tir de l'arme à distance en main, dans UNE direction, frappe CHAQUE ennemi de la ligne (géométrie de `Rayon` :
 * huit directions, arrêt au mur ou à la porte close, voile d'ombre exclu). Chacun se défend séparément, aux dés de
 * l'arme (`ResolveurTour::frapper()`, tir à distance).
 *
 * ⚠ Un seul point de passage pour QUELS ennemis la flèche touche. Le menu (`MenuMoteur`) propose les directions
 * qui portent une cible légale, le résolveur (`ResolveurTour::resoudreTirTraversant()`) frappe exactement ces
 * cibles : les deux lisent `cibles()` ici. « Le menu n'offre jamais ce que le résolveur refuse. »
 *
 * ⚠ Décision de lot A, à relire : une arme qui REFUSE LE CONTACT (`Objet::interditAuContact()`, arc, arbalète) ne
 * tire pas sur un ennemi ADJACENT au héros. Un tir au contact est interdit à l'arme, et la flèche ne le fait pas
 * d'elle-même ; les ennemis plus loin sur la même ligne restent visés. Une direction dont le seul ennemi est adjacent
 * n'est donc pas offerte.
 */
final class TirTraversant
{
    /**
     * Les ennemis que la flèche frappe dans cette direction — du plus proche au plus loin.
     *
     * @return Collection<int, InstanceMonstre>
     */
    public function cibles(Quete $quete, EtatPersonnageQuete $etat, string $direction, ?Inventaire $arme): Collection
    {
        if (! isset(Rayon::DIRECTIONS[$direction]) || $etat->position_x === null || $etat->position_y === null) {
            return collect();
        }

        $hx = (int) $etat->position_x;
        $hy = (int) $etat->position_y;
        $refuseLeContact = $arme?->objet?->interditAuContact() ?? false;
        $grille = FabriqueGrille::pour($quete);

        $actifs = $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true)
            ->with('monstre')
            ->orderBy('id')
            ->get();

        $ligne = collect();

        foreach (Rayon::cases($grille, $hx, $hy, $direction) as $case) {
            foreach ($actifs as $instance) {
                if ($ligne->contains(fn (InstanceMonstre $deja): bool => $deja->id === $instance->id)) {
                    continue;
                }

                $emprise = $instance->monstre->emprise();
                $occupe = $instance->position_x !== null
                    && $case['x'] >= (int) $instance->position_x && $case['x'] < (int) $instance->position_x + $emprise['l']
                    && $case['y'] >= (int) $instance->position_y && $case['y'] < (int) $instance->position_y + $emprise['h'];

                if (! $occupe) {
                    continue;
                }

                // L'arme qui refuse le contact ne vise pas l'ennemi qui touche le héros (voir la décision ci-dessus).
                if ($refuseLeContact && $grille->adjacenteAEmprise(
                    (int) $instance->position_x, (int) $instance->position_y, $emprise['l'], $emprise['h'], $hx, $hy,
                )) {
                    continue;
                }

                $ligne->push($instance);
            }
        }

        return $ligne->values();
    }

    /**
     * Les directions qui portent AU MOINS une cible légale, avec leur compte — ce que le menu offre, une option par
     * direction. Une direction vide n'est jamais offerte.
     *
     * @return array<string, int>
     */
    public function directions(Quete $quete, EtatPersonnageQuete $etat, ?Inventaire $arme): array
    {
        $comptes = [];

        foreach (array_keys(Rayon::DIRECTIONS) as $direction) {
            $nombre = $this->cibles($quete, $etat, $direction, $arme)->count();

            if ($nombre > 0) {
                $comptes[$direction] = $nombre;
            }
        }

        return $comptes;
    }
}
