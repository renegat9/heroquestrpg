<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Competence;
use App\Models\EtatPersonnageQuete;
use App\Models\Personnage;

/**
 * Lecture des CAPACITÉS INNÉES — les capacités de carte des 8 classes
 * d'extension (2026-08-12).
 *
 * Elles vivent dans `competences` avec `innee = true` : acquises d'emblée,
 * sans coûter de point, parce qu'au plateau la carte vient avec la figurine.
 * Cette classe est le point de passage unique pour les LIRE — sans elle chaque
 * moteur ferait sa propre requête sur le pivot, avec sa propre idée de ce qui
 * compte.
 *
 * ⚠ La plupart de ces cartes portent « **once per quest** ». Le compteur vit
 * dans `etat_personnage_quete.capacites_utilisees` (une liste de noms) plutôt
 * que dans 24 colonnes booléennes, et il se remet à zéro tout seul : l'état
 * d'un héros est recréé à chaque quête.
 */
final class CapacitesInnees
{
    /**
     * Attache à un héros neuf toutes les capacités de carte de sa classe.
     *
     * ⚠ Point de passage UNIQUE, et il a fallu un test pour s'en rendre compte :
     * la logique vivait dans `GroupeController`, si bien qu'un héros créé
     * autrement — par le helper de test, demain par un seeder ou une reprise —
     * naissait sans ses capacités. Les deux chemins passent ici désormais.
     */
    public function attacherA(Personnage $personnage): void
    {
        $innees = Competence::where('classe', $personnage->classe)
            ->where('innee', true)
            ->pluck('id');

        if ($innees->isNotEmpty()) {
            $personnage->competences()->syncWithoutDetaching($innees->all());
        }
    }

    /**
     * Le héros possède-t-il cette capacité (par son nom de nœud) ?
     *
     * Cherche parmi TOUTES ses compétences, innées ou achetées : un nœud
     * d'arbre pourrait un jour porter la même mécanique, et la lecture ne doit
     * pas dépendre de la façon dont il l'a obtenu.
     */
    public function a(Personnage $personnage, string $mecanique): bool
    {
        return $this->noeud($personnage, $mecanique) !== null;
    }

    /**
     * Le NŒUD portant cette mécanique, ou null. Rend l'accès à `effet` pour les
     * capacités chiffrées (valeur du bonus, plafond de PV, seuil…).
     */
    public function noeud(Personnage $personnage, string $mecanique): ?Competence
    {
        return $personnage->competences()
            ->get(['competences.id', 'competences.nom', 'competences.effet'])
            ->first(fn (Competence $c) => ($c->effet['mecanique'] ?? null) === $mecanique);
    }

    /**
     * La capacité est-elle DISPONIBLE ce tour-ci : possédée, seuil de PV
     * respecté, et pas encore dépensée si elle est « once per quest » ?
     *
     * Le seuil `pv_body_max` vient des cartes du Berserker : *Représailles*
     * exige « 5 or fewer Body Points », *Frénésie sanguinaire* « 3 or fewer ».
     * ⚠ C'est un PLAFOND — la capacité s'ouvre quand on est BLESSÉ, elle ne se
     * ferme pas quand on l'est trop.
     */
    public function disponible(Personnage $personnage, ?EtatPersonnageQuete $etat, string $mecanique): bool
    {
        $noeud = $this->noeud($personnage, $mecanique);

        if ($noeud === null) {
            return false;
        }

        $plafond = $noeud->effet['pv_body_max'] ?? null;

        if ($plafond !== null && (int) $personnage->pv_body > (int) $plafond) {
            return false;
        }

        if (($noeud->effet['frequence'] ?? null) === 'une_fois_par_quete') {
            return $etat !== null && ! $this->dejaUtilisee($etat, $noeud->nom);
        }

        return true;
    }

    /** La capacité a-t-elle déjà été dépensée dans cette quête ? */
    public function dejaUtilisee(EtatPersonnageQuete $etat, string $nom): bool
    {
        return in_array($nom, (array) ($etat->capacites_utilisees ?? []), true);
    }

    /**
     * Marque la capacité comme dépensée pour la quête. Sans effet si elle n'est
     * pas « once per quest » — les passifs permanents ne se consomment pas.
     */
    public function consommer(Personnage $personnage, EtatPersonnageQuete $etat, string $mecanique): void
    {
        $noeud = $this->noeud($personnage, $mecanique);

        if ($noeud === null || ($noeud->effet['frequence'] ?? null) !== 'une_fois_par_quete') {
            return;
        }

        $utilisees = (array) ($etat->capacites_utilisees ?? []);
        $utilisees[] = $noeud->nom;

        $etat->update(['capacites_utilisees' => array_values(array_unique($utilisees))]);
    }
}
