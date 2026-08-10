<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Inventaire;
use App\Models\Personnage;

/**
 * CHARGES d'un exemplaire d'objet (`inventaire.charges`).
 *
 * Un objet à charges fait ce qu'il annonce **N fois**, puis devient inerte : il
 * reste en inventaire, mais son effet cesse de s'appliquer. C'est le texte de la
 * carte de l'arc elfique — « There are only 4 arrows with this bow. It becomes
 * useless afterwards. » — et le même modèle sert aux anneaux et baguettes à
 * usage unique.
 *
 * Trois règles, et rien d'autre :
 *
 *  1. `objets.effet.charges` est la valeur INITIALE, `inventaire.charges` le
 *     restant de CET exemplaire. Deux héros peuvent porter le même arc avec un
 *     nombre de flèches différent.
 *  2. `null` = « jamais entamé », pas « épuisé ». Toute ligne d'inventaire
 *     démarre donc pleine sans que les chemins qui la créent (marché, coffre,
 *     don, butin) aient à connaître les charges.
 *  3. Un objet SANS `effet.charges` est illimité — la quasi-totalité du
 *     catalogue. `restantes()` rend `null` pour lui, et `disponible()` est vrai.
 */
final class MoteurCharges
{
    /**
     * Charges restantes de cet exemplaire, `null` si l'objet est illimité.
     */
    public function restantes(?Inventaire $ligne): ?int
    {
        $initiales = (int) ($ligne?->objet?->effet['charges'] ?? 0);

        if ($ligne === null || $initiales <= 0) {
            return null; // objet sans charges : usage illimité
        }

        return $ligne->charges === null ? $initiales : max(0, (int) $ligne->charges);
    }

    /** Cet exemplaire peut-il encore servir ? (vrai aussi pour un objet illimité) */
    public function disponible(?Inventaire $ligne): bool
    {
        $restantes = $this->restantes($ligne);

        return $restantes === null || $restantes > 0;
    }

    /**
     * Dépense une charge. Rend `false` si l'objet était déjà épuisé — l'appelant
     * ne doit alors PAS appliquer l'effet.
     *
     * Un objet illimité rend `true` sans rien écrire : les appelants n'ont pas à
     * savoir si la pièce qu'ils manipulent a des charges ou non.
     */
    public function consommer(?Inventaire $ligne): bool
    {
        $restantes = $this->restantes($ligne);

        if ($restantes === null) {
            return true;
        }

        if ($restantes <= 0) {
            return false;
        }

        $ligne->update(['charges' => $restantes - 1]);

        return true;
    }

    /**
     * Première pièce ÉQUIPÉE portant cette clé d'effet et encore chargée, ou
     * `null`.
     *
     * Le filtre sur les charges est le cœur du service : sans lui, un anneau à
     * usage unique continuerait d'agir indéfiniment — c'est-à-dire exactement le
     * genre de règle annoncée et jamais tenue que le projet traque.
     */
    public function pieceActive(Personnage $personnage, string $cle): ?Inventaire
    {
        return $personnage->inventaire()
            ->whereIn('emplacement', Equipement::SLOTS)
            ->with('objet')
            ->get()
            ->first(fn (Inventaire $l) => (bool) (($l->objet?->effet ?? [])[$cle] ?? false)
                && $this->disponible($l));
    }
}
