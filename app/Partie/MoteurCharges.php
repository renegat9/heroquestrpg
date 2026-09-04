<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\EtatPersonnageQuete;
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

    // ------------------------------------------------------------------
    // FENÊTRE D'USAGE — « once per quest », « once per turn »
    // ------------------------------------------------------------------
    //
    // ⚠ Une charge et une fenêtre ne disent PAS la même chose, et les confondre
    // a coûté six artefacts (corrigé le 2026-09-03). Une charge est un TOTAL
    // qui ne se réarme jamais — « there are only 4 arrows with this bow » ; une
    // fenêtre est une CADENCE qui repart à chaque quête — « once per quest ».
    // Exprimer la seconde avec la première donnait « une fois par CAMPAGNE ».
    //
    // Le stockage est celui des compétences (`etat_personnage_quete`), et c'est
    // délibéré : il naît vide avec la quête, il est déjà dans le snapshot, et il
    // n'a donc demandé aucune migration. Un second compteur aurait été un second
    // endroit où oublier de réarmer.

    /** Clé de fenêtre de CET exemplaire (deux copies se comptent séparément). */
    public static function cleFenetre(Inventaire $ligne): string
    {
        return 'objet:'.$ligne->id;
    }

    /**
     * La fenêtre de l'objet est-elle encore ouverte ?
     *
     * Vrai pour tout objet qui ne déclare aucune `frequence` — l'immense
     * majorité du catalogue. ⚠ Faux sans état de quête : une cadence « par
     * quête » n'a pas de sens au hub, et laisser passer y viderait la fenêtre
     * hors de toute quête.
     */
    public function fenetreOuverte(?Inventaire $ligne, ?EtatPersonnageQuete $etat): bool
    {
        $compteur = $this->compteurDe($ligne);

        if ($compteur === null) {
            return true;
        }

        return $etat !== null
            && ! app(Talents::class)->dejaUtilisee($etat, self::cleFenetre($ligne), $compteur);
    }

    /**
     * Utilisable MAINTENANT : charges restantes ET fenêtre ouverte.
     *
     * C'est CE point d'entrée que les menus doivent interroger, et pas
     * `disponible()` seul : une option offerte alors que la fenêtre est fermée
     * serait un bouton qui répond toujours non.
     */
    public function utilisable(?Inventaire $ligne, ?EtatPersonnageQuete $etat = null): bool
    {
        return $this->disponible($ligne) && $this->fenetreOuverte($ligne, $etat);
    }

    /**
     * Dépense l'usage : la fenêtre si l'objet en déclare une, la charge s'il en
     * a. Rend `false` si l'objet n'était pas utilisable — l'appelant ne doit
     * alors PAS appliquer l'effet.
     */
    public function consommerUsage(?Inventaire $ligne, ?EtatPersonnageQuete $etat = null): bool
    {
        if (! $this->utilisable($ligne, $etat)) {
            return false;
        }

        $compteur = $this->compteurDe($ligne);

        if ($compteur !== null && $etat !== null) {
            app(Talents::class)->marquerUtilisee($etat, self::cleFenetre($ligne), $compteur);
        }

        return $this->consommer($ligne);
    }

    /** La colonne qui compte la fréquence déclarée par l'objet, ou `null`. */
    private function compteurDe(?Inventaire $ligne): ?string
    {
        return Talents::compteurPour($ligne?->objet?->effet['frequence'] ?? null);
    }

    /**
     * Première pièce ÉQUIPÉE portant cette clé d'effet et encore chargée, ou
     * `null`.
     *
     * Le filtre sur les charges est le cœur du service : sans lui, un anneau à
     * usage unique continuerait d'agir indéfiniment — c'est-à-dire exactement le
     * genre de règle annoncée et jamais tenue que le projet traque.
     */
    public function pieceActive(Personnage $personnage, string $cle, ?EtatPersonnageQuete $etat = null): ?Inventaire
    {
        return $personnage->inventaire()
            ->whereIn('emplacement', Equipement::SLOTS)
            ->with('objet')
            ->get()
            // ⚠ `utilisable()` et non `disponible()` : depuis que la cadence
            // « une fois par quête » existe, une pièce peut être pleine de
            // charges (ou n'en avoir aucune) tout en ayant sa fenêtre fermée.
            // L'Anneau de Sort est passé de `charges: 1` à une fenêtre le
            // 2026-09-03 ; sans ce changement il aurait épargné un sort à
            // CHAQUE incantation.
            ->first(fn (Inventaire $l) => (bool) (($l->objet?->effet ?? [])[$cle] ?? false)
                && $this->utilisable($l, $etat));
    }
}
