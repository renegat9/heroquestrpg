<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use Illuminate\Support\Collection;

/**
 * SAUT DE TOUR DES MONSTRES — la mécanique `saute_tour_monstre` (lot A, 2026-10-23).
 *
 * Hurlement (barbare, Terreur) fait perdre son prochain tour à TOUS les monstres à ton contact ; Mot de pouvoir
 * (barde, Verbe) à UN monstre que tu vois. Une seule mécanique, deux portées (`effet.cible`), décidé par René :
 * « même mécanique que Hurlement, paramètre de cible ».
 *
 * ⚠ Un seul point de passage pour QUI est touchable. Le menu (`MenuMoteur`) l'offre, le résolveur
 * (`ResolveurTour::resoudreSauteTourMonstre()`) le revalide, et les deux lisent les mêmes listes ICI : « le menu
 * n'offre jamais ce que le résolveur refuse ». Deux copies de « qui est au contact » auraient fini par diverger.
 *
 * La pose est la condition `saute_tour` de Tempête (`MoteurSorts::poserConditionMonstre()`), SANS jet : la carte de
 * Tempête n'en laisse aucun, et Hurlement n'en a pas non plus. Elle est consommée à l'activation du monstre
 * (`ResolveurTour::jouerMonstre()`), donc « son prochain tour », exactement.
 *
 * Exclusions, toutes communes à la frappe balayée et aux sorts :
 *  - `conditionsImmunisees()` : un monstre immunisé à `saute_tour` n'est jamais compté ;
 *  - le voile d'ombre : un monstre sous le voile n'est ni au contact ni vu.
 * Pas `immuniseAuxSorts()` : ni Hurlement ni Mot de pouvoir ne sont des sorts. C'est une lecture, pas une
 * certitude — si René veut que « Mot de pouvoir » soit bloqué par l'immunité aux sorts, c'est une ligne ici.
 */
final class SauteTourMonstre
{
    public function __construct(
        private readonly MoteurSorts $sorts,
        private readonly MoteurOmbre $ombre,
        private readonly FaveursHopekins $faveurs,
    ) {}

    /**
     * Les monstres à ton CONTACT — orthogonal, comme tout le contact du projet (`Grille::adjacenteAEmprise()`) ;
     * une grande figurine compte dès qu'une de ses cases le touche.
     *
     * @return Collection<int, InstanceMonstre>
     */
    public function auContact(Quete $quete, EtatPersonnageQuete $etat): Collection
    {
        $grille = FabriqueGrille::pour($quete);
        $hx = (int) $etat->position_x;
        $hy = (int) $etat->position_y;

        return $this->candidats($quete)
            ->filter(function (InstanceMonstre $instance) use ($grille, $hx, $hy): bool {
                $emprise = $instance->monstre->emprise();

                return $grille->adjacenteAEmprise(
                    (int) $instance->position_x, (int) $instance->position_y,
                    $emprise['l'], $emprise['h'], $hx, $hy,
                );
            })
            ->values();
    }

    /**
     * Les monstres qu'un héros VOIT — la même ligne de vue que les sorts (`MoteurSorts::options()`), figures
     * interposées comprises, et la Faveur « Deadeye » (`figuresBloquentPour()`) qui les retire.
     *
     * @return Collection<int, InstanceMonstre>
     */
    public function vus(Quete $quete, EtatPersonnageQuete $etat, Personnage $personnage): Collection
    {
        $grille = FabriqueGrille::pour($quete);
        $figuresBloquent = $this->faveurs->figuresBloquentPour($personnage);
        $cx = (int) $etat->position_x;
        $cy = (int) $etat->position_y;

        return $this->candidats($quete)
            ->filter(function (InstanceMonstre $instance) use ($grille, $cx, $cy, $figuresBloquent): bool {
                $emprise = $instance->monstre->emprise();

                return $grille->ligneDeVueEmprise(
                    $cx, $cy, (int) $instance->position_x, (int) $instance->position_y,
                    $emprise['l'], $emprise['h'], figuresBloquent: $figuresBloquent,
                );
            })
            ->values();
    }

    /** Le monstre est-il immunisé à la condition que pose ce saut ? */
    public function immunise(InstanceMonstre $instance): bool
    {
        return in_array(MoteurSorts::MONSTRE_SAUTE_TOUR, (array) $instance->monstre->conditionsImmunisees(), true);
    }

    /** Pose le saut : la condition `saute_tour`, sans durée — consommée au tour même du monstre. */
    public function poser(InstanceMonstre $instance): void
    {
        $this->sorts->poserConditionMonstre($instance, MoteurSorts::MONSTRE_SAUTE_TOUR);
    }

    /**
     * Les monstres qu'un saut PEUT atteindre : actifs, révélés, hors voile, non immunisés, posés sur une case.
     *
     * @return Collection<int, InstanceMonstre>
     */
    private function candidats(Quete $quete): Collection
    {
        return $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true)
            ->with('monstre')
            ->orderBy('id')
            ->get()
            ->reject(fn (InstanceMonstre $instance): bool => $instance->position_x === null
                || $this->immunise($instance)
                || $this->ombre->contientMonstre($quete, $instance))
            ->values();
    }
}
