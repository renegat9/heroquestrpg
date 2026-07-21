<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Deplacement;
use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use Illuminate\Support\Facades\Cache;

/**
 * Menu générique construit PAR LE MOTEUR depuis l'état exact — repli garanti
 * de la boucle de jeu (contrat : « l'API ne dépend jamais du LLM »).
 *
 * En quête : Se déplacer / Attaquer (un bouton par monstre actif adjacent) /
 * Désamorcer / Franchir (un bouton par piège DÉTECTÉ adjacent, doc 10 §4) /
 * Lancer {sort} (un bouton par sort DISPONIBLE, cibles légales jointes) /
 * Utiliser un parchemin (un bouton par parchemin au sac) / Se concentrer
 * (magicien, nœud Concentration — doc 02, MoteurSorts) / Fouiller (jet de
 * Mind 1) / Attendre. Au hub : options d'attente neutres.
 * Toutes les options sont exécutables telles quelles par ResolveurTour.
 */
final class MenuMoteur
{
    public function __construct(
        private readonly MoteurPieges $pieges,
        private readonly MoteurPortes $portes,
        private readonly MoteurSorts $sorts,
        private readonly LanceurDes $des,
    ) {}

    /**
     * Déplacement du tour : lance le d6 (base + 1d6, doc 03 §3) la PREMIÈRE fois
     * du tour et mémorise le total sur l'état (réutilisé pour les régénérations
     * de menu et la résolution). Rien n'est relancé si déjà fixé.
     *
     * @return array{base: int, de: int|null, total: int}
     */
    /**
     * Un héros en (hx,hy) est-il ORTHOGONALEMENT au contact de l'instance, en
     * tenant compte de l'emprise des grandes figurines (3.9) ? Le contact vaut
     * dès que le héros jouxte n'importe quelle case de l'emprise. Pour un monstre
     * 1×1 (cas par défaut), équivaut exactement à |dx| + |dy| === 1.
     */
    private static function monstreAuContact(InstanceMonstre $instance, int $hx, int $hy): bool
    {
        $e = $instance->monstre->emprise();

        for ($dy = 0; $dy < $e['h']; $dy++) {
            for ($dx = 0; $dx < $e['l']; $dx++) {
                if (abs(((int) $instance->position_x + $dx) - $hx)
                    + abs(((int) $instance->position_y + $dy) - $hy) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * « Fouiller — trésor » offerte ? Le héros doit être dans une SALLE (pas un
     * couloir) « vide » — aucun monstre actif révélé à l'intérieur — qui n'a pas
     * déjà été fouillée pour son trésor (une fouille par salle, doc 14 §3.2).
     */
    private function salleFouillableTresor(Quete $quete, ?EtatPersonnageQuete $etat): bool
    {
        if ($etat === null || $etat->position_x === null || $quete->carte === null) {
            return false;
        }

        $salles = (array) data_get($quete->carte->grille, 'salles', []);
        $salle = null;
        $index = null;

        foreach ($salles as $i => $s) {
            if ((int) $etat->position_x >= (int) $s['x'] && (int) $etat->position_x < (int) $s['x'] + (int) $s['largeur']
                && (int) $etat->position_y >= (int) $s['y'] && (int) $etat->position_y < (int) $s['y'] + (int) $s['hauteur']) {
                $salle = $s;
                $index = (int) $i;
                break;
            }
        }

        if ($salle === null) {
            return false; // couloir : pas de fouille de trésor
        }

        // Salle déjà fouillée pour son trésor ? (une seule fois — anti-farm)
        $fouillees = (array) Cache::get(ResolveurTour::cleTresorFouille($quete->id), []);
        if (in_array($index, $fouillees, true)) {
            return false;
        }

        // Salle « vide » : aucun monstre actif révélé dans ses limites.
        $occupee = $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true)
            ->whereBetween('position_x', [(int) $salle['x'], (int) $salle['x'] + (int) $salle['largeur'] - 1])
            ->whereBetween('position_y', [(int) $salle['y'], (int) $salle['y'] + (int) $salle['hauteur'] - 1])
            ->exists();

        return ! $occupee;
    }

    /**
     * Points de déplacement encore disponibles ce tour (E1) — LECTURE SEULE :
     * le restant mémorisé si le mouvement est entamé, sinon le total du tour
     * (Vent Véloce inclus, mais JAMAIS consommé ici : c'est le résolveur qui
     * consomme le buff au premier pas).
     */
    private function pointsRestants(Personnage $personnage, ?EtatPersonnageQuete $etat): int
    {
        if ($etat?->deplacement_restant !== null) {
            return (int) $etat->deplacement_restant;
        }

        return $this->deplacementDuTour($personnage, $etat)['total']
            * $this->sorts->multiplicateurDeplacement($personnage);
    }

    private function deplacementDuTour(Personnage $personnage, ?EtatPersonnageQuete $etat): array
    {
        $base = (int) $personnage->deplacement_base;

        if ($etat === null) {
            return ['base' => $base, 'de' => null, 'total' => $base];
        }

        if ($etat->deplacement_tour === null && ! $etat->tombe && ! $etat->a_joue) {
            $etat->update(['deplacement_tour' => (new Deplacement($this->des))->calculer($base)->total]);
        }

        $total = $etat->deplacement_tour ?? $base;

        return ['base' => $base, 'de' => $total > $base ? $total - $base : null, 'total' => $total];
    }

    /**
     * Le héros a-t-il AU MOINS une case orthogonale accessible (donc un
     * déplacement réel possible) ? On reconstruit le plateau du moteur — même
     * occupation que ResolveurTour::grille (autres héros, monstres actifs avec
     * emprise, alliés) et portes/murs de la carte — puis on teste les 4 voisins :
     * l'ensemble atteignable est vide SSI aucun voisin n'est traversable. Sans
     * carte/position, on suppose le déplacement possible (ne jamais masquer à tort).
     */
    private function peutSeDeplacer(Quete $quete, Personnage $personnage, ?EtatPersonnageQuete $etat): bool
    {
        if ($etat === null || $etat->position_x === null || $etat->tombe || $quete->carte === null) {
            return true;
        }

        $grille = Grille::depuisCarte($quete->carte);

        $occupees = [];
        foreach ($quete->etatsPersonnages()->get() as $autre) {
            if ($autre->personnage_id !== $personnage->id && $autre->position_x !== null) {
                $occupees[] = ['x' => (int) $autre->position_x, 'y' => (int) $autre->position_y];
            }
        }
        foreach ($quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get() as $instance) {
            if ($instance->position_x !== null) {
                $e = $instance->monstre->emprise();
                $occupees = array_merge($occupees, $grille->cellulesEmprise(
                    (int) $instance->position_x, (int) $instance->position_y, $e['l'], $e['h'],
                ));
            }
        }
        foreach (GroupeMercenaire::where('groupe_id', $quete->groupe_id)->where('etat', 'actif')->get() as $allie) {
            if ($allie->position_x !== null) {
                $occupees[] = ['x' => (int) $allie->position_x, 'y' => (int) $allie->position_y];
            }
        }
        $grille->occuper($occupees);

        $x = (int) $etat->position_x;
        $y = (int) $etat->position_y;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            if ($grille->estTraversable($x + $dx, $y + $dy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{situation: string, options: list<array<string, mixed>>}
     */
    public function generer(Groupe $groupe, Personnage $personnage): array
    {
        $quete = $groupe->phase === 'quete' ? $groupe->queteCourante : null;

        if ($quete === null) {
            return [
                'situation' => 'Le groupe se prépare au hub.',
                'options' => [
                    ['id' => 'attendre', 'libelle' => 'Attendre et observer', 'type' => 'attente'],
                    ['id' => 'continuer', 'libelle' => 'Continuer prudemment', 'type' => 'action'],
                ],
            ];
        }

        $etat = $quete->etatsPersonnages()->where('personnage_id', $personnage->id)->first();

        // Tour = deux créneaux (doc 03 §28) : un DÉPLACEMENT + une ACTION. On
        // n'offre que les créneaux ENCORE LIBRES, plus « Terminer le tour ».
        // Une action TERMINANTE (relever, concentration, « Terminer le tour »)
        // pose a_joue sans consommer les deux créneaux : on la traite comme si
        // les DEUX étaient pris, sinon le menu proposait encore des actions
        // fantômes alors que le tour est fini.
        $aJoue = (bool) ($etat?->a_joue ?? false);
        $aDeplace = $aJoue || (bool) ($etat?->a_deplace ?? false);
        $aAgi = $aJoue || (bool) ($etat?->a_agi ?? false);
        $options = [];

        // ── Créneau DÉPLACEMENT (base + 1d6 lancé une fois/tour et mémorisé) ──
        // On masque « Se déplacer » quand le héros est TOTALEMENT bloqué (aucune
        // case orthogonale traversable : murs / portes fermées / figures) — sinon
        // c'était une option morte (0 case) qui forçait « Terminer le tour ». Le
        // plateau est celui du moteur (occupation identique à ResolveurTour).
        if (! $aDeplace && $this->peutSeDeplacer($quete, $personnage, $etat)) {
            $portee = $this->deplacementDuTour($personnage, $etat);

            // Déplacement FRACTIONNÉ (E1) : si le héros a DÉJÀ entamé son
            // mouvement ce tour, la portée offerte est le RESTANT ; sinon le total
            // du tour (Vent Véloce inclus, appliqué au 1er pas côté résolveur).
            $porteeEffective = $etat?->deplacement_restant !== null
                ? (int) $etat->deplacement_restant
                : $portee['total'] * $this->sorts->multiplicateurDeplacement($personnage);

            $options[] = [
                'id' => 'se_deplacer',
                'libelle' => $etat?->deplacement_restant !== null ? 'Continuer à se déplacer' : 'Se déplacer',
                'type' => 'deplacement',
                'parametres' => [
                    'portee_base' => (int) $personnage->deplacement_base,
                    'base' => $portee['base'],
                    'de' => $portee['de'],          // résultat du d6 (null si Armure de plates)
                    'portee' => $porteeEffective,    // cases restantes ce tour
                ],
            ];
        }

        // Pièges DÉTECTÉS adjacents (doc 10 §4) — partagés entre créneaux :
        // Franchir une fosse = DÉPLACEMENT, Désamorcer = ACTION.
        $detectes = ($etat !== null && $etat->position_x !== null && $quete->carte !== null)
            ? $this->pieges->detectesAdjacents($quete->carte, (int) $etat->position_x, (int) $etat->position_y)
            : [];

        // Sauter par-dessus une fosse fait partie du MOUVEMENT (E3) : l'option
        // n'apparaît que s'il reste assez de points pour payer le saut.
        if (! $aDeplace && $this->pointsRestants($personnage, $etat) >= ResolveurTour::COUT_FRANCHISSEMENT) {
            foreach ($detectes as $adjacent) {
                if ($this->pieges->estFosse($adjacent['piege'])) {
                    $nomPiege = $adjacent['piege']?->nom ?? 'Piège';
                    $options[] = [
                        'id' => "franchir_{$adjacent['x']}_{$adjacent['y']}",
                        'libelle' => "Sauter par-dessus {$nomPiege} — jet de Body",
                        'type' => 'franchissement',
                        'jet' => ['attribut' => 'body', 'difficulte' => ResolveurTour::DIFFICULTE_FRANCHISSEMENT],
                        'parametres' => [
                            'piege' => ['x' => $adjacent['x'], 'y' => $adjacent['y']],
                            'cout' => ResolveurTour::COUT_FRANCHISSEMENT,
                        ],
                    ];
                }
            }
        }

        // ── Créneau ACTION (attaque, relever, désamorçage, sorts, fouille) ──
        if (! $aAgi && $etat !== null && $etat->position_x !== null) {
            $adjacents = $quete->instancesMonstres()
                ->where('etat', 'actif')
                ->where('revele', true) // dormant (salle non découverte) = non ciblable (aligné sur ResolveurTour)
                ->with('monstre')
                ->orderBy('id')
                ->get()
                ->filter(fn (InstanceMonstre $i) => $i->position_x !== null
                    && self::monstreAuContact($i, (int) $etat->position_x, (int) $etat->position_y));

            foreach ($adjacents as $instance) {
                $nomBase = $instance->monstre->nom_base;
                $nom = $instance->habillage['nom'] ?? $nomBase;
                // Rappel du TYPE du catalogue quand le nom est un habillage IA →
                // le joueur retrouve la fiche du bestiaire (guide).
                $libelle = $nom === $nomBase ? "Attaquer {$nom}" : "Attaquer {$nom} ({$nomBase})";
                $options[] = [
                    'id' => "attaquer_{$instance->id}",
                    'libelle' => $libelle,
                    'type' => 'attaque',
                    'cible_id' => $instance->id,
                ];
            }

            // Relever un allié TOMBÉ adjacent (doc 03 §48) : sacrifie le tour.
            $allies = $quete->etatsPersonnages()
                ->where('tombe', true)
                ->where('personnage_id', '!=', $personnage->id)
                ->with('personnage')
                ->get()
                ->filter(fn ($e) => $e->position_x !== null
                    && abs((int) $e->position_x - (int) $etat->position_x)
                        + abs((int) $e->position_y - (int) $etat->position_y) === 1);

            foreach ($allies as $allie) {
                $options[] = [
                    'id' => "relever_{$allie->personnage_id}",
                    'libelle' => "Relever {$allie->personnage->nom}",
                    'type' => 'relever',
                    'cible_personnage_id' => (int) $allie->personnage_id,
                ];
            }

            // Désamorcer un piège détecté (Nain / trousse à outils).
            if ($detectes !== [] && $this->pieges->peutDesamorcer($personnage)) {
                foreach ($detectes as $adjacent) {
                    $nomPiege = $adjacent['piege']?->nom ?? 'Piège';
                    $options[] = [
                        'id' => "desamorcer_{$adjacent['x']}_{$adjacent['y']}",
                        'libelle' => "Désamorcer {$nomPiege} — jet de Body",
                        'type' => 'desamorcage',
                        'jet' => ['attribut' => 'body', 'difficulte' => ResolveurTour::DIFFICULTE_DESAMORCAGE],
                        'parametres' => ['piege' => ['x' => $adjacent['x'], 'y' => $adjacent['y']]],
                    ];
                }
            }

            // Équiper / ranger une pièce en pleine quête (doc 01 §149) = action
            // du tour. Réutilise l'inventaire réel : « Équiper » les pièces
            // d'équipement du sac, « Ranger » celles portées.
            foreach ($personnage->inventaire()->with('objet')->orderBy('id')->get() as $ligne) {
                $objet = $ligne->objet;
                if ($objet === null || ! in_array($objet->emplacement, Equipement::SLOTS, true)) {
                    continue;
                }

                if ($ligne->emplacement === 'sac') {
                    $options[] = [
                        'id' => "equiper_{$ligne->id}",
                        'libelle' => "Équiper {$objet->nom}",
                        'type' => 'equiper',
                        'parametres' => ['inventaire_id' => (int) $ligne->id],
                    ];
                } elseif (in_array($ligne->emplacement, Equipement::SLOTS, true)) {
                    $options[] = [
                        'id' => "desequiper_{$ligne->id}",
                        'libelle' => "Ranger {$objet->nom}",
                        'type' => 'desequiper',
                        'parametres' => ['inventaire_id' => (int) $ligne->id],
                    ];
                }
            }
        }

        // Sorts / parchemins / concentration = créneau ACTION.
        if (! $aAgi && $etat !== null) {
            foreach ($this->sorts->options($groupe, $quete, $personnage) as $option) {
                $options[] = $option;
            }

            // Ouvrir une porte verrouillée par CLÉ au contact (héros porteur).
            // Actionner un levier au contact (ouvre la porte liée).
            if ($etat->position_x !== null && $quete->carte !== null) {
                $px = (int) $etat->position_x;
                $py = (int) $etat->position_y;

                // Porte close adjacente : simplement fermée → ouverture libre
                // (E2) ; verrouillée à clé → seulement avec la clé. Ouvrir est
                // une INTERACTION : elle ne consomme aucun créneau, on peut
                // reprendre son déplacement juste après.
                $porte = $this->portes->porteFermeeAdjacente($quete->carte, $px, $py);

                if ($porte !== null) {
                    $p = $porte['porte'];
                    $avecCle = ($p['verrou']['type'] ?? null) === 'cle'
                        && $this->portes->possedeCle($personnage, $p['verrou']);

                    if ($this->portes->ouvrableAMain($p) || $avecCle) {
                        $cote = (string) ($p['cote'] ?? 'e');
                        $options[] = [
                            'id' => "ouvrir_porte_{$p['x']}_{$p['y']}_{$cote}",
                            'libelle' => $avecCle ? 'Ouvrir la porte (clé)' : 'Ouvrir la porte',
                            'type' => 'ouvrir_porte',
                            'parametres' => ['porte' => ['x' => (int) $p['x'], 'y' => (int) $p['y'], 'cote' => $cote]],
                        ];
                    }
                }

                foreach ($this->portes->leviersAdjacents($quete->carte, $px, $py) as $levier) {
                    $options[] = [
                        'id' => "actionner_levier_{$levier['x']}_{$levier['y']}",
                        'libelle' => 'Actionner le levier',
                        'type' => 'actionner_levier',
                        'parametres' => ['levier' => ['x' => $levier['x'], 'y' => $levier['y'], 'levier_id' => $levier['levier_id']]],
                    ];
                }
            }

            $options[] = [
                'id' => 'fouiller',
                'libelle' => 'Fouiller la zone — jet de Mind',
                'type' => 'jet',
                'jet' => ['attribut' => 'mind', 'difficulte' => 1],
            ];

            // Fouiller — trésor (doc 14 §3.2) : action SÉPARÉE, table
            // risque/récompense, offerte dans une salle « vide » non encore
            // fouillée (rencontres prévues nettoyées).
            if ($this->salleFouillableTresor($quete, $etat)) {
                $options[] = [
                    'id' => 'fouiller_tresor',
                    'libelle' => 'Fouiller — trésor',
                    'type' => 'fouille_tresor',
                ];
            }
        }

        // Terminer le tour tant qu'il RESTE un créneau (renonce au reste). Une
        // fois le tour joué (a_joue), aucune option : le tour est fini.
        if (! $aJoue) {
            $options[] = ['id' => 'attendre', 'libelle' => 'Terminer le tour', 'type' => 'attente'];
        }

        return [
            'situation' => $aJoue ? 'Tour terminé — au tour des autres héros.' : 'Vous progressez dans le donjon.',
            'options' => $options,
        ];
    }
}
