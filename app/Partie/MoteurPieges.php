<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Engine\MotsClesEquipement;
use App\Engine\TypeFigurine;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Models\Carte;
use App\Models\Competence;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Piege;
use App\Models\Quete;
use App\Models\Sort;
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
 * `declenche` (marché dessus, désamorçage raté, chute au franchissement) —
 * ou `fosse_ouverte` (Fosse déclenchée, 2026-09-27) / `bloc` (Chute de blocs).
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
 *  - un piège `effet.franchissable` (Fosse, et Chute de blocs tant qu'elle
 *    n'est pas tombée — livret p. 14) se saute une fois détecté ;
 *  - un piège DÉTECTÉ ne se foule plus gratuitement (René, 2026-09-27) : y
 *    marcher le déclenche comme s'il était caché. Seuls le saut et le
 *    désamorçage le passent indemne — le trajet, lui, le contourne quand un
 *    détour est payable (`ResolveurTour::cheminDuHeros()`) ;
 *  - la Chute de blocs devient un `ETAT_BLOC` PERMANENT — le héros dessus
 *    doit d'abord choisir où s'écarter (`casesEcart()`) avant que son tour
 *    ne se ferme ;
 *  - la fouille réussie révèle les pièges cachés de la salle ou du couloir
 *    du fouilleur, en entier (`ZoneFouille`, 2026-09-27) ;
 *  - l'Œil du mineur (nœud nain) détecte les pièges ORTHOGONALEMENT
 *    adjacents, à chaque début d'action et après chaque déplacement.
 */
final class MoteurPieges
{

    /** Détection automatique des pièges adjacents (Œil du mineur, du nain). */
    public const MECANIQUE_DETECTION = 'detection_pieges_adjacents';

    /** Droit de désamorcer, accordé par un talent (Désamorçage, Crochetage…). */
    public const MECANIQUE_DESAMORCAGE = 'desamorcer_piege';

    public const ETAT_CACHE = 'cache';

    public const ETAT_DETECTE = 'detecte';

    public const ETAT_DESARME = 'desarme';

    public const ETAT_DECLENCHE = 'declenche';

    /**
     * FOSSE OUVERTE (René, 2026-09-27) : une Fosse déclenchée. Le trou reste
     * (livret p. 14) — elle demeure ARMÉE (y marcher la déclenche encore) et
     * se SAUTE, mais ne se DÉSAMORCE plus (« non applicable une fois
     * déclenchée », doc 16 §7.3). Elle repassait à `ETAT_DETECTE` : sur la
     * carte, un trou déjà ouvert se lisait alors exactement comme un piège
     * détecté intact, légende « désamorçable au contact » comprise.
     */
    public const ETAT_FOSSE_OUVERTE = 'fosse_ouverte';

    /** États d'un piège qui se déclenche encore quand on marche dessus. */
    public const ETATS_ARMES = [self::ETAT_CACHE, self::ETAT_DETECTE, self::ETAT_FOSSE_OUVERTE];

    /** États d'un piège ARMÉ que le groupe CONNAÎT : ceux qu'on saute, qu'on évite. */
    public const ETATS_CONNUS_ARMES = [self::ETAT_DETECTE, self::ETAT_FOSSE_OUVERTE];

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
     * AMORCÉ (Fireburst Trap, Wizards of Morcar, doc 18 §5/§9) : « a token
     * remains until the beginning of Zargon's turn, when it will explode ».
     * DISTINCT des autres sorts : ni `declenche` (rien n'a encore explosé),
     * ni `fosse_ouverte`/`bloc` (ce n'est pas un obstacle de case) — un
     * troisième type de « dépensé mais pas tout de suite ». Exclu
     * d'`ETATS_ARMES` : un héros qui repasse sur la case ne redéclenche rien,
     * le jeton couve déjà. Résolu par
     * `MoteurPieges::explosionsFireburstEnAttente()`, appelée en tête de
     * `ResolveurTour::phaseMonstres()` — « le début du tour de Zargon » de ce
     * moteur.
     */
    public const ETAT_AMORCE = 'amorce';

    /**
     * RETIENT (Grasping Vine Trap, Jungles of Delthrak p. 4) : « If they roll a
     * skull, they suffer 1 Body Point of damage and are held in place by the
     * vines. […] They cannot move from the square until they or another
     * adjacent hero spends an action to destroy the vines. The hero is then
     * freed and the trap is removed from the board. » Un état qui n'est NI
     * armé (le héros qu'il tient ne le redéclenche pas, personne d'autre ne
     * peut se tenir sur sa case) NI dépensé : les lianes sont encore LÀ, et la
     * carte doit le montrer — publié, dessiné, jusqu'à ce que
     * `libererLianes()` le retire (état `desarme`). La clé `retenu` de
     * l'entrée porte le héros tenu : l'état DURABLE vit dans la grille de la
     * carte, comme celui de tous les pièges.
     */
    public const ETAT_RETIENT = 'retient';

    /**
     * `effet.desarmage_special` de la LAME BALANÇOIRE (Against the Ogre
     * Horde p. 5) : procédure de désamorçage PROPRE à ce piège, lue par
     * `MenuMoteur::generer()` (libellé) et `ResolveurTour::resoudreDesamorcage()`
     * (résolution) — jamais le jet de Body de la trousse ordinaire des autres
     * pièges. Le Nain réussit automatiquement ; tout autre héros habilité
     * lance UN SEUL dé de combat (bouclier = succès, crâne = déclenchement
     * immédiat de la zone entière).
     */
    public const DESARMAGE_LAME_BALANCIERE = 'lame_balanciere';

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
        private readonly AnnoncesTalents $annonces,
        private readonly Equipement $equipement,
    ) {}

    /**
     * Vérifie chaque case TRAVERSÉE par un déplacement de héros (chemin BFS,
     * arrivée incluse) : un piège ARMÉ sur le chemin — caché, ou détecté et
     * foulé sciemment (2026-09-27) — se déclenche.
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
        // LIANES AGRIPPANTES esquivées (`piege_esquive`) : le piège a jailli et
        // n'a rien pris, le héros CONTINUE sa course (p. 4 : « they successfully
        // dodge the vines and may continue their movement »). Une liste à part
        // de `$declenchements`, jamais dedans : le résolveur ferme le tour de
        // tout héros qui en a un (`finTourPiegeSol`), et une esquive ne le ferme pas.
        $esquives = [];
        $aDetection = $this->possedeOeilDuMineur($personnage);
        $provenance = $depart;

        foreach ($chemin as $case) {
            $x = (int) $case['x'];
            $y = (int) $case['y'];

            // 1) Piège ARMÉ SUR la case traversée → déclenchement immédiat,
            //    et la course s'arrête TOUJOURS là (voir docblock ci-dessus).
            //    Détecté compris (René, 2026-09-27) : sans quoi un piège connu
            //    se foulait sans rien subir, et sauter ou désamorcer ne
            //    servaient à rien.
            $index = $this->indexPiegeArme($carte, $x, $y);
            if ($index !== null) {
                $payload = $this->declencherSurCase($groupe, $carte, $index, $personnage, $etat, 'deplacement', $x, $y);

                if (($payload['type'] ?? null) === 'piege_esquive') {
                    $esquives[] = $payload;
                    $provenance = ['x' => $x, 'y' => $y];

                    continue;
                }

                $declenchements[] = $payload;

                // Forme démoniaque : « ignores pit traps » — le sol ne l'avale
                // pas, il n'a donc aucune raison de s'arrêter. Sans cette
                // ligne, une fosse DÉTECTÉE (persistante) l'aurait arrêté à
                // chaque passage.
                if (($payload['type'] ?? null) === 'piege_ignore') {
                    $provenance = ['x' => $x, 'y' => $y];

                    continue;
                }

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

                // TÉLÉPORTATION / OURAGAN (Wizards of Morcar) : l'ARRÊT n'est
                // plus la case du piège elle-même mais la DESTINATION que le
                // producteur a résolue — le héros ne « marche » pas jusqu'à
                // l'autre bout, il y apparaît (téléportation) ou s'y trouve
                // rejeté (bourrasque). `'dur' => true` referme le tour
                // comme pour tout déclenchement — même mécanisme générique
                // que les trois pièges de sol, aucune clé `fin_tour` dédiée.
                $arretCoord = in_array($payload['type'] ?? null, ['piege_teleporte', 'piege_bourrasque'], true)
                    ? ($payload['destination'] ?? ['x' => $x, 'y' => $y])
                    : ['x' => $x, 'y' => $y];

                return [
                    'arret' => $arretCoord, 'dur' => true,
                    'declenchements' => $declenchements, 'detections' => $detections,
                    'esquives' => $esquives, 'attente_ecart' => $attenteEcart,
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

                    return ['arret' => ['x' => $x, 'y' => $y], 'dur' => false, 'declenchements' => $declenchements, 'detections' => $detections, 'esquives' => $esquives, 'attente_ecart' => null];
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

                    // Un talent qui s'active tout seul se VOIT (2026-09-25) :
                    // le fil disait « X pressent 2 pièges tout près » sans
                    // jamais nommer Sens du piège — le joueur ne savait pas
                    // POURQUOI son tour s'arrêtait là. Le popup le dit.
                    $noeudAlerte = $this->capacites->noeud($personnage, self::MECANIQUE_ALERTE);

                    if ($noeudAlerte !== null) {
                        $nombreAlertes = count($alertes);
                        $this->annonces->annoncer($personnage, $noeudAlerte, $nombreAlertes > 1
                            ? "repère {$nombreAlertes} pièges cachés à proximité"
                            : 'repère un piège caché à proximité');
                    }

                    return ['arret' => ['x' => $x, 'y' => $y], 'dur' => false,
                        'declenchements' => $declenchements, 'detections' => $detections,
                        'alertes' => $alertes, 'esquives' => $esquives, 'attente_ecart' => null];
                }
            }

            // Rien ne s'est déclenché sur cette case : elle devient la
            // provenance du PAS SUIVANT — c'est elle que « reculer » visera si
            // le pas suivant tombe sur une Chute de blocs.
            $provenance = ['x' => $x, 'y' => $y];
        }

        return ['arret' => null, 'dur' => false, 'declenchements' => $declenchements, 'detections' => $detections, 'esquives' => $esquives, 'attente_ecart' => null];
    }

    /**
     * Le déclenchement d'un piège ARMÉ sur la case (x, y) — la résolution
     * choisie selon la forme de l'entrée ET l'effet du catalogue. UN seul
     * endroit, deux appelants : le pas d'un héros (`controlerChemin()`) et le
     * déplacement FORCÉ (`repousserFigure()`, *Hurricane* des Sorciers).
     *
     * @return array<string, mixed>
     */
    private function declencherSurCase(
        Groupe $groupe,
        Carte $carte,
        int $index,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        string $contexte,
        int $x,
        int $y,
    ): array {
                $entreeGrille = $carte->grille['pieges'][$index];
                $catalogueArme = Piege::find($entreeGrille['piege_id']);
                $declencheurSpecial = (string) data_get($catalogueArme?->effet, 'declencheur', '');

                // LAME BALANÇOIRE : une entrée à ZONE se résout par
                // `declencherZone()` (plusieurs cibles, défense normale),
                // jamais `declencher()` (une seule victime, jamais de
                // défense) — la forme de l'entrée (`zone` posée par
                // `AssembleurCarte::placerLameBalanciere()`) le dit sans
                // requête supplémentaire au catalogue.
                //
                // Wizards of Morcar (doc 18 §5/§9) : TROIS résolutions
                // dédiées, reconnues sur l'EFFET du catalogue plutôt que sur
                // une forme d'entrée — `teleportation` (paire A/B),
                // `declencheur: 'hurricane'` (recul de tout le couloir),
                // `declencheur: 'fireburst_differe'` (amorce, explose au
                // prochain tour du MJ — voir `explosionsFireburstEnAttente()`).
        return match (true) {
                    isset($entreeGrille['zone']) => $this->declencherZone($groupe, $carte, $index, $personnage, $etat, $contexte, ['x' => $x, 'y' => $y]),
                    (bool) data_get($catalogueArme?->effet, 'teleportation', false) => $this->declencherTeleportation($groupe, $carte, $index, $personnage, $etat, $contexte),
                    $declencheurSpecial === 'hurricane' => $this->declencherHurricane($groupe, $carte, $index, $personnage, $etat, $contexte),
                    $declencheurSpecial === 'fireburst_differe' => $this->declencherFireburst($groupe, $carte, $index, $personnage, $etat, $contexte),
                    default => $this->declencher($groupe, $carte, $index, $personnage, $etat, $contexte),
                };
    }

    /**
     * DÉPLACEMENT FORCÉ d'un héros le long d'un chemin déjà calculé (*Hurricane*,
     * sort du Storm Master : « forced back […] until they hit a wall, another
     * figure, fall down a pit trap or trigger another trap »).
     *
     * Mur et figure arrêtent le chemin AVANT son calcul (l'appelant le coupe) ;
     * ici, le premier piège ARMÉ rencontré se déclenche et fixe l'arrêt. Aucune
     * des finesses du pas volontaire (Œil du mineur, Sens du piège) : on
     * n'arrête pas un héros souffle-en-l'air parce qu'il « repère » quelque chose.
     *
     * @param  list<array{x: int, y: int}>  $chemin  étapes SANS la case de départ
     * @return array{arret: array{x: int, y: int}|null, declenchements: list<array<string, mixed>>}
     */
    public function repousserFigure(
        Groupe $groupe,
        Carte $carte,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $chemin,
    ): array {
        foreach ($chemin as $case) {
            $x = (int) $case['x'];
            $y = (int) $case['y'];
            $index = $this->indexPiegeArme($carte, $x, $y);

            if ($index === null) {
                continue;
            }

            $payload = $this->declencherSurCase($groupe, $carte, $index, $personnage, $etat, 'repoussement', $x, $y);

            // La fosse ignorée (Forme démoniaque) ne retient pas : on continue.
            // Les lianes esquivées non plus : le jet a été favorable, le héros
            // poursuit sa course forcée.
            if (in_array($payload['type'] ?? null, ['piege_ignore', 'piege_esquive'], true)) {
                continue;
            }

            $arret = in_array($payload['type'] ?? null, ['piege_teleporte', 'piege_bourrasque'], true)
                ? ($payload['destination'] ?? ['x' => $x, 'y' => $y])
                : ['x' => $x, 'y' => $y];

            return ['arret' => $arret, 'declenchements' => [$payload]];
        }

        return ['arret' => null, 'declenchements' => []];
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
    public function casesEcart(Carte $carte, Personnage $personnage, array $provenance, array $bloc): array
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
     * `declenche` définitif, fosse persistante → reste en jeu
     * (`fosse_ouverte`, distincte d'un piège détecté intact), Chute de blocs → `ETAT_BLOC` (bloc permanent,
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
        // SPIDERSTEP ELIXIR : « move unaffected through squares containing
        // REVELED pit traps » — la fosse que l'on VOIT (détectée, ou déjà
        // ouverte) ne fait pas tomber ; une fosse cachée surprend toujours.
        $fosseRevelee = $this->estFosse($piege)
            && in_array($entree['etat'] ?? null, self::ETATS_CONNUS_ARMES, true)
            && $this->sorts->aBuff($personnage, MotsClesEquipement::FRANCHIT_FOSSES_REVELEES);

        if ($fosseRevelee || ($this->estFosse($piege) && $this->sorts->aBuff($personnage, 'ignore_pieges_fosse'))) {
            return [
                'type' => 'piege_ignore',
                'piege' => $piege?->nom,
                'personnage' => $personnage->nom,
            ];
        }

        $nbDesCombat = (int) data_get($piege?->effet, 'des_combat', 0);
        $jetDesCombat = $this->resoudreDesCombat($nbDesCombat);
        $faces = $jetDesCombat['faces'];
        $touches = $jetDesCombat['touches'];

        // LIANES AGRIPPANTES (Grasping Vine Trap, Jungles of Delthrak p. 4) :
        // « The hero must roll 1 combat die. On a black or white shield, they
        // successfully dodge the vines and may continue their movement. » Un
        // bouclier (pas un crâne) : aucun dégât, aucun arrêt, aucun état
        // dépensé — le piège a jailli, le groupe le SAIT désormais (il reste
        // armé, et connu), et le héros poursuit sa course. `esquive_sur_bouclier`
        // est la clé qui dit « un jet sans crâne épargne tout » ; les trois
        // pièges de sol, eux, ne lancent un dé que pour compter des crânes.
        $esquiveSurBouclier = (bool) data_get($piege?->effet, 'esquive_sur_bouclier', false);

        if ($esquiveSurBouclier && $nbDesCombat > 0 && $touches === 0) {
            if (($entree['etat'] ?? null) === self::ETAT_CACHE) {
                $this->changerEtat($carte, $index, self::ETAT_DETECTE);
            }

            $payload = [
                'type' => 'piege_esquive',
                'contexte' => $contexte,
                'piege' => ['nom' => $piege?->nom ?? 'Piège', 'x' => (int) $entree['x'], 'y' => (int) $entree['y']],
                'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
                'faces' => $faces,
                'touches' => 0,
            ];

            Journal::ajouter($groupe, 'action', $payload, [
                'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
            ]);

            return $payload;
        }

        if ($nbDesCombat > 0) {
            $degats = $jetDesCombat['degats'];
        } elseif ((bool) data_get($piege?->effet, 'degats_selon_armure', false)) {
            // FOSSE DES TÉNÈBRES (Against the Ogre Horde p. 5) : « Heroes
            // wearing no armor or only non-metal armor take 1 Body Point of
            // damage. Heroes wearing metal armor take 2 Body Points of
            // damage, unless they're wearing plate mail, in which case they
            // take 3. » Dégâts fixes mais dépendants de l'ARMURE portée AU
            // MOMENT de la chute — ni dé, ni jet de défense, comme la Fosse
            // ordinaire.
            $degats = $this->equipement->porteArmureDePlates($personnage) ? 3
                : ($this->equipement->porteArmureMetallique($personnage) ? 2 : 1);
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
        // ouverte, `fosse_ouverte`, sautable) ni simplement dépensé (`declenche`, la lance
        // disparaît pour de bon) — un TROISIÈME sort, un obstacle qui reste.
        // Persistant sinon (fosse) : le piège reste en jeu, désormais visible
        // de tous ; usage unique sinon : consommé définitivement.
        $blocPermanent = (bool) data_get($piege?->effet, 'bloc_permanent', false);
        $persistant = $piege?->usage === 'persistant';

        // LIANES AGRIPPANTES, sur un crâne : « suffer 1 Body Point of damage and
        // are held in place by the vines » — `effet.retient` nomme la condition
        // qui tient le héros (Immobilisé : `deplacement_interdit`, que seule
        // l'action « Détruire les entraves » lève — « they or another adjacent
        // hero spends an action to destroy the vines »). ⚠ Posée seulement si le
        // héros n'y résiste pas (`Competence::resisteA`) : alors rien ne le
        // retient, et le piège reste armé et connu.
        $nomRetient = (string) data_get($piege?->effet, 'retient', '');
        $retenu = $nomRetient !== '' && $touches > 0
            && $this->appliquerConditionSiApplicable($personnage, $nomRetient, 'piege:'.($piege?->nom ?? 'lianes')) !== null;

        $nouvelEtat = match (true) {
            $blocPermanent => self::ETAT_BLOC,
            $retenu => self::ETAT_RETIENT,
            $esquiveSurBouclier => self::ETAT_DETECTE,
            $persistant => self::ETAT_FOSSE_OUVERTE,
            default => self::ETAT_DECLENCHE,
        };
        $this->changerEtat($carte, $index, $nouvelEtat);

        if ($retenu) {
            $this->noterRetenu($carte, $index, (int) $personnage->id);
        }

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
            // LIANES : le héros est TENU sur sa case jusqu'à ce que l'une des
            // deux actions de libération détruise les lianes. Publié pour que
            // la manette et la table le DISENT (un effet automatique muet est
            // injouable) : `retient` nomme la condition posée.
            'retenu' => $retenu,
            'retient' => $retenu ? $nomRetient : null,
            // La même clé que les pièges de coffre : le fil dit « X est
            // Immobilisé » sans nouveau texte (`JournalCombat::piegeDeclenche()`).
            'condition_appliquee' => $retenu ? $nomRetient : null,
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

    /** Inscrit sur l'entrée de piège QUEL héros les lianes tiennent (état durable, dans la grille). */
    private function noterRetenu(Carte $carte, int $index, int $personnageId): void
    {
        $grille = $carte->grille;

        if (! isset($grille['pieges'][$index])) {
            return;
        }

        $grille['pieges'][$index]['retenu'] = $personnageId;
        $carte->update(['grille' => $grille]);
    }

    /**
     * Les lianes qui tiennent ce héros sont DÉTRUITES : « The hero is then freed
     * and the trap is removed from the board » (Jungles of Delthrak p. 4). Appelé
     * par `ResolveurTour::resoudreLiberationEntraves()` — l'action « Détruire les
     * entraves » que le héros tenu OU un voisin au contact dépense. Le piège
     * passe à `desarme` (il n'est plus sur le plateau : plus rien ne se
     * déclenchera, plus rien ne retient personne). Rend les pièges libérés.
     *
     * @return list<array{x: int, y: int, nom: string}>
     */
    public function libererLianes(Carte $carte, int $personnageId): array
    {
        $liberes = [];

        foreach ((array) ($carte->grille['pieges'] ?? []) as $index => $entree) {
            if (($entree['etat'] ?? null) !== self::ETAT_RETIENT || (int) ($entree['retenu'] ?? 0) !== $personnageId) {
                continue;
            }

            $this->changerEtat($carte, (int) $index, self::ETAT_DESARME);
            $liberes[] = [
                'x' => (int) $entree['x'], 'y' => (int) $entree['y'],
                'nom' => (string) (Piege::find($entree['piege_id'] ?? 0)?->nom ?? 'Piège'),
            ];
        }

        return $liberes;
    }

    /**
     * Lance `$nbDesCombat` dés de combat, un crâne = 1 PV de Body, SANS jet
     * de défense — le calcul PARTAGÉ du Piège à lances / Chute de blocs
     * (`declencher()`) et de la carte « Poison »/« Magical Trap » du deck de
     * trésor (`declencherEphemere()`). Extrait le 2026-10-06 (Wizards of
     * Morcar) pour que les deux producteurs du même jet ne divergent jamais.
     *
     * @return array{degats: int, faces: list<string>|null, touches: int|null}
     */
    private function resoudreDesCombat(int $nbDesCombat): array
    {
        if ($nbDesCombat <= 0) {
            return ['degats' => 0, 'faces' => null, 'touches' => null];
        }

        $facesLancees = $this->des->desCombat($nbDesCombat);
        $touches = count(array_filter($facesLancees, fn (FaceDeCombat $f) => $f->estCrane()));
        $faces = array_map(fn (FaceDeCombat $f) => $f->value, $facesLancees);

        return ['degats' => $touches, 'faces' => $faces, 'touches' => $touches];
    }

    /**
     * LAME BALANÇOIRE (Against the Ogre Horde, livret F9528 p. 4-5) — SECOND
     * producteur de dégâts de piège, à côté de `declencher()` ci-dessus :
     * « Zargon rolls 2 Attack dice, and any affected heroes roll Defend dice
     * as normal. » Frappe TOUS les héros actuellement sur une case de la
     * ZONE (`effet.zone_lames`, posée par `AssembleurCarte::placerLameBalanciere()`),
     * chacun avec SA PROPRE défense — à la différence du Piège à lances et
     * de la Chute de blocs, qui ne lancent JAMAIS de défense.
     *
     * ⚠ Le piège reste ARMÉ après ce déclenchement : rien dans le texte ne
     * limite son usage, à la différence de la Fosse (persistante mais
     * `fosse_ouverte`) ou du Piège à lances (`declenche`, dépensé). Aucun
     * changement d'état n'est donc écrit ici — l'appelant (`controlerChemin()`,
     * `ResolveurTour::resoudreDesamorcage()` en cas d'échec) garde l'entrée
     * telle quelle.
     *
     * @param  int  $index  index de l'entrée dans `carte.grille.pieges`
     * @param  Personnage  $personnage  le héros qui a DÉCLENCHÉ la lame
     *         (marché sur la case dorée, ou raté son désamorçage) — journalisé
     *         comme acteur et utilisé pour la narration, MÊME s'il n'est pas
     *         forcément parmi les cibles touchées (le désamorceur agit depuis
     *         une case adjacente à la zone, pas forcément dedans).
     * @param  array{x: int, y: int}|null  $positionDeclencheur  SEULEMENT
     *         quand `$personnage` vient de MARCHER sur le déclencheur : sa
     *         colonne `position_x`/`position_y` n'est pas encore à jour à cet
     *         instant (`ResolveurTour::resoudreDeplacement()` écrit l'arrêt
     *         SEULEMENT APRÈS que `controlerChemin()` — qui appelle cette
     *         méthode — soit revenu), donc lire sa colonne lui ferait manquer
     *         sa PROPRE lame. `null` pour un désamorçage raté : le
     *         désamorceur agit depuis une case ADJACENTE, sa colonne EST déjà
     *         sa vraie position, et la forcer sur la case du piège l'aurait
     *         compté à tort comme une cible.
     * @return array<string, mixed> payload journalisé
     */
    public function declencherZone(
        Groupe $groupe,
        Carte $carte,
        int $index,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        string $contexte,
        ?array $positionDeclencheur = null,
    ): array {
        $entree = $carte->grille['pieges'][$index];
        $piege = Piege::find($entree['piege_id']);
        $nbDesAttaque = max(1, (int) data_get($piege?->effet, 'des_attaque_zone', 2));
        $zone = (array) ($entree['zone'] ?? []);
        $quete = $etat->quete;

        $cibles = $quete === null ? collect() : $quete->etatsPersonnages()
            ->whereNotNull('position_x')
            ->with('personnage')
            ->get()
            ->map(fn (EtatPersonnageQuete $e) => [
                'etat' => $e,
                'x' => ($positionDeclencheur !== null && (int) $e->personnage_id === (int) $personnage->id)
                    ? (int) $positionDeclencheur['x'] : (int) $e->position_x,
                'y' => ($positionDeclencheur !== null && (int) $e->personnage_id === (int) $personnage->id)
                    ? (int) $positionDeclencheur['y'] : (int) $e->position_y,
            ])
            ->filter(fn (array $p) => $p['etat']->personnage !== null && collect($zone)
                ->contains(fn (array $z) => (int) $z['x'] === $p['x'] && (int) $z['y'] === $p['y']))
            ->map(fn (array $p) => $p['etat'])
            ->values();

        $resultats = [];

        foreach ($cibles as $cibleEtat) {
            $cible = $cibleEtat->personnage;

            $garde = $this->sorts->desDefenseHerosDetail($cible)['total'];
            $resultat = (new Combat($this->des))->resoudreAttaque(
                desAttaque: $nbDesAttaque,
                desDefense: $garde,
                typeDefenseur: TypeFigurine::Heros,
                pvBodyDefenseur: (int) $cible->pv_body,
            );

            $subis = $this->degats->infligerAHeros(
                $cible, $resultat->degats, MoteurDegats::SOURCE_PIEGE, ['piege' => $piege?->nom],
            );
            $pvApres = (int) $cible->pv_body;
            $tombe = $pvApres === 0 && $subis > 0;

            if ($tombe) {
                $cibleEtat->update(['tombe' => true]);
            }

            $resultats[] = [
                'personnage' => ['id' => $cible->id, 'nom' => $cible->nom],
                'des_attaque' => $nbDesAttaque,
                'des_defense' => $garde,
                'faces_attaque' => array_map(fn (FaceDeCombat $f) => $f->value, $resultat->facesAttaque),
                'faces_defense' => array_map(fn (FaceDeCombat $f) => $f->value, $resultat->facesDefense),
                'degats' => $resultat->degats,
                'pv_body_apres' => $pvApres,
                'tombe' => $tombe,
            ];
        }

        // Le DÉCLENCHEUR garde une entrée mémoire à jour (une de ses propres
        // chutes a pu être écrite via `$cibleEtat` ci-dessus, un objet DIFFÉRENT
        // en mémoire de `$etat`) : sans ce rafraîchissement, l'appelant
        // (`controlerChemin()`) lirait un `$etat->tombe` périmé.
        $etat->refresh();

        $payload = [
            'type' => 'piege_declenche',
            'contexte' => $contexte,
            'zone' => true,
            'piege' => [
                'nom' => $piege?->nom ?? 'Piège',
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
            ],
            'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
            'cibles' => $resultats,
        ];

        Journal::ajouter($groupe, 'action', $payload, [
            'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
        ]);

        $this->narrerPiegeDeclenche($groupe, $quete, $personnage);

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

        // POISON / MAGICAL TRAP (cartes de trésor, Wizards of Morcar, doc 18
        // §8) : un JET de dé de combat plutôt qu'un montant fixe — « roll 1
        // combat die ; on a skull, lose 1 Body Point, otherwise nothing »
        // (Poison), ou « you set off a Fireburst trap » (Magical Trap,
        // référence la MÊME carte que le piège de sol, `des_combat: 3`).
        // ⚠ DIVERGENCE NOMMÉE pour Magical Trap : frappe le TIREUR seul, sans
        // défense — le résolveur éphémère générique ne sait viser qu'une
        // victime ; la salle entière reste le privilège de la version posée
        // sur la grille (`explosionsFireburstEnAttente()`).
        $nbDesCombat = (int) data_get($issue, 'des_combat', 0);
        $jetDesCombat = $this->resoudreDesCombat($nbDesCombat);

        $degats = $nbDesCombat > 0 ? $jetDesCombat['degats'] : (int) data_get($issue, 'degats_pv_body', 0);
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

        if ($jetDesCombat['faces'] !== null) {
            $payload['faces'] = $jetDesCombat['faces'];
            $payload['touches'] = $jetDesCombat['touches'];
        }

        Journal::ajouter($groupe, 'action', $payload, [
            'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
        ]);

        if ($narrer) {
            $this->narrerPiegeDeclenche($groupe, $etat->quete, $personnage);
        }

        return $payload;
    }

    /**
     * TELEPORT TRAP (Wizards of Morcar, doc 18 §5/§9) — « finishing movement
     * on space A teleports the character to space B elsewhere on the board,
     * disoriented, and their turn ends ». La paire A/B est posée par
     * `AssembleurCarte::placerPiegesMorcar()` : les deux entrées de
     * `cartes.grille.pieges` partagent `piege_id` ET `paire_id`.
     *
     * ⚠ Même garde que `ResolveurTour::teleporterSiTunnel()` (Tunnel de
     * glace) : destination OCCUPÉE par une figure, ou dans une salle NON
     * DÉCOUVERTE → RIEN ne se passe, le piège reste ARMÉ (jamais marqué
     * `declenche`) pour une tentative future — un héros n'apparaît jamais
     * dans une pièce que le groupe n'a pas ouverte, et deux figurines ne
     * partagent jamais une case.
     *
     * @return array<string, mixed> payload journalisé
     */
    private function declencherTeleportation(
        Groupe $groupe,
        Carte $carte,
        int $index,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        string $contexte,
    ): array {
        $entree = $carte->grille['pieges'][$index];
        $piege = Piege::find($entree['piege_id']);
        $quete = $etat->quete;

        $destination = $this->destinationPaire($carte, $entree);

        if ($destination !== null && $quete !== null) {
            $indexSalle = Salles::indexDe((array) ($carte->grille['salles'] ?? []), $destination['x'], $destination['y']);

            if ($indexSalle !== null && ! in_array($indexSalle, $quete->sallesDecouvertes(), true)) {
                $destination = null; // salle non découverte : refusé, même garde que le tunnel de glace
            } elseif (FabriqueGrille::pour($quete, exceptPersonnageId: $personnage->id)->estOccupeeParFigure($destination['x'], $destination['y'])) {
                $destination = null; // deux figurines ne partagent jamais une case
            }
        }

        if ($destination === null) {
            // Publié sous SON type (`piege_teleporte`, sans `destination`) : la
            // table et le fil doivent dire « le piège reste armé », pas « piège
            // déclenché » — un « déclenché » sans dégât serait faux.
            return [
                'type' => 'piege_teleporte',
                'contexte' => $contexte,
                'piege' => ['nom' => $piege?->nom ?? 'Piège', 'x' => (int) $entree['x'], 'y' => (int) $entree['y']],
                'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
                'degats' => 0, 'pv_body_apres' => (int) $personnage->pv_body, 'tombe' => false,
                'immobilise' => false, 'bloc_permanent' => false,
                'teleportation_echouee' => true,
            ];
        }

        // Usage unique (livret : « may only be activated once ») — l'entrée
        // JUMELLE garde son propre `etat` : une paire n'est consommée que du
        // côté réellement franchi, l'autre moitié reste un piège normal pour
        // qui l'atteindrait depuis l'autre sens.
        $this->changerEtat($carte, $index, self::ETAT_DECLENCHE);

        $payload = [
            'type' => 'piege_teleporte',
            'contexte' => $contexte,
            'piege' => ['nom' => $piege?->nom ?? 'Piège', 'x' => (int) $entree['x'], 'y' => (int) $entree['y']],
            'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
            'destination' => $destination,
            'degats' => 0, 'pv_body_apres' => (int) $personnage->pv_body, 'tombe' => false,
            'immobilise' => false, 'bloc_permanent' => false,
        ];

        Journal::ajouter($groupe, 'action', $payload, [
            'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
        ]);

        $this->narrerPiegeDeclenche($groupe, $quete, $personnage);

        return $payload;
    }

    /**
     * L'autre moitié de la paire de téléportation (`paire_id` partagé),
     * null si la paire est incomplète (ne devrait pas arriver si semée
     * correctement).
     *
     * @param  array<string, mixed>  $entree
     * @return array{x: int, y: int}|null
     */
    private function destinationPaire(Carte $carte, array $entree): ?array
    {
        $paireId = $entree['paire_id'] ?? null;

        if ($paireId === null) {
            return null;
        }

        foreach ((array) ($carte->grille['pieges'] ?? []) as $autre) {
            if (($autre['paire_id'] ?? null) === $paireId
                && ((int) $autre['x'] !== (int) $entree['x'] || (int) $autre['y'] !== (int) $entree['y'])) {
                return ['x' => (int) $autre['x'], 'y' => (int) $autre['y']];
            }
        }

        return null;
    }

    /**
     * HURRICANE TRAP (Wizards of Morcar, doc 18 §5/§9) — « repousse tous les
     * personnages du couloir de 8 cases en arrière, ou jusqu'au premier mur
     * rencontré ». Posé en COULOIR seulement (`AssembleurCarte::placerPiegesMorcar()`),
     * jamais en salle.
     *
     * ⚠ SCOPE ASSUMÉ, nommé plutôt que deviné : les HÉROS seulement — aucun
     * piège de sol de ce catalogue ne touche jamais un monstre ou un allié
     * (`declencher()`/`declencherZone()` prennent tous deux un seul
     * `Personnage`), et l'étendre aurait demandé un second modèle de
     * déplacement forcé (`InstanceMonstre`/`GroupeMercenaire`) hors du
     * périmètre de ce chantier.
     *
     * Chaque héros du couloir est repoussé À L'OPPOSÉ du piège, le long de
     * l'axe du couloir (déterminé par l'étendue de ses cases — les couloirs
     * de ce moteur sont toujours droits), jusqu'à `portee_recul` cases ou
     * jusqu'au premier obstacle. ⚠ FIGURES IGNORÉES pendant le recul (la
     * grille est reconstruite PAR HÉROS DÉJÀ DÉPLACÉ, du plus loin du piège
     * au plus proche, pour que deux héros poussés dans le même sens ne se
     * bloquent pas l'un l'autre alors qu'ils sont tous deux soufflés par la
     * MÊME bourrasque) : seuls les murs/le mobilier/un autre piège arrêtent
     * la glissade.
     *
     * @return array<string, mixed> payload journalisé
     */
    private function declencherHurricane(
        Groupe $groupe,
        Carte $carte,
        int $index,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        string $contexte,
    ): array {
        $entree = $carte->grille['pieges'][$index];
        $piege = Piege::find($entree['piege_id']);
        $quete = $etat->quete;
        $trapX = (int) $entree['x'];
        $trapY = (int) $entree['y'];

        $this->changerEtat($carte, $index, self::ETAT_DECLENCHE);

        $portee = max(1, (int) data_get($piege?->effet, 'portee_recul', 8));
        $repousses = [];
        $destinationDeclencheur = ['x' => $trapX, 'y' => $trapY];

        if ($quete !== null) {
            $grille = (array) $carte->grille;
            $zone = ZoneFouille::de($grille, $trapX, $trapY);
            $cellules = $zone->cellulesCouloir();

            $xs = array_column($cellules, 'x');
            $ys = array_column($cellules, 'y');
            // Axe du couloir : celui dont l'étendue est la plus large — les
            // couloirs de ce moteur sont toujours droits (une case ou deux
            // de large), jamais coudés.
            $axeHorizontal = $cellules === [] || (max($xs) - min($xs)) >= (max($ys) - min($ys));

            $cibles = $quete->etatsPersonnages()->where('tombe', false)->with('personnage')->get()
                ->filter(fn (EtatPersonnageQuete $e) => $e->personnage !== null && $e->position_x !== null
                    && $zone->contient((int) $e->position_x, (int) $e->position_y))
                // Le plus LOIN du piège d'abord (voir docblock) : il libère
                // la case que le suivant, plus proche, pourrait vouloir
                // traverser en reculant à son tour.
                ->sortByDesc(fn (EtatPersonnageQuete $e) => abs((int) ($axeHorizontal ? $e->position_x : $e->position_y)
                    - (int) ($axeHorizontal ? $trapX : $trapY)));

            foreach ($cibles as $cibleEtat) {
                $cx = (int) $cibleEtat->position_x;
                $cy = (int) $cibleEtat->position_y;

                // Sens : à l'opposé du piège sur l'axe du couloir. Égalité
                // (cas rare d'un couloir à VOIE DOUBLE, la cible alignée sur
                // l'AUTRE lane) : REPLI assumé vers le sens positif, faute
                // d'un sens que la carte nommerait pour ce cas précis.
                $sens = $axeHorizontal
                    ? ($cx === $trapX ? 1 : ($cx > $trapX ? 1 : -1))
                    : ($cy === $trapY ? 1 : ($cy > $trapY ? 1 : -1));
                $dx = $axeHorizontal ? $sens : 0;
                $dy = $axeHorizontal ? 0 : $sens;

                $grilleActuelle = FabriqueGrille::pour($quete, exceptPersonnageId: (int) $cibleEtat->personnage_id);
                $arrivee = $this->pousserEnLigne($grilleActuelle, $carte, $cx, $cy, $dx, $dy, $portee);

                if ((int) $cibleEtat->personnage_id === (int) $personnage->id) {
                    $destinationDeclencheur = $arrivee;

                    continue; // le DÉCLENCHEUR est repositionné par le retour de controlerChemin(), pas ici
                }

                if ($arrivee['x'] !== $cx || $arrivee['y'] !== $cy) {
                    $cibleEtat->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);
                }

                $repousses[] = [
                    'personnage_id' => (int) $cibleEtat->personnage_id,
                    'nom' => (string) ($cibleEtat->personnage?->nom ?? 'Un héros'),
                    'de' => ['x' => $cx, 'y' => $cy],
                    'vers' => $arrivee,
                ];
            }
        }

        $payload = [
            'type' => 'piege_bourrasque',
            'contexte' => $contexte,
            'piege' => ['nom' => $piege?->nom ?? 'Piège', 'x' => $trapX, 'y' => $trapY],
            'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
            'destination' => $destinationDeclencheur,
            // Un effet automatique que rien n'annonce est injouable : chaque
            // héros repoussé (hors le déclencheur, publié par `destination`
            // ci-dessus et par `vers`/`chemin` du déplacement lui-même) doit
            // apparaître ici pour que la table ET la manette des AUTRES
            // joueurs sachent pourquoi leur figurine a bougé sans qu'ils
            // aient rien demandé.
            'repousses' => $repousses,
            'degats' => 0, 'pv_body_apres' => (int) $personnage->pv_body, 'tombe' => false,
            'immobilise' => false, 'bloc_permanent' => false,
        ];

        Journal::ajouter($groupe, 'action', $payload, [
            'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
        ]);

        $this->narrerPiegeDeclenche($groupe, $quete, $personnage);

        return $payload;
    }

    /**
     * Pousse une figure le long d'une ligne (dx, dy ∈ {-1, 0, 1}), jusqu'à
     * `$max` cases ou jusqu'à la première case non traversable (mur, meuble
     * bloquant, embrasure close) ou déjà ARMÉE d'un autre piège — on ne
     * rechaîne jamais un déclenchement pendant un recul forcé, même patron
     * que « jusqu'au mur » du livret, étendu par prudence.
     *
     * @return array{x: int, y: int}
     */
    private function pousserEnLigne(Grille $grille, Carte $carte, int $x, int $y, int $dx, int $dy, int $max): array
    {
        for ($i = 0; $i < $max; $i++) {
            $nx = $x + $dx;
            $ny = $y + $dy;

            if (! $grille->estTraversable($nx, $ny) || $this->indexPiegeArme($carte, $nx, $ny) !== null) {
                break;
            }

            $x = $nx;
            $y = $ny;
        }

        return ['x' => $x, 'y' => $y];
    }

    /**
     * FIREBURST TRAP (Wizards of Morcar, doc 18 §5/§9) — déclenché (marché
     * sur sa case, non détectable), il n'inflige RIEN tout de suite : « a
     * token remains until the beginning of Zargon's turn, when it will
     * explode ». Cette méthode AMORCE seulement (`ETAT_AMORCE`) ; l'explosion
     * elle-même est résolue par `explosionsFireburstEnAttente()`, appelée en
     * tête de `ResolveurTour::phaseMonstres()`.
     *
     * ⚠ Un effet automatique que rien n'annonce est injouable, MÊME discret :
     * le jeton est amorcé en silence narratif (rien ne dit « piège » tant
     * qu'il n'a pas explosé — « non détectable » veut aussi dire ça), mais le
     * payload et le journal portent l'événement comme tout déclenchement —
     * la surprise est dans le DÉLAI, jamais dans l'absence de trace.
     *
     * @return array<string, mixed> payload journalisé
     */
    private function declencherFireburst(
        Groupe $groupe,
        Carte $carte,
        int $index,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        string $contexte,
    ): array {
        $entree = $carte->grille['pieges'][$index];
        $piege = Piege::find($entree['piege_id']);

        $this->changerEtat($carte, $index, self::ETAT_AMORCE);

        $payload = [
            'type' => 'piege_amorce',
            'contexte' => $contexte,
            'piege' => ['nom' => $piege?->nom ?? 'Piège', 'x' => (int) $entree['x'], 'y' => (int) $entree['y']],
            'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
            'degats' => 0, 'pv_body_apres' => (int) $personnage->pv_body, 'tombe' => false,
            'immobilise' => false, 'bloc_permanent' => false,
        ];

        Journal::ajouter($groupe, 'action', $payload, [
            'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
        ]);

        $this->narrerPiegeDeclenche($groupe, $etat->quete, $personnage);

        return $payload;
    }

    /**
     * Résout TOUS les pièges d'embrasement AMORCÉS de la quête — « the
     * beginning of Zargon's turn » de ce moteur est le début de
     * `ResolveurTour::phaseMonstres()`, APPELÉE ICI en tout premier, avant
     * le moindre monstre : trois dés d'attaque de feu, défense NORMALE,
     * contre TOUS les héros et TOUS les monstres actifs de la salle ou du
     * couloir où le jeton couvait (`ZoneFouille`, la même zone que la
     * fouille).
     *
     * ⚠ Défense NORMALE (pas « sans défense » comme les trois pièges de sol
     * d'origine) : c'est une carte de SORCIER DU DREAD, la carte-sœur
     * *Lightning Strike* de ce même carton dit « defend normally against 3
     * combat dice », et aucune source ne distingue le piège du sort pour
     * cette clause.
     *
     * @return list<array<string, mixed>> payloads journalisés, un par
     *         explosion
     */
    public function explosionsFireburstEnAttente(Groupe $groupe, Quete $quete): array
    {
        $carte = $quete->carte;
        $grille = (array) ($carte?->grille ?? []);
        $actions = [];

        foreach ((array) ($grille['pieges'] ?? []) as $index => $entree) {
            if (($entree['etat'] ?? null) !== self::ETAT_AMORCE) {
                continue;
            }

            $piege = Piege::find($entree['piege_id']);
            $nbDes = max(1, (int) data_get($piege?->effet, 'des_attaque_zone', 3));
            $typeDegat = (string) data_get($piege?->effet, 'type_degat', 'feu');
            $x = (int) $entree['x'];
            $y = (int) $entree['y'];
            $zone = ZoneFouille::de($grille, $x, $y);

            $cibles = [];

            foreach ($quete->etatsPersonnages()->where('tombe', false)->with('personnage')->get() as $cibleEtat) {
                $personnage = $cibleEtat->personnage;

                if ($personnage === null || $cibleEtat->position_x === null
                    || ! $zone->contient((int) $cibleEtat->position_x, (int) $cibleEtat->position_y)) {
                    continue;
                }

                $resultat = (new Combat($this->des))->resoudreAttaque(
                    desAttaque: $nbDes,
                    desDefense: $this->sorts->desDefenseHerosDetail($personnage)['total'],
                    typeDefenseur: TypeFigurine::Heros,
                    pvBodyDefenseur: (int) $personnage->pv_body,
                );

                $degats = $resultat->degats;

                if ($degats > 0 && $this->sorts->absorbeDegat($personnage, $typeDegat)) {
                    $degats = 0;
                }

                $subis = $degats > 0 ? $this->degats->infligerAHeros(
                    $personnage, $degats, MoteurDegats::SOURCE_PIEGE, ['piege' => $piege?->nom],
                ) : 0;
                $tombe = (int) $personnage->pv_body === 0 && $subis > 0;

                if ($tombe) {
                    $cibleEtat->update(['tombe' => true]);
                }

                $cibles[] = [
                    'type' => 'heros', 'personnage_id' => $personnage->id, 'nom' => $personnage->nom,
                    'degats' => $subis, 'pv_body_apres' => (int) $personnage->pv_body, 'tombe' => $tombe,
                ];
            }

            foreach ($quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get() as $instance) {
                if (! $zone->contient((int) $instance->position_x, (int) $instance->position_y)) {
                    continue;
                }

                $resultat = (new Combat($this->des))->resoudreAttaque(
                    desAttaque: $nbDes,
                    desDefense: $instance->defenseEffective(),
                    typeDefenseur: TypeFigurine::Monstre,
                    pvBodyDefenseur: (int) $instance->pv_body,
                );

                $issue = $this->degats->infligerAMonstre($instance, $resultat->degats, MoteurDegats::SOURCE_PIEGE, ['piege' => $piege?->nom]);

                $cibles[] = [
                    'type' => 'monstre', 'instance_id' => $instance->id,
                    'nom' => $instance->monstre?->nom_base ?? 'Monstre',
                    'degats' => $issue['degats'], 'vaincu' => $issue['vaincu'],
                ];
            }

            $this->changerEtat($carte, $index, self::ETAT_DECLENCHE);

            $payload = [
                'type' => 'piege_explosion',
                'piege' => ['nom' => $piege?->nom ?? 'Piège', 'x' => $x, 'y' => $y],
                'cibles' => $cibles,
            ];

            Journal::ajouter($groupe, 'action', $payload);
            $actions[] = $payload;
        }

        return $actions;
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
     * Fouille RÉUSSIE : révèle les pièges cachés de la ZONE du fouilleur — sa
     * salle ou son couloir, EN ENTIER, sans rayon ni ligne de vue (René,
     * 2026-09-27, voir `ZoneFouille`). L'historique ci-dessous explique d'où
     * venaient le rayon et la vue ; la zone règle le cas « piège derrière une
     * porte fermée » qui les avait motivés, puisque ce qui est derrière une
     * porte appartient à une autre zone.
     *
     * — Historique (rayon RAYON_FOUILLE + vue, jusqu'au 2026-09-27) —
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
     * ⚠ La Potion de Vision (« within their line of sight ») garde, elle, la
     * ligne de vue : `revelerEnVue()`. Elle voit à distance, d'une salle à
     * l'autre ; la fouille couvre la pièce où l'on est, recoins compris.
     *
     * @return list<array{x: int, y: int, nom: string}> pièges révélés
     */
    public function revelerAutour(Groupe $groupe, Carte $carte, Personnage $personnage, ZoneFouille $zone): array
    {
        return $this->reveler(
            $groupe,
            $carte,
            $personnage,
            fn (array $entree) => $zone->contient((int) $entree['x'], (int) $entree['y']),
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

        $reveles = $this->reveler(
            $groupe,
            $carte,
            $personnage,
            fn (array $entree) => abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) === 1,
            'oeil_du_mineur',
        );

        // Un talent qui s'active tout seul se VOIT (2026-09-25) : sans cette
        // annonce, un piège apparaissait sur la carte sans que le joueur sache
        // que c'était l'Œil du mineur qui venait de le révéler.
        if ($reveles !== []) {
            $noeud = $this->talents->noeud($personnage, self::MECANIQUE_DETECTION);

            if ($noeud !== null) {
                $nombre = count($reveles);
                $this->annonces->annoncer($personnage, $noeud, $nombre > 1
                    ? "révèle {$nombre} pièges adjacents"
                    : 'révèle un piège adjacent');
            }
        }

        return $reveles;
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
     * Pièges CONNUS et encore armés (détectés, ou fosses ouvertes)
     * orthogonalement adjacents à une position, avec leur modèle de catalogue
     * — base des options de menu Désamorcer (détectés seuls) / Franchir.
     *
     * @return list<array{index: int, x: int, y: int, etat: string, piege: Piege|null}>
     */
    public function detectesAdjacents(Carte $carte, int $x, int $y): array
    {
        $adjacents = [];

        foreach ($carte->grille['pieges'] ?? [] as $index => $entree) {
            if (! in_array($entree['etat'] ?? null, self::ETATS_CONNUS_ARMES, true)) {
                continue;
            }
            if (abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) !== 1) {
                continue;
            }

            $adjacents[] = [
                'index' => $index,
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                // Une fosse OUVERTE se saute mais ne se désamorce pas : le menu
                // et le résolveur lisent cet état pour trier les deux gestes.
                'etat' => (string) $entree['etat'],
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
     * Un sort qui DÉSARME un piège d'embrasement une fois défaussé (Magic
     * Reference Chart, Wizards of Morcar, relu à l'image 2026-10-08) : « a
     * Tempest spell or any Water Spell ». « Tempest » est nommé — c'est le sort
     * *Tempête* du catalogue, rangé en élément `air` dans `SortSeeder` (et non
     * `eau` comme le supposait le brief) —, « any Water Spell » est l'élément
     * `eau` (Sommeil, Voile de Brume, Eau de Guérison). Lecteur unique de la
     * règle : `MenuMoteur` (l'option) et `ResolveurTour` (la résolution).
     */
    public static function sortDesarmeEmbrasement(Sort $sort): bool
    {
        return $sort->nom === 'Tempête' || $sort->element === 'eau';
    }

    /**
     * Les jetons d'embrasement AMORCÉS dont la ZONE (salle ou couloir, celle de
     * la fouille) contient le héros — la seule condition de désarmement, avec le
     * sort. Lu par le MENU (option publiée seulement si légale) et REVALIDÉ à
     * l'identique par le résolveur : une liste portée par l'option est un
     * whitelist, jamais une confiance.
     *
     * @return list<array{index: int, x: int, y: int}>
     */
    public function jetonsEmbrasementLegaux(Quete $quete, EtatPersonnageQuete $etat): array
    {
        $carte = $quete->carte;

        if ($carte === null || $etat->position_x === null || $etat->position_y === null) {
            return [];
        }

        $grille = (array) $carte->grille;
        $hx = (int) $etat->position_x;
        $hy = (int) $etat->position_y;
        $jetons = [];

        foreach ((array) ($grille['pieges'] ?? []) as $index => $entree) {
            if (($entree['etat'] ?? null) !== self::ETAT_AMORCE) {
                continue;
            }

            if (data_get(Piege::find($entree['piege_id'])?->effet, 'declencheur') !== 'fireburst_differe') {
                continue;
            }

            if (ZoneFouille::de($grille, (int) $entree['x'], (int) $entree['y'])->contient($hx, $hy)) {
                $jetons[] = ['index' => (int) $index, 'x' => (int) $entree['x'], 'y' => (int) $entree['y']];
            }
        }

        return $jetons;
    }

    /**
     * DÉSARME un jeton d'embrasement AMORCÉ en défaussant un sort (Magic
     * Reference Chart : « the trap is disarmed »). Le sort est ÉPUISÉ pour la
     * quête (`pivot.disponible`, le même état que tout sort lancé), le jeton
     * passe en `ETAT_DESARME` : `explosionsFireburstEnAttente()` ne le voit plus
     * jamais. Le résolveur a déjà validé le jeton et le sort ; ici on ne fait
     * que l'écriture et l'annonce.
     *
     * @return array<string, mixed> payload journalisé
     */
    public function desarmerEmbrasement(Groupe $groupe, Carte $carte, int $index, Personnage $personnage, Sort $sort): array
    {
        $entree = $carte->grille['pieges'][$index];
        $piege = Piege::find($entree['piege_id']);

        $personnage->sorts()->updateExistingPivot($sort->id, ['disponible' => false]);
        $this->changerEtat($carte, $index, self::ETAT_DESARME);

        $payload = [
            'type' => 'piege_desarme_embrasement',
            'contexte' => 'sort',
            'piege' => ['nom' => $piege?->nom ?? "Piège d'embrasement", 'x' => (int) $entree['x'], 'y' => (int) $entree['y']],
            'personnage' => ['id' => $personnage->id, 'nom' => $personnage->nom],
            'sort' => ['id' => (int) $sort->id, 'nom' => $sort->nom],
            'degats' => 0, 'pv_body_apres' => (int) $personnage->pv_body, 'tombe' => false,
            'immobilise' => false, 'bloc_permanent' => false,
        ];

        Journal::ajouter($groupe, 'action', $payload, [
            'type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom,
        ]);

        return $payload;
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

    /**
     * Une fosse = le piège de sol PERSISTANT du catalogue : « le trou reste »
     * (livret p. 14). Ce n'est plus `franchissable` qui la désigne depuis que
     * la Chute de blocs se saute aussi (2026-09-27) : Forme démoniaque, la
     * Potion de dextérité et l'immobilisation parlent de la FOSSE seule.
     */
    public function estFosse(?Piege $piege): bool
    {
        return $piege?->usage === 'persistant' && $this->estFranchissable($piege);
    }

    /**
     * Se saute-t-il une fois détecté ? Fosse, et Chute de blocs tant qu'elle
     * n'est pas tombée (livret p. 14, `reference/16_armurerie.md` §7.3) —
     * jamais le Piège à lances, qui ne se désamorce que.
     */
    public function estFranchissable(?Piege $piege): bool
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

            $piege = Piege::find($entree['piege_id']);

            // NON DÉTECTABLE (Wizards of Morcar) : « cannot be found by
            // searching » couvre aussi l'avertissement du Sens du piège —
            // sans quoi l'Explorateur apprendrait l'existence d'un piège que
            // la carte dit introuvable par tout autre moyen que le marcher.
            if ($piege?->detectable === false) {
                continue;
            }

            $caches[] = [
                'x' => (int) $entree['x'],
                'y' => (int) $entree['y'],
                'nom' => $piege?->nom ?? 'Piège',
            ];
        }

        return $caches;
    }

    /** Index du piège encore ARMÉ (caché ou détecté) posé sur une case (null sinon). */
    private function indexPiegeArme(Carte $carte, int $x, int $y): ?int
    {
        foreach ($carte->grille['pieges'] ?? [] as $index => $entree) {
            if (in_array($entree['etat'] ?? null, self::ETATS_ARMES, true)
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

            // NON DÉTECTABLE (Wizards of Morcar, doc 18 §5/§9) : « cannot be
            // found by searching » — un piège magique reste `cache` quelle
            // que soit la méthode de détection (fouille, Œil du mineur,
            // Potion de Vision partagent ce MÊME révélateur privé). Seul le
            // pas qui marche dessus le déclenche (`controlerChemin()`,
            // via `indexPiegeArme()`, qui ne lit JAMAIS `detectable`).
            if (Piege::find($entree['piege_id'])?->detectable === false) {
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
            $payload = [
                'type' => 'pieges_detectes',
                'methode' => $methode,
                'pieges' => $reveles,
                'personnage' => $personnage->nom,
            ];

            Journal::ajouter($groupe, 'action', $payload, ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom]);

            // La fouille (ligne du `jet`) et l'Œil du mineur (popup du talent) se
            // disent déjà ; la Potion de vision, elle, balaie la ligne de vue à
            // chaque action SANS que rien d'autre ne le raconte — le tampon générique
            // porte l'annonce dans le résultat (`TamponAnnonces`).
            if ($methode === 'clairvoyance') {
                app(TamponAnnonces::class)->ajouter($payload);
            }
        }

        return $reveles;
    }
}
