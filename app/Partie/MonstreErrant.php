<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Monstre;
use App\Models\Quete;

/**
 * LE monstre errant d'une quête — point de passage unique (René, 2026-11-08 : « ajuste aussi les monstres errants
 * pour des monstres de plus en plus forts »).
 *
 * Jusqu'ici l'errant était le monstre le MOINS cher du bestiaire, à toutes les quêtes : un Gobelin à la quête 1 comme
 * à la quête 6. Le livret de base en nomme un par quête, et il monte au fil de la campagne (F2847, « Wandering
 * Monster in this Quest ») : Orc aux quêtes 1 à 3, puis surtout l'Abomination à partir de la 4, le Dread Warrior à
 * la 13. Nos quêtes ne sont pas écrites : on suit la même pente sur le `cout` du bestiaire. À la quête n de l'arc,
 * l'errant est le monstre de base le plus cher dont le `cout` ne dépasse pas
 * `cout_depart + (n − 1) × cout_par_quete` (config `jeu.errant`) — 2, 3, 4, 5, 6, 7… : Orque, Fimir, …, Abomination,
 * Guerrier du Chaos, Gargouille. ⚠ La pente est la NÔTRE, plus rapide que celle du livret : nos campagnes sont plus
 * courtes que ses 14 quêtes. Personne n'a de valeur à sourcer ici, le `cout` n'existant pas au plateau.
 *
 * Toujours le MÊME pour une quête donnée (« le monstre errant de la quête ») : à coût égal, la boîte du thème
 * d'abord, puis le jeu de base (les errants du livret en viennent tous), puis le reste ; enfin une rotation sur
 * l'id du groupe et la position d'arc — la discipline du boss final (`DemarreurQuete::acheterMonstres()`), jamais
 * un tirage, qui changerait d'errant à chaque reprise.
 *
 * Écartés, et pourquoi :
 *  - une variante À DISTANCE : elle ne joue qu'en substitution de son monstre standard, jamais comme une créature
 *    de plus (Q6) ;
 *  - un TIREUR (`portee: distance`, l'Archer elfe) : l'errant naît au contact et frappe aussitôt, et un tireur au
 *    contact ne lance qu'un dé (« Attack 4 (1 if adjacent) ») — tous les errants du livret sont des combattants ;
 *  - une Attaque 0 (le Rejeton putride s'accroche) : la carte dit « The monster immediately attacks » ;
 *  - une figure immobile ou de plus d'une case : l'errant se pose sur UNE case libre près du fouilleur
 *    (`ResolveurTour::caseLibreProche()`) ;
 *  - une EMBUSCADE (le Dreadshifter se pose déguisé en coffre) ou une figure PASSIVE (la Gargouille-statue dort
 *    jusqu'à son déclencheur) : deux capacités qui ne vivent qu'à la pose d'une rencontre, au démarrage.
 */
final class MonstreErrant
{
    public function deLaQuete(Quete $quete): ?Monstre
    {
        $bestiaire = BestiaireGroupe::duGroupe($quete->groupe);

        $candidats = Monstre::query()
            ->where('tier', 'base')
            ->where('cout', '>', 0)
            ->where('attaque', '>', 0)
            ->where('portee', 'corps_a_corps')
            ->whereNull('variante_distance_de')
            ->orderBy('cout')
            ->orderBy('id')
            ->get()
            ->filter(fn (Monstre $m) => $bestiaire->autorise($m->boites()))
            ->filter(fn (Monstre $m) => ! $m->reserveASonTheme() || $bestiaire->contient($m->boites()))
            ->reject(fn (Monstre $m) => $m->estImmobile() || $m->grandeTaille() || $m->aCapaciteEmbuscade()
                || array_key_exists(MoteurDormants::CAPACITE, (array) $m->capacites))
            ->values();

        if ($candidats->isEmpty()) {
            return null;
        }

        $positionArc = max(1, (int) $quete->position_arc);
        $plafond = (int) config('jeu.errant.cout_depart', 2)
            + ($positionArc - 1) * (int) config('jeu.errant.cout_par_quete', 1);

        // Le plus cher sous le plafond ; repli sur le moins cher quand le plafond est sous tout le bestiaire.
        $sousPlafond = $candidats->filter(fn (Monstre $m) => (int) $m->cout <= $plafond);
        $cout = $sousPlafond->isNotEmpty() ? (int) $sousPlafond->max('cout') : (int) $candidats->min('cout');
        $auCout = $candidats->filter(fn (Monstre $m) => (int) $m->cout === $cout)->values();

        // Le thème, puis le jeu de base, resserrent sans jamais vider — comme le thème pour le boss final.
        foreach ([
            fn (Monstre $m) => $bestiaire->contient($m->boites()),
            fn (Monstre $m) => in_array('base', $m->boites(), true),
        ] as $preference) {
            $preferes = $auCout->filter($preference)->values();

            if ($preferes->isNotEmpty()) {
                $auCout = $preferes;
                break;
            }
        }

        return $auCout[((int) $quete->groupe_id + $positionArc) % $auCout->count()];
    }
}
