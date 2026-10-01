<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Résultat immuable d'un calcul de déplacement (doc 03 §3).
 *
 * Héros : base + 1d6 par tour — sauf si `$deAnnule` (armure lourde, clé
 * `deplacement_sans_d6` : le porteur avance de sa base SEULE). `$de` est
 * TOUJOURS lancé, `$deAnnule` ou pas : le dé compte ou ne compte pas dans
 * `$total`, il n'est jamais supprimé — le joueur doit voir ce qu'il aurait eu.
 *
 * `$des` porte TOUS les dés lancés — un seul d'ordinaire, deux avec les *Bottes
 * elfiques*. `$de` reste le premier : c'est celui que les autres règles lisent
 * (rupture d'Évanescence), et il ne devait pas changer de sens sous leurs pieds.
 *
 * `$sansMenace` (FL-Q p. 7, First Light, 2026-09-30 — *Unthreatened Movement*) :
 * aucun monstre actif révélé sur le plateau de la quête → chaque dé **compte 4
 * au lieu d'être lancé**, littéralement — `$de`/`$des` portent alors cette
 * valeur fixe, jamais un jet. ⚠ `$deAnnule` reste prioritaire et indépendant :
 * un dé annulé (Armure de plates) l'est toujours, que la table soit menacée ou
 * pas — les deux clés peuvent cohabiter sans contradiction, seul `$total` lit
 * `$deAnnule` d'abord.
 */
final readonly class ResultatDeplacement
{
    public function __construct(
        public int $base,
        public ?int $de,
        public int $total,
        public bool $deAnnule = false,
        /** @var list<int> */
        public array $des = [],
        public bool $sansMenace = false,
    ) {}
}
