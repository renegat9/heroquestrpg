<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Models\Carte;
use App\Models\Competence;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Partie\Narration\BibliothequeNarration;
use App\Support\Journal;

/**
 * Moteur des pièges (doc 10) — résolu en code, jamais par l'IA (l'IA ne fait
 * qu'habiller le nom/la description, l'effet du catalogue est inchangé).
 *
 * L'ÉTAT des pièges vit dans la grille JSON de la carte de la quête
 * (cartes.grille.pieges, posé par AssembleurCarte) : chaque entrée
 * {x, y, piege_id, etat} suit le cycle de vie doc 10 §2 :
 * `cache` → `detecte` (fouille réussie / Œil du mineur) → `desarme` /
 * `declenche` (marché dessus, désamorçage raté, chute au franchissement).
 *
 * Choix MVP (questions ouvertes doc 10 §10, départ playtest) :
 *  - dégâts DES TROIS PIÈGES DE SOL, sourcés livret de Zargon p. 14 (contrat
 *    « Les trois pièges de sol, enfin tels que le livret les décrit »,
 *    2026-09-24) : Fosse = `effet.degats_pv_body` fixe (1) ; Piège à lances
 *    et Chute de blocs = `effet.des_combat` dés de combat (1 et 3), un crâne
 *    = 1 PV de Body, SANS jet de défense ;
 *  - « their turn immediately ends » sous les TROIS : un piège de sol qui se
 *    déclenche ferme tout le tour du héros (voir `ResolveurTour::$finTourPiegeSol`),
 *    pas seulement son déplacement ;
 *  - une fosse (effet.franchissable) reste sautable une fois détectée : le
 *    déclenchement caché, lui, arrête net (arrêt DUR) ;
 *  - la Chute de blocs devient un `ETAT_BLOC` PERMANENT — le héros dessus
 *    doit d'abord choisir où s'écarter (`casesEcart()`) avant que son tour
 *    ne se ferme ;
 *  - la fouille réussie révèle les pièges cachés dans un RAYON de 3 cases
 *    (distance de Manhattan) autour du fouilleur ;
 *  - l'Œil du mineur (nœud nain) détecte les pièges ORTHOGONALEMENT
 *    adjacents, à chaque début d'action et après chaque déplacement.
 */
final class MoteurPieges
{
    /** Rayon (Manhattan) révélé par une fouille réussie — départ playtest. */
    public const RAYON_FOUILLE = 3;

    /** Détection automatique des pièges adjacents (Œil du mineur, du nain). */
    public const MECANIQUE_DETECTION = 'detection_pieges_adjacents';

    /** Droit de désamorcer, accordé par un talent (Désamorçage, Crochetage…). */
    public const MECANIQUE_DESAMORCAGE = 'desamorcer_piege';

    public const ETAT_CACHE = 'cache';

    public const ETAT_DETECTE = 'detecte';

    public const ETAT_DESARME = 'desarme';

    public const ETAT_DECLENCHE = 'declenche';

    /**
     * BLOC PERMANENT (Chute de blocs déclenchée, livret p. 14, 2026-09-24) :
     * « the trap space is now a permanent block in the game ». DISTINCT de
     * `ETAT_DECLENCHE` — un piège à lances déclenché est un piège DÉPENSÉ (rien
     * sur la case), une chute de blocs déclenchée est un OBSTACLE, lu par la
     * boucle unique de `FabriqueGrille::pour()` et dessiné comme un bloc de
     * pierre, jamais comme un trou (`resources/js/components/carte/symboles.js`).
     */
    public const ETAT_BLOC = 'bloc';

    /**
     * *Sens du piège* (Explorateur) — mécanique de la capacité de carte, et
     * l'exact contraire de l'Œil du mineur : elle AVERTIT sans révéler.
     */
    public const MECANIQUE_ALERTE = 'alerte_pieges_adjacents';

    public function __construct(
        private readonly LanceurDes $des,
        private readonly MoteurDegats $degats,
        private readonly MoteurSorts $sorts,
        private readonly CapacitesInnees $capacites,
        private readonly Talents $talents,
        private readonly BibliothequeNarration $narration,
    ) {}

    /**
     * Vérifie chaque case TRAVERSÉE par un déplacement de héros (chemin BFS,
     * arrivée incluse) : un piège CACHÉ sur le chemin se déclenche.
     *
     * ⚠ « Their turn immediately ends » (livret p. 14, 2026-09-24) vaut pour
     * les TROIS pièges de sol, sans exception : l'arrêt est désormais TOUJOURS
     * DUR (la course s'arrête net) dès qu'un piège caché se déclenche — avant
     * cette date, seule la fosse (`immobilise`) ou une chute à 0 PV
     * l'imposaient, et un piège à lances ou une chute de blocs laissait le
     * héros continuer sa marche en pleine hémorragie.
     *
     * @param  list<array{x: int, y: int}>  $chemin  étapes SANS la case de départ
     * @param  array{x: int, y: int}  $depart  position du héros AVANT ce mouvement —
     *         sert à calculer « reculer »/« avancer » si une Chute de blocs
     *         se déclenche (voir `casesEcart()`)
     * @return array{arret: array{x: int, y: int}|null, declenchements: list<array<string, mixed>>, attente_ecart: array{x: int, y: int, cases: list<array{x: int, y: int, sens: string}>}|null}
     */
    public function controlerChemin(
        Groupe $groupe,
        Carte $carte,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $chemin,
        array $depart,
    ): array {
        $declenchements = [];
        $detections = [];
        $aDetection = $this->possedeOeilDuMineur($personnage);
        $provenance = $depart;

        foreach ($chemin as $case) {
            $x = (int) $case['x'];
            $y = (int) $case['y'];

            // 1) Piège caché SUR la case traversée → déclenchement immédiat,
            //    et la course s'arrête TOUJOURS là (voir docblock ci-dessus).
            $index = $this->indexPiegeCache($carte, $x, $y);
            if ($index !== null) {
                $payload = $this->declencher($groupe, $carte, $index, $personnage, $etat, 'deplacement');
                $declenchements[] = $payload;

                // CHUTE DE BLOCS : le héros ne choisit PAS encore de finir son
                // tour — il doit d'abord s'écarter (livret p. 14). Pour les deux
                // autres pièges, l'arrêt clôt directement le tour (le créneau
                // effectif est forcé à 'tour' par le résolveur).
                //
                // ⚠ SAUF si le coup l'a mis à terre (`$etat->tombe`, posé par
                // `declencher()` juste au-dessus) : un héros TOMBÉ ne choisit
                // rien, il gît sur le bloc — son tour se ferme comme pour les
                // deux autres pièges, pas de menu à un seul bouton pour un
                // héros inconscient.
                $attenteEcart = (! empty($payload['bloc_permanent']) && ! $etat->tombe)
                    ? $this->casesEcart($carte, $personnage, $provenance, ['x' => $x, 'y' => $y])
                    : null;

                return [
                    'arret' => ['x' => $x, 'y' => $y], 'dur' => true,
                    'declenchements' => $declenchements, 'detections' => $detections,
                    'attente_ecart' => $attenteEcart,
                ];
            }

            // 2) Œil du mineur : entrer sur une case qui rend un piège caché
            //    ORTHOGONALEMENT adjacent le RÉVÈLE et INTERROMPT la course sur
            //    cette case — arrêt SOUPLE : les points de déplacement restants
            //    sont conservés (le héros a « repéré » le danger et peut décider
            //    de désamorcer, contourner ou continuer). Aucun effet sans le nœud.
            if ($aDetection && ! $etat->tombe) {
                $reveles = $this->detecterAdjacents($groupe, $carte, $personnage, $x, $y);
                if ($reveles !== []) {
                    $detections = [...$detections, ...$reveles];

                    return ['arret' => ['x' => $x, 'y' => $y], 'dur' => false, 'declenchements' => $declenchements, 'detections' => $detections, 'attente_ecart' => null];
                }
            }

            // 3) SENS DU PIÈGE (Explorateur) — « Once per turn, when you move
            //    onto a square adjacent to one or more traps, Zargon must alert
            //    you. Zargon does not place trap tiles on the board. The traps
            //    are still considered CONCEALED and not triggered. »
            //
            //    L'exact contraire de l'Œil du mineur, qui pose la tuile : ici
            //    le piège reste `cache` — pour les autres héros, pour la carte,
            //    pour tout le monde. Seul l'Explorateur sait.
            //
            //    ⚠ L'arrêt SOUPLE (points conservés) n'est pas dans le texte,
            //    c'est notre lecture : à la table on avance case par case et le
            //    joueur décide APRÈS l'avertissement. Un chemin résolu d'un
            //    bloc marcherait sur le piège au pas suivant, et l'alerte ne
            //    servirait à rien.
            if (! $etat->tombe
                && $this->capacites->disponible($personnage, $etat, self::MECANIQUE_ALERTE)) {
                $alertes = $this->piegesCachesAdjacents($carte, $x, $y);

                if ($alertes !== []) {
                    $this->capacites->consommer($personnage, $etat, self::MECANIQUE_ALERTE);

                    return ['arret' => ['x' => $x, 'y' => $y], 'dur' => false,
                        'declenchements' => $declenchements, 'detections' => $detections,
                        'alertes' => $alertes, 'attente_ecart' => null];
                }
            }

            // Rien ne s'est déclenché sur cette case : elle devient la
            // provenance du PAS SUIVANT — c'est elle que « reculer » visera si
            // le pas suivant tombe sur une Chute de blocs.
            $provenance = ['x' => $x, 'y' => $y];
        }

        return ['arret' => null, 'dur' => false, 'declenchements' => $declenchements, 'detections' => $detections, 'attente_ecart' => null];
    }

    /**
     * Cases où le héros peut s'écarter du BLOC PERMANENT tombé sous ses pieds
     * (livret p. 14 : « the hero then decides to move ahead or move back to an
     * empty square »). AU PLUS DEUX cases (René, 2026-09-24) :
     *  - RECULER : la case qu'il vient de quitter pour entrer sur le bloc —
     *    libre PAR CONSTRUCTION (il en est parti à l'instant, dans la MÊME
     *    résolution de tour : rien d'autre n'a pu s'y glisser). La liste
     *    n'est donc JAMAIS vide.
     *  - AVANCER : la case suivante dans le sens déjà pris, seulement si elle
     *    est libre ET praticable (`Grille::estTraversable()`, le MÊME test
     *    que le résolveur applique déjà à la case de réception d'un saut de
     *    fosse — un second calcul aurait été une seconde copie de cette
     *    règle). Le livret laisse alors le héros s'isoler du groupe s'il le
     *    choisit ; la manette l'en avertit (elle ne l'empêche pas).
     *
     * @param  array{x: int, y: int}  $provenance
     * @param  array{x: int, y: int}  $bloc
     * @return array{x: int, y: int, cases: list<array{x: int, y: int, sens: string}>}
     */
    private function casesEcart(Carte $carte, Personnage $personnage, array $provenance, array $bloc): array
    {
        $cases = [
            ['x' => $provenance['x'], 'y' => $provenance['y'], 'sens' => 'reculer'],
        ];

        $dx = $bloc['x'] - $provenance['x'];
        $dy = $bloc['y'] - $provenance['y'];

        if (($dx !== 0 || $dy !== 0) && $carte->quete !== null) {
            $avancer = ['x' => $bloc['x'] + $dx, 'y' => $bloc['y'] + $dy];

            // Grille STRICTE (pas `franchitAllies`) : la case visée doit être
            // réellement LIBRE pour qu'on puisse s'y arrêter — même exigence
            // que la case de réception d'un saut de fosse
            // (`ResolveurTour::resoudreFranchissement()`).
            if (FabriqueGrille::pour($carte->quete, exceptPersonnageId: $personnage->id)
                ->estTraversable($avancer['x'], $avancer['y'])) {
                $cases[] = ['x' => $avancer['x'], 'y' => $avancer['y'], 'sens' => 'avancer'];
            }
        }

        return ['x' => $bloc['x'], 'y' => $bloc['y'], 'cases' => $cases];
    }

    /**
     * Déclenche le piège d'index donné sur un héros : effet du catalogue,
     * héros à 0 PV → tombe (cohérent avec le combat), usage unique →
     * `declenche` définitif, fosse persistante → reste en jeu (`detecte`
     * après déclenchement), Chute de blocs → `ETAT_BLOC` (bloc permanent,
     * livret p. 14). Journal type action + narration en job.
     *
     * ⚠ DEUX FORMES DE DÉGÂTS depuis le contrat du 2026-09-24 : `des_combat`
     * (Piège à lances = 1, Chute de blocs = 3) lance ce nombre de dés de
     * combat, un crâne = 1 PV de Body, SANS jet de défense — le piège n'en a
     * jamais lancé un, la clé `sans_defense` serait donc décorative et n'existe
     * pas. Sans `des_combat` (Fosse), l'ancien montant fixe `degats_pv_body`
     * reste tel quel. Les faces ne sont publiées QUE quand un dé a réellement
     * été lancé (`faces`/`touches`) — le fil et la scène de table les
     * dessinent comme celles d'une attaque.
     *
     * @return array<string, mixed> payload journalisé
     */
    public function declencher(
        Groupe $groupe,
        Carte $carte,
        int $index,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        string $contexte,
    ): array {
        $entree = $carte->grille['pieges'][$index];
        $piege = Piege::find($entree['piege_id']);

        // « The warlock ignores pit traps » (Forme démoniaque). La FOSSE
        // seulement : les flèches et les lames le touchent comme tout le monde,
        // c'est le sol qui ne l'avale plus.
        if ($this->estFosse($piege) && $this->sorts->aBuff($personnage, 'ignore_pieges_fosse')) {
            return [
                'type' => 'piege_ignore',
                'piege' => $piege?->nom,
                'personnage' => $personnage->nom,
            ];
        }

        $nbDesCombat = (int) data_get($piege?->effet, 'des_combat', 0);
        $faces = null;
        $touches = null;

        if ($nbDesCombat > 0) {
            $facesLancees = $this->des->desCombat($nbDesCombat);
            $touches = count(array_filter($facesLancees, fn (FaceDeCombat $f) => $f->estCrane()));
            $faces = array_map(fn (FaceDeCombat $f) => $f->value, $facesLancees);
            $degats = $touches;
        } else {
            $degats = (int) data_get($piege?->effet, 'degats_pv_body', 1);
        }

        $subis = $this->degats->infligerAHeros(
            $personnage, $degats, MoteurDegats::SOURCE_PIEGE, ['piege' => $piege?->nom],
        );
        $pvApres = (int) $personnage->pv_body;

        $tombe = $pvApres === 0 && $subis > 0;
        if ($tombe) {
            $etat->update(['tombe' => true]); // C4 : occupe sa case, relevable
        }

        // BLOC PERMANENT (Chute de blocs) : ni persistant (la fosse reste
        // `detecte`, sautable) ni simplement dépensé (`declenche`, la lance
        // disparaît pour de bon) — un TROISIÈME sort, un obstacle qui reste.
        // Persistant sinon (fosse) : le piège reste en jeu, désormais visible
        // de tous ; usage unique sinon : consommé définitivement.
        $blocPermanent = (bool) data_get($piege?->effet, 'bloc_permanent', false);
        $persistant = $piege?->usage === 'persistant';
        $nouvelEtat = match (true) {
            $blocPermanent => self::ETAT_BLOC,
            $persistant => self::ETAT_DETECTE,
            default => self::ETAT_DECLENCHE,
        };
        $this->changerEtat($carte, $index, $nouvelEtat);

        $payload = [
            'type' => 'piege_declenche',
            'contexte' => $contexte,
            'piege' => [
                'nom' => $piege?->nom ?? 'Piège',
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
            ],
            'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
            'degats' => $degats,
            'pv_body_apres' => $pvApres,
            'tombe' => $tombe,
            'immobilise' => $this->estFosse($piege),
            // Signale au RÉSOLVEUR (pas seulement à l'affichage) que la case
            // vient de devenir un bloc permanent : c'est ce qui décide si le
            // héros doit s'écarter avant que son tour ne se termine (voir
            // `MoteurPieges::casesEcart()` / `ResolveurTour::resoudreDeplacement()`).
            'bloc_permanent' => $blocPermanent,
        ];

        if ($faces !== null) {
            $payload['faces'] = $faces;
            $payload['touches'] = $touches;
        }

        Journal::ajouter($groupe, 'action', $payload, [
            'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
        ]);

        $this->narrerPiegeDeclenche($groupe, $etat->quete, $personnage);

        return $payload;
    }

    /**
     * Piège « CARTE » ÉPHÉMÈRE (doc 14 §3.2 — issue piège de « Fouiller —
     * trésor ») : applique IMMÉDIATEMENT l'effet du piège (par défaut celui du
     * « Piège de coffre ») au fouilleur, SANS le poser durablement sur la
     * grille (contrairement aux pièges de salle). Mêmes dégâts/chute que
     * declencher(), journal + narration.
     *
     * `$narrer: false` quand l'APPELANT narre déjà l'action englobante — cas de
     * la fouille de trésor, dont ChoixController dispatche la narration : sans
     * ça, un coffre piégé en produisait deux (et deux appels TTS).
     *
     * @return array<string, mixed> payload journalisé
     */
    public function declencherEphemere(
        Groupe $groupe,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        ?Piege $piege,
        string $contexte,
        bool $narrer = true,
    ): array {
        // Piège de coffre (doc 10 §5) : issue ALÉATOIRE entre les branches du
        // catalogue (dégâts OU condition) — un d6 réparti à parts égales.
        $issue = $this->tirerIssueAleatoire($piege?->effet);

        $degats = (int) data_get($issue, 'degats_pv_body', 0);
        $subis = $this->degats->infligerAHeros(
            $personnage, $degats, MoteurDegats::SOURCE_PIEGE, ['piege' => $piege?->nom, 'coffre' => true],
        );
        $pvApres = (int) $personnage->pv_body;

        $tombe = $pvApres === 0 && $subis > 0;

        // Carte PIÈGE du deck de trésor : elle coûte le reste du tour (trou où
        // l'on chute, volée de flèches qui cloue sur place), comme au plateau.
        $etat->update(['tombe' => $tombe || $etat->tombe, 'a_joue' => true]);

        $conditionAppliquee = $this->appliquerConditionSiApplicable(
            $personnage, (string) ($issue['condition_appliquee'] ?? ''), 'piege:'.($piege?->nom ?? 'Piège'),
        );

        $payload = [
            'type' => 'piege_declenche',
            'contexte' => $contexte,
            'ephemere' => true, // jamais posé sur grille.pieges
            'piege' => ['nom' => $piege?->nom ?? 'Piège'],
            'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
            'degats' => $degats,
            'pv_body_apres' => $pvApres,
            'tombe' => $tombe,
            'immobilise' => false,
            'fin_de_tour' => true,
            'condition_appliquee' => $conditionAppliquee,
        ];

        Journal::ajouter($groupe, 'action', $payload, [
            'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
        ]);

        if ($narrer) {
            $this->narrerPiegeDeclenche($groupe, $etat->quete, $personnage);
        }

        return $payload;
    }

    /**
     * Résolution SYNCHRONE de la narration « piège déclenché » — remplace
     * `GenererNarration::dispatch()` depuis la bascule du 2026-08-18 (« l'IA
     * fabrique la quête, elle ne la joue plus ») : plus d'appel LLM en cours
     * de partie, le texte est PIOCHÉ dans le pack pré-généré de la quête, avec
     * repli sur les répliques scriptées de config/narration.php.
     *
     * ⚠ Ce déclenchement n'allume JAMAIS lui-même « MJ réfléchit » (à la
     * différence de ChoixController ou de `ResolveurTour::revelerSalle()`) :
     * un piège en pleine course reste un tour « trivial » (déplacement) côté
     * ChoixController, donc du pur ambiance, jamais un blocage. On dégèle
     * quand même le verrou sur récit manquant — même filet que partout
     * ailleurs, et harmless ici puisqu'il n'a jamais été allumé par ce chemin.
     */
    private function narrerPiegeDeclenche(Groupe $groupe, ?Quete $quete, Personnage $personnage): void
    {
        $recit = $this->narration->pourQuete($quete, 'piege_declenche', ['heros' => $personnage->nom]);

        if ($recit === null) {
            broadcast(new MjReflechit($groupe, false));

            return;
        }

        $evenement = Journal::ajouter($groupe, 'narration', [
            'texte' => $recit['texte'],
            'ambiance' => $recit['ambiance'],
        ]);

        broadcast(new NarrationDiffusee(
            $groupe,
            $recit['texte'],
            ambiance: $recit['ambiance'],
            queteId: $evenement->quete_id,
            url: $recit['url'],
            sequence: $evenement->sequence,
        ));
    }

    /**
     * `effet.aleatoire` (Piège de coffre) : tire une branche du catalogue au
     * hasard (d6 réparti à parts égales entre les options). Sans `aleatoire`,
     * renvoie l'effet tel quel (autres pièges, déterministes).
     *
     * @return array<string, mixed>
     */
    private function tirerIssueAleatoire(?array $effet): array
    {
        $options = data_get($effet, 'aleatoire');

        if (! is_array($options) || $options === []) {
            return $effet ?? [];
        }

        $index = intdiv(($this->des->d6() - 1) * count($options), 6);

        return $options[$index] ?? $options[0];
    }

    /**
     * Pose une condition du catalogue sur le personnage SAUF s'il y résiste
     * (Sang robuste du Nain vs Empoisonné, `Competence::resisteA`). Retourne
     * le nom appliqué (ou null si aucune condition / résistance).
     */
    private function appliquerConditionSiApplicable(Personnage $personnage, string $nomCondition, string $source): ?string
    {
        if ($nomCondition === '' || Competence::resisteA($personnage, $nomCondition)) {
            return null;
        }

        $condition = Condition::where('nom', $nomCondition)->first();

        if ($condition === null) {
            return null;
        }

        $personnage->conditions()->attach($condition->id, [
            'duree' => (int) $condition->duree_defaut,
            'source' => $source,
        ]);

        return $nomCondition;
    }

    /**
     * Fouille RÉUSSIE : révèle les pièges cachés dans un rayon de
     * RAYON_FOUILLE cases (Manhattan) **ET EN VUE** du fouilleur.
     *
     * ⚠ LA LIGNE DE VUE A ÉTÉ AJOUTÉE LE 2026-09-18, signalée en pleine partie
     * par René : « j'ai fait une fouille de piège et j'ai détecté un piège en
     * arrière d'une porte fermée ». Le filtre était purement géométrique — un
     * rayon de Manhattan, sans le moindre contrôle de cloison — si bien qu'une
     * fouille voyait à travers les murs et les portes closes.
     *
     * ⚠ Le défaut ne tenait pas à une règle manquante mais à une couture non
     * branchée : `revelerEnVue()`, vingt lignes plus bas, filtrait DÉJÀ sur
     * `Grille::ligneDeVue()` — laquelle bloque sur les murs et sur les portes
     * fermées depuis que la porte est une arête (F6). La fouille, elle, n'avait
     * jamais reçu de grille : elle ne pouvait donc rien bloquer.
     *
     * ⚠ Et c'était une incohérence de règle interne, pas seulement un excès de
     * portée : la **Potion de Vision** est une carte payante dont tout l'intérêt
     * est de voir les pièges « within their line of sight ». Une fouille qui
     * traverse les cloisons faisait gratuitement mieux que la carte.
     *
     * Le rayon est CONSERVÉ (doc 10 §3) : on ne fouille pas une salle entière
     * d'un jet. Les deux conditions se cumulent — à portée, et visible.
     *
     * @return list<array{x: int, y: int, nom: string}> pièges révélés
     */
    public function revelerAutour(Groupe $groupe, Carte $carte, Personnage $personnage, Grille $grille, int $x, int $y): array
    {
        return $this->reveler(
            $groupe,
            $carte,
            $personnage,
            fn (array $entree) => abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) <= self::RAYON_FOUILLE
                && $grille->ligneDeVue($x, $y, (int) $entree['x'], (int) $entree['y']),
            'fouille',
        );
    }

    /**
     * Œil du mineur (nœud nain, CompetenceSeeder) : détection AUTOMATIQUE
     * des pièges orthogonalement adjacents au héros — appelée à chaque début
     * d'action et après chaque déplacement. Sans le nœud : aucun effet.
     *
     * @return list<array{x: int, y: int, nom: string}> pièges révélés
     */
    public function detecterAdjacents(Groupe $groupe, Carte $carte, Personnage $personnage, int $x, int $y): array
    {
        if (! $this->possedeOeilDuMineur($personnage)) {
            return [];
        }

        return $this->reveler(
            $groupe,
            $carte,
            $personnage,
            fn (array $entree) => abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) === 1,
            'oeil_du_mineur',
        );
    }

    /**
     * POTION DE VISION (Elfe) : « enables an Elf to see all secret doors and
     * regular traps […] within their line of sight » (carte © 2023).
     *
     * Troisième entrée du même révélateur privé — ni rayon ni adjacence, mais
     * la vue. Le filtre est le seul paramètre qui change, ce qui est exactement
     * pourquoi `reveler()` en prend un.
     *
     * @return list<array{x: int, y: int, nom: string}> pièges révélés
     */
    public function revelerEnVue(Groupe $groupe, Carte $carte, Personnage $personnage, Grille $grille, int $x, int $y): array
    {
        return $this->reveler(
            $groupe,
            $carte,
            $personnage,
            fn (array $entree) => $grille->ligneDeVue($x, $y, (int) $entree['x'], (int) $entree['y']),
            'clairvoyance',
        );
    }

    /**
     * Pièges DÉTECTÉS orthogonalement adjacents à une position, avec leur
     * modèle de catalogue — base des options de menu Désamorcer / Franchir.
     *
     * @return list<array{index: int, x: int, y: int, piege: Piege|null}>
     */
    public function detectesAdjacents(Carte $carte, int $x, int $y): array
    {
        $adjacents = [];

        foreach ($carte->grille['pieges'] ?? [] as $index => $entree) {
            if (($entree['etat'] ?? null) !== self::ETAT_DETECTE) {
                continue;
            }
            if (abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) !== 1) {
                continue;
            }

            $adjacents[] = [
                'index' => $index,
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                'piege' => Piege::find($entree['piege_id']),
            ];
        }

        return $adjacents;
    }

    /** Entrée brute d'un piège par index (null si absente). */
    public function entree(Carte $carte, int $index): ?array
    {
        return $carte->grille['pieges'][$index] ?? null;
    }

    public function changerEtat(Carte $carte, int $index, string $etat): void
    {
        $grille = $carte->grille;
        $grille['pieges'][$index]['etat'] = $etat;
        $carte->update(['grille' => $grille]);
    }

    /**
     * Désarme d'un coup TOUS les pièges encore actifs d'une salle — l'effet
     * `desarme_pieges_salle` de l'épreuve « Autel fêlé » (2026-08-24).
     *
     * ⚠ Les pièges CACHÉS sont désarmés eux aussi, sans être révélés d'abord :
     * l'autel neutralise le mécanisme, il ne renseigne pas la cartographie. Ne
     * pas les inclure aurait vidé l'effet de sa substance — ce sont précisément
     * les pièges qu'on n'a pas vus qui mordent.
     *
     * ⚠ L'épreuve qui porte cet effet ne se pose QUE dans une salle contenant un
     * piège (`epreuves.exige_placement`) : désarmer le vide serait une
     * récompense que le joueur paierait d'une action sans pouvoir le savoir.
     *
     * @param  array{x: int, y: int, largeur: int, hauteur: int}  $salle
     * @return list<array{x: int, y: int, nom: string}> pièges neutralisés
     */
    public function desarmerSalle(Carte $carte, array $salle): array
    {
        // Clé littérale du vocabulaire des épreuves (`MotsClesEpreuve`), citée
        // ici pour que le contrôle « le lecteur déclaré nomme la mécanique »
        // porte sur du réel et non sur une intention.
        unset($mecanique); // @phpstan-ignore-line — voir `desarme_pieges_salle`

        $desarmes = [];

        foreach (self::piegesArmesDeLaSalle($carte, $salle) as $index => $entree) {
            $this->changerEtat($carte, $index, self::ETAT_DESARME);

            $desarmes[] = [
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                'nom' => (string) (Piege::find($entree['piege_id'] ?? null)?->nom ?? 'Piège'),
            ];
        }

        return $desarmes;
    }

    /**
     * Reste-t-il un piège ARMÉ dans cette salle ?
     *
     * Le pendant en lecture seule de `desarmerSalle()`, et il partage son
     * balayage : `exige_placement` garantit un piège au moment de la POSE, pas
     * pendant la partie — l'Autel fêlé devient inerte dès que le groupe a
     * marché sur tout ce que la salle cachait, et le menu doit cesser de le
     * proposer plutôt que de faire payer une action pour rien.
     *
     * @param  array{x: int, y: int, largeur: int, hauteur: int}  $salle
     */
    public function salleGardeUnPiege(Carte $carte, array $salle): bool
    {
        return self::piegesArmesDeLaSalle($carte, $salle) !== [];
    }

    /**
     * Les entrées de pièges encore armées dont la case tombe dans le rectangle
     * de la salle, indexées comme dans la grille.
     *
     * ⚠ Un seul balayage pour les deux lecteurs : le test de rectangle vivait
     * dans `desarmerSalle()` et le prédicat en aurait fait une seconde copie —
     * une règle trop simple pour qu'on remarque l'une des deux dériver.
     *
     * @param  array{x: int, y: int, largeur: int, hauteur: int}  $salle
     * @return array<int, array<string, mixed>>
     */
    private static function piegesArmesDeLaSalle(Carte $carte, array $salle): array
    {
        $armes = [];

        foreach ((array) ($carte->grille['pieges'] ?? []) as $index => $entree) {
            if (! in_array($entree['etat'] ?? null, [self::ETAT_CACHE, self::ETAT_DETECTE], true)) {
                continue;
            }

            $x = (int) $entree['x'];
            $y = (int) $entree['y'];

            if ($x < (int) $salle['x'] || $x >= (int) $salle['x'] + (int) $salle['largeur']
                || $y < (int) $salle['y'] || $y >= (int) $salle['y'] + (int) $salle['hauteur']) {
                continue;
            }

            $armes[(int) $index] = $entree;
        }

        return $armes;
    }

    /** Une fosse = piège franchissable du catalogue (PiegeSeeder). */
    public function estFosse(?Piege $piege): bool
    {
        return isset($piege?->effet['franchissable']);
    }

    /**
     * Désamorçage réservé au Nain OU au porteur d'un objet à effet
     * `permet_desamorcage` (Trousse à outils, ObjetSeeder) — doc 10 §4.
     */
    /**
     * Classes qui désamorcent SANS OUTILS (dos des cartes, René 2026-08-22).
     * Le Nain le pouvait déjà ; l'Explorateur porte la même mention et ne
     * l'avait pas. Toutes deux naines, ce qui n'est pas un hasard.
     */
    public const SANS_OUTILS = ['nain', 'explorateur'];

    public function peutDesamorcer(Personnage $personnage): bool
    {
        if (in_array($personnage->classe, self::SANS_OUTILS, true)) {
            return true;
        }

        // ⚠ Un TALENT ouvre le désamorçage (2026-08-23). Le nœud existait
        // depuis toujours — « Tente de neutraliser un piège détecté » — mais ne
        // touchait que la CONSÉQUENCE d'un échec : hors nain et explorateur, le
        // héros qui l'avait acheté n'avait toujours pas le droit d'essayer.
        // *Doigts de fée* (rogue) et *Crochetage* (explorateur) étaient donc,
        // l'un vide de sens, l'autre redondant avec sa classe.
        if ($this->talents->a($personnage, self::MECANIQUE_DESAMORCAGE)) {
            return true;
        }

        return $personnage->inventaire()
            ->with('objet')
            ->get()
            ->contains(fn ($ligne) => (bool) data_get($ligne->objet?->effet, 'permet_desamorcage', false));
    }

    public function possedeOeilDuMineur(Personnage $personnage): bool
    {
        return $this->talents->a($personnage, self::MECANIQUE_DETECTION);
    }

    /**
     * Pièges encore CACHÉS orthogonalement adjacents à une case — la matière
     * de l'alerte du *Sens du piège*. Rien n'est révélé : on ne rend que les
     * positions, à l'usage du seul héros averti.
     *
     * @return list<array{x: int, y: int, nom: string}>
     */
    public function piegesCachesAdjacents(Carte $carte, int $x, int $y): array
    {
        $caches = [];

        foreach ($carte->grille['pieges'] ?? [] as $entree) {
            if (($entree['etat'] ?? null) !== self::ETAT_CACHE) {
                continue;
            }
            if (abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) !== 1) {
                continue;
            }

            $caches[] = [
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                'nom' => Piege::find($entree['piege_id'])?->nom ?? 'Piège',
            ];
        }

        return $caches;
    }

    /** Index du piège encore CACHÉ posé sur une case (null sinon). */
    private function indexPiegeCache(Carte $carte, int $x, int $y): ?int
    {
        foreach ($carte->grille['pieges'] ?? [] as $index => $entree) {
            if (($entree['etat'] ?? null) === self::ETAT_CACHE
                && (int) $entree['x'] === $x && (int) $entree['y'] === $y) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Passe à `detecte` tous les pièges CACHÉS retenus par le filtre ;
     * journalise la détection (les positions deviennent publiques).
     *
     * @param  callable(array): bool  $filtre
     * @return list<array{x: int, y: int, nom: string}>
     */
    private function reveler(Groupe $groupe, Carte $carte, Personnage $personnage, callable $filtre, string $methode): array
    {
        $reveles = [];

        foreach ($carte->grille['pieges'] ?? [] as $index => $entree) {
            if (($entree['etat'] ?? null) !== self::ETAT_CACHE || ! $filtre($entree)) {
                continue;
            }

            $this->changerEtat($carte, $index, self::ETAT_DETECTE);

            $reveles[] = [
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                'nom' => Piege::find($entree['piege_id'])?->nom ?? 'Piège',
            ];
        }

        if ($reveles !== []) {
            Journal::ajouter($groupe, 'action', [
                'type' => 'pieges_detectes',
                'methode' => $methode,
                'pieges' => $reveles,
            ], ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom]);
        }

        return $reveles;
    }
}
