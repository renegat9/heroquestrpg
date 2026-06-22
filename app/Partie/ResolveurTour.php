<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Deplacement;
use App\Engine\Des\LanceurDes;
use App\Engine\JetCompetence;
use App\Engine\SortMental;
use App\Engine\TypeFigurine;
use App\Events\BarkDiffuse;
use App\Events\EtatGroupeDiffuse;
use App\Events\MjReflechit;
use App\Jobs\GenererNarration;
use App\Partie\Audio\BanqueBarks;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\Sort;
use App\Support\Journal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Models\Condition;

/**
 * Résolution d'une option de menu VALIDÉE pendant une quête (doc 11 §4) —
 * le moteur fait autorité, l'IA ne résout jamais une mécanique :
 *
 *  - deplacement : Engine\Deplacement (base + 1d6) + plus court chemin
 *    orthogonal sur la grille (cases occupées infranchissables) — chaque
 *    case TRAVERSÉE peut déclencher un piège caché (MoteurPieges) ;
 *  - attaque     : Engine\Combat, cible monstre actif ADJACENT (orthogonal) ;
 *  - jet         : Engine\JetCompetence (Body/Mind, difficulté 1-4) — la
 *    fouille réussie révèle les pièges cachés autour du héros ;
 *  - desamorcage / franchissement : options de piège du MenuMoteur (doc 10
 *    §4), jets de Body résolus ici ;
 *  - sort / parchemin / concentration : sorts des héros (doc 02, MoteurSorts) —
 *    degats à DISTANCE via Engine\Combat (tir ami possible, S3), mental via
 *    Engine\SortMental (binaire S2, Mind 0 immunisé), utilitaires en
 *    conditions (`personnage_conditions`) relues ici même ; parchemin
 *    consommé dans TOUS les cas (jet de Mind pour un non-lanceur, S1) ;
 *    concentration (S6) sacrifie le tour pour récupérer un sort épuisé ;
 *  - dialogue / action / attente : journal seulement.
 *
 * Le héros est marqué a_joue ; l'ordre d'initiative figé (C1) est imposé.
 * Quand tous les héros ont joué, la phase des monstres SCRIPTÉS se déroule
 * (C2 : se rapprocher du héros le plus proche, attaquer si adjacent — résolu
 * par le moteur, jamais par le LLM), puis un nouveau tour commence.
 * Fin de quête détectée : tous les monstres vaincus → quête terminée, retour
 * au hub, or du butin du gabarit versé au pot commun — et montée de niveau
 * si la quête est un jalon (sous_boss / boss_final, MonteeNiveau). Tous les
 * héros tombés → quête échouée, retour au hub.
 */
final class ResolveurTour
{
    /** Difficulté du jet de Body pour désamorcer (départ playtest, doc 10 §10). */
    public const DIFFICULTE_DESAMORCAGE = 1;

    /** Difficulté du jet de Body pour franchir une fosse (départ playtest). */
    public const DIFFICULTE_FRANCHISSEMENT = 2;

    public function __construct(
        private readonly LanceurDes $des,
        private readonly EtatGroupe $etatGroupe,
        private readonly MoteurPieges $pieges,
        private readonly MoteurSorts $sorts,
        private readonly MoteurDread $dread,
        private readonly MonteeNiveau $monteeNiveau,
        private readonly ClotureCampagne $cloture,
        private readonly Sauvegarde $sauvegarde,
        private readonly BanqueBarks $barks,
    ) {}

    /**
     * @param  array<string, mixed>  $option  option du dernier menu proposé (déjà validée)
     * @param  array<string, mixed>  $parametres  paramètres du client (ex. destination x/y)
     * @return array<string, mixed> résultat moteur (echo + narration)
     */
    public function resoudre(Groupe $groupe, Personnage $personnage, array $option, array $parametres = []): array
    {
        $quete = $groupe->phase === 'quete' ? $groupe->queteCourante : null;

        if ($quete === null || $quete->etat !== 'en_cours') {
            throw ValidationException::withMessages(['groupe' => 'Aucune quête en cours.']);
        }

        $etats = $quete->etatsPersonnages()->get();
        $etat = $etats->firstWhere('personnage_id', $personnage->id);

        if ($etat === null) {
            throw ValidationException::withMessages(['personnage_id' => 'Ce héros ne participe pas à la quête en cours.']);
        }
        if ($etat->tombe) {
            throw ValidationException::withMessages(['personnage_id' => 'Ce héros est tombé : il ne peut plus agir ce tour.']);
        }
        if ($etat->a_joue) {
            throw ValidationException::withMessages(['personnage_id' => 'Ce héros a déjà agi ce tour.']);
        }

        $this->verifierInitiative($groupe, $quete, $personnage, $etats);

        // Créneau visé (doc 03 §28 : un déplacement + une action par tour) :
        // on refuse de rejouer un créneau déjà consommé ce tour.
        $creneau = $this->creneauOption((string) ($option['type'] ?? ''));
        if ($creneau === 'mouvement' && $etat->a_deplace) {
            throw ValidationException::withMessages(['personnage_id' => 'Tu t\'es déjà déplacé ce tour.']);
        }
        if ($creneau === 'action' && $etat->a_agi) {
            throw ValidationException::withMessages(['personnage_id' => 'Tu as déjà agi ce tour.']);
        }

        $resultat = DB::transaction(function () use ($groupe, $quete, $personnage, $etat, $option, $parametres, $creneau) {
            $acteur = ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom];

            // Endormi (Sommeil de Dread ou sort héros en tir ami) : le héros
            // saute son tour, réveillé uniquement par une attaque subie.
            if ($this->dread->herosSousCondition($personnage, 'Endormi')) {
                $payload = ['type' => 'heros_endormi', 'personnage' => $personnage->nom, 'action' => 'endormi'];
                Journal::ajouter($groupe, 'action', $payload, $acteur);
                $etat->update(['a_joue' => true]);

                return $this->apresActionHeros($payload, $groupe, $quete);
            }

            // Commandé (Commandement de Dread) : le moteur joue à la place du héros.
            if ($this->dread->herosSousCondition($personnage, 'Commandé')) {
                $allies = $quete->etatsPersonnages()
                    ->where('tombe', false)
                    ->with('personnage')
                    ->get()
                    ->filter(fn (EtatPersonnageQuete $e) => (int) $e->personnage_id !== (int) $personnage->id)
                    ->values();

                $payload = $this->dread->jouerHerosSousCommandement(
                    $groupe, $quete, $personnage, $etat, $allies,
                ) ?? ['type' => 'commandement_sans_effet', 'personnage' => $personnage->nom];

                $etat->update(['a_joue' => true]);

                return $this->apresActionHeros($payload, $groupe, $quete);
            }

            // Œil du mineur (nœud nain) : détection automatique des pièges
            // adjacents au début de chaque action du héros (doc 10 §3).
            if ($quete->carte !== null && $etat->position_x !== null) {
                $this->pieges->detecterAdjacents(
                    $groupe, $quete->carte, $personnage,
                    (int) $etat->position_x, (int) $etat->position_y,
                );
            }

            $resultat = match ($option['type']) {
                'deplacement' => $this->resoudreDeplacement($groupe, $quete, $personnage, $etat, $option, $parametres, $acteur),
                'attaque' => $this->resoudreAttaque($groupe, $quete, $etat, $personnage, $option, $parametres, $acteur),
                'jet' => $this->resoudreJet($groupe, $quete, $personnage, $etat, $option, $acteur),
                'desamorcage' => $this->resoudreDesamorcage($groupe, $quete, $personnage, $etat, $option, $acteur),
                'franchissement' => $this->resoudreFranchissement($groupe, $quete, $personnage, $etat, $option, $acteur),
                'sort' => $this->resoudreSort($groupe, $quete, $personnage, $etat, $option, $parametres, $acteur),
                'parchemin' => $this->resoudreParchemin($groupe, $quete, $personnage, $etat, $option, $parametres, $acteur),
                'concentration' => $this->resoudreConcentration($groupe, $personnage, $option, $parametres, $acteur),
                'relever' => $this->resoudreRelever($groupe, $quete, $personnage, $etat, $option, $acteur),
                default => $this->resoudreNarratif($groupe, $option, $acteur),
            };

            // Consomme le créneau (mouvement/action) ; le tour ne se termine
            // que quand les DEUX créneaux sont faits, ou via une action terminante.
            $this->marquerCreneau($etat, $creneau);

            // Entrée dans une salle encore inexplorée (déplacement classique OU
            // Traverser la Pierre) → description de la nouvelle salle par le MJ.
            $this->decouvrirSalle($groupe, $quete, $etat);

            // Fin de quête : plus aucun monstre actif → victoire.
            if (! $quete->instancesMonstres()->where('etat', 'actif')->exists()) {
                $resultat['quete'] = $this->terminerQuete($groupe, $quete);

                return $resultat;
            }

            // Tous les héros ont joué (ou sont tombés) → phase des monstres (C2).
            $enAttente = $quete->etatsPersonnages()
                ->where('a_joue', false)
                ->where('tombe', false)
                ->exists();

            if (! $enAttente) {
                $resultat['tour_monstres'] = $this->phaseMonstres($groupe, $quete);
            }

            return $resultat;
        });

        // Toute mutation d'état → journal (fait au fil de l'eau) puis broadcast.
        broadcast(new EtatGroupeDiffuse($groupe, $this->etatGroupe->payload($groupe->fresh())));

        return $resultat;
    }

    /**
     * Ordre d'initiative figé (C1) : seul le prochain héros debout n'ayant
     * pas joué peut agir.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $etats
     */
    private function verifierInitiative(Groupe $groupe, Quete $quete, Personnage $personnage, Collection $etats): void
    {
        $ordres = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->pluck('personnages.id');

        foreach ($ordres as $id) {
            $etatHeros = $etats->firstWhere('personnage_id', $id);

            if ($etatHeros === null || $etatHeros->a_joue || $etatHeros->tombe) {
                continue;
            }

            if ((int) $id === (int) $personnage->id) {
                return; // c'est bien son tour
            }

            throw ValidationException::withMessages([
                'personnage_id' => 'Ce n\'est pas le tour de ce héros (ordre d\'initiative figé pour la quête).',
            ]);
        }

        throw ValidationException::withMessages(['personnage_id' => 'Aucun héros en attente ce tour.']);
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreDeplacement(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $parametres,
        array $acteur,
    ): array {
        $x = $parametres['x'] ?? null;
        $y = $parametres['y'] ?? null;

        if (! is_numeric($x) || ! is_numeric($y)) {
            throw ValidationException::withMessages(['parametres' => 'Destination requise : parametres.x et parametres.y (entiers).']);
        }

        $x = (int) $x;
        $y = (int) $y;

        // Allonce du tour : le d6 a été lancé à la génération du menu et MÉMORISÉ
        // (le joueur l'a vu avant de choisir sa case). Repli : lancer si absent.
        $base = (int) $personnage->deplacement_base;
        $totalTour = $etat->deplacement_tour ?? (new Deplacement($this->des))->calculer($base)->total;
        $deDuTour = $totalTour > $base ? $totalTour - $base : null;

        // Vent Véloce (doc 02 §7) : déplacement ×2, buff consommé à l'usage.
        $multiplicateur = $this->sorts->multiplicateurDeplacement($personnage);
        $total = $totalTour * $multiplicateur;

        $grille = $this->grille($quete, exceptPersonnageId: $personnage->id);
        $chemin = $grille->chemin((int) $etat->position_x, (int) $etat->position_y, $x, $y);

        if ($chemin === null || $chemin === []) {
            throw ValidationException::withMessages(['parametres' => 'Destination inaccessible (mur, case occupée ou sur place).']);
        }

        $distance = count($chemin);

        if ($distance > $total) {
            throw ValidationException::withMessages([
                'parametres' => "Destination hors de portée : {$distance} cases pour {$total} de déplacement.",
            ]);
        }

        if ($multiplicateur > 1) {
            $this->sorts->consommerBuffs($personnage, 'deplacement_multiplie');
        }

        // Pièges cachés sur les cases TRAVERSÉES (chemin BFS, arrivée incluse) :
        // déclenchement immédiat ; une fosse (ou un héros tombé) arrête le
        // déplacement sur la case du piège (doc 10 §5).
        $controle = $this->pieges->controlerChemin($groupe, $quete->carte, $personnage, $etat, $chemin);
        $arrivee = $controle['arret'] ?? ['x' => $x, 'y' => $y];

        $etat->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);

        // Œil du mineur : les pièges adjacents à la case d'arrivée sont
        // auto-détectés (doc 10 §3).
        if (! $etat->tombe) {
            $this->pieges->detecterAdjacents($groupe, $quete->carte, $personnage, $arrivee['x'], $arrivee['y']);
        }

        $payload = [
            'type' => 'deplacement',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'de' => $deDuTour,
            'deplacement_total' => $total,
            'multiplicateur_sort' => $multiplicateur,
            'distance' => $distance,
            'vers' => $arrivee,
            'interrompu' => $controle['arret'] !== null,
            'pieges_declenches' => $controle['declenchements'],
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreAttaque(
        Groupe $groupe,
        Quete $quete,
        EtatPersonnageQuete $etat,
        Personnage $personnage,
        array $option,
        array $parametres,
        array $acteur,
    ): array {
        $cibleId = (int) ($option['cible_id'] ?? $parametres['cible_id'] ?? 0);

        $instance = $quete->instancesMonstres()
            ->whereKey($cibleId)
            ->where('etat', 'actif')
            ->with('monstre')
            ->first();

        if ($instance === null) {
            throw ValidationException::withMessages(['option_id' => 'Cible invalide : ce monstre n\'est pas actif dans la quête.']);
        }

        $adjacentes = abs((int) $etat->position_x - (int) $instance->position_x)
            + abs((int) $etat->position_y - (int) $instance->position_y) === 1;

        if (! $adjacentes) {
            throw ValidationException::withMessages(['option_id' => 'Cible hors de portée : l\'attaque exige une case adjacente.']);
        }

        // Courage (doc 02 §7) : +2 dés à la PROCHAINE attaque, consommé ici.
        $bonusAttaque = $this->sorts->bonusDes($personnage, 'bonus_des_attaque');

        // Frayeur (Dread) : condition Apeuré → −1 dé d'attaque (min 0), 2 tours.
        $malusFrayeur = $this->dread->malusDesAttaqueFrayeur($personnage);

        $desAttaqueEffectifs = max(0, (int) $personnage->des_attaque + $bonusAttaque - $malusFrayeur);

        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: $desAttaqueEffectifs,
            desDefense: (int) $instance->monstre->defense,
            typeDefenseur: TypeFigurine::Monstre,
            pvBodyDefenseur: (int) $instance->pv_body,
        );

        if ($bonusAttaque > 0) {
            $this->sorts->consommerBuffs($personnage, 'bonus_des_attaque');
        }

        $instance->update([
            'pv_body' => $resultat->pvBodyApres,
            'etat' => $resultat->pvBodyApres === 0 ? 'vaincu' : 'actif',
        ]);

        // Une attaque réveille un monstre endormi (Sommeil, doc 02 §7).
        $this->sorts->retirerConditionMonstre($instance, MoteurSorts::MONSTRE_ENDORMI);

        $payload = [
            'type' => 'attaque',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'bonus_des_attaque' => $bonusAttaque,
            'malus_frayeur' => $malusFrayeur,
            'des_attaque_effectifs' => $desAttaqueEffectifs,
            'cible' => [
                'instance_id' => $instance->id,
                'nom' => $instance->habillage['nom'] ?? $instance->monstre->nom_base,
            ],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $resultat->degats,
            'pv_body_apres' => $resultat->pvBodyApres,
            'cible_vaincue' => $resultat->pvBodyApres === 0,
            'faces_attaque' => array_map(fn ($face) => $face->value, $resultat->facesAttaque),
            'faces_defense' => array_map(fn ($face) => $face->value, $resultat->facesDefense),
        ];

        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        // Bark d'ambiance du monstre touché (mort / blessé / paré), best-effort.
        $this->diffuserBark($groupe, $instance,
            $resultat->pvBodyApres === 0 ? 'mort' : ($resultat->degats > 0 ? 'touche' : 'rate'));

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreJet(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $acteur,
    ): array {
        $attribut = $option['jet']['attribut'] ?? null;
        $difficulte = (int) ($option['jet']['difficulte'] ?? 0);

        if (! in_array($attribut, ['body', 'mind'], true) || $difficulte < 1 || $difficulte > 4) {
            throw ValidationException::withMessages(['option_id' => 'Option de jet invalide (attribut body|mind, difficulté 1-4).']);
        }

        $nbDes = $attribut === 'body' ? (int) $personnage->attribut_body : (int) $personnage->attribut_mind;
        $resultat = (new JetCompetence($this->des))->resoudre($nbDes, $difficulte);

        $payload = [
            'type' => 'jet',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'attribut' => $attribut,
            'difficulte' => $difficulte,
            'des_lances' => $nbDes,
            'succes' => $resultat->succes,
            'issue' => $resultat->issue->value,
            'faces' => array_map(fn ($face) => $face->value, $resultat->faces),
        ];

        // Fouille RÉUSSIE : les pièges cachés dans le rayon de fouille
        // autour du héros sont révélés (doc 10 §3, MoteurPieges).
        if ($option['id'] === 'fouiller' && $resultat->estReussi()
            && $quete->carte !== null && $etat->position_x !== null) {
            $payload['pieges_reveles'] = $this->pieges->revelerAutour(
                $groupe, $quete->carte, $personnage,
                (int) $etat->position_x, (int) $etat->position_y,
            );
        }

        Journal::ajouter($groupe, 'jet', $payload, $acteur);

        return $payload;
    }

    /**
     * Désamorcer un piège DÉTECTÉ adjacent (doc 10 §4) : réservé au Nain ou
     * au porteur d'un objet `permet_desamorcage` (Trousse à outils), jet de
     * Body difficulté 1 — succès : piège désarmé ; échec : il se déclenche
     * sur le désamorceur (choix MVP, question ouverte n°3).
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreDesamorcage(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $acteur,
    ): array {
        $cible = $this->piegeCible($quete, $etat, $option);

        if (! $this->pieges->peutDesamorcer($personnage)) {
            throw ValidationException::withMessages([
                'option_id' => 'Désamorçage réservé au Nain ou au porteur d\'une trousse à outils.',
            ]);
        }

        $resultat = (new JetCompetence($this->des))
            ->resoudre((int) $personnage->attribut_body, self::DIFFICULTE_DESAMORCAGE);

        if ($resultat->estReussi()) {
            $this->pieges->changerEtat($quete->carte, $cible['index'], MoteurPieges::ETAT_DESARME);
        }

        $payload = [
            'type' => 'desamorcage',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'piege' => ['nom' => $cible['piege']?->nom ?? 'Piège', 'x' => $cible['x'], 'y' => $cible['y']],
            'attribut' => 'body',
            'difficulte' => self::DIFFICULTE_DESAMORCAGE,
            'des_lances' => (int) $personnage->attribut_body,
            'succes' => $resultat->succes,
            'issue' => $resultat->issue->value,
            'faces' => array_map(fn ($face) => $face->value, $resultat->faces),
            'desarme' => $resultat->estReussi(),
        ];

        // Échec : le piège se déclenche sur le désamorceur (doc 10 §4).
        if (! $resultat->estReussi()) {
            $payload['declenchement'] = $this->pieges->declencher(
                $groupe, $quete->carte, $cible['index'], $personnage, $etat, 'desamorcage_rate',
            );
        }

        Journal::ajouter($groupe, 'jet', $payload, $acteur);

        return $payload;
    }

    /**
     * Franchir une fosse DÉTECTÉE adjacente (doc 10 §4) : jet de Body
     * difficulté 2 (départ playtest) — succès : le héros atterrit de l'autre
     * côté (case libre exigée) ; échec : chute, effet de la fosse, le héros
     * reste sur sa case.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreFranchissement(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $acteur,
    ): array {
        $cible = $this->piegeCible($quete, $etat, $option);

        if (! $this->pieges->estFosse($cible['piege'])) {
            throw ValidationException::withMessages([
                'option_id' => 'Seule une fosse détectée peut être franchie.',
            ]);
        }

        // Case de réception : le prolongement de l'élan, de l'autre côté de
        // la fosse (héros → fosse → réception, alignés).
        $arrivee = [
            'x' => 2 * $cible['x'] - (int) $etat->position_x,
            'y' => 2 * $cible['y'] - (int) $etat->position_y,
        ];

        if (! $this->grille($quete, exceptPersonnageId: $personnage->id)
            ->estTraversable($arrivee['x'], $arrivee['y'])) {
            throw ValidationException::withMessages([
                'option_id' => 'Impossible de franchir : la case de réception n\'est pas libre.',
            ]);
        }

        $resultat = (new JetCompetence($this->des))
            ->resoudre((int) $personnage->attribut_body, self::DIFFICULTE_FRANCHISSEMENT);

        $payload = [
            'type' => 'franchissement',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'piege' => ['nom' => $cible['piege']?->nom ?? 'Fosse', 'x' => $cible['x'], 'y' => $cible['y']],
            'attribut' => 'body',
            'difficulte' => self::DIFFICULTE_FRANCHISSEMENT,
            'des_lances' => (int) $personnage->attribut_body,
            'succes' => $resultat->succes,
            'issue' => $resultat->issue->value,
            'faces' => array_map(fn ($face) => $face->value, $resultat->faces),
            'franchi' => $resultat->estReussi(),
        ];

        if ($resultat->estReussi()) {
            $etat->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);

            // Œil du mineur : détection automatique autour de la réception.
            $this->pieges->detecterAdjacents($groupe, $quete->carte, $personnage, $arrivee['x'], $arrivee['y']);

            $payload['vers'] = $arrivee;
        } else {
            // Chute : le héros tombe DANS la fosse (effet du catalogue) et
            // y reste — la fosse persistante demeure en jeu (doc 10 §5).
            $etat->update(['position_x' => $cible['x'], 'position_y' => $cible['y']]);

            $payload['declenchement'] = $this->pieges->declencher(
                $groupe, $quete->carte, $cible['index'], $personnage, $etat, 'franchissement_rate',
            );
            $payload['vers'] = ['x' => $cible['x'], 'y' => $cible['y']];
        }

        Journal::ajouter($groupe, 'jet', $payload, $acteur);

        return $payload;
    }

    /**
     * Lancer un sort CONNU et DISPONIBLE (doc 02 §4-5) : résolution par type
     * (degats / mental / utilitaire), puis le sort est ÉPUISÉ pour la quête
     * (pivot personnage_sorts.disponible = false). Aucune adjacence requise :
     * les sorts se lancent à distance.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreSort(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $parametres,
        array $acteur,
    ): array {
        $sort = $this->sortDeLOption($option);

        $connu = $personnage->sorts()->whereKey($sort->id)->first();

        if ($connu === null || ! $connu->pivot->disponible) {
            throw ValidationException::withMessages([
                'option_id' => 'Sort inconnu ou épuisé : chaque sort est lançable une fois par quête (S5).',
            ]);
        }

        $payload = [
            'type' => 'sort',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'sort' => ['id' => $sort->id, 'nom' => $sort->nom, 'element' => $sort->element, 'type' => $sort->type],
            ...$this->lancerSort($quete, $personnage, $etat, $sort, $option, $parametres),
        ];

        $personnage->sorts()->updateExistingPivot($sort->id, ['disponible' => false]);

        Journal::ajouter($groupe, $sort->type === 'degats' ? 'combat' : 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Utiliser un parchemin du sac (doc 02 §6, S1/S4) : lanceur (magicien /
     * elfe) → réussite automatique ; non-lanceur → jet de Mind à la
     * difficulté du sort (1-3). CONSOMMÉ dans tous les cas — échec =
     * gaspillé. La résolution de l'effet est celle du sort (sans toucher au
     * répertoire du héros).
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreParchemin(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $parametres,
        array $acteur,
    ): array {
        $ligne = $personnage->inventaire()
            ->with('objet')
            ->whereKey((int) data_get($option, 'parametres.inventaire_id', 0))
            ->first();

        $sort = $ligne === null ? null : Sort::find(data_get($ligne->objet?->effet, 'sort_id'));

        if ($sort === null) {
            throw ValidationException::withMessages(['option_id' => 'Parchemin introuvable dans le sac de ce héros.']);
        }

        $estLanceur = in_array($personnage->classe, MoteurSorts::LANCEURS, true);

        $payload = [
            'type' => 'parchemin',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'sort' => ['id' => $sort->id, 'nom' => $sort->nom, 'element' => $sort->element, 'type' => $sort->type],
            'lanceur_de_sorts' => $estLanceur,
        ];

        $reussi = true;

        if (! $estLanceur) {
            $difficulte = max(1, (int) $sort->difficulte_parchemin);
            $jet = (new JetCompetence($this->des))->resoudre((int) $personnage->attribut_mind, $difficulte);
            $reussi = $jet->estReussi();

            $payload['jet'] = [
                'attribut' => 'mind',
                'difficulte' => $difficulte,
                'des_lances' => (int) $personnage->attribut_mind,
                'succes' => $jet->succes,
                'issue' => $jet->issue->value,
                'faces' => array_map(fn ($face) => $face->value, $jet->faces),
            ];
        }

        if ($reussi) {
            $payload += $this->lancerSort($quete, $personnage, $etat, $sort, $option, $parametres);
        }

        // Consommé dans TOUS les cas (S1) — échec = parchemin gaspillé.
        (int) $ligne->quantite > 1 ? $ligne->decrement('quantite') : $ligne->delete();
        $payload['consomme'] = true;
        $payload['gaspille'] = ! $reussi;

        Journal::ajouter($groupe, $reussi && $sort->type === 'degats' ? 'combat' : 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * « Se concentrer » (S6, nœud magicien) : sacrifie le tour (a_joue est
     * marqué par l'appelant) pour rendre disponible UN sort épuisé au choix
     * (parametres.sort_id) — une seule fois par quête (marqueur en cache,
     * réarmé par DemarreurQuete).
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreConcentration(
        Groupe $groupe,
        Personnage $personnage,
        array $option,
        array $parametres,
        array $acteur,
    ): array {
        if (! $this->sorts->concentrationDisponible($groupe, $personnage)) {
            throw ValidationException::withMessages([
                'option_id' => 'Concentration indisponible : nœud magicien requis, une seule fois par quête (S6).',
            ]);
        }

        $sort = $personnage->sorts()->whereKey((int) ($parametres['sort_id'] ?? 0))->first();

        if ($sort === null || $sort->pivot->disponible) {
            throw ValidationException::withMessages([
                'parametres' => 'Choisissez un sort ÉPUISÉ à récupérer : parametres.sort_id.',
            ]);
        }

        $personnage->sorts()->updateExistingPivot($sort->id, ['disponible' => true]);
        $this->sorts->marquerConcentrationUtilisee($groupe, $personnage);

        $payload = [
            'type' => 'concentration',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'sort_recupere' => ['id' => $sort->id, 'nom' => $sort->nom],
            'tour_sacrifie' => true,
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Résolution de l'EFFET d'un sort (commune au sort connu et au
     * parchemin) — par type de catalogue.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    private function lancerSort(
        Quete $quete,
        Personnage $lanceur,
        EtatPersonnageQuete $etat,
        Sort $sort,
        array $option,
        array $parametres,
    ): array {
        return match ($sort->type) {
            'degats' => $this->sortDegats($quete, $sort, $option, $parametres),
            'mental' => $this->sortMental($quete, $sort, $option, $parametres),
            default => $this->sortUtilitaire($quete, $lanceur, $etat, $sort, $option, $parametres),
        };
    }

    /**
     * Sort de dégâts (Boule de Feu, Trait de Feu, Génie) : dés de combat de
     * l'effet JSON du catalogue contre la défense de la cible (règles de
     * combat de base), À DISTANCE — et tir ami possible (S3) : un héros visé
     * se défend exactement comme face à un monstre.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    private function sortDegats(Quete $quete, Sort $sort, array $option, array $parametres): array
    {
        $des = (int) data_get($sort->effet, 'des_degats', MoteurSorts::DES_DEGATS_DEFAUT[$sort->nom] ?? 1);
        $cible = $this->cibleSort($quete, $option, $parametres);

        if ($cible['type'] === 'monstre') {
            /** @var InstanceMonstre $instance */
            $instance = $cible['monstre'];

            // Résistance magique (capacité boss) : +2 dés de défense contre les sorts de dégâts.
            $bonusResistance = $this->dread->bonusDefenseResistanceMagique($instance);

            $resultat = (new Combat($this->des))->resoudreAttaque(
                desAttaque: $des,
                desDefense: (int) $instance->monstre->defense + $bonusResistance,
                typeDefenseur: TypeFigurine::Monstre,
                pvBodyDefenseur: (int) $instance->pv_body,
            );

            $instance->update([
                'pv_body' => $resultat->pvBodyApres,
                'etat' => $resultat->pvBodyApres === 0 ? 'vaincu' : 'actif',
            ]);

            // Être attaqué réveille un monstre endormi (doc 02 §7).
            $this->sorts->retirerConditionMonstre($instance, MoteurSorts::MONSTRE_ENDORMI);

            return [
                'des_degats' => $des,
                'bonus_resistance_magique' => $bonusResistance,
                'cible' => [
                    'type' => 'monstre',
                    'instance_id' => $instance->id,
                    'nom' => $instance->habillage['nom'] ?? $instance->monstre->nom_base,
                ],
                'touches' => $resultat->touches,
                'boucliers' => $resultat->boucliers,
                'degats' => $resultat->degats,
                'pv_body_apres' => $resultat->pvBodyApres,
                'cible_vaincue' => $resultat->pvBodyApres === 0,
                'faces_attaque' => array_map(fn ($face) => $face->value, $resultat->facesAttaque),
                'faces_defense' => array_map(fn ($face) => $face->value, $resultat->facesDefense),
            ];
        }

        /** @var Personnage $heros */
        $heros = $cible['personnage'];

        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: $des,
            desDefense: (int) $heros->des_defense + $this->sorts->bonusDes($heros, 'bonus_des_defense'),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $heros->pv_body,
        );

        $heros->update(['pv_body' => $resultat->pvBodyApres]);
        $this->sorts->reveillerHeros($heros); // être attaqué réveille

        if ($resultat->cibleTombee) {
            $cible['etat']->update(['tombe' => true]); // C4
        }

        return [
            'des_degats' => $des,
            'tir_ami' => true,
            'cible' => ['type' => 'heros', 'personnage_id' => $heros->id, 'nom' => $heros->nom],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $resultat->degats,
            'pv_body_apres' => $resultat->pvBodyApres,
            'cible_tombee' => $resultat->cibleTombee,
            'faces_attaque' => array_map(fn ($face) => $face->value, $resultat->facesAttaque),
            'faces_defense' => array_map(fn ($face) => $face->value, $resultat->facesDefense),
        ];
    }

    /**
     * Sort mental (Sommeil, Tempête — S2 binaire) : la cible résiste avec un
     * jet de Mind (PV de Mind pour un monstre, attribut Mind pour un héros ;
     * Mind 0 = immunisé). Échec → condition :
     *  - monstre endormi : ne joue plus tant qu'il n'est pas attaqué ;
     *  - monstre sous Tempête : n'attaque pas à son prochain tour ;
     *  - héros (tir ami) : condition du catalogue posée (Endormi / Étourdi),
     *    levée à l'attaque pour Endormi — sans blocage d'action côté héros
     *    au MVP (documenté).
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    private function sortMental(Quete $quete, Sort $sort, array $option, array $parametres): array
    {
        $cible = $this->cibleSort($quete, $option, $parametres);

        $mind = $cible['type'] === 'monstre'
            ? (int) $cible['monstre']->pv_mind
            : (int) $cible['personnage']->attribut_mind;

        $resultat = (new SortMental($this->des))->resoudre($mind);

        $payload = [
            'cible' => $cible['type'] === 'monstre'
                ? [
                    'type' => 'monstre',
                    'instance_id' => $cible['monstre']->id,
                    'nom' => $cible['monstre']->habillage['nom'] ?? $cible['monstre']->monstre->nom_base,
                ]
                : ['type' => 'heros', 'personnage_id' => $cible['personnage']->id, 'nom' => $cible['personnage']->nom],
            'mind_cible' => $mind,
            'issue' => $resultat->issue->value,
            'succes' => $resultat->succes,
            'difficulte' => $resultat->difficulte,
            'faces' => array_map(fn ($face) => $face->value, $resultat->faces),
            'effet_applique' => $resultat->effetApplique(),
        ];

        if (! $resultat->effetApplique()) {
            return $payload;
        }

        $conditionNom = data_get($sort->effet, 'condition_appliquee');

        if ($cible['type'] === 'monstre') {
            if ($conditionNom === 'Endormi') {
                $this->sorts->poserConditionMonstre($cible['monstre'], MoteurSorts::MONSTRE_ENDORMI);
            }
            if ((bool) data_get($sort->effet, 'empeche_attaque', false)) {
                $this->sorts->poserConditionMonstre($cible['monstre'], MoteurSorts::MONSTRE_EMPECHE_ATTAQUE);
                $conditionNom ??= 'Étourdi';
            }
        } else {
            $conditionNom ??= 'Étourdi'; // Tempête côté héros : perd_prochain_tour (catalogue)
            $this->sorts->appliquerConditionCatalogue($cible['personnage'], $conditionNom, $sort);
        }

        $payload['condition'] = $conditionNom;

        return $payload;
    }

    /**
     * Sort utilitaire (effet direct, sans opposition — doc 02 §5) : soins
     * plafonnés, Traverser la Pierre, ou buff posé en personnage_conditions
     * (source `sort:{Nom}`) et relu aux résolutions d'attaque / défense /
     * déplacement.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    private function sortUtilitaire(
        Quete $quete,
        Personnage $lanceur,
        EtatPersonnageQuete $etat,
        Sort $sort,
        array $option,
        array $parametres,
    ): array {
        $effet = $sort->effet ?? [];

        // Soin du Corps / Eau de Guérison : +4 PV Body, PLAFONNÉ au maximum.
        if (isset($effet['soin_pv_body'])) {
            $cible = $this->cibleSort($quete, $option, $parametres);
            /** @var Personnage $heros */
            $heros = $cible['personnage'];

            $avant = (int) $heros->pv_body;
            $apres = min((int) $heros->pv_body_max, $avant + (int) $effet['soin_pv_body']);
            $heros->update(['pv_body' => $apres]);

            // Un héros tombé soigné au-dessus de 0 PV est relevé (C4).
            if ($apres > 0 && $cible['etat']->tombe) {
                $cible['etat']->update(['tombe' => false]);
            }

            return [
                'cible' => ['type' => 'heros', 'personnage_id' => $heros->id, 'nom' => $heros->nom],
                'soin' => $apres - $avant,
                'pv_body_apres' => $apres,
            ];
        }

        // Traverser la Pierre : franchir UN mur adjacent — vaut le déplacement.
        if (isset($effet['franchit_mur'])) {
            return $this->franchirMur($quete, $lanceur, $etat, $parametres);
        }

        // Buff (Courage, Peau de Pierre, Voile de Brume, Vent Véloce) : cible
        // héros si le sort est ciblé, sinon le lanceur lui-même.
        $cibleBuff = isset($option['parametres']['cibles'])
            ? $this->cibleSort($quete, $option, $parametres)['personnage']
            : $lanceur;

        $condition = $this->sorts->appliquerBuff($cibleBuff, $sort);

        return [
            'cible' => ['type' => 'heros', 'personnage_id' => $cibleBuff->id, 'nom' => $cibleBuff->nom],
            'condition' => $condition->nom,
            'source' => MoteurSorts::PREFIXE_SOURCE.$sort->nom,
        ];
    }

    /**
     * Traverser la Pierre (doc 02 §7) : le héros passe de l'autre côté d'un
     * mur ORTHOGONALEMENT adjacent — destination à 2 cases, mur au milieu,
     * case de sortie libre. « Vaut son déplacement » (l'action du tour).
     *
     * @param  array<string, mixed>  $parametres
     * @return array<string, mixed>
     */
    private function franchirMur(Quete $quete, Personnage $personnage, EtatPersonnageQuete $etat, array $parametres): array
    {
        $x = $parametres['x'] ?? null;
        $y = $parametres['y'] ?? null;

        if (! is_numeric($x) || ! is_numeric($y)) {
            throw ValidationException::withMessages([
                'parametres' => 'Destination requise de l\'autre côté du mur : parametres.x et parametres.y.',
            ]);
        }

        $x = (int) $x;
        $y = (int) $y;
        $dx = $x - (int) $etat->position_x;
        $dy = $y - (int) $etat->position_y;

        if (! (abs($dx) === 2 && $dy === 0) && ! ($dx === 0 && abs($dy) === 2)) {
            throw ValidationException::withMessages([
                'parametres' => 'Traverser la Pierre : destination à 2 cases orthogonales, derrière un mur adjacent.',
            ]);
        }

        $murX = (int) $etat->position_x + intdiv($dx, 2);
        $murY = (int) $etat->position_y + intdiv($dy, 2);
        $cases = $quete->carte?->grille['cases'] ?? [];

        if (($cases[$murY][$murX] ?? null) !== 'm') {
            throw ValidationException::withMessages(['parametres' => 'Aucun mur à traverser dans cette direction.']);
        }

        if (! $this->grille($quete, exceptPersonnageId: $personnage->id)->estTraversable($x, $y)) {
            throw ValidationException::withMessages(['parametres' => 'La case de sortie derrière le mur n\'est pas libre.']);
        }

        $etat->update(['position_x' => $x, 'position_y' => $y]);

        return ['mur' => ['x' => $murX, 'y' => $murY], 'vers' => ['x' => $x, 'y' => $y]];
    }

    /**
     * Cible d'un sort : parametres.cible_id (+ cible_type monstre|heros si
     * un monstre et un héros partagent le même id) doit figurer dans les
     * CIBLES LÉGALES de l'option (le menu fait autorité, S3 : les héros y
     * figurent pour les sorts offensifs — tir ami).
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @return array{type: string, monstre?: InstanceMonstre, personnage?: Personnage, etat?: EtatPersonnageQuete}
     */
    private function cibleSort(Quete $quete, array $option, array $parametres): array
    {
        $cibleId = (int) ($parametres['cible_id'] ?? 0);
        $cibleType = $parametres['cible_type'] ?? null;

        $candidats = array_values(array_filter(
            (array) data_get($option, 'parametres.cibles', []),
            fn ($c) => (int) ($c['id'] ?? 0) === $cibleId
                && ($cibleType === null || ($c['type'] ?? null) === $cibleType),
        ));

        if ($cibleId < 1 || count($candidats) !== 1) {
            throw ValidationException::withMessages([
                'parametres' => 'Cible requise : parametres.cible_id (et cible_type monstre|heros si ambigu) parmi les cibles légales du sort.',
            ]);
        }

        if ($candidats[0]['type'] === 'monstre') {
            $instance = $quete->instancesMonstres()
                ->whereKey($cibleId)
                ->where('etat', 'actif')
                ->with('monstre')
                ->first();

            if ($instance === null) {
                throw ValidationException::withMessages(['parametres' => 'Cible invalide : ce monstre n\'est plus actif.']);
            }

            return ['type' => 'monstre', 'monstre' => $instance];
        }

        $etatCible = $quete->etatsPersonnages()
            ->where('personnage_id', $cibleId)
            ->with('personnage')
            ->first();

        if ($etatCible === null) {
            throw ValidationException::withMessages(['parametres' => 'Cible invalide : ce héros ne participe pas à la quête.']);
        }

        return ['type' => 'heros', 'personnage' => $etatCible->personnage, 'etat' => $etatCible];
    }

    /** Sort du catalogue pointé par l'option (parametres.sort_id du menu). */
    private function sortDeLOption(array $option): Sort
    {
        return Sort::find((int) data_get($option, 'parametres.sort_id', 0))
            ?? throw ValidationException::withMessages(['option_id' => 'Option de sort invalide (sort_id inconnu).']);
    }

    /**
     * Piège visé par une option Désamorcer / Franchir : il doit être DÉTECTÉ
     * et orthogonalement adjacent au héros (parametres.piege du MenuMoteur).
     *
     * @param  array<string, mixed>  $option
     * @return array{index: int, x: int, y: int, piege: Piege|null}
     */
    private function piegeCible(Quete $quete, EtatPersonnageQuete $etat, array $option): array
    {
        $x = (int) data_get($option, 'parametres.piege.x', -1);
        $y = (int) data_get($option, 'parametres.piege.y', -1);

        if ($quete->carte !== null && $etat->position_x !== null) {
            foreach ($this->pieges->detectesAdjacents($quete->carte, (int) $etat->position_x, (int) $etat->position_y) as $candidat) {
                if ($candidat['x'] === $x && $candidat['y'] === $y) {
                    return $candidat;
                }
            }
        }

        throw ValidationException::withMessages([
            'option_id' => 'Aucun piège détecté adjacent à cette position.',
        ]);
    }

    /**
     * Dialogue / action narrative / attente : journal seulement, la mise en
     * récit revient au MJ IA (avec repli neutre).
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreNarratif(Groupe $groupe, array $option, array $acteur): array
    {
        $payload = [
            'type' => $option['type'],
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
        ];

        Journal::ajouter($groupe, 'choix', $payload, $acteur);

        return $payload;
    }

    /**
     * Relever un allié TOMBÉ adjacent (doc 03 §48 : relevable par un allié) :
     * le héros sacrifie son tour, l'allié se remet debout à 1 PV de Body et
     * libère sa case. Empêche le blocage d'un couloir par une figure tombée.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreRelever(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $acteur,
    ): array {
        $cibleId = (int) ($option['cible_personnage_id'] ?? 0);

        $cible = $quete->etatsPersonnages()
            ->where('personnage_id', $cibleId)
            ->where('tombe', true)
            ->with('personnage')
            ->first();

        if ($cible === null) {
            throw ValidationException::withMessages(['option_id' => 'Aucun allié tombé à relever.']);
        }

        $adjacent = abs((int) $etat->position_x - (int) $cible->position_x)
            + abs((int) $etat->position_y - (int) $cible->position_y) === 1;

        if (! $adjacent) {
            throw ValidationException::withMessages(['option_id' => 'L\'allié tombé doit être sur une case adjacente.']);
        }

        // Debout à 1 PV de Body ; la figure n'occupe plus la case en « tombée ».
        $cible->update(['tombe' => false]);
        $cible->personnage->update(['pv_body' => 1]);

        $payload = [
            'type' => 'relever',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'cible' => ['personnage_id' => $cible->personnage_id, 'nom' => $cible->personnage->nom],
            'pv_body' => 1,
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Logique partagée après qu'un héros a joué (via une condition ou une
     * action normale) : détection fin de quête + déclenchement phase monstres.
     *
     * @param  array<string, mixed>  $resultat
     * @return array<string, mixed>
     */
    private function apresActionHeros(array $resultat, Groupe $groupe, Quete $quete): array
    {
        if (! $quete->instancesMonstres()->where('etat', 'actif')->exists()) {
            $resultat['quete'] = $this->terminerQuete($groupe, $quete);

            return $resultat;
        }

        $enAttente = $quete->etatsPersonnages()
            ->where('a_joue', false)
            ->where('tombe', false)
            ->exists();

        if (! $enAttente) {
            $resultat['tour_monstres'] = $this->phaseMonstres($groupe, $quete);
        }

        return $resultat;
    }

    /**
     * Phase des monstres SCRIPTÉS (C2), résolue par le moteur : chaque
     * monstre actif rejoint le héros debout le plus proche (déplacement fixe
     * du catalogue, chemin orthogonal) puis attaque s'il est adjacent.
     * Termine le tour : a_joue est réinitialisé (nouveau tour des héros).
     *
     * @return array<string, mixed>
     */
    private function phaseMonstres(Groupe $groupe, Quete $quete): array
    {
        $actions = [];

        foreach ($quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)->with('monstre')->orderBy('id')->get() as $instance) {
            $cibles = $quete->etatsPersonnages()->where('tombe', false)->with('personnage')->get()
                // Voile de Brume : un héros caché (condition « inattaquable »)
                // est ignoré du ciblage jusqu'à son prochain tour.
                ->reject(fn (EtatPersonnageQuete $c) => $this->sorts->estInattaquable($c->personnage))
                ->values();

            if ($cibles->isEmpty()) {
                break; // plus personne debout (ou tout le monde est caché)
            }

            $resultatMonstre = $this->jouerMonstre($groupe, $quete, $instance, $cibles);

            // Si le monstre a joué plusieurs actions (p. ex. régénération + sort/attaque),
            // elles sont encapsulées sous 'type'='actions_composites' → on les étale.
            if (($resultatMonstre['type'] ?? null) === 'actions_composites') {
                foreach ($resultatMonstre['actions'] as $action) {
                    $actions[] = $action;
                }
            } else {
                $actions[] = $resultatMonstre;
            }
        }

        // Nouveau tour : les héros debout rejouent (l'initiative reste figée, C1).
        // Nouveau tour : créneaux remis à zéro + on relancera le d6 de déplacement.
        $quete->etatsPersonnages()->update([
            'a_joue' => false, 'a_deplace' => false, 'a_agi' => false, 'deplacement_tour' => null,
        ]);

        // Fin de tour : décompte des durées des conditions de sorts des héros
        // (Caché expire ici ; Vent Véloce survit jusqu'au déplacement suivant).
        $this->sorts->decrementerDurees($quete);

        Journal::ajouter($groupe, 'systeme', ['action' => 'nouveau_tour', 'quete_id' => $quete->id]);

        // Tous les héros tombés → quête échouée, retour au hub : le groupe
        // vote recharger (POST reprise) ou abandonner (doc 05 §6) — les
        // snapshots de la quête sont CONSERVÉS pour la reprise.
        if (! $quete->etatsPersonnages()->where('tombe', false)->exists()) {
            $quete->update(['etat' => 'echouee']);
            Journal::ajouter($groupe, 'systeme', ['action' => 'quete_echouee', 'quete_id' => $quete->id]);
            $groupe->update(['phase' => 'hub', 'quete_courante_id' => null]);

            return ['actions' => $actions];
        }

        // Snapshot `nouveau_tour` après la phase des monstres (contrat
        // « Snapshots & reprise ») : seul le dernier est conservé.
        $this->sauvegarde->snapshotter($groupe->refresh(), Sauvegarde::ETIQUETTE_NOUVEAU_TOUR);

        return ['actions' => $actions];
    }

    /**
     * Script C2 d'un monstre : pour les boss/sous-boss, MoteurDread gère la
     * régénération, les sorts de Dread et la Charge en priorité. Pour les
     * monstres de base (et en repli pour les boss sans action Dread), comportement
     * classique : approche du héros le plus proche puis attaque si adjacent —
     * avec Frappe de zone si capacité et plusieurs héros adjacents.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @return array<string, mixed>
     */
    private function jouerMonstre(Groupe $groupe, Quete $quete, InstanceMonstre $instance, Collection $cibles): array
    {
        $nomMonstre = $instance->habillage['nom'] ?? $instance->monstre->nom_base;
        $acteur = ['type' => 'monstre', 'id' => $instance->id, 'nom' => $nomMonstre];

        // Sommeil (doc 02 §7) : le monstre endormi NE JOUE PAS tant qu'il
        // n'est pas attaqué — une attaque le réveille (resoudreAttaque /
        // sortDegats retirent la condition).
        if ($this->sorts->monstreA($instance, MoteurSorts::MONSTRE_ENDORMI)) {
            $payload = ['type' => 'monstre_endormi', 'monstre' => $nomMonstre, 'action' => 'endormi'];
            Journal::ajouter($groupe, 'action', $payload, $acteur);

            return $payload;
        }

        // Tempête : « n'attaque pas à son prochain tour » — l'empêchement est
        // consommé à cette activation-ci (le monstre se déplace librement).
        $attaqueEmpechee = $this->sorts->monstreA($instance, MoteurSorts::MONSTRE_EMPECHE_ATTAQUE);
        if ($attaqueEmpechee) {
            $this->sorts->retirerConditionMonstre($instance, MoteurSorts::MONSTRE_EMPECHE_ATTAQUE);
        }

        // Boss / sous-boss : sorts de Dread + capacités spéciales (Régénération,
        // Charge). Si une action Dread a été jouée, on retourne son payload.
        $tier = $instance->monstre->tier ?? 'base';

        if (in_array($tier, ['sous_boss', 'boss'], true)) {
            $actionDread = $this->dread->jouerTourDread($groupe, $quete, $instance, $cibles);

            if ($actionDread !== null) {
                return $actionDread;
            }
        }

        $grille = $this->grille($quete, exceptInstanceId: $instance->id);

        // Héros le plus proche : plus court chemin vers une case adjacente
        // (sa propre case si déjà au contact).
        $meilleure = null; // [etat héros, chemin]
        foreach ($cibles as $cible) {
            if ($grille->sontAdjacentes(
                (int) $instance->position_x, (int) $instance->position_y,
                (int) $cible->position_x, (int) $cible->position_y,
            )) {
                $meilleure = [$cible, []];
                break;
            }

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $cx = (int) $cible->position_x + $dx;
                $cy = (int) $cible->position_y + $dy;
                $chemin = $grille->chemin((int) $instance->position_x, (int) $instance->position_y, $cx, $cy);

                if ($chemin !== null && ($meilleure === null || count($chemin) < count($meilleure[1]))) {
                    $meilleure = [$cible, $chemin];
                }
            }
        }

        if ($meilleure === null) {
            return ['monstre' => $nomMonstre, 'action' => 'immobile']; // aucun héros joignable
        }

        [$cible, $chemin] = $meilleure;

        // Se rapprocher : déplacement fixe du catalogue, le long du chemin.
        if ($chemin !== []) {
            $pas = min((int) $instance->monstre->deplacement, count($chemin));
            $arrivee = $chemin[$pas - 1];
            $instance->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);
        }

        $adjacent = $grille->sontAdjacentes(
            (int) $instance->position_x, (int) $instance->position_y,
            (int) $cible->position_x, (int) $cible->position_y,
        );

        if (! $adjacent) {
            $payload = [
                'type' => 'deplacement_monstre',
                'monstre' => $nomMonstre,
                'vers' => ['x' => $instance->position_x, 'y' => $instance->position_y],
            ];
            Journal::ajouter($groupe, 'action', $payload, $acteur);

            return $payload;
        }

        if ($attaqueEmpechee) {
            $payload = [
                'type' => 'attaque_empechee',
                'monstre' => $nomMonstre,
                'cible' => ['personnage_id' => $cible->personnage->id, 'nom' => $cible->personnage->nom],
            ];
            Journal::ajouter($groupe, 'action', $payload, $acteur);

            return $payload;
        }

        // Frappe de zone (capacité) : si plusieurs héros adjacents, tous sont touchés.
        if ($this->dread->aCapacite($instance, 'frappe_de_zone')) {
            $adjacents = $cibles->filter(fn (EtatPersonnageQuete $c) =>
                $grille->sontAdjacentes(
                    (int) $instance->position_x, (int) $instance->position_y,
                    (int) $c->position_x, (int) $c->position_y,
                )
            )->values();

            if ($adjacents->count() >= 2) {
                return $this->dread->frappeDeZone($groupe, $instance, $cibles, $acteur);
            }
        }

        // Attaque du héros adjacent — moteur seul. La défense intègre les
        // buffs de sorts (Peau de Pierre : bonus_des_defense, source sort:).
        $personnage = $cible->personnage;
        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: (int) $instance->monstre->attaque,
            desDefense: (int) $personnage->des_defense + $this->sorts->bonusDes($personnage, 'bonus_des_defense'),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $personnage->pv_body,
        );

        $personnage->update(['pv_body' => $resultat->pvBodyApres]);
        $this->sorts->reveillerHeros($personnage); // être attaqué réveille (Endormi)

        if ($resultat->cibleTombee) {
            $cible->update(['tombe' => true]); // C4 : occupe sa case, relevable
        }

        $payload = [
            'type' => 'attaque_monstre',
            'monstre' => $nomMonstre,
            'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $resultat->degats,
            'pv_body_apres' => $resultat->pvBodyApres,
            'cible_tombee' => $resultat->cibleTombee,
        ];

        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        // Bark d'ambiance : cri d'attaque du monstre, best-effort.
        $this->diffuserBark($groupe, $instance, 'attaque');

        return $payload;
    }

    /**
     * Créneau de tour consommé par un type d'option (doc 03 §28) :
     *  - `mouvement` : se déplacer, franchir une fosse ;
     *  - `tour` : actions qui sacrifient le tour entier (concentration, relever,
     *    terminer le tour) ;
     *  - `action` : tout le reste (attaque, jet/fouille, sort, parchemin, désamorçage).
     */
    private function creneauOption(string $type): string
    {
        return match ($type) {
            'deplacement', 'franchissement' => 'mouvement',
            'concentration', 'relever', 'attente' => 'tour',
            default => 'action',
        };
    }

    /**
     * Consomme le créneau et marque le tour terminé (a_joue) seulement quand les
     * DEUX créneaux sont faits, ou immédiatement pour une action terminante.
     */
    private function marquerCreneau(EtatPersonnageQuete $etat, string $creneau): void
    {
        if ($creneau === 'tour') {
            $etat->a_joue = true;
        } elseif ($creneau === 'mouvement') {
            $etat->a_deplace = true;
        } else {
            $etat->a_agi = true;
        }

        if ($etat->a_deplace && $etat->a_agi) {
            $etat->a_joue = true;
        }

        $etat->save();
    }

    /** Clé de cache des salles déjà découvertes d'une quête. */
    public static function cleSallesDecouvertes(int $queteId): string
    {
        return "partie:salles:{$queteId}";
    }

    /**
     * Index de la salle (carte.grille.salles) contenant la case (x, y), ou null
     * si la case n'appartient à aucune salle (couloir).
     */
    private function salleA(Quete $quete, int $x, int $y): ?int
    {
        foreach ((array) data_get($quete->carte?->grille, 'salles', []) as $i => $s) {
            if ($x >= (int) $s['x'] && $x < (int) $s['x'] + (int) $s['largeur']
                && $y >= (int) $s['y'] && $y < (int) $s['y'] + (int) $s['hauteur']) {
                return (int) $i;
            }
        }

        return null;
    }

    /**
     * Si le héros vient d'entrer dans une salle JAMAIS explorée, la marque vue
     * et déclenche la description de la salle par le MJ (narration). Best-effort,
     * sans incidence mécanique.
     */
    private function decouvrirSalle(Groupe $groupe, Quete $quete, EtatPersonnageQuete $etat): void
    {
        if ($etat->tombe || $etat->position_x === null) {
            return;
        }

        $salle = $this->salleA($quete, (int) $etat->position_x, (int) $etat->position_y);

        if ($salle === null) {
            return; // couloir : rien à décrire
        }

        $cle = self::cleSallesDecouvertes($quete->id);
        $vues = (array) Cache::get($cle, []);

        if (in_array($salle, $vues, true)) {
            return; // déjà décrite
        }

        $vues[] = $salle;
        Cache::put($cle, $vues, now()->addMinutes(360)); // durée d'une séance

        // Révélation des monstres de la salle : dormants → actifs visibles
        // (ils joueront dès la phase des monstres de ce tour).
        $s = (array) data_get($quete->carte?->grille, "salles.{$salle}");
        $reveles = $quete->instancesMonstres()
            ->where('revele', false)
            ->whereBetween('position_x', [(int) $s['x'], (int) $s['x'] + (int) $s['largeur'] - 1])
            ->whereBetween('position_y', [(int) $s['y'], (int) $s['y'] + (int) $s['hauteur'] - 1])
            ->update(['revele' => true]);

        Journal::ajouter($groupe, 'systeme', ['action' => 'salle_decouverte', 'salle' => $salle, 'monstres_reveles' => $reveles]);

        broadcast(new MjReflechit($groupe, true));
        GenererNarration::dispatch($groupe->id, [
            'type' => 'salle_decouverte',
            'salle' => $salle,
            'theme' => data_get($quete->carte?->grille, "salles.{$salle}.theme"),
        ]);
    }

    /**
     * Diffuse un bark de monstre (pur ambiance) sur le canal de groupe — joué
     * par l'écran de table. Best-effort : ni le combat ni l'API ne dépendent de
     * l'audio (pas de bark configuré → simplement rien).
     */
    private function diffuserBark(Groupe $groupe, InstanceMonstre $instance, string $evenement): void
    {
        $bark = $this->barks->pourInstance($instance, $evenement);

        if ($bark === null) {
            return;
        }

        broadcast(new BarkDiffuse(
            $groupe, $bark['profil'], $bark['evenement'], $bark['nom'], $bark['texte'], $bark['url'],
        ));
    }

    /**
     * Victoire : quête terminée, or du butin du gabarit au pot commun,
     * retour au hub.
     *
     * @return array<string, mixed>
     */
    private function terminerQuete(Groupe $groupe, Quete $quete): array
    {
        $orButin = (int) data_get($quete->gabarit?->structure, 'butin.or_base', 0);

        $quete->update(['etat' => 'terminee']);

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'quete_terminee',
            'quete_id' => $quete->id,
            'or_butin' => $orButin,
        ]);

        $groupe->update([
            'phase' => 'hub',
            'quete_courante_id' => null,
            'or' => (int) $groupe->or + $orButin,
        ]);

        // Fin de quête : les snapshots de la quête sont purgés (rétention
        // du contrat « Snapshots & reprise » — on ne recharge pas une
        // quête gagnée).
        $this->sauvegarde->purgerQuete($groupe, $quete);

        // Montée de niveau par jalon (doc 01 §5) : quête sous_boss/boss_final
        // gagnée → +1 niveau par héros actif, broadcast `.niveau.monte` émis
        // AVANT le `.groupe.etat` final (null pour une quête normale).
        $niveaux = $this->monteeNiveau->appliquer($groupe, $quete);

        // Clôture de campagne (doc 05 §6) : la victoire du BOSS FINAL ouvre
        // automatiquement la fenêtre de clôture (broadcast `.cloture.ouverte`,
        // butin déjà versé au pot — l'or à partager l'inclut).
        if ($quete->type_jalon === 'boss_final') {
            $this->cloture->ouvrirVictoire($groupe);
        }

        return ['etat' => 'terminee', 'or_butin' => $orButin, 'niveaux' => $niveaux];
    }

    /**
     * Grille tactique de la quête, cases occupées marquées (héros — même
     * tombés, C4 — et monstres actifs), avec une figurine exclue (celle qui
     * se déplace).
     */
    private function grille(Quete $quete, ?int $exceptPersonnageId = null, ?int $exceptInstanceId = null): Grille
    {
        $carte = $quete->carte;

        if ($carte === null) {
            throw ValidationException::withMessages(['groupe' => 'La quête en cours n\'a pas de carte assemblée.']);
        }

        $grille = Grille::depuisCarte($carte);

        $occupees = [];

        foreach ($quete->etatsPersonnages()->get() as $etat) {
            if ($etat->personnage_id !== $exceptPersonnageId && $etat->position_x !== null) {
                $occupees[] = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];
            }
        }

        foreach ($quete->instancesMonstres()->where('etat', 'actif')->get() as $instance) {
            if ($instance->id !== $exceptInstanceId && $instance->position_x !== null) {
                $occupees[] = ['x' => (int) $instance->position_x, 'y' => (int) $instance->position_y];
            }
        }

        $grille->occuper($occupees);

        return $grille;
    }
}
