<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Deplacement;
use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Engine\DureeEffet;
use App\Engine\JetCompetence;
use App\Engine\MotsClesSort;
use App\Engine\ResultatAttaque;
use App\Engine\SortMental;
use App\Engine\TypeDegat;
use App\Engine\TypeFigurine;
use App\Engine\RegainEffet;
use App\Events\BarkDiffuse;
use App\Events\EtatGroupeDiffuse;
use App\Events\MjReflechit;
use App\Events\MouvementAnime;
use App\Jobs\GenererNarration;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
use App\Models\Monstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\Sort;
use App\Partie\Audio\BanqueBarks;
use App\Partie\Fouille\DeckFouille;
use App\Partie\Votes\VoteGroupe;
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

    /**
     * Coût en points de déplacement d'un SAUT au-dessus d'une fosse (E3) :
     * sauter fait partie du mouvement — héros → fosse → réception = 2 cases.
     */
    public const COUT_FRANCHISSEMENT = 2;

    /** Nœud barbare (CompetenceSeeder) : +1 dé d'attaque sous la moitié des PV de Body. */
    public const NOEUD_FRENESIE = 'Frénésie';

    /** Nœud barbare (CompetenceSeeder) : relance une fois les dés d'attaque ratés. */
    public const NOEUD_COUP_PUISSANT = 'Coup puissant';

    /** Nœud nain (CompetenceSeeder) : +1 dé de défense à la première attaque subie de la quête. */
    public const NOEUD_GARDE_TENACE = 'Garde tenace';

    /**
     * Avantage aux jets de Mind (`avantage_jet_mind`, CompetenceSeeder) : nœud
     * exact requis par `contexte` d'option de jet (MenuChoix / MenuMoteur).
     * +1 dé de Mind si le héros possède le nœud du contexte proposé.
     */
    public const NOEUDS_AVANTAGE_MIND = [
        'social_peur' => 'Intimidation',
        'perception' => 'Sens aiguisés',
        'savoir' => 'Érudition',
    ];

    public function __construct(
        private readonly LanceurDes $des,
        private readonly EtatGroupe $etatGroupe,
        private readonly MoteurPieges $pieges,
        private readonly MoteurPortes $portes,
        private readonly MoteurMobilier $mobilier,
        private readonly MoteurSorts $sorts,
        private readonly MoteurDread $dread,
        private readonly MoteurDegats $degats,
        private readonly MonteeNiveau $monteeNiveau,
        private readonly ClotureCampagne $cloture,
        private readonly Sauvegarde $sauvegarde,
        private readonly BanqueBarks $barks,
        private readonly Equipement $equipement,
        private readonly DeckFouille $deck,
        private readonly MoteurCharges $charges,
    ) {}

    /**
     * Déplacements de figurines de la résolution courante, pour l'animation
     * case-par-case côté table (E4) : chaque entrée = {type, id, depart, chemin}.
     * Réinitialisé à chaque `resoudre` ; diffusé (MouvementAnime) avant l'état.
     *
     * @var list<array{type: string, id: int, depart: array{x: int, y: int}, chemin: list<array{x: int, y: int}>}>
     */
    private array $mouvementsAnime = [];

    /**
     * @param  array<string, mixed>  $option  option du dernier menu proposé (déjà validée)
     * @param  array<string, mixed>  $parametres  paramètres du client (ex. destination x/y)
     * @return array<string, mixed> résultat moteur (echo + narration)
     */
    public function resoudre(Groupe $groupe, Personnage $personnage, array $option, array $parametres = []): array
    {
        $this->mouvementsAnime = [];
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
        // on refuse de rejouer un créneau déjà consommé ce tour. Réserve
        // arcanique (nœud magicien) : un SECOND sort par tour, au-delà du
        // créneau action normal — une seule fois par tour (bonus_sort_utilise).
        $creneau = $this->creneauOption((string) ($option['type'] ?? ''));
        // Second sort du tour : le nœud magicien *Réserve arcanique* OU la
        // Baguette de Rappel (« cast two spells instead of one »). Les deux
        // passent par le MÊME drapeau `bonus_sort_utilise`, donc un magicien
        // équipé n'obtient pas trois sorts — ils ne se cumulent pas.
        $bonusReserveArcanique = $creneau === 'action' && $etat->a_agi
            && ($option['type'] ?? null) === 'sort'
            && ! $etat->bonus_sort_utilise
            && ($this->possedeCompetence($personnage, 'Réserve arcanique')
                || $this->charges->pieceActive($personnage, 'second_sort_par_tour') !== null);

        if ($creneau === 'mouvement' && $etat->a_deplace) {
            throw ValidationException::withMessages(['personnage_id' => 'Tu t\'es déjà déplacé ce tour.']);
        }
        // Potion d'héroïsme : une seconde ATTAQUE au-delà du créneau d'action.
        $bonusHeroisme = $creneau === 'action' && $etat->a_agi
            && ($option['type'] ?? null) === 'attaque'
            && (bool) $etat->attaque_supplementaire;

        if ($creneau === 'action' && $etat->a_agi && ! $bonusReserveArcanique && ! $bonusHeroisme) {
            throw ValidationException::withMessages(['personnage_id' => 'Tu as déjà agi ce tour.']);
        }

        $resultat = DB::transaction(function () use ($groupe, $quete, $personnage, $etat, $option, $parametres, $creneau, $bonusReserveArcanique, $bonusHeroisme) {
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
                'detacher_rejetons' => $this->resoudreDetacherRejetons($groupe, $quete, $etat, $option, $parametres, $acteur),
                'relever' => $this->resoudreRelever($groupe, $quete, $personnage, $etat, $option, $acteur),
                'ouvrir_porte' => $this->resoudreOuvrirPorte($groupe, $quete, $personnage, $etat, $option, $acteur),
                'actionner_levier' => $this->resoudreActionnerLevier($groupe, $quete, $etat, $option, $acteur),
                'fouille_tresor' => $this->resoudreFouilleTresor($groupe, $quete, $personnage, $etat, $option, $acteur),
                'fouille_mobilier' => $this->resoudreFouilleMobilier($groupe, $quete, $personnage, $etat, $option, $acteur),
                'sortie' => $this->resoudreQuitterDonjon($groupe, $quete, $option, $acteur),
                'equiper' => $this->resoudreEquipement($groupe, $personnage, $option, $acteur, equiper: true),
                'desequiper' => $this->resoudreEquipement($groupe, $personnage, $option, $acteur, equiper: false),
                default => $this->resoudreNarratif($groupe, $option, $acteur),
            };

            if ($bonusReserveArcanique) {
                $resultat['bonus_reserve_arcanique'] = true;
            }

            // Consomme le créneau (mouvement/action) ; le tour ne se termine
            // que quand les DEUX créneaux sont faits, ou via une action terminante.
            $this->marquerCreneau($etat, $creneau, $bonusReserveArcanique, $bonusHeroisme);

            // Hook post-combat : portes à verrou « monstres_vaincus » qui
            // s'ouvrent quand leur(s) gardien(s) tombe(nt) (doc 14 §3.3).
            $this->revelerDerriere($groupe, $quete, $this->portes->ouvrirParMonstresVaincus($groupe, $quete));

            // Entrée dans une salle encore inexplorée (déplacement classique OU
            // Traverser la Pierre) → description de la nouvelle salle par le MJ.
            $this->decouvrirSalle($groupe, $quete, $etat);

            // Fin du COMBAT (plus aucun monstre engagé) — distincte de la fin de
            // quête : il peut rester des dormants derrière des portes closes.
            $this->verifierFinDuCombat($quete);

            // Fin de quête : plus aucun monstre actif → victoire.
            if (! $quete->instancesMonstres()->where('etat', 'actif')->exists()) {
                return $this->donjonNettoye($resultat, $quete);
            }

            // Tous les héros ont joué (ou sont tombés) → phase des monstres (C2).
            $enAttente = $quete->etatsPersonnages()
                ->where('a_joue', false)
                ->where('tombe', false)
                ->exists();

            if (! $enAttente) {
                $resultat = $this->jouerFinDeRound($resultat, $groupe, $quete);
                $this->verifierFinDuCombat($quete); // les alliés ont pu achever le dernier
            }

            return $resultat;
        });

        // Animation case-par-case (table, E4) : les trajets de figurines sont
        // diffusés AVANT l'état, pour que la table amorce le glissement avant que
        // l'état ne pose les positions finales (évite le « saut » puis rembobinage).
        if ($this->mouvementsAnime !== []) {
            broadcast(new MouvementAnime($groupe, $this->mouvementsAnime));
        }

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
            throw ValidationException::withMessages(['parametres' => 'Choisis d\'abord une case de destination.']);
        }

        $x = (int) $x;
        $y = (int) $y;

        $depart = ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y];

        // Allonce du tour : le d6 a été lancé à la génération du menu et MÉMORISÉ
        // (le joueur l'a vu avant de choisir sa case). Repli : lancer si absent.
        $base = (int) $personnage->deplacement_base;
        $totalTour = $etat->deplacement_tour ?? (new Deplacement($this->des))
            ->calculer($base, $this->equipement->valeurEffetPorte($personnage, 'malus_deplacement'))
            ->total;
        $deDuTour = $totalTour > $base ? $totalTour - $base : null;

        // Déplacement FRACTIONNÉ (E1) : on dépense sur les points RESTANTS du tour.
        ['restant' => $restant, 'multiplicateur' => $multiplicateur] = $this->pointsDeplacement($personnage, $etat, $totalTour);

        // Traverser la Pierre : tant que le buff tient (ce tour), la roche et
        // les portes closes ne barrent plus le chemin de CE héros.
        if ($this->sorts->deplacementInterdit($personnage)) {
            throw ValidationException::withMessages([
                'parametres' => 'Impossible de bouger : tu es immobilisé.',
            ]);
        }

        $traverseRoche = $this->sorts->traverseRoche($personnage);

        $grille = $this->grille($quete, exceptPersonnageId: $personnage->id, traverseRoche: $traverseRoche);
        $chemin = $grille->chemin((int) $etat->position_x, (int) $etat->position_y, $x, $y);

        if ($chemin === null || $chemin === []) {
            throw ValidationException::withMessages(['parametres' => 'Destination inaccessible (mur, case occupée ou sur place).']);
        }

        $distance = count($chemin);

        if ($distance > $restant) {
            throw ValidationException::withMessages([
                'parametres' => "Destination hors de portée : {$distance} cases pour {$restant} de déplacement restant.",
            ]);
        }

        // Contrôle du chemin (chemin BFS, arrivée incluse) :
        //  - piège caché TRAVERSÉ → déclenchement immédiat ; une fosse (ou un
        //    héros tombé) arrête DUREMENT le déplacement (doc 10 §5) ;
        //  - Œil du mineur : entrer sur une case rendant un piège caché adjacent
        //    le RÉVÈLE et interrompt la course sur cette case (arrêt SOUPLE : les
        //    points restants sont conservés, le héros peut réagir).
        // Racines entravantes (Jungles of Delthrak) : « un héros entrant dans une
        // case adjacente au monstre voit son mouvement stoppé net ». On tronque
        // AVANT le contrôle des pièges — les racines arrêtent la course, le
        // héros ne traverse donc pas les cases suivantes et ne peut pas y
        // déclencher de piège.
        //
        // ⚠ Il ne suffit PAS de raccourcir le chemin : plus bas, l'arrivée
        // retombe sur la destination DEMANDÉE quand aucun piège n'arrête le
        // héros. Sans réécrire $x/$y ici, le héros se téléportait à bon port en
        // ayant l'air d'avoir été stoppé.
        $tronque = $this->tronquerSurRacines($quete, $chemin);

        if (count($tronque) < count($chemin)) {
            $chemin = $tronque;
            $derniere = end($chemin);
            $x = (int) $derniere['x'];
            $y = (int) $derniere['y'];
        }

        $controle = $this->pieges->controlerChemin($groupe, $quete->carte, $personnage, $etat, $chemin);
        $interrompu = $controle['arret'] !== null;
        $arretDur = $controle['dur'] ?? false;
        $arrivee = $controle['arret'] ?? ['x' => $x, 'y' => $y];

        // Chemin RÉELLEMENT parcouru (jusqu'à l'arrêt éventuel) → pour l'animation
        // case-par-case côté table (E4) et le décompte des points dépensés.
        $cheminParcouru = $this->cheminJusqua($chemin, $arrivee);
        $parcourue = count($cheminParcouru);

        // Animation case-par-case (table) : le trajet réel du héros (type
        // « heros » pour coller aux figurines EtatGroupe — l'acteur, lui, est
        // « personnage »).
        if ($cheminParcouru !== []) {
            $this->mouvementsAnime[] = [
                'type' => 'heros',
                'id' => (int) $personnage->id,
                'depart' => $depart,
                'chemin' => $cheminParcouru,
            ];
        }

        // Un arrêt DUR (piège immobilisant / chute) TERMINE le mouvement ; un
        // arrêt SOUPLE (détection) ou une arrivée normale conservent les points
        // restants (on pourra se redéplacer / désamorcer puis continuer). Le
        // mouvement restant sera FORFAIT à la première action hors mouvement.
        $restantApres = $arretDur ? 0 : max(0, $restant - $parcourue);
        $mouvementFini = $arretDur || $restantApres <= 0;

        $etat->update([
            'position_x' => $arrivee['x'],
            'position_y' => $arrivee['y'],
            'deplacement_restant' => $restantApres,
            'a_deplace' => $mouvementFini,
        ]);

        $payload = [
            'type' => 'deplacement',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'de' => $deDuTour,
            'deplacement_total' => $totalTour * ($multiplicateur > 1 ? $multiplicateur : 1),
            'multiplicateur_sort' => $multiplicateur,
            'distance' => $parcourue,
            'depart' => $depart,
            'chemin' => $cheminParcouru, // animation case-par-case (table, E4)
            'vers' => $arrivee,
            'deplacement_restant' => $restantApres,
            'interrompu' => $interrompu,
            'arret_detection' => $interrompu && ! $arretDur, // stoppé par un talent de détection (Œil du mineur)
            'pieges_declenches' => $controle['declenchements'],
            'pieges_reveles' => $controle['detections'], // révélés en chemin par la détection adjacente
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Tronque le chemin BFS à la case d'arrivée EFFECTIVE (incluse) : le trajet
     * réellement parcouru quand un piège interrompt la course avant la
     * destination. Sans interruption, rend le chemin complet.
     *
     * @param  list<array{x: int, y: int}>  $chemin
     * @param  array{x: int, y: int}  $arrivee
     * @return list<array{x: int, y: int}>
     */
    /**
     * Points de déplacement RESTANTS du tour (E1). Au PREMIER usage du tour
     * (restant null), le pool est initialisé au total du tour × Vent Véloce — le
     * buff est appliqué ET consommé à ce moment, une seule fois ; ensuite on lit
     * simplement le restant mémorisé. Partagé par le déplacement et le saut
     * au-dessus d'une fosse (le saut fait partie du mouvement, E3).
     *
     * @return array{restant: int, multiplicateur: int}
     */
    private function pointsDeplacement(Personnage $personnage, EtatPersonnageQuete $etat, int $totalTour): array
    {
        if ($etat->deplacement_restant !== null) {
            return ['restant' => (int) $etat->deplacement_restant, 'multiplicateur' => 1];
        }

        $multiplicateur = $this->sorts->multiplicateurDeplacement($personnage);

        if ($multiplicateur > 1) {
            $this->sorts->consommerBuffs($personnage, 'deplacement_multiplie');
        }

        return ['restant' => $totalTour * $multiplicateur, 'multiplicateur' => $multiplicateur];
    }

    private function cheminJusqua(array $chemin, array $arrivee): array
    {
        $parcouru = [];

        foreach ($chemin as $case) {
            $parcouru[] = $case;

            if ((int) $case['x'] === (int) $arrivee['x'] && (int) $case['y'] === (int) $arrivee['y']) {
                break;
            }
        }

        return $parcouru;
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
        $lancer = (bool) ($option['lancer'] ?? $option['parametres']['lancer'] ?? false);

        // Ciblage en deux temps : l'option ne vaut plus pour UNE cible, elle
        // joint la liste des cibles légales. C'est donc `parametres.cibles` qui
        // porte maintenant la légalité — et non plus l'identifiant d'option que
        // le contrôleur validait contre le menu. Sans cette vérification, un
        // client pourrait viser n'importe quel monstre de la quête, hors portée
        // et hors ligne de vue : la garde n'est pas défensive, elle REMPLACE
        // celle que le repli des options vient de retirer.
        $legales = $option['parametres']['cibles'] ?? null;

        if (is_array($legales)) {
            $ids = array_map(
                static fn ($c) => (int) (is_array($c) ? ($c['id'] ?? 0) : $c),
                $legales,
            );

            if (! in_array($cibleId, $ids, true)) {
                throw ValidationException::withMessages([
                    'parametres' => 'Cible invalide : ce monstre ne fait pas partie des cibles proposées (hors portée, hors ligne de vue ou déjà hors jeu).',
                ]);
            }
        }

        $instance = $quete->instancesMonstres()
            ->whereKey($cibleId)
            ->where('etat', 'actif')
            ->where('revele', true)
            ->with('monstre')
            ->first();

        if ($instance === null) {
            throw ValidationException::withMessages(['option_id' => 'Cible invalide : ce monstre n\'est pas une cible active et visible dans la quête.']);
        }

        // Arme à distance équipée (Arbalète, ObjetSeeder `portee: distance`) :
        // permet d'attaquer un monstre non adjacent en ligne de vue dégagée
        // (Tir précis, nœud elfe) ; `inutilisable_adjacent` l'interdit au contact.
        $armePrincipale = $personnage->inventaire()->where('emplacement', 'arme_principale')->with('objet')->first()?->objet;
        $armeADistance = ($armePrincipale?->effet['portee'] ?? null) === 'distance';

        // Arme longue (Bâton, Épée longue) : le contact inclut les diagonales.
        $adjacentes = $this->heroAuContact(
            $instance,
            (int) $etat->position_x,
            (int) $etat->position_y,
            (bool) ($armePrincipale?->effet['attaque_diagonale'] ?? false),
        );

        // Une arme JETÉE porte à distance le temps de son vol : la hache à main
        // n'est pas une arme de tir, mais on peut la lancer.
        if ($lancer) {
            if (! (bool) ($armePrincipale?->effet['jetable'] ?? false)) {
                throw ValidationException::withMessages(['option_id' => 'Cette arme ne se lance pas.']);
            }
            $armeADistance = true;
        }

        if (! $adjacentes) {
            if (! $armeADistance) {
                throw ValidationException::withMessages(['option_id' => 'Cible hors de portée : l\'attaque exige une case adjacente.']);
            }

            $grille = $this->grille($quete, exceptPersonnageId: $personnage->id);
            if (! $grille->ligneDeVue(
                (int) $etat->position_x, (int) $etat->position_y,
                (int) $instance->position_x, (int) $instance->position_y,
                figuresBloquent: true,
            )) {
                throw ValidationException::withMessages(['option_id' => 'Cible hors de vue : aucune ligne de tir dégagée.']);
            }
        } elseif ($armeADistance && (bool) ($armePrincipale?->effet['inutilisable_adjacent'] ?? false)) {
            throw ValidationException::withMessages([
                'option_id' => "« {$armePrincipale->nom} » ne peut pas être utilisée au corps-à-corps.",
            ]);
        }

        $tirADistance = ! $adjacentes;

        // Courage (doc 02 §7) : +2 dés à la PROCHAINE attaque, consommé ici.
        $bonusAttaque = $this->sorts->bonusDes($personnage, 'bonus_des_attaque');

        // Frayeur (Dread) : condition Apeuré → −1 dé d'attaque (min 0), 2 tours.
        $malusFrayeur = $this->dread->malusDesAttaqueFrayeur($personnage);

        // Frénésie (nœud barbare) : +1 dé d'attaque tant que les PV de Body
        // sont SOUS la moitié du max (comparaison en entiers, sans arrondi).
        $bonusFrenesie = $this->possedeCompetence($personnage, self::NOEUD_FRENESIE)
            && (int) $personnage->pv_body * 2 < (int) $personnage->pv_body_max ? 1 : 0;

        // Tir précis (nœud elfe) : +1 dé d'attaque sur un tir à distance véritable.
        $bonusTirPrecis = $tirADistance && $this->possedeCompetence($personnage, 'Tir précis') ? 1 : 0;

        // Lame des Esprits : « three combat dice in attack OR four dice against
        // undead creatures such as Skeletons, Zombies and Mummies ». Le bonus
        // remplace la valeur de l'arme, il ne s'y ajoute pas — d'où un max, pas
        // une somme : contre une momie l'arme vaut 4, jamais 3 + 4.
        $desArmeContre = $this->desArmeContre($armePrincipale, $instance);

        $desAttaqueEffectifs = max(0, max((int) $personnage->des_attaque, $desArmeContre)
            + $bonusAttaque - $malusFrayeur + $bonusFrenesie + $bonusTirPrecis);

        // Dague de jet magique : « This weapon ALWAYS inflicts one Body Point of
        // damage. » Aucun jet, aucune défense — le seul cas du jeu où l'attaque
        // ne passe pas par les dés. Traité avant `Combat` plutôt qu'à travers
        // lui : y faire entrer un « toujours N » obligerait le moteur de combat
        // à connaître l'équipement, qu'il ignore par construction.
        $degatsFixes = (int) ($armePrincipale?->effet['degats_fixes'] ?? 0);

        // Arc elfique de Vindication : « instantly kills any one monster within
        // the Elf's line of sight, unless the monster rolls a black shield on
        // 1 combat die ». Une FLÈCHE par tir, et l'arc n'en a que quatre — sans
        // les charges, une mort instantanée illimitée viderait un donjon sans
        // combat. À court de flèches il devient inerte et retombe sur ses dés.
        $fleche = $this->flecheDeVindication($personnage, $armePrincipale);

        if ($fleche !== null) {
            $face = FaceDeCombat::depuisD6($this->des->d6());
            $survit = $face === FaceDeCombat::BouclierNoir;

            $payload = $this->payloadVindication($option, $instance, $face, $survit, $fleche);
            $instance->update([
                'pv_body' => $survit ? (int) $instance->pv_body : 0,
                'etat' => $survit ? 'actif' : 'vaincu',
            ]);

            $this->sorts->expirerBuffs($personnage, DureeEffet::PROCHAINE_ATTAQUE);
            $this->sorts->retirerConditionMonstre($instance, MoteurSorts::MONSTRE_ENDORMI);
            Journal::ajouter($groupe, 'combat', $payload, $acteur);
            $this->diffuserBark($groupe, $instance, $survit ? 'rate' : 'mort');

            return $payload;
        }

        // Éthéré (Rise of the Dread Moon) : « une attaque de héros ne les touche
        // que sur un bouclier noir (au lieu d'un crâne), **sauf via sort ou
        // artefact** ». L'exception est prise au mot : une arme `unique` — un
        // artefact — frappe normalement, et les sorts passent par un tout autre
        // chemin (`sortDegats`), donc ne sont pas concernés ici.
        $ethere = $this->dread->aCapacite($instance, 'ethere')
            && ($armePrincipale?->rarete ?? null) !== 'unique';

        $resultat = $degatsFixes > 0
            ? ResultatAttaque::sansJet($degatsFixes, (int) $instance->pv_body)
            : (new Combat($this->des))->resoudreAttaque(
                desAttaque: $desAttaqueEffectifs,
                desDefense: $instance->defenseEffective(),
                typeDefenseur: TypeFigurine::Monstre,
                pvBodyDefenseur: (int) $instance->pv_body,
                relanceDesAttaqueRatee: $this->possedeCompetence($personnage, self::NOEUD_COUP_PUISSANT),
                defenseurEthere: $ethere,
            );

        // Le héros vient de frapper : les buffs déclarés `prochaine_attaque`
        // (Courage, Potion de force) sont consommés — quel que soit le résultat,
        // c'est bien l'attaque qui les dépense. Ceux qui durent le combat
        // (Potion de rage) survivent, ce que l'ancien `consommerBuffs` sur la
        // clé d'effet ne savait pas distinguer.
        $this->sorts->expirerBuffs($personnage, DureeEffet::PROCHAINE_ATTAQUE);

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
            'bonus_frenesie' => $bonusFrenesie,
            'bonus_tir_precis' => $bonusTirPrecis,
            'portee' => $tirADistance ? 'distance' : 'corps_a_corps',
            'cible_etheree' => $ethere,
            'des_attaque_effectifs' => $desAttaqueEffectifs,
            'cible' => [
                'instance_id' => $instance->id,
                'nom' => $instance->nomAffiche(),
            ],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $resultat->degats,
            'pv_body_apres' => $resultat->pvBodyApres,
            'cible_vaincue' => $resultat->pvBodyApres === 0,
            ...$resultat->pourJournal(),
        ];

        // *Demonform* : « Regain this spell when you reduce a monster's Body
        // Points to zero » — c'est l'ABATTEUR qui recharge, pas le groupe.
        if ($resultat->pvBodyApres === 0) {
            $this->sorts->regagnerSorts($personnage, RegainEffet::MONSTRE_VAINCU);
        }

        // Fléau des Orques : une seconde attaque ce tour si la cible en était un.
        if ($this->accorderSecondeAttaque($armePrincipale, $instance, $etat)) {
            $payload['attaque_supplementaire'] = true;
        }

        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        // Bark d'ambiance du monstre touché (mort / blessé / paré), best-effort.
        $this->diffuserBark($groupe, $instance,
            $resultat->pvBodyApres === 0 ? 'mort' : ($resultat->degats > 0 ? 'touche' : 'rate'));

        if ($lancer) {
            $payload['lancer'] = $this->consommerArmeLancee($personnage);
        }

        return $payload;
    }

    /**
     * L'arme est-elle un arc de Vindication encore chargé ? Rend la LIGNE
     * d'inventaire (dont la flèche vient d'être décomptée), ou `null`.
     *
     * La flèche est dépensée ici, avant le jet : elle part que le monstre
     * survive ou non — c'est ce que dit la carte, quatre flèches, pas quatre
     * morts.
     */
    private function flecheDeVindication(Personnage $personnage, ?Objet $arme): ?Inventaire
    {
        if (! (bool) ($arme?->effet['tue_sauf_bouclier_noir'] ?? false)) {
            return null;
        }

        $ligne = $personnage->inventaire()->where('emplacement', 'arme_principale')->with('objet')->first();

        // Épuisé : l'arc reste en main mais redevient une arme ordinaire, et
        // l'attaque repart par le chemin normal (ses 2 dés).
        return $this->charges->consommer($ligne) ? $ligne : null;
    }

    /**
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function payloadVindication(
        array $option,
        InstanceMonstre $instance,
        FaceDeCombat $face,
        bool $survit,
        Inventaire $arc,
    ): array {
        return [
            'type' => 'attaque',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'portee' => 'distance',
            'vindication' => true,
            'faces_defense' => [$face->value],
            'faces_attaque' => [],
            // Le monstre ne survit QUE sur un bouclier noir (ligne 662) : c'est
            // donc la face qui « pare » ce jet, et la seule à entourer en vert.
            'face_defensive' => FaceDeCombat::BouclierNoir->value,
            'des_attaque_effectifs' => 0,
            'touches' => 0,
            'boucliers' => $survit ? 1 : 0,
            'degats' => $survit ? 0 : (int) $instance->pv_body,
            'pv_body_apres' => $survit ? (int) $instance->pv_body : 0,
            'cible_vaincue' => ! $survit,
            'fleches_restantes' => $this->charges->restantes($arc->fresh()),
            'cible' => ['instance_id' => $instance->id, 'nom' => $instance->nomAffiche()],
        ];
    }

    /**
     * Attaquer les rejetons accrochés — les SIENS ou ceux d'un voisin.
     *
     * « Un héros portant des jetons peut les attaquer, et un héros adjacent à un
     * autre héros portant des jetons peut les attaquer aussi, en ciblant le
     * jeton et non le joueur » (règle de retrait, René 2026-08-10). Soi-même
     * (distance 0) ou au contact (distance 1) — jamais à distance : on arrache
     * une bestiole accrochée, on ne la tire pas d'une salle à l'autre.
     *
     * Combien par attaque ? Le bloc de stats du Rejeton le dit sans qu'on ait à
     * l'inventer : **Body 1, Défense 0**. Un jeton n'a rien pour parer et tombe
     * au premier point — chaque CRÂNE en détache donc un.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreDetacherRejetons(
        Groupe $groupe,
        Quete $quete,
        EtatPersonnageQuete $etat,
        array $option,
        array $parametres,
        array $acteur,
    ): array {
        $cibleId = (int) ($parametres['personnage_id'] ?? data_get($option, 'parametres.personnage_id', 0));

        $porteur = $quete->etatsPersonnages()
            ->where('personnage_id', $cibleId)
            ->where('jetons_rejeton', '>', 0)
            ->with('personnage')
            ->first();

        if ($porteur === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Aucun rejeton à arracher sur ce héros.',
            ]);
        }

        if (abs((int) $porteur->position_x - (int) $etat->position_x)
            + abs((int) $porteur->position_y - (int) $etat->position_y) > 1) {
            throw ValidationException::withMessages([
                'option_id' => 'Trop loin : on arrache un rejeton sur soi ou sur un compagnon au contact.',
            ]);
        }

        $faces = $this->des->desCombat((int) $etat->personnage?->des_attaque);
        $cranes = count(array_filter($faces, fn ($face) => $face->estCrane()));
        $retires = min($cranes, (int) $porteur->jetons_rejeton);

        $porteur->update(['jetons_rejeton' => (int) $porteur->jetons_rejeton - $retires]);

        $payload = [
            'type' => 'detacher_rejetons',
            'option_id' => $option['id'],
            'cible' => ['personnage_id' => $porteur->personnage_id, 'nom' => $porteur->personnage?->nom],
            'faces_attaque' => array_map(fn ($face) => $face->value, $faces),
            // Un rejeton s'arrache PAR CRÂNE — la figure a Body 1 / Défense 0,
            // elle ne pare rien : il n'y a pas de jet de défense à afficher.
            'face_touchante' => FaceDeCombat::Crane->value,
            'retires' => $retires,
            'restants' => (int) $porteur->fresh()->jetons_rejeton,
        ];

        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    /**
     * Les rejetons accrochés rongent leur porteur en fin de tour : 1 PV de Body
     * par jeton, automatique et indéfendable (Jungles of Delthrak, doc 18 §5).
     *
     * Aucun jet, ni d'attaque ni de défense : c'est le seul dégât du jeu qui ne
     * passe par aucun dé. Un héros qui tombe ainsi tombe comme d'un coup reçu.
     */
    private function rongerParRejetons(EtatPersonnageQuete $etat): void
    {
        $jetons = (int) $etat->jetons_rejeton;
        $personnage = $etat->personnage;

        if ($jetons <= 0 || $personnage === null || $etat->tombe) {
            return;
        }

        $this->degats->infligerAHeros(
            $personnage, $jetons, MoteurDegats::SOURCE_REJETON, ['jetons' => $jetons],
        );

        if ((int) $personnage->fresh()->pv_body === 0) {
            $etat->update(['tombe' => true]); // C4 : il occupe sa case, relevable
        }
    }

    /**
     * **Double-action du tacticien** — le mouvement d'APRÈS l'attaque.
     *
     * « Peut bouger avant et après son action » (Jungles of Delthrak, p. 48).
     * La carte accorde la permission sans dire quoi en faire : le décrochage est
     * NOTRE lecture, et la seule qui donne un sens à un second mouvement — un
     * monstre qui resterait au contact n'aurait rien gagné à bouger deux fois.
     *
     * Il recule d'un pas hors de portée de tout héros. S'il n'existe aucune case
     * libre non adjacente, il reste où il est : mieux vaut ne pas bouger que
     * reculer dans un autre corps-à-corps.
     *
     * @return array{x: int, y: int}|null
     */
    private function replierTacticien(InstanceMonstre $instance): ?array
    {
        $quete = $instance->quete;

        if ($quete === null) {
            return null;
        }

        $grille = $this->grille($quete, exceptInstanceId: $instance->id);

        $heros = $quete->etatsPersonnages()
            ->where('tombe', false)
            ->whereNotNull('position_x')
            ->get();

        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $x = (int) $instance->position_x + $dx;
            $y = (int) $instance->position_y + $dy;

            if (! $grille->estTraversable($x, $y)) {
                continue;
            }

            $auContact = $heros->contains(fn ($h) => abs((int) $h->position_x - $x)
                + abs((int) $h->position_y - $y) === 1);

            if (! $auContact) {
                $instance->update(['position_x' => $x, 'position_y' => $y]);

                return ['x' => $x, 'y' => $y];
            }
        }

        return null;
    }

    /**
     * Dernière case du trajet où la figurine a le DROIT de s'arrêter.
     *
     * Un monstre ordinaire s'arrête au bout de son allonce : sa grille lui
     * interdisait déjà tout le reste. Un traversant (éthéré, agile) a pu se
     * frayer un chemin à travers murs et figures — il peut PASSER, pas
     * stationner. On recule donc jusqu'à la dernière case libre et découverte,
     * et `null` s'il n'en existe aucune (il reste alors sur place).
     *
     * @param  list<array{x: int, y: int}>  $chemin
     * @return array{x: int, y: int}|null
     */
    private function derniereCaseOuSArreter(Quete $quete, InstanceMonstre $instance, array $chemin, int $pas): ?array
    {
        $traversant = $this->dread->aCapacite($instance, 'ethere')
            || $this->dread->aCapacite($instance, 'agile');

        if (! $traversant) {
            return $chemin[$pas - 1];
        }

        // Grille NORMALE : elle dit ce qui est réellement occupé/infranchissable.
        $reelle = $this->grille($quete, exceptInstanceId: $instance->id);
        $decouvertes = $quete->sallesDecouvertes();

        for ($i = $pas - 1; $i >= 0; $i--) {
            $case = $chemin[$i];

            if ($reelle->estTraversable((int) $case['x'], (int) $case['y'])
                && $this->salleDecouverte($quete, $decouvertes, (int) $case['x'], (int) $case['y'])) {
                return $case;
            }
        }

        return null;
    }

    /**
     * La case appartient-elle à une salle DÉCOUVERTE (ou à un couloir) ?
     *
     * « Jamais dans une zone non découverte » : un éthéré ne doit pas traverser
     * un mur pour aller se poster dans une pièce que le groupe n'a pas encore
     * ouverte — il y serait invisible et injouable.
     *
     * @param  list<int>  $decouvertes
     */
    private function salleDecouverte(Quete $quete, array $decouvertes, int $x, int $y): bool
    {
        foreach ((array) data_get($quete->carte?->grille, 'salles', []) as $index => $salle) {
            if ($x >= (int) $salle['x'] && $x < (int) $salle['x'] + (int) $salle['largeur']
                && $y >= (int) $salle['y'] && $y < (int) $salle['y'] + (int) $salle['hauteur']) {
                return in_array((int) $index, $decouvertes, true);
            }
        }

        return true; // couloir : jamais « non découvert »
    }

    /**
     * Coupe le chemin à la PREMIÈRE case adjacente à une créature à racines
     * entravantes — « un héros entrant dans une case adjacente au monstre voit
     * son mouvement stoppé net » (Jungles of Delthrak, p. 49).
     *
     * Le héros s'arrête SUR cette case : il est entré, la racine l'a saisi. Un
     * arrêt une case avant l'aurait empêché d'atteindre la créature au contact,
     * donc de la frapper — l'inverse de ce que la carte décrit.
     *
     * @param  list<array{x: int, y: int}>  $chemin
     * @return list<array{x: int, y: int}>
     */
    private function tronquerSurRacines(Quete $quete, array $chemin): array
    {
        $gardiens = $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true)
            ->with('monstre')
            ->get()
            ->filter(fn (InstanceMonstre $i) => in_array('racines_entravantes', (array) ($i->monstre?->capacites ?? []), true));

        if ($gardiens->isEmpty()) {
            return $chemin;
        }

        foreach ($chemin as $index => $case) {
            $adjacent = $gardiens->contains(fn (InstanceMonstre $i) => abs((int) $i->position_x - (int) $case['x'])
                + abs((int) $i->position_y - (int) $case['y']) === 1);

            if ($adjacent) {
                return array_slice($chemin, 0, $index + 1);
            }
        }

        return $chemin;
    }

    /**
     * Le sort qu'on vient de lancer échappe-t-il à l'épuisement ?
     *
     * Deux artefacts, deux économies opposées :
     *  - **Sceptre de Mémoire** : un dé de combat après chaque sort, bouclier
     *    noir (1 chance sur 6) → le sort reste disponible. Illimité, c'est le
     *    dé qui limite. Testé EN PREMIER, parce qu'il ne coûte rien : réussir
     *    ici évite de gaspiller la charge de l'anneau.
     *  - **Anneau de Sort** : une charge, aucun jet, le sort reste disponible.
     *    « cast one spell twice in the same Quest » — l'écart assumé est que le
     *    sort se choisit en le lançant, pas au début de la quête.
     *
     * `$payload` reçoit la trace de ce qui a joué : sans elle, le joueur verrait
     * son sort rester allumé sans comprendre pourquoi.
     *
     * @param  array<string, mixed>  $payload
     */
    private function preserverSort(Personnage $personnage, array &$payload): bool
    {
        if ($this->charges->pieceActive($personnage, 'sort_non_epuise_sur_bouclier_noir') !== null) {
            $face = FaceDeCombat::depuisD6($this->des->d6());
            $payload['jet_memoire'] = $face->value;

            if ($face === FaceDeCombat::BouclierNoir) {
                $payload['sort_preserve'] = 'sceptre_de_memoire';

                return true;
            }
        }

        $anneau = $this->charges->pieceActive($personnage, 'sort_non_epuise');

        if ($anneau !== null && $this->charges->consommer($anneau)) {
            $payload['sort_preserve'] = 'anneau_de_sort';
            $payload['charges_restantes'] = $this->charges->restantes($anneau->fresh());

            return true;
        }

        return false;
    }

    /**
     * Dés d'attaque que l'arme oppose SPÉCIFIQUEMENT à cette créature, 0 si
     * elle n'a pas de clause contre elle (`des_attaque_contre`).
     *
     * Le test porte sur `nom_base`, le nom de CATALOGUE : le nom affiché est
     * habillé par l'IA (« Grull l'Éventreur »), donc une Lame des Esprits
     * cesserait de reconnaître les morts-vivants dès la première quête narrée.
     */
    private function desArmeContre(?Objet $arme, InstanceMonstre $instance): int
    {
        $clause = (array) ($arme?->effet['des_attaque_contre'] ?? []);
        $noms = array_map('mb_strtolower', array_map('strval', (array) ($clause['noms'] ?? [])));

        if ($noms === [] || ! in_array(mb_strtolower((string) $instance->monstre?->nom_base), $noms, true)) {
            return 0;
        }

        return (int) ($clause['des'] ?? 0);
    }

    /**
     * L'arme accorde-t-elle une SECONDE attaque contre cette créature ?
     *
     * Fléau des Orques : « You may attack TWICE if you are fighting Orcs. » On
     * réutilise `etat.attaque_supplementaire`, le créneau que pose déjà la
     * Potion d'héroïsme — un second mécanisme aurait permis de cumuler deux
     * attaques bonus dans le même tour sans que rien ne l'ait voulu.
     */
    private function accorderSecondeAttaque(?Objet $arme, InstanceMonstre $instance, EtatPersonnageQuete $etat): bool
    {
        $noms = array_map('mb_strtolower', array_map('strval', (array) ($arme?->effet['attaque_double_contre'] ?? [])));

        if ($noms === [] || ! in_array(mb_strtolower((string) $instance->monstre?->nom_base), $noms, true)) {
            return false;
        }

        if ((bool) $etat->attaque_supplementaire) {
            return false; // déjà une attaque bonus en réserve : pas de cumul
        }

        $etat->update(['attaque_supplementaire' => true]);

        return true;
    }

    /**
     * Retire de la main l'arme qui vient d'être LANCÉE.
     *
     * Une arme lancée est PERDUE, dague comprise : elle reste où elle tombe.
     *
     * Les dés d'attaque sont recalculés : le héros se retrouve à mains nues
     * s'il n'a rien d'autre, et il doit le voir immédiatement.
     *
     * @return array<string, mixed>
     */
    private function consommerArmeLancee(Personnage $personnage): array
    {
        $ligne = $personnage->inventaire()->where('emplacement', 'arme_principale')->with('objet')->first();

        if ($ligne === null) {
            return ['arme' => null];
        }

        $nom = $ligne->objet?->nom;
        $ligne->delete();

        $this->equipement->recalculerCombat($personnage->refresh());

        return ['arme' => $nom, 'perdue' => true];
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

        // Avantage de Mind (Intimidation/Sens aiguisés/Érudition, CompetenceSeeder) :
        // +1 dé si le contexte proposé correspond à un nœud acquis du héros.
        $contexte = $option['jet']['contexte'] ?? null;
        $noeudAvantage = self::NOEUDS_AVANTAGE_MIND[$contexte] ?? null;
        $bonusAvantage = $attribut === 'mind' && $noeudAvantage !== null
            && $this->possedeCompetence($personnage, $noeudAvantage) ? 1 : 0;

        $nbDes = ($attribut === 'body' ? (int) $personnage->attribut_body : (int) $personnage->attribut_mind) + $bonusAvantage;
        $resultat = (new JetCompetence($this->des))->resoudre($nbDes, $difficulte);

        $payload = [
            'type' => 'jet',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'attribut' => $attribut,
            'difficulte' => $difficulte,
            'des_lances' => $nbDes,
            'bonus_avantage_mind' => $bonusAvantage,
            'succes' => $resultat->succes,
            'issue' => $resultat->issue->value,
            'faces' => array_map(fn ($face) => $face->value, $resultat->faces),
        ];

        // Fouille de la zone RÉUSSIE (doc 14 §3.1) : un seul jet de Mind révèle
        // dans le rayon de fouille les pièges cachés (doc 10 §3) ET les portes
        // secrètes (passées révélées + ouvertes).
        if ($option['id'] === 'fouiller' && $resultat->estReussi()
            && $quete->carte !== null && $etat->position_x !== null) {
            $payload['pieges_reveles'] = $this->pieges->revelerAutour(
                $groupe, $quete->carte, $personnage,
                (int) $etat->position_x, (int) $etat->position_y,
            );
            $payload['portes_revelees'] = $this->portes->revelerSecretesAutour(
                $groupe, $quete->carte, $personnage,
                (int) $etat->position_x, (int) $etat->position_y,
            );
        }

        // §2.5 — un jet RÉUSSI peut ne RIEN trouver : le narrateur ne voyait que
        // `issue: reussite` et écrivait une découverte inexistante (« elle décèle
        // des indices cruciaux ») pendant que le journal mécanique disait
        // « rien de suspect ». On distingue explicitement les deux.
        if ($option['id'] === 'fouiller') {
            $payload['a_trouve'] = ($payload['pieges_reveles'] ?? []) !== []
                || ($payload['portes_revelees'] ?? []) !== [];
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

        // Échec (doc 10 §10 question n°3, résolue par le nœud nain Désamorçage) :
        // sans le nœud, le piège se déclenche sur le désamorceur (racial Nain /
        // Trousse à outils, tout le monde) ; AVEC le nœud, l'échec est sans
        // casse — le piège reste détecté, retentable.
        if (! $resultat->estReussi() && ! $this->possedeCompetence($personnage, 'Désamorçage')) {
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

        // Sauter FAIT PARTIE DU MOUVEMENT (E3) : le saut se paie sur les points
        // de déplacement restants du tour (héros → fosse → réception = 2 cases).
        $totalTour = (int) ($etat->deplacement_tour ?? $personnage->deplacement_base);
        ['restant' => $restant] = $this->pointsDeplacement($personnage, $etat, $totalTour);

        if ($restant < self::COUT_FRANCHISSEMENT) {
            throw ValidationException::withMessages([
                'option_id' => 'Pas assez de déplacement restant pour sauter par-dessus la fosse.',
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
            // Saut réussi : on paie les 2 cases ; s'il reste des points, le héros
            // peut CONTINUER son mouvement après avoir sauté (E1/E3).
            $restantApres = max(0, $restant - self::COUT_FRANCHISSEMENT);

            $etat->update([
                'position_x' => $arrivee['x'],
                'position_y' => $arrivee['y'],
                'deplacement_restant' => $restantApres,
                'a_deplace' => $restantApres <= 0,
            ]);

            // Œil du mineur : détection automatique autour de la réception.
            $this->pieges->detecterAdjacents($groupe, $quete->carte, $personnage, $arrivee['x'], $arrivee['y']);

            $payload['vers'] = $arrivee;
            $payload['deplacement_restant'] = $restantApres;
        } else {
            // Chute : le héros tombe DANS la fosse (effet du catalogue) et
            // y reste — la fosse persistante demeure en jeu (doc 10 §5). La
            // course s'arrête là : le mouvement du tour est terminé.
            $etat->update([
                'position_x' => $cible['x'],
                'position_y' => $cible['y'],
                'deplacement_restant' => 0,
                'a_deplace' => true,
            ]);

            $payload['declenchement'] = $this->pieges->declencher(
                $groupe, $quete->carte, $cible['index'], $personnage, $etat, 'franchissement_rate',
            );
            $payload['vers'] = ['x' => $cible['x'], 'y' => $cible['y']];
            $payload['deplacement_restant'] = 0;
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

        // Économie de sorts : deux artefacts peuvent épargner le sort qu'on
        // vient de lancer. On ne les cumule pas — l'anneau dépense une charge,
        // il ne doit pas la gaspiller derrière un sceptre qui a déjà réussi.
        $preserve = $this->preserverSort($personnage, $payload);

        if (! $preserve) {
            $personnage->sorts()->updateExistingPivot($sort->id, ['disponible' => false]);
        }

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
        // Second mode (Génie : « ouvre une porte au choix OU attaque ») — traité
        // AVANT tout garde-fou de ciblage, parce qu'il ne vise pas une figure
        // mais une PORTE. Placé plus bas, il n'était jamais atteint : le
        // contrôle de ligne de vue ci-dessous appelle `cibleSort()`, qui exigeait
        // un `cible_id` que ce mode ne porte pas — chaque tentative d'ouverture à
        // distance échouait donc sur « Cible requise : parametres.cible_id »
        // (constaté en partie réelle, 2026-08-06).
        if (data_get($option, 'parametres.mode') === 'ouvre_porte') {
            return $this->sortOuvrePorte($quete, $sort, $option);
        }

        // Garde-fou de ligne de vue (doc 03 §36) : un sort offensif (degats /
        // mental) exige que la cible soit VISIBLE — une figure interposée coupe
        // la vue. Revérifié ici même si un menu périmé listait la cible.
        if (in_array($sort->type, ['degats', 'mental'], true)) {
            $this->verifierLigneDeVueSort($quete, $etat, $option, $parametres);
        }

        return match ($sort->type) {
            'degats' => $this->sortDegats($quete, $sort, $option, $parametres),
            'mental' => $this->sortMental($quete, $sort, $option, $parametres),
            default => $this->sortUtilitaire($quete, $lanceur, $etat, $sort, $option, $parametres),
        };
    }

    /**
     * Rejette (422) un sort offensif si la cible n'est pas dans la ligne de vue
     * du lanceur, figures interposées bloquantes (mêmes règles que le menu).
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $parametres
     */
    private function verifierLigneDeVueSort(Quete $quete, EtatPersonnageQuete $etat, array $option, array $parametres): void
    {
        if ($etat->position_x === null) {
            return;
        }

        $cible = $this->cibleSort($quete, $option, $parametres);

        if ($cible['type'] === 'monstre') {
            $instance = $cible['monstre'];
            $e = $instance->monstre->emprise();
            [$tx, $ty, $l, $h] = [(int) $instance->position_x, (int) $instance->position_y, (int) $e['l'], (int) $e['h']];
        } else {
            [$tx, $ty, $l, $h] = [(int) $cible['etat']->position_x, (int) $cible['etat']->position_y, 1, 1];
        }

        $visible = FabriqueGrille::pour($quete)->ligneDeVueEmprise(
            (int) $etat->position_x, (int) $etat->position_y, $tx, $ty, $l, $h, figuresBloquent: true,
        );

        if (! $visible) {
            throw ValidationException::withMessages([
                'parametres' => 'Cible hors de vue : une figure interposée bloque la ligne de vue.',
            ]);
        }
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

            // Un sort de FEU brûle : le troll cesse définitivement de régénérer
            // (« damage done by fire is permanent and cannot be regenerated »).
            // Marqué avant la résolution — la brûlure ne dépend pas des dégâts
            // encaissés, seulement de la nature du sort reçu.
            if (($sort->effet['type_degat'] ?? null) === TypeDegat::FEU && ! $instance->brule) {
                $instance->update(['brule' => true]);
            }

            // Résistance magique (capacité boss) : +2 dés de défense contre les sorts de dégâts.
            $bonusResistance = $this->dread->bonusDefenseResistanceMagique($instance);

            // `defense_applicable` PILOTE désormais le jet de défense au lieu de
            // seulement le décrire : un sort qui la met à false frappe sans que
            // la cible puisse parer. Par défaut true — comportement inchangé
            // pour les trois sorts de dégâts actuels.
            $defense = ($sort->effet['defense_applicable'] ?? true)
                ? $instance->defenseEffective() + $bonusResistance
                : 0;

            $resultat = (new Combat($this->des))->resoudreAttaque(
                desAttaque: $des,
                desDefense: $defense,
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
                    'nom' => $instance->nomAffiche(),
                ],
                'touches' => $resultat->touches,
                'boucliers' => $resultat->boucliers,
                'degats' => $resultat->degats,
                'pv_body_apres' => $resultat->pvBodyApres,
                'cible_vaincue' => $resultat->pvBodyApres === 0,
                ...$resultat->pourJournal(),
            ];
        }

        /** @var Personnage $heros */
        $heros = $cible['personnage'];

        // Anneau de Feu : « prevents the wearer from being affected by the next
        // two Fire spells ». Immunité TOTALE, avant tout jet — un héros protégé
        // ne lance même pas sa défense.
        $typeDegat = $sort->effet['type_degat'] ?? null;

        if ($this->sorts->absorbeDegat($heros, $typeDegat)) {
            return [
                'des_degats' => $des,
                'tir_ami' => true,
                'degats' => 0,
                'immunite_degat' => $typeDegat,
                'cible' => ['type' => 'heros', 'personnage_id' => $heros->id, 'nom' => $heros->nom],
                'pv_body_apres' => (int) $heros->pv_body,
                'faces_attaque' => [],
                'faces_defense' => [],
            ];
        }

        // Même règle qu'en face : `defense_applicable` pilote, et un héros visé
        // par un tir ami se défend exactement comme un monstre (S3).
        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: $des,
            desDefense: ($sort->effet['defense_applicable'] ?? true)
                ? (int) $heros->des_defense + $this->sorts->bonusDes($heros, 'bonus_des_defense')
                : 0,
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $heros->pv_body,
        );

        $subis = $this->degats->infligerAHeros(
            $heros, $resultat->degats, MoteurDegats::SOURCE_TIR_AMI, ['sort' => $sort->nom],
        );
        $this->sorts->reveillerHeros($heros); // être attaqué réveille

        if ((int) $heros->pv_body === 0 && $subis > 0) {
            $cible['etat']->update(['tombe' => true]); // C4
        }

        return [
            'des_degats' => $des,
            'tir_ami' => true,
            'cible' => ['type' => 'heros', 'personnage_id' => $heros->id, 'nom' => $heros->nom],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            // ⚠ Les DÉGÂTS et la CHUTE sont relus après application, jamais
            // repris de `$resultat` : celui-ci porte ce que `Engine\Combat` a
            // calculé AVANT qu'un écouteur de `HerosVaSubirDegats` ait pu
            // réduire le coup. Publier le calcul plutôt que le fait ferait
            // mentir le journal dès la première réaction portée.
            'degats' => $subis,
            'pv_body_apres' => (int) $heros->pv_body,
            'cible_tombee' => (int) $heros->pv_body === 0 && $subis > 0,
            ...$resultat->pourJournal(),
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

        // `resistance` PILOTE la façon dont la cible résiste. Un seul mot
        // aujourd'hui — `jet_mind`, le jet binaire de Engine\SortMental — et il
        // reste le défaut : la clé décrivait jusqu'ici ce que `type = mental`
        // imposait de toute façon. La lire ici permet d'en ajouter d'autres
        // sans toucher au routage par type.
        $resistance = (string) ($sort->effet['resistance'] ?? MotsClesSort::RESISTANCE_JET_MIND);

        if ($resistance !== MotsClesSort::RESISTANCE_JET_MIND) {
            throw ValidationException::withMessages([
                'option_id' => "Résistance inconnue pour {$sort->nom} : {$resistance}.",
            ]);
        }

        $resultat = (new SortMental($this->des))->resoudre($mind);

        $payload = [
            'cible' => $cible['type'] === 'monstre'
                ? [
                    'type' => 'monstre',
                    'instance_id' => $cible['monstre']->id,
                    'nom' => $cible['monstre']->nomAffiche(),
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
            if ((bool) data_get($sort->effet, 'saute_tour', false)) {
                $this->sorts->poserConditionMonstre($cible['monstre'], MoteurSorts::MONSTRE_SAUTE_TOUR);
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
     * Traverser la Pierre : « danger de rester bloqué dans la roche massive »
     * (Witch Lord, reference/18_extensions.md §3).
     *
     * Le héros qui TERMINE son mouvement dans la roche tombe (0 PV). Notre
     * moteur n'a pas de mort instantanée — il n'a que `tombe`, à terre et
     * relevable (décision de René, 2026-08-06) —, mais l'issue est de fait
     * fatale : atteindre un compagnon PRIS DANS UN MUR demande le même sort.
     *
     * Volontairement indépendant du buff : on juge la case, pas l'intention.
     * Un héros qu'un autre effet déposerait dans la roche tomberait pareillement.
     */
    private function verifierRocheMortelle(EtatPersonnageQuete $etat): void
    {
        if ($etat->tombe || $etat->position_x === null || $etat->quete === null) {
            return;
        }

        $carte = $etat->quete->carte;

        if ($carte === null
            || ! Grille::depuisCarte($carte)->estRoche((int) $etat->position_x, (int) $etat->position_y)) {
            return;
        }

        $etat->update(['tombe' => true]);
        $etat->personnage?->update(['pv_body' => 0]);
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
            // `revele` autant qu'`actif` : un monstre dormant (salle non
            // découverte) n'est pas une cible légale — garde-fou contre un menu
            // périmé (reprise) dont la liste de cibles pointe un monstre
            // redevenu dormant/éloigné dans le monde restauré.
            $instance = $quete->instancesMonstres()
                ->whereKey($cibleId)
                ->where('etat', 'actif')
                ->where('revele', true)
                ->with('monstre')
                ->first();

            if ($instance === null) {
                throw ValidationException::withMessages(['parametres' => 'Cible invalide : ce monstre n\'est plus une cible active et visible.']);
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
    /**
     * Équiper / ranger une pièce en PLEINE QUÊTE (doc 01 §149) : action du tour
     * (créneau ACTION → forfait le déplacement restant, E1). Réutilise le service
     * Equipement (mêmes garde-fous deux-mains / capacité de sac qu'au hub).
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreEquipement(Groupe $groupe, Personnage $personnage, array $option, array $acteur, bool $equiper): array
    {
        $ligneId = (int) data_get($option, 'parametres.inventaire_id', 0);
        $ligne = $personnage->inventaire()->with('objet')->whereKey($ligneId)->first();

        if ($ligne === null) {
            throw ValidationException::withMessages(['option_id' => 'Objet introuvable dans le sac de ce héros.']);
        }

        $ligne = $equiper
            ? $this->equipement->equiper($personnage, $ligne)
            : $this->equipement->desequiper($personnage, $ligne);

        $payload = [
            'type' => $equiper ? 'equiper' : 'desequiper',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'objet' => $ligne->objet?->nom,
            'emplacement' => $ligne->emplacement,
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

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

        // La case du tombé ne doit porter AUCUNE autre figure (un tombé n'occupe
        // plus sa case, donc un allié/monstre a pu s'y placer) : il faut de la
        // place pour qu'il se relève. La grille exclut déjà les tombés — la case
        // est « traversable » ssi personne d'autre ne s'y tient.
        if (! $this->grille($quete, exceptPersonnageId: $cibleId)
            ->estTraversable((int) $cible->position_x, (int) $cible->position_y)) {
            throw ValidationException::withMessages([
                'option_id' => 'Impossible de le relever : une autre figure occupe sa case.',
            ]);
        }

        // Debout à 1 POINT — Body ou Mind, celui qui est à zéro (décision de
        // René, 2026-08-06).
        //
        // ⚠ INTENTION, à ne pas « corriger » une troisième fois. Cette valeur a
        // déjà fait l'aller-retour : 1 PV → moitié des PV max (pour casser la
        // boucle « relevé/retombe ») → 1 PV. Le compromis est assumé parce que
        // `relever` n'est PAS une action de combat : c'est le dernier recours
        // quand il ne reste ni potion ni sort de soin, et on s'en sert
        // typiquement APRÈS l'engagement, pour ramener un compagnon. Hors
        // combat, repartir à 1 point ne boucle sur rien — rien ne frappe.
        // Relever au milieu d'une mêlée reste possible, et reste un pari.
        //
        // Les deux jauges sont traitées, pas seulement Body : c'est celle qui
        // est tombée à zéro qui remonte. ⚠ Aucun chemin ne réduit `pv_mind`
        // d'un héros aujourd'hui (seuls des soins l'augmentent), la branche
        // Mind est donc correcte mais dormante — elle le restera tant qu'un
        // effet ne saura pas entamer l'esprit.
        $soins = [];
        if ((int) $cible->personnage->pv_body <= 0) {
            $soins['pv_body'] = 1;
        }
        if ((int) $cible->personnage->pv_mind <= 0) {
            $soins['pv_mind'] = 1;
        }
        // ⚠ Tombé SANS jauge à zéro : on le remet debout, POINT — surtout pas
        // « pv_body = 1 ». Un héros peut être à terre avec des PV positifs (il
        // a bu sa potion de soin, qui ne relève pas ; il a chuté dans la roche),
        // et un repli à 1 lui RETIRERAIT des points au lieu de l'aider.
        $cible->update(['tombe' => false]);

        if ($soins !== []) {
            $cible->personnage->update($soins);
        }

        $payload = [
            'type' => 'relever',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'cible' => ['personnage_id' => $cible->personnage_id, 'nom' => $cible->personnage->nom],
            'pv_body' => (int) $cible->personnage->fresh()->pv_body,
            'pv_mind' => (int) $cible->personnage->fresh()->pv_mind,
            'jauges_relevees' => array_keys($soins),
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Ouvrir une porte VERROUILLÉE par CLÉ au contact (doc 14 §3.3) : le héros
     * doit être adjacent à la porte et porter l'objet-clé. État persistant.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreOuvrirPorte(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $acteur,
    ): array {
        $x = (int) data_get($option, 'parametres.porte.x', -1);
        $y = (int) data_get($option, 'parametres.porte.y', -1);
        $cote = (string) data_get($option, 'parametres.porte.cote', 'e');

        $cible = $quete->carte === null ? null
            : $this->portes->porteFermeeAdjacente($quete->carte, (int) $etat->position_x, (int) $etat->position_y);

        if ($cible === null
            || (int) $cible['porte']['x'] !== $x
            || (int) $cible['porte']['y'] !== $y
            || (string) ($cible['porte']['cote'] ?? 'e') !== $cote) {
            throw ValidationException::withMessages(['option_id' => 'Aucune porte fermée adjacente à cette position.']);
        }

        // Porte simplement CLOSE (E2) : ouverture à la main, sans clé. Sinon
        // (verrouillée) il faut la clé du verrou.
        $aMain = $this->portes->ouvrableAMain($cible['porte']);
        $verrou = (array) ($cible['porte']['verrou'] ?? []);

        if (! $aMain
            && (($verrou['type'] ?? null) !== 'cle' || ! $this->portes->possedeCle($personnage, $verrou))) {
            throw ValidationException::withMessages(['option_id' => 'La clé requise est absente de l\'inventaire.']);
        }

        $cause = $aMain ? 'main' : 'cle';
        $this->portes->ouvrir($groupe, $quete->carte, $cible['index'], $cause, $acteur);

        // Comme au plateau : le contenu de la salle (monstres compris) se
        // révèle à l'OUVERTURE de la porte, pas en attendant qu'un héros y
        // mette les pieds (doc 06/14 — cf. decouvrirSalle pour le détail).
        foreach ($this->sallesAdjacentesPorte($quete, $cible['porte']) as $salleAdjacente) {
            $this->revelerSalle($groupe, $quete, $salleAdjacente);
        }

        $payload = [
            'type' => 'ouvrir_porte',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'cause' => $cause,
            'porte' => ['x' => $x, 'y' => $y, 'cote' => $cote],
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Génie, second mode : « ouvre une porte AU CHOIX » (Kellar's Keep p. 15).
     *
     * Aucune adjacence requise — c'est tout l'intérêt : ouvrir à distance une
     * porte que des figures bloquent, ou dégager un passage sans traverser la
     * salle. Aucune clé non plus : le génie force le verrou. Comme toute
     * ouverture, la salle derrière se révèle (monstres compris).
     *
     * @param  array<string, mixed>  $option
     * @return array<string, mixed>
     */
    private function sortOuvrePorte(Quete $quete, Sort $sort, array $option): array
    {
        $x = (int) data_get($option, 'parametres.porte.x', -1);
        $y = (int) data_get($option, 'parametres.porte.y', -1);
        $cote = (string) data_get($option, 'parametres.porte.cote', 'e');

        $index = null;
        foreach ((array) data_get($quete->carte?->grille, 'portes', []) as $i => $porte) {
            if ((int) $porte['x'] === $x && (int) $porte['y'] === $y
                && (string) ($porte['cote'] ?? 'e') === $cote) {
                $index = $i;
                break;
            }
        }

        if ($index === null || $quete->carte === null) {
            throw ValidationException::withMessages(['option_id' => 'Cette porte n\'existe pas sur la carte.']);
        }

        $porte = (array) data_get($quete->carte->grille, "portes.{$index}");
        $this->portes->ouvrir($quete->groupe, $quete->carte, $index, 'sort', ['type' => 'sort', 'nom' => $sort->nom]);

        foreach ($this->sallesAdjacentesPorte($quete, $porte) as $salleAdjacente) {
            $this->revelerSalle($quete->groupe, $quete, $salleAdjacente);
        }

        return [
            'mode' => 'ouvre_porte',
            'porte' => ['x' => $x, 'y' => $y, 'cote' => $cote],
        ];
    }

    /**
     * Actionner un LEVIER au contact (doc 14 §3.3) : bascule en `ouverte`
     * toute porte liée par verrou {type: levier, levier_id}. État persistant.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreActionnerLevier(
        Groupe $groupe,
        Quete $quete,
        EtatPersonnageQuete $etat,
        array $option,
        array $acteur,
    ): array {
        $lx = (int) data_get($option, 'parametres.levier.x', -1);
        $ly = (int) data_get($option, 'parametres.levier.y', -1);

        $leviers = $quete->carte === null ? []
            : $this->portes->leviersAdjacents($quete->carte, (int) $etat->position_x, (int) $etat->position_y);

        $levier = collect($leviers)->first(fn ($l) => $l['x'] === $lx && $l['y'] === $ly);

        if ($levier === null) {
            throw ValidationException::withMessages(['option_id' => 'Aucun levier adjacent à cette position.']);
        }

        $ouvertes = [];
        $portesOuvertes = [];
        foreach ($this->portes->portes($quete->carte) as $index => $porte) {
            if (($porte['etat'] ?? null) === MoteurPortes::ETAT_OUVERTE) {
                continue;
            }
            if (($porte['verrou']['type'] ?? null) === 'levier'
                && (string) ($porte['verrou']['levier_id'] ?? '') === (string) $levier['levier_id']) {
                $this->portes->ouvrir($groupe, $quete->carte, $index, 'levier', $acteur);
                $ouvertes[] = ['x' => (int) $porte['x'], 'y' => (int) $porte['y']];
                $portesOuvertes[] = $porte;
            }
        }

        // Un levier ouvre une porte : la salle derrière se révèle, comme si un
        // héros l'avait poussée lui-même.
        $this->revelerDerriere($groupe, $quete, $portesOuvertes);

        $payload = [
            'type' => 'actionner_levier',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'levier' => ['x' => $lx, 'y' => $ly, 'levier_id' => $levier['levier_id']],
            'portes_ouvertes' => $ouvertes,
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * « Fouiller — trésor » (doc 14 §3.2) : on PIOCHE une carte de fouille, à la
     * HeroQuest. Le deck est bâti au démarrage de la quête (DeckFouille) et
     * pioché SANS REMISE — la composition du gabarit est donc garantie, là où
     * l'ancien tirage pondéré n'en donnait qu'une espérance biaisée.
     *
     * Issues : `tresor` (or au groupe) · `potion` (rangée au sac du fouilleur) ·
     * `artefact` (le coffre désigné, une arme unique, au plus une par quête) ·
     * `errant` (monstre instancié au contact, sans plafond — sa carte revient
     * sous le paquet ; il jouera au tour des monstres) · `piege` (« Piège de coffre » appliqué TOUT
     * DE SUITE au fouilleur, jamais posé sur la grille) · `rien`.
     *
     * La **salle-coffre ne consomme aucune carte** : son butin est un bonus net.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    /**
     * Fouiller un MEUBLE au contact (doc 17) — coffre, tombeau, armoire…
     *
     * Une seule fois pour tout le GROUPE : un meuble est un objet physique, le
     * premier qui l'ouvre le vide. C'est ce qui le distingue de la fouille de
     * salle, qui est une par héros.
     *
     * Le butin vient du MÊME deck que la fouille de salle : un seul barème de
     * trésor pour le donjon, et le meuble consomme donc une carte.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreFouilleMobilier(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $acteur,
    ): array {
        $index = (int) data_get($option, 'parametres.index', -1);
        $carteQuete = $quete->carte;

        if ($carteQuete === null) {
            throw ValidationException::withMessages(['option_id' => 'Cette quête n\'a pas de carte.']);
        }

        // Revalidé ici : le menu peut dater d'avant qu'un compagnon ne vide le
        // meuble, ou d'avant un déplacement qui a éloigné le héros.
        $adjacents = $this->mobilier->fouillablesAdjacents(
            $carteQuete, (int) $etat->position_x, (int) $etat->position_y,
        );
        $meuble = collect($adjacents)->firstWhere('index', $index);

        if ($meuble === null) {
            throw ValidationException::withMessages([
                'option_id' => 'Ce meuble n\'est plus à ta portée, ou il a déjà été fouillé.',
            ]);
        }

        $this->mobilier->marquerFouille($carteQuete, $index);

        $payload = $this->appliquerButin($this->deck->piocher($quete), [
            'type' => 'fouille_mobilier',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'mobilier' => $meuble['nom'],
        ], $groupe, $quete, $personnage, $etat);

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    private function resoudreFouilleTresor(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $option,
        array $acteur,
    ): array {
        $salle = $this->salleA($quete, (int) $etat->position_x, (int) $etat->position_y);

        if ($salle === null) {
            throw ValidationException::withMessages(['option_id' => 'On ne fouille un trésor que dans une salle.']);
        }

        // Une seule fouille de trésor par salle (anti-farm) : on marque la
        // salle EN BASE (§2.16 — comme l'exploration, c'est de l'état durable).
        if ($quete->aFouille($salle, (int) $personnage->id)) {
            throw ValidationException::withMessages(['option_id' => 'Tu as déjà fouillé cette salle.']);
        }

        // Interrogé AVANT de marquer : `marquerTresorFouille` inscrit la salle
        // dans la liste dont `coffrePlein` se déduit.
        $coffre = $quete->coffrePlein($salle);
        $quete->marquerTresorFouille($salle, (int) $personnage->id);

        // Un coffre ne consomme aucune carte du deck : son butin est un bonus
        // net. La salle du fond rend l'arme unique, celles ouvertes par une porte
        // secrète rendent or ou potion. Mais UNE SEULE FOIS pour le groupe : les
        // fouilleurs suivants cherchent dans la salle et piochent normalement.
        $carte = $coffre
            ? $this->deck->carteCoffre($quete, $salle)
            : $this->deck->piocher($quete);

        $payload = $this->appliquerButin($carte, [
            'type' => 'fouille_tresor',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'salle' => $salle,
        ], $groupe, $quete, $personnage, $etat);

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Applique le butin d'une carte de fouille (deck ou coffre) et complète le
     * payload : or au pot commun, objet au fouilleur, errant sur le plateau,
     * piège déclenché.
     *
     * Partagé par la fouille de SALLE et celle du MOBILIER — deux entrées, un
     * seul barème : les laisser diverger, c'est se retrouver avec deux tables
     * de trésor à maintenir.
     *
     * @param  array<string, mixed>  $carte
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function appliquerButin(
        array $carte,
        array $payload,
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
    ): array {
        $issue = (string) ($carte['issue'] ?? 'rien');
        $payload['issue'] = $issue;
        $payload['deck_restant'] = count($quete->deckFouille());

        foreach (['coffre', 'deck_vide'] as $drapeau) {
            if (! empty($carte[$drapeau])) {
                $payload[$drapeau] = true;
            }
        }

        if ($issue === 'tresor') {
            $or = max(0, (int) ($carte['or'] ?? 0));
            $groupe->update(['or' => (int) $groupe->or + $or]);
            $payload['or'] = $or;
        } elseif ($issue === 'potion' || $issue === 'artefact') {
            $payload = [...$payload, ...$this->remettreButin($carte, $personnage, $issue)];
        } elseif ($issue === 'errant') {
            $errant = $this->spawnErrant($quete, $etat);

            if ($errant === null) {
                // Budget errant épuisé / bestiaire indisponible : rien ne sort.
                $payload['issue'] = 'rien';
                $payload['errant_indisponible'] = true;
            } else {
                $payload['monstre'] = [
                    'instance_id' => $errant->id,
                    'nom' => $errant->nomAffiche(),
                    'x' => (int) $errant->position_x,
                    'y' => (int) $errant->position_y,
                ];
            }
        } elseif ($issue === 'piege') {
            $piege = Piege::query()->where('nom', 'Piège de coffre')->first()
                ?? Piege::query()->orderBy('id')->first();
            // narrer: false — ChoixController dispatche déjà la narration de
            // l'action ; sans ça le coffre piégé en produisait deux.
            $payload['declenchement'] = $this->pieges->declencherEphemere(
                $groupe, $personnage, $etat, $piege, 'fouille_tresor', narrer: false,
            );
        }

        return $payload;
    }

    /**
     * Remet un objet (potion ou artefact) au FOUILLEUR — le premier butin en
     * nature du projet : jusqu'ici seuls l'achat au marché et la restauration de
     * snapshot créaient une ligne d'inventaire.
     *
     * Sac plein : on remet QUAND MÊME, en dépassement, et on lève `sac_deborde`
     * (décision de René). Refuser un artefact unique le perdrait définitivement ;
     * le héros régularise en équipant l'arme, ce qui la sort du sac.
     *
     * @param  array<string, mixed>  $carte
     * @return array<string, mixed>
     */
    private function remettreButin(array $carte, Personnage $personnage, string $issue): array
    {
        $objet = Objet::find((int) ($carte['objet_id'] ?? 0));

        if ($objet === null) {
            // Catalogue incomplet : on ne fabrique pas d'objet fantôme, la
            // fouille est simplement blanche.
            return ['issue' => 'rien', 'objet_indisponible' => true];
        }

        $deborde = ! RangementObjet::peutRanger($personnage, $objet);
        RangementObjet::ranger($objet, $personnage->id);

        $ajout = [
            'objet' => [
                'id' => $objet->id,
                'nom' => $objet->nom,
                'categorie' => $objet->categorie,
                'rarete' => $objet->rarete,
                'emplacement' => $objet->emplacement,
            ],
        ];

        if ($deborde) {
            $ajout['sac_deborde'] = true;
        }

        return $ajout;
    }

    /**
     * Instancie un monstre ERRANT (doc 14 §3.2) depuis le bestiaire, placé sur une
     * case libre proche du fouilleur, actif et révélé (il jouera au tour des
     * monstres). null si aucune place libre autour du héros.
     *
     * SANS PLAFOND : sa carte revient sous le paquet comme les autres, elle doit
     * donc mordre à chaque fois.
     */
    private function spawnErrant(Quete $quete, EtatPersonnageQuete $etat): ?InstanceMonstre
    {
        // AUCUN plafond (décision de René) : la carte « monstre errant » repart
        // sous le paquet comme les autres, donc elle revient — et elle doit
        // mordre à chaque fois. Un budget qui s'épuise transformait la carte la
        // plus fréquente du deck (6 sur 24) en carte blanche.
        //
        // Toujours le MÊME monstre pour une quête donnée — « le monstre errant
        // de la quête » du plateau : le moins cher du bestiaire de base, choix
        // déterministe.
        $monstre = Monstre::query()
            ->where('tier', 'base')
            ->where('cout', '>', 0)
            ->orderBy('cout')
            ->orderBy('id')
            ->first();

        if ($monstre === null) {
            return null;
        }

        $place = $this->caseLibreProche($quete, (int) $etat->position_x, (int) $etat->position_y);

        if ($place === null) {
            return null;
        }

        return $quete->instancesMonstres()->create([
            'monstre_id' => $monstre->id,
            'pv_body' => $monstre->pv_body,
            'pv_mind' => $monstre->pv_mind,
            'position_x' => $place['x'],
            'position_y' => $place['y'],
            'etat' => 'actif',
            'elite' => false,
            'revele' => true, // errant : surgit et attaque
        ]);
    }

    /**
     * Première case traversable LIBRE proche de (x, y) : adjacence d'abord,
     * puis anneau Manhattan croissant (rayon ≤ 3). null si rien de libre.
     *
     * @return array{x: int, y: int}|null
     */
    private function caseLibreProche(Quete $quete, int $x, int $y): ?array
    {
        $grille = $this->grille($quete);

        for ($rayon = 1; $rayon <= 3; $rayon++) {
            for ($dx = -$rayon; $dx <= $rayon; $dx++) {
                $dy = $rayon - abs($dx);
                foreach (array_unique([$dy, -$dy]) as $signeY) {
                    $cx = $x + $dx;
                    $cy = $y + $signeY;
                    if ($grille->estTraversable($cx, $cy)) {
                        return ['x' => $cx, 'y' => $cy];
                    }
                }
            }
        }

        return null;
    }

    /**
     * Logique partagée après qu'un héros a joué (via une condition ou une
     * action normale) : détection fin de quête + déclenchement phase monstres.
     *
     * @param  array<string, mixed>  $resultat
     * @return array<string, mixed>
     */
    /**
     * Le combat est-il terminé ? — plus aucun monstre **ENGAGÉ**, c'est-à-dire
     * ni vaincu ni encore dormant derrière une porte close.
     *
     * ⚠ Ce n'est PAS « plus aucun monstre dans le donjon » (décision de René,
     * 2026-08-06). `etat = actif` signifie seulement « pas encore vaincu » :
     * une quête garde des monstres actifs mais `revele = 0` dans les salles
     * jamais ouvertes. Les confondre repoussait la fin du combat au nettoyage
     * COMPLET du donjon — un buff « un combat » couvrait alors toute la
     * descente. On termine bien le combat en cours quand la salle est nettoyée,
     * même s'il reste des ennemis endormis ailleurs ; rouvrir une porte plus
     * loin rouvre un NOUVEAU combat.
     */
    private function combatTermine(Quete $quete): bool
    {
        return ! $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true)
            ->exists();
    }

    /**
     * Expire les buffs `fin_du_combat` (Potion de rage, Peau de Pierre) dès que
     * plus aucun monstre n'est engagé. Idempotent : appelable après chaque
     * action sans risque.
     */
    private function verifierFinDuCombat(Quete $quete): void
    {
        if ($this->combatTermine($quete)) {
            $this->sorts->expirerBuffsQuete($quete, DureeEffet::FIN_DU_COMBAT);
        }
    }

    /**
     * Plus aucun monstre actif du tout : le donjon est nettoyé (victoire).
     *
     * Passe par un helper car trois chemins y mènent (déplacement, action,
     * phase des alliés) : les laisser diverger, c'est garantir qu'un buff
     * survive par l'un d'eux. Un donjon nettoyé implique un combat terminé,
     * d'où la délégation — une seule définition de la fin du combat.
     *
     * @param  array<string, mixed>  $resultat
     * @return array<string, mixed>
     */
    private function donjonNettoye(array $resultat, Quete $quete): array
    {
        $resultat['donjon_nettoye'] = true;
        $this->verifierFinDuCombat($quete);

        return $resultat;
    }

    private function apresActionHeros(array $resultat, Groupe $groupe, Quete $quete): array
    {
        // Hook post-combat (portes « monstres_vaincus ») aussi sur les chemins
        // à retour anticipé (héros endormi/commandé).
        $this->revelerDerriere($groupe, $quete, $this->portes->ouvrirParMonstresVaincus($groupe, $quete));

        // Fin du COMBAT (plus aucun monstre engagé), avant la fin de QUÊTE :
        // les dormants derrière les portes closes ne prolongent pas un combat.
        $this->verifierFinDuCombat($quete);

        if (! $quete->instancesMonstres()->where('etat', 'actif')->exists()) {
            return $this->donjonNettoye($resultat, $quete);
        }

        $enAttente = $quete->etatsPersonnages()
            ->where('a_joue', false)
            ->where('tombe', false)
            ->exists();

        if (! $enAttente) {
            $resultat = $this->jouerFinDeRound($resultat, $groupe, $quete);
            $this->verifierFinDuCombat($quete); // les alliés ont pu achever le dernier
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
            'a_joue' => false, 'a_deplace' => false, 'a_agi' => false,
            'deplacement_tour' => null, 'deplacement_restant' => null,
            'bonus_sort_utilise' => false,
            'attaque_supplementaire' => false,
        ]);

        // Fin de round, APRÈS la phase des monstres : c'est le début du prochain
        // tour des héros. Les effets `prochain_tour` (Voile de Brume) expirent
        // donc ici — ils ont couvert la phase des monstres, ce qui est tout leur
        // intérêt.
        $this->sorts->expirerBuffsQuete($quete, DureeEffet::PROCHAIN_TOUR);

        // Puis le décompte des durées EXPRIMÉES EN TOURS (Empoisonné 3 tours…).
        $this->sorts->decrementerDurees($quete);

        Journal::ajouter($groupe, 'systeme', ['action' => 'nouveau_tour', 'quete_id' => $quete->id]);

        // Tous les héros tombés → quête échouée, retour au hub : le groupe
        // vote recharger (POST reprise) ou abandonner (doc 05 §6) — les
        // snapshots de la quête sont CONSERVÉS pour la reprise.
        if (! $quete->etatsPersonnages()->where('tombe', false)->exists()) {
            $quete->update(['etat' => 'echouee']);
            Journal::ajouter($groupe, 'systeme', ['action' => 'quete_echouee', 'quete_id' => $quete->id]);
            $groupe->update(['phase' => 'hub', 'quete_courante_id' => null]);

            // Alliés (3.5) consommés même en cas d'échec de la quête.
            GroupeMercenaire::where('groupe_id', $groupe->id)->delete();

            return ['actions' => $actions];
        }

        // Snapshot `nouveau_tour` après la phase des monstres (contrat
        // « Snapshots & reprise ») : seul le dernier est conservé.
        $this->sauvegarde->snapshotter($groupe->refresh(), Sauvegarde::ETIQUETTE_NOUVEAU_TOUR);

        return ['actions' => $actions];
    }

    /**
     * Un héros en (hx,hy) est-il au contact de l'instance, en tenant compte de
     * l'emprise des grandes figurines (3.9) ? Le héros est au contact dès qu'il
     * jouxte N'IMPORTE QUELLE case de l'emprise.
     *
     * `$diagonale` élargit le voisinage de 4 à 8 cases pour les ARMES LONGUES
     * (Bâton, Épée longue). Le défaut `false` est ce qui rend l'asymétrie
     * canonique possible : les appels côté MONSTRE (riposte, tour de Zargon)
     * ne le passent jamais, donc un monstre ne frappe jamais en diagonale même
     * quand le héros, lui, le peut — le livret qualifie explicitement cette
     * case de « safe » (p. 14, cf. reference/16_armurerie.md §6.2).
     *
     * ⚠ `MenuMoteur::monstreAuContact()` porte la MÊME règle : garder les deux
     * en phase, sinon le menu propose une attaque que le résolveur refuse.
     */
    private function heroAuContact(InstanceMonstre $instance, int $hx, int $hy, bool $diagonale = false): bool
    {
        $e = $instance->monstre->emprise();

        for ($dy = 0; $dy < $e['h']; $dy++) {
            for ($dx = 0; $dx < $e['l']; $dx++) {
                $ex = abs(((int) $instance->position_x + $dx) - $hx);
                $ey = abs(((int) $instance->position_y + $dy) - $hy);

                // Tchebychev (8 voisins) pour une arme longue, Manhattan (4) sinon.
                if (($diagonale ? max($ex, $ey) : $ex + $ey) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Le héros a-t-il acquis le nœud d'arbre exactement nommé `$nom` (CompetenceSeeder) ? */
    private function possedeCompetence(Personnage $personnage, string $nom): bool
    {
        return $personnage->competences()->where('nom', $nom)->exists();
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
        $nomMonstre = $instance->nomAffiche();
        $acteur = ['type' => 'monstre', 'id' => $instance->id, 'nom' => $nomMonstre];

        // Sommeil (doc 02 §7) : le monstre endormi NE JOUE PAS tant qu'il
        // n'est pas attaqué — une attaque le réveille (resoudreAttaque /
        // sortDegats retirent la condition).
        if ($this->sorts->monstreA($instance, MoteurSorts::MONSTRE_ENDORMI)) {
            $payload = ['type' => 'monstre_endormi', 'monstre' => $nomMonstre, 'action' => 'endormi'];
            Journal::ajouter($groupe, 'action', $payload, $acteur);

            return $payload;
        }

        // Tempête : « un monstre choisi PASSE SON PROCHAIN TOUR » (Kellar's Keep
        // p. 15). Le tour entier saute — ni déplacement ni attaque —, et la
        // condition est consommée à cette activation-ci. On ne bloquait
        // auparavant que l'attaque, en laissant le monstre avancer librement :
        // il refermait la distance, et le sort ne faisait que retarder d'un tour
        // un coup qu'il portait ensuite au contact.
        if ($this->sorts->monstreA($instance, MoteurSorts::MONSTRE_SAUTE_TOUR)) {
            $this->sorts->retirerConditionMonstre($instance, MoteurSorts::MONSTRE_SAUTE_TOUR);

            $payload = ['type' => 'monstre_saute_tour', 'monstre' => $nomMonstre, 'action' => 'saute_tour'];
            Journal::ajouter($groupe, 'action', $payload, $acteur);

            return $payload;
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

        // Monstre à distance (3.4) : s'il a une ligne de vue sur un héros, il TIRE
        // plutôt que de foncer au contact (au contact, il frappe en corps-à-corps,
        // un dé de moins). Sans cible en vue, il retombe sur l'approche standard
        // ci-dessous (pour gagner une ligne de tir au tour suivant).
        if ($instance->monstre->aDistance()) {
            $tir = $this->tirerSiCibleEnVue($groupe, $instance, $cibles, $grille, $acteur, $nomMonstre);

            if ($tir !== null) {
                return $tir;
            }
        }

        // Éthéré : murs, portes, mobilier et figures s'effacent. Agile : le
        // mobilier et les figures seulement. Les deux interdits que la grille ne
        // sait pas porter — ne pas finir sur une case occupée, ne pas entrer
        // dans une zone non découverte — sont tenus juste après, au moment de
        // choisir la case d'arrivée.
        if ($this->dread->aCapacite($instance, 'ethere')) {
            $grille->autoriserEthere();
        } elseif ($this->dread->aCapacite($instance, 'agile')) {
            $grille->autoriserFranchissement();
        }

        // Le rejeton s'accroche plutôt que de frapper : sa fiche porte Attaque 0,
        // sa menace est le jeton qu'il dépose sur la fiche du héros.
        $accroche = $this->dread->accrocher($groupe, $instance, $cibles, $acteur);

        if ($accroche !== null) {
            return $accroche;
        }

        // Héros le plus proche : plus court chemin vers une case adjacente
        // (sa propre case si déjà au contact).
        $meilleure = null; // [etat héros, chemin]
        foreach ($cibles as $cible) {
            if ($this->heroAuContact($instance, (int) $cible->position_x, (int) $cible->position_y)) {
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
            // Spawn : ceinturé de personne, il pond. « En alternative à chaque
            // tour » — c'est l'action de ce tour, il n'attaquera pas.
            $ponte = $this->dread->pondre($groupe, $quete, $instance, $acteur);

            return $ponte ?? ['monstre' => $nomMonstre, 'action' => 'immobile'];
        }

        [$cible, $chemin] = $meilleure;

        // Spawn : hors contact, pondre vaut mieux qu'avancer d'un pas — la
        // carte en fait une alternative au tour, pas un bonus.
        if ($chemin !== [] && ($ponte = $this->dread->pondre($groupe, $quete, $instance, $acteur)) !== null) {
            return $ponte;
        }

        // Se rapprocher : déplacement fixe du catalogue, le long du chemin.
        $departMonstre = ['x' => (int) $instance->position_x, 'y' => (int) $instance->position_y];
        $cheminParcouruMonstre = [];
        if ($chemin !== []) {
            $pas = min((int) $instance->monstre->deplacement, count($chemin));

            if ($instance->monstre->grandeTaille()) {
                // Déplacement multi-cases (3.9) — SIMPLIFICATION assumée : le BFS
                // calcule le chemin de l'ANCRE (coin haut-gauche) sans connaître
                // l'emprise. On avance donc le long de ce chemin et on s'arrête à
                // la DERNIÈRE case où l'emprise ENTIÈRE tient (empriseLibre). Une
                // grande figurine peut ainsi progresser moins loin qu'un 1×1, mais
                // ne chevauche jamais un mur ni une autre figurine. (Le BFS n'est
                // pas réécrit : on valide simplement la tenue d'emprise à l'arrivée.)
                $e = $instance->monstre->emprise();
                $arrivee = null;

                for ($i = 0; $i < $pas; $i++) {
                    if (! $grille->empriseLibre($chemin[$i]['x'], $chemin[$i]['y'], $e['l'], $e['h'])) {
                        break;
                    }
                    $arrivee = $chemin[$i];
                }

                if ($arrivee !== null) {
                    $instance->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);
                    // Sous-chemin réellement parcouru (jusqu'à l'arrivée incluse).
                    foreach ($chemin as $c) {
                        $cheminParcouruMonstre[] = ['x' => (int) $c['x'], 'y' => (int) $c['y']];
                        if ($c['x'] === $arrivee['x'] && $c['y'] === $arrivee['y']) {
                            break;
                        }
                    }
                }
            } else {
                // Une figurine traversante (éthéré/agile) peut avoir un chemin
                // qui PASSE par des cases occupées ou non découvertes. Elle les
                // traverse, mais ne s'y arrête pas : on recule jusqu'à la
                // dernière case d'arrêt légale — « jamais pour finir sur une
                // case occupée, jamais dans une zone non découverte ».
                $arrivee = $this->derniereCaseOuSArreter($quete, $instance, $chemin, $pas);

                if ($arrivee !== null) {
                    $instance->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);

                    foreach (array_slice($chemin, 0, $pas) as $c) {
                        $cheminParcouruMonstre[] = ['x' => (int) $c['x'], 'y' => (int) $c['y']];
                        if ((int) $c['x'] === (int) $arrivee['x'] && (int) $c['y'] === (int) $arrivee['y']) {
                            break;
                        }
                    }
                }
            }
        }

        // Animation case-par-case (table) : le trajet du monstre est enregistré
        // ICI — avant la branche d'attaque — pour couvrir aussi le cas
        // « s'approche PUIS frappe » dans le même tour (sinon la figurine se
        // téléporterait avant de frapper).
        if ($cheminParcouruMonstre !== []) {
            $this->mouvementsAnime[] = [
                'type' => 'monstre',
                'id' => (int) $instance->id,
                'depart' => $departMonstre,
                'chemin' => $cheminParcouruMonstre,
            ];
        }

        $adjacent = $this->heroAuContact($instance, (int) $cible->position_x, (int) $cible->position_y);

        if (! $adjacent) {
            $payload = [
                'type' => 'deplacement_monstre',
                'id' => $instance->id,
                'monstre' => $nomMonstre,
                'depart' => $departMonstre,
                'chemin' => $cheminParcouruMonstre, // animation case-par-case (table)
                'vers' => ['x' => $instance->position_x, 'y' => $instance->position_y],
            ];
            Journal::ajouter($groupe, 'action', $payload, $acteur);

            return $payload;
        }

        // Frappe de zone (capacité) : si plusieurs héros adjacents, tous sont touchés.
        if ($this->dread->aCapacite($instance, 'frappe_de_zone')) {
            $adjacents = $cibles->filter(fn (EtatPersonnageQuete $c) => $this->heroAuContact($instance, (int) $c->position_x, (int) $c->position_y)
            )->values();

            if ($adjacents->count() >= 2) {
                return $this->dread->frappeDeZone($groupe, $instance, $cibles, $acteur);
            }
        }

        // Capacité à choix tactique (3.7) : selon les PV de la cible, attaque
        // massive unique OU double attaque — décision 100 % mécanique (jamais LLM).
        $choixTactique = $instance->monstre->capacites['choix_attaque'] ?? null;
        if (is_array($choixTactique)) {
            return $this->attaqueChoixTactique($groupe, $instance, $cible, $choixTactique, $acteur, $nomMonstre);
        }

        // Attaque simple du héros adjacent — moteur seul.
        return $this->resoudreAttaqueMonstre(
            $groupe, $instance, $cible, $instance->attaqueEffective(), $acteur, $nomMonstre,
        );
    }

    /**
     * Une attaque d'un monstre contre un héros adjacent (moteur seul). La défense
     * intègre les buffs de sorts (Peau de Pierre : bonus_des_defense). Met à jour
     * les PV, réveille la cible, marque la chute (C4), journalise et diffuse le bark.
     *
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreAttaqueMonstre(
        Groupe $groupe,
        InstanceMonstre $instance,
        EtatPersonnageQuete $cible,
        int $desAttaque,
        array $acteur,
        string $nomMonstre,
    ): array {
        $personnage = $cible->personnage;

        // Garde tenace (nœud nain) : +1 dé de défense à la PREMIÈRE attaque
        // subie de la quête (faute de notion de « combat » distincte, départ
        // playtest — voir migration ajouter_garde_tenace_utilisee).
        $bonusGardeTenace = 0;
        if (! $cible->garde_tenace_utilisee && $this->possedeCompetence($personnage, self::NOEUD_GARDE_TENACE)) {
            $bonusGardeTenace = 1;
            $cible->update(['garde_tenace_utilisee' => true]);
        }

        // Tacticien (Jungles of Delthrak) : +1 dé contre une cible FLANQUÉE,
        // c'est-à-dire au contact d'un second monstre. Le tacticien lui-même ne
        // compte pas comme son propre flanc.
        $bonusFlanc = $this->dread->cibleFlanquee($instance->quete, $instance, $cible) ? 1 : 0;

        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: max(0, $desAttaque + $bonusFlanc),
            desDefense: (int) $personnage->des_defense + $this->sorts->bonusDes($personnage, 'bonus_des_defense') + $bonusGardeTenace,
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $personnage->pv_body,
        );

        $subis = $this->degats->infligerAHeros(
            $personnage, $resultat->degats, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
            ['monstre' => $instance->nomAffiche()],
        );
        $this->sorts->reveillerHeros($personnage); // être attaqué réveille (Endormi)

        // Parade spectaculaire : 2 boucliers blancs rechargent *Inspiring Tale*
        // chez les héros qui EN SONT TÉMOINS (le défenseur excepté).
        if ($cible->quete !== null) {
            $this->sorts->regainSurParade($cible->quete, $personnage, array_map(
                fn ($face) => $face->value, $resultat->facesDefense,
            ));
        }

        // Le héros vient de se défendre : les buffs `prochaine_defense`
        // (Potion de défense) sont dépensés ici. Ce déclencheur N'EXISTAIT PAS —
        // aucun chemin ne retirait un bonus de défense, et une durée 0 n'étant
        // jamais décrémentée, le bonus valait pour toute la campagne.
        $this->sorts->expirerBuffs($personnage, DureeEffet::PROCHAINE_DEFENSE);

        // Venimeux : le venin ne passe que si le coup a porté — donc sur les
        // dégâts RÉELLEMENT subis, pas sur ceux qu'annonçait le jet : un coup
        // annulé n'empoisonne pas.
        $venin = $subis > 0 && $this->dread->appliquerVenin($instance, $personnage);

        if ((int) $personnage->pv_body === 0 && $subis > 0) {
            $cible->update(['tombe' => true]); // C4 : occupe sa case, relevable
        }

        // Tacticien : « peut bouger AVANT *et* APRÈS son action ». Le second
        // mouvement est une PERMISSION, pas une obligation — c'est donc à nous
        // de décider ce qu'il en fait. Choix retenu : le décrochage. Il se
        // retire du contact après avoir frappé, ce qui est tout l'intérêt de
        // pouvoir bouger deux fois et donne au raptor sa morsure fuyante.
        $repli = $this->dread->aCapacite($instance, 'tacticien')
            ? $this->replierTacticien($instance)
            : null;

        $payload = [
            'type' => 'attaque_monstre',
            'monstre' => $nomMonstre,
            'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
            'bonus_garde_tenace' => $bonusGardeTenace,
            'bonus_flanc' => $bonusFlanc,
            'venin' => $venin,
            'repli' => $repli,
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $subis,
            'pv_body_apres' => (int) $personnage->pv_body,
            'cible_tombee' => (int) $personnage->pv_body === 0 && $subis > 0,
        ];

        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        // Bark d'ambiance : cri d'attaque du monstre, best-effort.
        $this->diffuserBark($groupe, $instance, 'attaque');

        return $payload;
    }

    /**
     * Attaque à choix tactique (3.7) : un monstre doté de la capacité
     * `choix_attaque` frappe en MODE MASSIF (une attaque unique à dés bonifiés)
     * tant que la cible a beaucoup de PV (> seuil), sinon en DOUBLE ATTAQUE (deux
     * attaques normales, interrompues si la cible tombe). Règle 100 % mécanique,
     * paramétrée par la capacité — aucune décision confiée au LLM (C2, doc 08 §5).
     *
     * @param  array<string, mixed>  $choix
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function attaqueChoixTactique(
        Groupe $groupe,
        InstanceMonstre $instance,
        EtatPersonnageQuete $cible,
        array $choix,
        array $acteur,
        string $nomMonstre,
    ): array {
        $seuil = (int) ($choix['seuil'] ?? 2);
        $personnage = $cible->personnage;

        // Cible robuste → coup massif unique (dés d'attaque bonifiés).
        if ((int) $personnage->pv_body > $seuil) {
            $bonus = (int) ($choix['massive_des_bonus'] ?? 2);
            $payload = $this->resoudreAttaqueMonstre(
                $groupe, $instance, $cible, $instance->attaqueEffective() + $bonus, $acteur, $nomMonstre,
            );
            $payload['mode'] = 'massive';

            return $payload;
        }

        // Cible affaiblie → plusieurs attaques normales, stoppées si elle tombe.
        $nombre = max(2, (int) ($choix['double_nombre'] ?? 2));
        $actions = [];

        for ($coup = 1; $coup <= $nombre; $coup++) {
            if ($cible->tombe || (int) $personnage->pv_body <= 0) {
                break;
            }

            $action = $this->resoudreAttaqueMonstre(
                $groupe, $instance, $cible, $instance->attaqueEffective(), $acteur, $nomMonstre,
            );
            $action['mode'] = 'double';
            $action['coup'] = $coup;
            $actions[] = $action;
        }

        return ['type' => 'actions_composites', 'monstre' => $nomMonstre, 'actions' => $actions];
    }

    /**
     * Comportement de tir d'un monstre à distance (3.4) : s'il a une LIGNE DE VUE
     * dégagée sur au moins un héros, il vise le plus avantageux (PV de Body les
     * plus faibles, puis le plus proche) et l'attaque — au contact en corps-à-corps
     * (dés d'attaque de mêlée, moindres) sinon à distance (`attaque_distance`).
     * Retourne null si AUCUNE cible n'est visible (→ approche standard de l'appelant).
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>|null
     */
    private function tirerSiCibleEnVue(
        Groupe $groupe,
        InstanceMonstre $instance,
        Collection $cibles,
        Grille $grille,
        array $acteur,
        string $nomMonstre,
    ): ?array {
        $ix = (int) $instance->position_x;
        $iy = (int) $instance->position_y;

        // Ligne de TIR (doc 03 §36) : une figure interposée (héros OU monstre)
        // coupe la vue — un archer ne tire pas sur un héros caché DERRIÈRE
        // d'autres figures. `$grille` porte déjà l'occupation (FabriqueGrille).
        $visibles = $cibles->filter(fn (EtatPersonnageQuete $c) => $grille->ligneDeVue($ix, $iy, (int) $c->position_x, (int) $c->position_y, figuresBloquent: true)
        )->values();

        if ($visibles->isEmpty()) {
            return null; // pas de ligne de tir → l'appelant fera approcher le monstre
        }

        // Cible la plus avantageuse : PV de Body les plus faibles (achever), puis
        // la plus proche (chemin le plus court ; inaccessible = très loin).
        $cible = $visibles->sortBy([
            fn (EtatPersonnageQuete $c) => (int) $c->personnage->pv_body,
            fn (EtatPersonnageQuete $c) => $grille->distance($ix, $iy, (int) $c->position_x, (int) $c->position_y) ?? PHP_INT_MAX,
        ])->first();

        $adjacent = $this->heroAuContact($instance, (int) $cible->position_x, (int) $cible->position_y);

        // Au contact : corps-à-corps (valeur d'attaque de mêlée, un dé de moins par
        // construction du catalogue) ; à distance : dés d'`attaque_distance`.
        $desAttaque = $adjacent
            ? $instance->attaqueEffective()
            : ($instance->attaqueDistanceEffective() ?? $instance->attaqueEffective());

        $payload = $this->resoudreAttaqueMonstre($groupe, $instance, $cible, $desAttaque, $acteur, $nomMonstre);
        $payload['portee'] = $adjacent ? 'corps_a_corps' : 'distance';

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
            // Ouvrir une porte / actionner un levier = interaction LIBRE (E2) :
            // ne consomme ni le déplacement ni l'action — on s'arrête devant la
            // porte, on l'ouvre, puis on continue son mouvement s'il reste des points.
            // Proposer de rentrer ne coûte rien : le donjon est déjà nettoyé.
            'ouvrir_porte', 'actionner_levier', 'sortie' => 'interaction',
            'concentration', 'relever', 'attente' => 'tour',
            default => 'action',
        };
    }

    /**
     * Consomme le créneau et marque le tour terminé (a_joue) seulement quand les
     * DEUX créneaux sont faits, ou immédiatement pour une action terminante.
     *
     * Déplacement FRACTIONNÉ (E1) : le créneau « mouvement » est géré par
     * resoudreDeplacement (a_deplace/deplacement_restant selon les points laissés).
     * Une ACTION hors mouvement FORFAIT le déplacement restant (a_deplace + 0).
     * Une INTERACTION libre (porte, levier) ne consomme aucun créneau.
     */
    private function marquerCreneau(EtatPersonnageQuete $etat, string $creneau, bool $bonusReserveArcanique = false, bool $bonusHeroisme = false): void
    {
        if ($creneau === 'interaction') {
            return; // ouvrir une porte / actionner un levier : libre, aucun créneau
        }

        if ($creneau === 'tour') {
            // Fin de tour EXPLICITE (« Terminer le tour », relever, concentration).
            $etat->a_joue = true;

            // Traverser la Pierre : « danger de rester bloqué dans la roche »
            // — le contrôle vient AVANT l'expiration du buff, sinon le héros
            // aurait déjà cessé de traverser au moment où on le juge.
            $this->verifierRocheMortelle($etat);

            // `ce_tour` s'arrête ICI, et pas au round : le héros décide seul de
            // la fin de son tour, donc un effet « ce tour » n'atteint jamais la
            // phase des monstres — c'est ce qui le distingue de `prochain_tour`.
            if ($etat->personnage !== null) {
                $this->sorts->expirerBuffs($etat->personnage, DureeEffet::CE_TOUR);
            }

            // Rejetons accrochés : « 1 Body Point automatique et indéfendable à
            // chaque fin de tour, cumulable ». Aucun jet, ni d'attaque ni de
            // défense — c'est le seul dégât du jeu qui ne passe par aucun dé.
            $this->rongerParRejetons($etat);
        } elseif ($creneau === 'action') {
            if ($bonusReserveArcanique) {
                // Réserve arcanique (nœud magicien) : ce sort consomme le
                // BONUS, pas le créneau action normal (déjà pris ce tour).
                $etat->bonus_sort_utilise = true;
            } elseif ($bonusHeroisme) {
                // Potion d'héroïsme : cette attaque consomme le bonus, pas le
                // créneau — le héros a bien frappé deux fois ce tour.
                $etat->attaque_supplementaire = false;
            } else {
                $etat->a_agi = true;

                // Règle du plateau (décision de René, 2026-08-07) : on se déplace
                // PUIS on agit, ou on agit PUIS on se déplace — jamais les trois.
                // Agir après avoir COMMENCÉ à bouger sacrifie donc le reste de
                // l'allonce. On n'intercale plus : fouiller au milieu de son
                // mouvement puis repartir n'est plus possible.
                //
                // ⚠ La condition porte sur « avoir déjà bougé », pas sur
                // `a_deplace` (posé seulement quand l'allonce est ÉPUISÉE) :
                // c'est `deplacement_restant`, non nul et inférieur au total du
                // tour, qui signale un mouvement entamé. Agir sans avoir bougé
                // laisse au contraire le déplacement entier.
                if ($etat->deplacement_restant !== null
                    && (int) $etat->deplacement_restant > 0
                    && (int) $etat->deplacement_restant < (int) ($etat->deplacement_tour ?? 0)) {
                    $etat->deplacement_restant = 0;
                    $etat->a_deplace = true;
                }
            }
        }
        // 'mouvement' : a_deplace / deplacement_restant déjà posés par resoudreDeplacement.

        // Le tour ne se termine QUE sur décision du joueur (créneau 'tour') : plus
        // de fin automatique quand les deux créneaux sont pris — le héros garde la
        // main (boire une potion, terminer quand il le décide).

        $etat->save();
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
     * sans incidence mécanique. Filet de sécurité pour une salle atteinte SANS
     * passer par une porte qu'on vient d'ouvrir (cf. resoudreOuvrirPorte, qui
     * révèle désormais dès l'ouverture — comme au plateau) : sans effet si la
     * salle est déjà révélée (revelerSalle est idempotent).
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

        $this->revelerSalle($groupe, $quete, $salle);
    }

    /**
     * Salle(s) directement adjacente(s) à une porte — les deux cases qu'elle
     * sépare (Grille::casesPorte). Sert à révéler la salle DÈS L'OUVERTURE de
     * la porte (resoudreOuvrirPorte), comme au plateau : on voit le contenu
     * avant même d'y entrer, sans attendre le pas suivant.
     *
     * @param  array{x: int, y: int, cote?: string}  $porte
     * @return list<int>
     */
    /**
     * « Quitter le donjon » — le donjon nettoyé, un héros PROPOSE de rentrer.
     *
     * La quête ne se termine plus d'elle-même à la mort du dernier monstre :
     * sans cette fenêtre, un groupe qui achevait le dernier garde perdait tout
     * ce qu'il n'avait pas encore fouillé — coffre à artefact et portes
     * secrètes compris, alors que rien n'oblige à explorer pour gagner.
     *
     * Le départ passe par un VOTE (décision de René) : personne ne se fait
     * couper sa fouille par un compagnon pressé.
     *
     * @param  array<string, mixed>  $option
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreQuitterDonjon(Groupe $groupe, Quete $quete, array $option, array $acteur): array
    {
        $vide = ! $quete->instancesMonstres()->where('etat', 'actif')->exists();

        if (! $quete->objectifAccompli() && ! $vide) {
            throw ValidationException::withMessages([
                'option_id' => 'Vous n\'avez pas encore accompli ce pourquoi vous êtes venus.',
            ]);
        }

        // Résolu par VoteGroupe, qui appellera terminerQuete() à la majorité.
        $vote = app(VoteGroupe::class)->lancerSortie($groupe, $acteur);

        $payload = [
            'type' => 'sortie',
            'option_id' => $option['id'],
            'libelle' => $option['libelle'] ?? null,
            'vote' => $vote,
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Révèle les salles bordant les portes qui viennent d'être OUVERTES.
     *
     * Comme au plateau, ouvrir une porte montre la salle et ses monstres. Cette
     * règle n'était appliquée que sur l'action explicite `ouvrir_porte` : un
     * LEVIER actionné ou un GARDIEN abattu ouvrait la porte en laissant la salle
     * noire et ses monstres dormants — la voie ouverte, rien à voir. Pire, le
     * client ne connaissait pas ces monstres alors que le moteur les compte
     * comme occupant leur case (FabriqueGrille), d'où des déplacements proposés
     * puis refusés.
     *
     * `revelerSalle()` est idempotent : appeler deux fois ne coûte rien.
     *
     * @param  list<array<string, mixed>>  $portes
     */
    private function revelerDerriere(Groupe $groupe, Quete $quete, array $portes): void
    {
        foreach ($portes as $porte) {
            foreach ($this->sallesAdjacentesPorte($quete, $porte) as $salle) {
                $this->revelerSalle($groupe, $quete, $salle);
            }
        }
    }

    private function sallesAdjacentesPorte(Quete $quete, array $porte): array
    {
        [$a, $b] = Grille::casesPorte($porte);

        $salles = [];
        foreach ([$a, $b] as $case) {
            $salle = $this->salleA($quete, $case['x'], $case['y']);
            if ($salle !== null) {
                $salles[] = $salle;
            }
        }

        return array_values(array_unique($salles));
    }

    /**
     * Révèle une salle (index dans carte.grille.salles) si ce n'est pas déjà
     * fait : marque « découverte » (quetes.salles_decouvertes), réveille ses
     * monstres dormants (dormants → actifs visibles, joueront dès la phase des
     * monstres de ce tour), journalise, et déclenche la narration de
     * description. Idempotent — sans effet si la salle est déjà révélée.
     * Appelée à l'entrée en salle (decouvrirSalle) ET, désormais, dès
     * l'ouverture d'une porte y donnant accès (resoudreOuvrirPorte) — comme au
     * plateau, où ouvrir une porte révèle immédiatement le contenu de la
     * salle, avant même d'y mettre les pieds.
     */
    private function revelerSalle(Groupe $groupe, Quete $quete, int $salle): void
    {
        if (in_array($salle, $quete->sallesDecouvertes(), true)) {
            return; // déjà décrite
        }

        // Persisté EN BASE (§2.16) : cet avancement conditionne le brouillard
        // de guerre, donc les cases que la manette juge accessibles. Quand il
        // vivait en cache avec un TTL, sa disparition refermait le brouillard
        // sur des zones explorées et immobilisait tout le groupe.
        $quete->marquerSalleDecouverte($salle);

        // Révélation des monstres de la salle : dormants → actifs visibles
        // (ils joueront dès la phase des monstres de ce tour).
        $s = (array) data_get($quete->carte?->grille, "salles.{$salle}");
        $aReveler = $quete->instancesMonstres()
            ->where('revele', false)
            ->whereBetween('position_x', [(int) $s['x'], (int) $s['x'] + (int) $s['largeur'] - 1])
            ->whereBetween('position_y', [(int) $s['y'], (int) $s['y'] + (int) $s['hauteur'] - 1])
            ->with('monstre')
            ->get();

        // §2.6 — on retient les NOMS de ce qui vient d'apparaître : sans eux, le
        // narrateur ne recevait qu'un « salle découverte » nu et a décrit une
        // salle vide devant trois monstres qui venaient de surgir.
        $nomsReveles = $aReveler
            ->map(fn ($i) => $i->nomAffiche())
            ->filter()
            ->values()
            ->all();

        $reveles = $aReveler->count();

        if ($reveles > 0) {
            $quete->instancesMonstres()->whereIn('id', $aReveler->pluck('id'))->update(['revele' => true]);
        }

        Journal::ajouter($groupe, 'systeme', ['action' => 'salle_decouverte', 'salle' => $salle, 'monstres_reveles' => $reveles]);

        broadcast(new MjReflechit($groupe, true));
        GenererNarration::dispatch($groupe->id, [
            'type' => 'salle_decouverte',
            'salle' => $salle,
            'theme' => data_get($quete->carte?->grille, "salles.{$salle}.theme"),
            // Ce que le groupe DÉCOUVRE à l'instant : le MJ doit en parler.
            'monstres_reveles' => $reveles,
            'monstres_noms' => $nomsReveles,
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
    public function terminerQuete(Groupe $groupe, Quete $quete): array
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

        // Alliés (3.5) CONSOMMÉS en fin de quête (décision canon) : purge.
        GroupeMercenaire::where('groupe_id', $groupe->id)->delete();

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
    private function grille(
        Quete $quete,
        ?int $exceptPersonnageId = null,
        ?int $exceptInstanceId = null,
        ?int $exceptMercenaireId = null,
        bool $traverseRoche = false,
    ): Grille {
        return FabriqueGrille::pour($quete, $exceptPersonnageId, $exceptInstanceId, $exceptMercenaireId, $traverseRoche);
    }

    /**
     * Fin de round (tous les héros ont joué) : phase ALLIÉE dédiée (3.5) — les
     * alliés scriptés jouent AVANT les monstres, HORS initiative des héros —
     * puis (s'il reste des monstres) la phase des monstres. Les alliés ayant pu
     * vaincre le dernier monstre, la victoire est revérifiée entre les deux.
     *
     * @param  array<string, mixed>  $resultat
     * @return array<string, mixed>
     */
    private function jouerFinDeRound(array $resultat, Groupe $groupe, Quete $quete): array
    {
        $allies = $this->phaseAllies($groupe, $quete);
        if ($allies['actions'] !== []) {
            $resultat['tour_allies'] = $allies;
        }

        // Les alliés ont pu nettoyer le dernier monstre → victoire avant les monstres.
        if (! $quete->instancesMonstres()->where('etat', 'actif')->exists()) {
            return $this->donjonNettoye($resultat, $quete);
        }

        $resultat['tour_monstres'] = $this->phaseMonstres($groupe, $quete);

        return $resultat;
    }

    /**
     * Phase des alliés scriptés (3.5) : chaque allié actif joue comme un
     * « monstre allié » ciblant les MONSTRES (révélés). PNJ scripté, hors
     * initiative héros. NB : le ciblage des alliés PAR les monstres est hors
     * périmètre v1 — `jouerMonstre` continue de ne viser que les héros.
     *
     * @return array{actions: list<array<string, mixed>>}
     */
    private function phaseAllies(Groupe $groupe, Quete $quete): array
    {
        $actions = [];

        $allies = GroupeMercenaire::where('groupe_id', $quete->groupe_id)
            ->where('etat', 'actif')
            ->whereNotNull('position_x')
            ->with('mercenaire')
            ->orderBy('id')
            ->get();

        foreach ($allies as $allie) {
            $monstres = $quete->instancesMonstres()
                ->where('etat', 'actif')->where('revele', true)
                ->whereNotNull('position_x')->with('monstre')->get();

            if ($monstres->isEmpty()) {
                break; // plus rien à combattre
            }

            $action = $this->jouerAllie($groupe, $quete, $allie, $monstres);
            if ($action !== null) {
                $actions[] = $action;
            }
        }

        if ($actions !== []) {
            // Un allié a pu vaincre un gardien → portes « monstres_vaincus ».
            $this->revelerDerriere($groupe, $quete, $this->portes->ouvrirParMonstresVaincus($groupe, $quete));
        }

        return ['actions' => $actions];
    }

    /**
     * Tour d'un allié : tire à distance sur le monstre visible le plus faible
     * (allié à distance avec ligne de vue) ; sinon rejoint le monstre le plus
     * proche (emprise comprise) puis frappe au contact. 100 % moteur.
     *
     * @param  \Illuminate\Support\Collection<int, InstanceMonstre>  $monstres
     * @return array<string, mixed>|null
     */
    private function jouerAllie(Groupe $groupe, Quete $quete, GroupeMercenaire $allie, $monstres): ?array
    {
        $merc = $allie->mercenaire;
        $nom = $merc->nom;
        $acteur = ['type' => 'allie', 'id' => $allie->id, 'nom' => $nom];
        $grille = $this->grille($quete, exceptMercenaireId: $allie->id);
        $ax = (int) $allie->position_x;
        $ay = (int) $allie->position_y;

        // Allié à distance : tirer sur le monstre VISIBLE le plus faible — même
        // règle que l'archer ennemi : une figure interposée coupe la ligne de tir.
        if ($merc->aDistance()) {
            $visibles = $monstres->filter(function (InstanceMonstre $m) use ($grille, $ax, $ay) {
                $e = $m->monstre->emprise();

                return $grille->ligneDeVueEmprise($ax, $ay, (int) $m->position_x, (int) $m->position_y, $e['l'], $e['h'], figuresBloquent: true);
            })->values();

            if ($visibles->isNotEmpty()) {
                $cible = $visibles->sortBy(fn (InstanceMonstre $m) => (int) $m->pv_body)->first();
                $e = $cible->monstre->emprise();
                $adjacent = $grille->adjacenteAEmprise((int) $cible->position_x, (int) $cible->position_y, $e['l'], $e['h'], $ax, $ay);
                $des = $adjacent ? (int) $merc->attaque : (int) ($merc->attaque_distance ?? $merc->attaque);

                return $this->resoudreAttaqueAllie($groupe, $allie, $cible, $des, $adjacent ? 'corps_a_corps' : 'distance', $acteur, $nom);
            }
            // Pas de ligne de tir → se rapproche comme un combattant de mêlée.
        }

        // Mêlée : rejoindre le monstre le plus proche (case adjacente à l'emprise).
        $meilleure = null; // [InstanceMonstre, chemin]
        foreach ($monstres as $m) {
            $e = $m->monstre->emprise();

            if ($grille->adjacenteAEmprise((int) $m->position_x, (int) $m->position_y, $e['l'], $e['h'], $ax, $ay)) {
                $meilleure = [$m, []];
                break;
            }

            foreach ($grille->cellulesEmprise((int) $m->position_x, (int) $m->position_y, $e['l'], $e['h']) as $cell) {
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $chemin = $grille->chemin($ax, $ay, $cell['x'] + $dx, $cell['y'] + $dy);
                    if ($chemin !== null && ($meilleure === null || count($chemin) < count($meilleure[1]))) {
                        $meilleure = [$m, $chemin];
                    }
                }
            }
        }

        if ($meilleure === null) {
            return ['type' => 'allie_immobile', 'allie' => $nom]; // aucun monstre joignable
        }

        [$cible, $chemin] = $meilleure;

        if ($chemin !== []) {
            $pas = min((int) $merc->deplacement, count($chemin));
            $arrivee = $chemin[$pas - 1];
            $allie->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);
            $ax = (int) $arrivee['x'];
            $ay = (int) $arrivee['y'];
        }

        $e = $cible->monstre->emprise();
        if ($grille->adjacenteAEmprise((int) $cible->position_x, (int) $cible->position_y, $e['l'], $e['h'], $ax, $ay)) {
            return $this->resoudreAttaqueAllie($groupe, $allie, $cible, (int) $merc->attaque, 'corps_a_corps', $acteur, $nom);
        }

        $payload = ['type' => 'deplacement_allie', 'allie' => $nom, 'vers' => ['x' => $ax, 'y' => $ay]];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Une attaque d'un allié contre un monstre (le défenseur est un monstre :
     * boucliers NOIRS, défense effective élite comprise). Met à jour les PV du
     * monstre, le réveille, journalise.
     *
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function resoudreAttaqueAllie(Groupe $groupe, GroupeMercenaire $allie, InstanceMonstre $cible, int $desAttaque, string $portee, array $acteur, string $nom): array
    {
        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: max(0, $desAttaque),
            desDefense: $cible->defenseEffective(),
            typeDefenseur: TypeFigurine::Monstre,
            pvBodyDefenseur: (int) $cible->pv_body,
        );

        $cible->update([
            'pv_body' => $resultat->pvBodyApres,
            'etat' => $resultat->pvBodyApres === 0 ? 'vaincu' : 'actif',
        ]);

        // Être attaqué réveille un monstre endormi (cohérent avec les héros).
        $this->sorts->retirerConditionMonstre($cible, MoteurSorts::MONSTRE_ENDORMI);

        $payload = [
            'type' => 'attaque_allie',
            'allie' => $nom,
            'portee' => $portee,
            'cible' => [
                'instance_id' => $cible->id,
                'nom' => $cible->nomAffiche(),
            ],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $resultat->degats,
            'pv_body_apres' => $resultat->pvBodyApres,
            'cible_vaincue' => $resultat->pvBodyApres === 0,
        ];

        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }
}
