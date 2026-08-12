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
     * VOCABULAIRE des capacités de carte : une mécanique par ligne, et le
     * lecteur qui l'applique. Même garde-fou que `MotsClesEquipement` et
     * `DureeEffet` — et pour la même raison : une capacité annoncée au joueur
     * que rien n'applique est une promesse non tenue, et c'est ce que le projet
     * retire depuis des semaines.
     *
     * `CapacitesInneesTest` la vérifie DANS LES DEUX SENS : aucune mécanique du
     * seeder hors de cette liste, et aucune ligne d'ici que plus aucune carte ne
     * porte. Ajouter une capacité, c'est donc lui écrire un lecteur — ou
     * renoncer à la semer.
     *
     * @var array<string, string>  mécanique => lecteur
     */
    public const MECANIQUES = [
        // Barde
        'bonus_des_defense_sans_metal' => 'MoteurSorts::desDefenseHeros()',
        // Rogue
        'attaque_supplementaire_arme' => 'ResolveurTour::ambidextrie()',
        'franchit_figures' => 'Grille::autoriserFranchissement()',
        'bonus_des_attaque_flanc' => 'ResolveurTour::frapper()',
        // Chevalier
        'plancher_pv' => 'MoteurReactions::resoudre()',
        'annule_degats_voisin' => 'MoteurReactions::proposerAuVoisin()',
        'defi_errant' => 'MoteurReactions::proposerDefi()',
        // Berserker
        'sacrifice_pv_pour_des' => 'ResolveurTour::payerLaFurie()',
        'riposte' => 'MoteurReactions::riposter()',
        'attaque_balayee' => 'ResolveurTour::resoudreAttaqueBalayee()',
        // Explorateur
        'bonus_or_tresor' => 'ResolveurTour::appliquerButin()',
        'repiocher_carte_piege' => 'ResolveurTour::piocherAvecSixiemeSens()',
        'alerte_pieges_adjacents' => 'MoteurPieges::controlerChemin()',
        // Moine — la carte de style, puis ses deux techniques
        'style_elementaire' => 'StylesElementaires',
        'saut_piege_automatique' => 'ResolveurTour::resoudreFranchissement()',
        'bonus_des_attaque_mains_nues' => 'ResolveurTour::activerPoingDeMontagne()',
        'fouille_complete' => 'ResolveurTour::resoudreJet()',
        'deplacement_scinde' => 'ResolveurTour::marquerCreneau()',
        'annule_degats' => 'MoteurReactions::proposer()',
        'rayon' => 'ResolveurTour::resoudreRayon()',
        'degat_differe' => 'ResolveurTour::resoudreDegatDiffere()',
    ];

    /** Fréquences déclarées par les cartes, et la colonne qui les compte. */
    private const COMPTEURS = [
        'une_fois_par_quete' => 'capacites_utilisees',
        'une_fois_par_tour' => 'capacites_tour',
    ];

    /**
     * La capacité est-elle DISPONIBLE : possédée, seuil de PV respecté, et pas
     * encore dépensée dans sa fenêtre (quête ou tour) ?
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

        $compteur = self::COMPTEURS[$noeud->effet['frequence'] ?? ''] ?? null;

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
     */
    public function consommer(Personnage $personnage, EtatPersonnageQuete $etat, string $mecanique): void
    {
        $noeud = $this->noeud($personnage, $mecanique);
        $compteur = $noeud === null ? null : (self::COMPTEURS[$noeud->effet['frequence'] ?? ''] ?? null);

        if ($compteur === null) {
            return;
        }

        $utilisees = (array) ($etat->{$compteur} ?? []);
        $utilisees[] = $noeud->nom;

        $etat->update([$compteur => array_values(array_unique($utilisees))]);
    }
}
