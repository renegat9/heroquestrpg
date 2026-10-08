<?php

declare(strict_types=1);

namespace App\Partie;

/**
 * Les FAVEURS de Hopekins Rest qui se déclenchent PENDANT la résolution d'une
 * action — aujourd'hui Peacekeeper (25 po à chaque monstre achevé par le héros
 * porteur) — pour que le fil en direct (`.combat.journal`) les dise, et pas
 * seulement l'historique.
 *
 * ⚠ Le défaut qu'il corrige est celui de `TamponCharges` : le fil en direct est
 * bâti sur le RÉSULTAT de l'action, jamais sur les événements journalisés à
 * côté. Une récompense versée au milieu d'une frappe, journalisée seulement,
 * n'apparaît à la table qu'au prochain rechargement — « un effet automatique
 * que rien n'annonce est injouable ».
 *
 * Même patron que `TamponCharges` et `AnnoncesTalents` : SINGLETON (pas
 * `scoped`), rempli par `FaveursHopekins`, vidé par `ResolveurTour::resoudre()`
 * à l'entrée ET à la sortie, et rendu dans le résultat sous
 * `faveurs_declenchees` (lignes produites par `JournalCombat::depuisResultat()`).
 */
final class TamponFaveurs
{
    /** @var list<array<string, mixed>> */
    private array $entrees = [];

    /** @param  array<string, mixed>  $payload  même forme que l'événement journalisé. */
    public function ajouter(array $payload): void
    {
        $this->entrees[] = $payload;
    }

    /**
     * Rend les faveurs déclenchées depuis le dernier vidage, puis le vide.
     *
     * @return list<array<string, mixed>>
     */
    public function vider(): array
    {
        $entrees = $this->entrees;
        $this->entrees = [];

        return $entrees;
    }
}
