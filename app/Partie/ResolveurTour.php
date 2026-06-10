<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Deplacement;
use App\Engine\Des\LanceurDes;
use App\Engine\JetCompetence;
use App\Engine\TypeFigurine;
use App\Events\EtatGroupeDiffuse;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Support\Journal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly MonteeNiveau $monteeNiveau,
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

        $resultat = DB::transaction(function () use ($groupe, $quete, $personnage, $etat, $option, $parametres) {
            $acteur = ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom];

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
                default => $this->resoudreNarratif($groupe, $option, $acteur),
            };

            $etat->update(['a_joue' => true]);

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

        $mouvement = (new Deplacement($this->des))->calculer((int) $personnage->deplacement_base);

        $grille = $this->grille($quete, exceptPersonnageId: $personnage->id);
        $chemin = $grille->chemin((int) $etat->position_x, (int) $etat->position_y, $x, $y);

        if ($chemin === null || $chemin === []) {
            throw ValidationException::withMessages(['parametres' => 'Destination inaccessible (mur, case occupée ou sur place).']);
        }

        $distance = count($chemin);

        if ($distance > $mouvement->total) {
            throw ValidationException::withMessages([
                'parametres' => "Destination hors de portée : {$distance} cases pour {$mouvement->total} de déplacement.",
            ]);
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
            'de' => $mouvement->de,
            'deplacement_total' => $mouvement->total,
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

        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: (int) $personnage->des_attaque,
            desDefense: (int) $instance->monstre->defense,
            typeDefenseur: TypeFigurine::Monstre,
            pvBodyDefenseur: (int) $instance->pv_body,
        );

        $instance->update([
            'pv_body' => $resultat->pvBodyApres,
            'etat' => $resultat->pvBodyApres === 0 ? 'vaincu' : 'actif',
        ]);

        $payload = [
            'type' => 'attaque',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
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

        foreach ($quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->orderBy('id')->get() as $instance) {
            $cibles = $quete->etatsPersonnages()->where('tombe', false)->with('personnage')->get();

            if ($cibles->isEmpty()) {
                break; // plus personne debout
            }

            $actions[] = $this->jouerMonstre($groupe, $quete, $instance, $cibles);
        }

        // Nouveau tour : les héros debout rejouent (l'initiative reste figée, C1).
        $quete->etatsPersonnages()->update(['a_joue' => false]);

        Journal::ajouter($groupe, 'systeme', ['action' => 'nouveau_tour', 'quete_id' => $quete->id]);

        // Tous les héros tombés → quête échouée, retour au hub (le TPK complet
        // — vote recharger/abandonner — viendra avec les sauvegardes).
        if (! $quete->etatsPersonnages()->where('tombe', false)->exists()) {
            $quete->update(['etat' => 'echouee']);
            Journal::ajouter($groupe, 'systeme', ['action' => 'quete_echouee', 'quete_id' => $quete->id]);
            $groupe->update(['phase' => 'hub', 'quete_courante_id' => null]);
        }

        return ['actions' => $actions];
    }

    /**
     * Script C2 d'un monstre : cible = héros debout le plus proche (distance
     * de chemin), se rapprocher (déplacement FIXE du catalogue, doc 09 §1),
     * attaquer si adjacent — via Engine\Combat.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @return array<string, mixed>
     */
    private function jouerMonstre(Groupe $groupe, Quete $quete, InstanceMonstre $instance, Collection $cibles): array
    {
        $nomMonstre = $instance->habillage['nom'] ?? $instance->monstre->nom_base;
        $acteur = ['type' => 'monstre', 'id' => $instance->id, 'nom' => $nomMonstre];

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

        // Attaque du héros adjacent — moteur seul.
        $personnage = $cible->personnage;
        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: (int) $instance->monstre->attaque,
            desDefense: (int) $personnage->des_defense,
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $personnage->pv_body,
        );

        $personnage->update(['pv_body' => $resultat->pvBodyApres]);

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

        return $payload;
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

        return ['etat' => 'terminee', 'or_butin' => $orButin];
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
