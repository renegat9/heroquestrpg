<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\EtatPersonnageQuete;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Support\Journal;

/**
 * CHARGES d'un exemplaire d'objet (`inventaire.charges`).
 *
 * Un objet à charges fait ce qu'il annonce **N fois**, puis il est DÉTRUIT : sa
 * ligne d'inventaire disparaît au dernier usage.
 *
 * ⚠ ARBITRAGE DE RENÉ (2026-09-16), qui remplace « devient inerte et reste au
 * sac » : « Les artefacts qui sont à usage unique ou limité peuvent être de
 * nouveau trouvés une fois qu'ils sont détruits (plus assignés à un héros). »
 * Inerte, l'arc vide restait au sac pour toujours — donc DÉTENU, donc écarté des
 * coffres (`DeckFouille::choisirArtefact()` n'écarte que ce qu'un héros
 * possède), donc perdu pour la campagne. Détruit, il redevient trouvable, sans
 * statut à tenir : l'absence de ligne suffit. Les cinq objets à charges du
 * catalogue sont tous des artefacts (Arc de Vindication, Anneaux de Feu et du
 * Retour, Bâton Ancien, Orbe Céleste).
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
    public function consommer(?Inventaire $ligne, bool $differerDestruction = false): bool
    {
        $restantes = $this->restantes($ligne);

        if ($restantes === null) {
            return true;
        }

        if ($restantes <= 0) {
            return false;
        }

        $ligne->update(['charges' => $restantes - 1]);
        app(TamponCharges::class)->depense($ligne, $restantes - 1);

        // ⚠ `$differerDestruction` : l'appelant qui JOURNALISE son action juste
        // après (la flèche, l'anneau activé) détruit lui-même, ENSUITE, par
        // `detruireSiEpuise()`. Sinon le fil dirait « l'arc se brise » avant
        // « Lindir tire » — la chute affichée avant le coup, encore.
        if ($restantes - 1 === 0 && ! $differerDestruction) {
            $this->detruire($ligne);
        }

        return true;
    }

    /** Détruit l'exemplaire s'il n'a plus de charge. Le pendant différé de `consommer()`. */
    public function detruireSiEpuise(?Inventaire $ligne): void
    {
        if ($ligne !== null && $ligne->exists && $this->restantes($ligne->fresh()?->load('objet')) === 0) {
            $this->detruire($ligne);
        }
    }

    /**
     * Le dernier usage vient de partir : l'objet se brise.
     *
     * ⚠ L'appelant garde le modèle EN MÉMOIRE (`$ligne->exists` passe à faux) :
     * il peut encore lire son objet et ses charges pour son payload, mais plus
     * rien ne doit le relire en base — `fresh()` rendrait `null`.
     *
     * ⚠ Journalisé : un artefact qui disparaît de la main d'un héros sans un mot
     * est exactement l'effet automatique que rien n'annonce.
     */
    private function detruire(Inventaire $ligne): void
    {
        $nom = $ligne->objet?->nom;
        $porteur = $ligne->personnage()->first();

        app(TamponCharges::class)->detruit($ligne);
        $ligne->delete();

        $groupe = $porteur?->groupeActif;

        if ($groupe !== null) {
            Journal::ajouter($groupe, 'combat', [
                'type' => 'objet_detruit',
                'objet' => $nom,
                'personnage' => $porteur->nom,
            ], ['type' => 'personnage', 'id' => $porteur->id, 'nom' => $porteur->nom]);
        }
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
    public function consommerUsage(?Inventaire $ligne, ?EtatPersonnageQuete $etat = null, bool $differerDestruction = false): bool
    {
        if (! $this->utilisable($ligne, $etat)) {
            return false;
        }

        $compteur = $this->compteurDe($ligne);

        if ($compteur !== null && $etat !== null) {
            app(Talents::class)->marquerUtilisee($etat, self::cleFenetre($ligne), $compteur);
        }

        return $this->consommer($ligne, $differerDestruction);
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
