<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Personnage;
use App\Models\Quete;

/**
 * La CHEMINÉE — *Healing Hearth* (First Light, livret de quêtes p. 7) :
 * « When a hero would draw a treasure card in a room containing a fireplace,
 * they may instead heal 1 Body point. »
 *
 * Même patron que la Mare (`MoteurTerrain::mareDeLaSalle()`) : la règle se lit
 * en UN point, que le menu (qui OFFRE le choix, `MenuMoteur`) et le résolveur
 * (qui rend le point, `ResolveurTour::resoudreSoinCheminee()`) relisent. Une
 * règle, un point de passage : deux lectures divergeraient au premier changement.
 *
 * DEUX conditions, lues ici et nulle part ailleurs :
 *  - la SALLE porte une cheminée (un meuble `Cheminée` posé par
 *    `AssembleurCarte::placerMobilier()`, qui renseigne toujours son `salle`) ;
 *  - le GROUPE joue la boîte First Light (`BestiaireGroupe::contient()`). Le MEUBLE,
 *    lui, est du jeu de base (RB p. 4) : c'est la RÈGLE qui est propre à First Light.
 *
 * ⚠ La règle vise la SALLE (« in a room containing a fireplace »), pas la case :
 * on ne fouille pas la cheminée, on fouille la salle qui la contient. Comme la
 * fouille de salle, le soin dépense la fouille du héros dans cette salle.
 *
 * ⚠ Le héros doit avoir perdu un point : « may instead heal 1 Body point » ne
 * dit rien d'un héros au maximum, et l'offrir quand même ferait choisir une
 * fouille gratuite pour rien. Le menu ne propose pas ce que le résolveur refuse.
 */
final class MoteurCheminee
{
    /** Nom du meuble au catalogue (`mobiliers.nom`). */
    public const NOM = 'Cheminée';

    /** Boîte dont le groupe doit jouer la règle (`mobiliers.boite`). */
    public const BOITE = 'first_light';

    /** PV de Body rendus (« heal 1 Body point »). */
    public const SOIN = 1;

    public function __construct(private readonly MoteurMobilier $mobilier) {}

    /**
     * La salle d'index `$salle` a-t-elle une cheminée pour CE groupe ?
     */
    public function reconforte(Quete $quete, int $salle): bool
    {
        if ($quete->carte === null || $quete->groupe === null
            || ! BestiaireGroupe::duGroupe($quete->groupe)->contient(self::BOITE)) {
            return false;
        }

        return $this->mobilier->salleContientType($quete->carte, $salle, self::NOM);
    }

    /**
     * Le soin est-il OFFERT à ce héros, dans cette salle ? Une cheminée, un
     * groupe First Light, et un héros qui a perdu au moins un point de Body.
     */
    public function soinOffert(Quete $quete, Personnage $personnage, int $salle): bool
    {
        return $this->reconforte($quete, $salle)
            && (int) $personnage->pv_body < (int) $personnage->pv_body_max;
    }
}
