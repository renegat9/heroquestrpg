<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\NiveauMonte;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;

/**
 * Montée de niveau par jalons (doc 01 §5, contrat docs/contrat-api.md) :
 * à la fin VICTORIEUSE d'une quête `sous_boss` ou `boss_final` — ou d'une
 * quête ordinaire portant un OBJECTIF MAJEUR accompli (troisième déclencheur
 * du document, porté le 2026-09-04) —, chaque héros actif gagne +1 niveau ; à chaque niveau PAIR, +1 PV max (Body pour
 * barbare/nain, Mind pour elfe/magicien — départ playtest), le PV courant
 * suit. Les points de compétence ne sont JAMAIS stockés :
 * `points_competence = (niveau − 1) − nb de nœuds acquis` (dérivé).
 *
 * Broadcast `.niveau.monte` (gains lisibles) émis AVANT le `.groupe.etat`
 * de fin de quête ; journal `systeme`.
 */
final class MonteeNiveau
{
    /** Types de jalon qui déclenchent la montée (doc 06 §4). */
    public const JALONS = ['sous_boss', 'boss_final'];

    /**
     * @return array{personnages: list<array<string, mixed>>}|null null si la quête ne fait pas monter
     */
    public function appliquer(Groupe $groupe, Quete $quete): ?array
    {
        $declencheur = $this->declencheur($quete);

        if ($declencheur === null) {
            return null;
        }

        $personnages = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->get()
            ->map(fn (Personnage $p) => $this->monter($p))
            ->values()
            ->all();

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'niveau_monte',
            'quete_id' => $quete->id,
            'type_jalon' => $quete->type_jalon,
            // Dit LEQUEL des trois déclencheurs a joué : sans lui, une montée
            // sur objectif majeur serait indiscernable d'une montée de jalon
            // dans le journal, et c'est justement la nouveauté à pouvoir
            // relire.
            'declencheur' => $declencheur,
            'personnages' => $personnages,
        ]);

        $resultat = ['personnages' => $personnages];

        // Avant le `.groupe.etat` final (diffusé par ResolveurTour::resoudre).
        broadcast(new NiveauMonte($groupe, $resultat));

        return $resultat;
    }

    /**
     * Lequel des TROIS déclencheurs de la doc 01 §5 s'applique à cette quête,
     * ou `null` si aucun.
     *
     * ⚠ Le troisième — « certains objectifs de quête majeurs marqués par le
     * gabarit » — était déclaré depuis toujours et n'avait AUCUN lecteur : la
     * constante ne listait que les deux premiers. Conséquence mesurée sur la
     * campagne de René (2026-09-04) : trois quêtes pour un seul niveau, et une
     * campagne courte plafonnée à DEUX niveaux là où la même section en vise
     * cinq à huit — pour une grille de talents dimensionnée sur « neuf cases
     * pour quatre à sept points » (§6). Le choix qui devait faire mal ne se
     * posait pas.
     *
     * ⚠ L'objectif doit être ACCOMPLI, pas seulement la quête terminée. Les
     * deux ne coïncident pas : `quitter_donjon` s'ouvre aussi sur un donjon
     * entièrement nettoyé, filet anti-blocage qui laisse repartir un groupe
     * sans avoir rien rapporté du fond. Un niveau donné là récompenserait le
     * fait d'être sorti, pas d'avoir réussi.
     *
     * ⚠ Un jalon ne cumule JAMAIS avec un objectif majeur : `CadenceNiveaux`
     * ne place les positions majeures que sur les quêtes ordinaires, et
     * l'ordre de ce `match` le redit ici plutôt que de s'en remettre à
     * l'invariant d'un autre fichier.
     */
    private function declencheur(Quete $quete): ?string
    {
        if (in_array($quete->type_jalon, self::JALONS, true)) {
            return (string) $quete->type_jalon;
        }

        return $quete->objectif_majeur && $quete->objectifAccompli()
            ? 'objectif_majeur'
            : null;
    }

    /**
     * @return array<string, mixed> ligne du payload `.niveau.monte` du contrat
     */
    private function monter(Personnage $personnage): array
    {
        $niveau = (int) $personnage->niveau + 1;
        $attributs = ['niveau' => $niveau];
        $gains = ['+1 niveau', '+1 point de compétence'];

        if ($niveau % 2 === 0) {
            // Les classes de CORPS gagnent du Body, les autres du Mind. Les
            // trois ajoutées le 2026-08-12 se rangent d'après leur fiche :
            // Chevalier et Berserker à 7 Body / 2 Mind, Moine à 6/4 avec la
            // meilleure défense du jeu — ce sont des combattants. Le Rogue,
            // l'Explorateur, le Barde, le Druide et le Warlock non.
            if (in_array($personnage->classe, ['barbare', 'nain', 'chevalier', 'berserker', 'moine'], true)) {
                $attributs['pv_body_max'] = (int) $personnage->pv_body_max + 1;
                $attributs['pv_body'] = (int) $personnage->pv_body + 1;
                $gains[] = '+1 PV de Body maximum';
            } else {
                $attributs['pv_mind_max'] = (int) $personnage->pv_mind_max + 1;
                $attributs['pv_mind'] = (int) $personnage->pv_mind + 1;
                $gains[] = '+1 PV de Mind maximum';
            }
        }

        $personnage->update($attributs);

        return [
            'id' => $personnage->id,
            'nom' => $personnage->nom,
            'niveau' => $niveau,
            'points_competence' => $personnage->pointsCompetence(),
            'gains' => $gains,
        ];
    }
}
