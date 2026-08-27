<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Competence;
use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;

/**
 * Lecture des COMPÉTENCES d'un héros — point de passage unique.
 *
 * Nœuds de la grille de talents et capacités de carte (`innee`) vivent dans la
 * même table et se lisent de la même façon : **par mécanique, jamais par nom**.
 * C'est la correction de fond du 2026-08-23. Les lecteurs du moteur étaient
 * branchés sur le nom du nœud — `'Garde tenace'`, `'Coup puissant'`,
 * `'Intimidation'`, `'Réserve arcanique'`, `'Concentration'`, `'Désamorçage'` —
 * si bien qu'une quinzaine de talents des classes d'extension portaient la
 * bonne `effet.mecanique` et ne faisaient **rien** : *Esquive* (rogue),
 * *Garde haute* (chevalier), *Écorce* (druide), *Refrain vaillant* (barde),
 * *Prestance*, *Méditation*, *Cartographe*, *Bras d'acier*, *Coup sauvage*,
 * *Second couplet*, *Rappel*, *Communion*, *Doigts de fée*, *Crochetage*…
 * Une grille qui répète les mêmes thèmes sur douze classes rend ce câblage
 * intenable : le nom est de la présentation, la mécanique est le contrat.
 *
 * @see \App\Engine\MotsClesTalent  le vocabulaire fermé et son lecteur déclaré
 */
class Talents
{
    /** Fréquences déclarées par les cartes, et la colonne qui les compte. */
    protected const COMPTEURS = [
        'une_fois_par_quete' => 'capacites_utilisees',
        'une_fois_par_tour' => 'capacites_tour',
    ];

    /**
     * Le héros possède-t-il un nœud portant cette mécanique ?
     *
     * @param  array<string, mixed>  $criteres  clés de `effet` à faire correspondre ;
     *                                          une valeur `null` exige la clé ABSENTE
     */
    public function a(Personnage $personnage, string $mecanique, array $criteres = []): bool
    {
        return $this->noeud($personnage, $mecanique, $criteres) !== null;
    }

    /**
     * Le NŒUD portant cette mécanique, ou null. Rend l'accès à `effet` pour les
     * capacités chiffrées (valeur du bonus, plafond de PV, seuil…).
     *
     * ⚠ `$criteres` n'est pas un raffinement cosmétique : `bonus_des_attaque`
     * désigne AUSSI BIEN le bonus permanent versé dans la colonne que la
     * *Frénésie* qui ne vaut que sous la moitié des PV. Les lire sans
     * distinguer, c'est appliquer l'un à la place de l'autre.
     *
     * @param  array<string, mixed>  $criteres
     */
    public function noeud(Personnage $personnage, string $mecanique, array $criteres = []): ?Competence
    {
        return $this->noeuds($personnage, $mecanique, $criteres)->first();
    }

    /**
     * TOUS les nœuds portant cette mécanique : la grille autorise une même
     * mécanique dans deux colonnes, et deux `+1 dé de défense` font `+2`.
     *
     * @param  array<string, mixed>  $criteres
     * @return \Illuminate\Support\Collection<int, Competence>
     */
    public function noeuds(Personnage $personnage, string $mecanique, array $criteres = [])
    {
        return $personnage->competences()
            // ⚠ `description` FAIT PARTIE du minimum : les offres de réaction la
            // publient (`MoteurReactions::deposer()`) et `ReactionSheet.vue` la
            // rend sous le nom de la capacité. Tant qu'elle manquait à ce
            // `get()`, elle revenait `null` sans erreur nulle part — le joueur
            // voyait « Inébranlable » avec un compte à rebours et RIEN qui dise
            // ce qu'accepter allait dépenser, pour une ressource qui ne sert
            // qu'une fois par quête (constaté en validation le 2026-08-14).
            ->get(['competences.id', 'competences.nom', 'competences.description', 'competences.effet'])
            ->filter(function (Competence $c) use ($mecanique, $criteres) {
                if (($c->effet['mecanique'] ?? null) !== $mecanique) {
                    return false;
                }

                foreach ($criteres as $cle => $attendu) {
                    $valeur = $c->effet[$cle] ?? null;

                    if ($attendu === null ? $valeur !== null : $valeur !== $attendu) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    /**
     * La somme des `effet.valeur` des nœuds portant cette mécanique (0 si
     * aucun). C'est la lecture normale d'un bonus chiffré : demander la
     * possession puis relire `effet['valeur']` oublie le second nœud.
     *
     * @param  array<string, mixed>  $criteres
     */
    public function valeur(Personnage $personnage, string $mecanique, array $criteres = []): int
    {
        return (int) $this->noeuds($personnage, $mecanique, $criteres)
            ->sum(fn (Competence $c) => (int) ($c->effet['valeur'] ?? 0));
    }

    /**
     * La capacité est-elle DISPONIBLE : possédée, seuil de PV respecté, et pas
     * encore dépensée dans sa fenêtre (quête ou tour) ?
     *
     * Le seuil `pv_body_max` vient des cartes du Berserker : *Représailles*
     * exige « 5 or fewer Body Points », *Frénésie sanguinaire* « 3 or fewer ».
     * ⚠ C'est un PLAFOND — la capacité s'ouvre quand on est BLESSÉ, elle ne se
     * ferme pas quand on l'est trop.
     *
     * @param  array<string, mixed>  $criteres
     */
    public function disponible(Personnage $personnage, ?EtatPersonnageQuete $etat, string $mecanique, array $criteres = []): bool
    {
        $noeud = $this->noeud($personnage, $mecanique, $criteres);

        if ($noeud === null) {
            return false;
        }

        $plafond = $noeud->effet['pv_body_max'] ?? null;

        if ($plafond !== null && (int) $personnage->pv_body > (int) $plafond) {
            return false;
        }

        $compteur = static::COMPTEURS[$noeud->effet['frequence'] ?? ''] ?? null;

        if ($compteur !== null) {
            return $etat !== null && ! $this->dejaUtilisee($etat, $noeud->nom, $compteur);
        }

        return true;
    }

    /**
     * La capacité a-t-elle déjà été dépensée dans sa fenêtre ? Par défaut celle
     * de la quête, la seule qui existait avant les capacités « par tour ».
     */
    public function dejaUtilisee(EtatPersonnageQuete $etat, string $nom, string $compteur = 'capacites_utilisees'): bool
    {
        return in_array($nom, (array) ($etat->{$compteur} ?? []), true);
    }

    /**
     * Marque la capacité comme dépensée dans sa fenêtre. Sans effet si elle
     * n'en déclare aucune — les passifs permanents ne se consomment pas.
     *
     * @param  array<string, mixed>  $criteres
     */
    public function consommer(Personnage $personnage, EtatPersonnageQuete $etat, string $mecanique, array $criteres = []): void
    {
        $noeud = $this->noeud($personnage, $mecanique, $criteres);
        $compteur = $noeud === null ? null : (static::COMPTEURS[$noeud->effet['frequence'] ?? ''] ?? null);

        if ($compteur === null) {
            return;
        }

        $utilisees = (array) ($etat->{$compteur} ?? []);
        $utilisees[] = $noeud->nom;

        $etat->update([$compteur => array_values(array_unique($utilisees))]);
    }
}
