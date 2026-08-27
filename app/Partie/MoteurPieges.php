<?php

declare(strict_types=1);

namespace App\Partie;

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
 *  - dégâts = effet.degats_pv_body du catalogue (1 partout) ;
 *  - une fosse (effet.franchissable) IMMOBILISE : le déplacement s'arrête
 *    sur sa case ; persistante, elle reste en jeu (`detecte`) après
 *    déclenchement — les pièges à usage unique passent à `declenche` ;
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
     * arrivée incluse) : un piège CACHÉ sur le chemin se déclenche. Une fosse
     * (ou un héros tombé à 0 PV) interrompt le déplacement sur la case.
     *
     * @param  list<array{x: int, y: int}>  $chemin  étapes SANS la case de départ
     * @return array{arret: array{x: int, y: int}|null, declenchements: list<array<string, mixed>>}
     */
    public function controlerChemin(
        Groupe $groupe,
        Carte $carte,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        array $chemin,
    ): array {
        $declenchements = [];
        $detections = [];
        $aDetection = $this->possedeOeilDuMineur($personnage);

        foreach ($chemin as $case) {
            $x = (int) $case['x'];
            $y = (int) $case['y'];

            // 1) Piège caché SUR la case traversée → déclenchement immédiat.
            $index = $this->indexPiegeCache($carte, $x, $y);
            if ($index !== null) {
                $payload = $this->declencher($groupe, $carte, $index, $personnage, $etat, 'deplacement');
                $declenchements[] = $payload;

                // Fosse = immobilisé (perd le reste de son déplacement) ; un héros
                // tombé à 0 PV s'arrête aussi là où il tombe. Arrêt DUR : la
                // course est terminée pour le tour.
                if ($payload['immobilise'] || $payload['tombe']) {
                    return ['arret' => ['x' => $x, 'y' => $y], 'dur' => true, 'declenchements' => $declenchements, 'detections' => $detections];
                }
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

                    return ['arret' => ['x' => $x, 'y' => $y], 'dur' => false, 'declenchements' => $declenchements, 'detections' => $detections];
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
                        'alertes' => $alertes];
                }
            }
        }

        return ['arret' => null, 'dur' => false, 'declenchements' => $declenchements, 'detections' => $detections];
    }

    /**
     * Déclenche le piège d'index donné sur un héros : effet du catalogue
     * (degats_pv_body), héros à 0 PV → tombe (cohérent avec le combat),
     * usage unique → `declenche` définitif, fosse persistante → reste en jeu
     * (`detecte` après déclenchement). Journal type action + narration en job.
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

        $degats = (int) data_get($piege?->effet, 'degats_pv_body', 1);
        $subis = $this->degats->infligerAHeros(
            $personnage, $degats, MoteurDegats::SOURCE_PIEGE, ['piege' => $piege?->nom],
        );
        $pvApres = (int) $personnage->pv_body;

        $tombe = $pvApres === 0 && $subis > 0;
        if ($tombe) {
            $etat->update(['tombe' => true]); // C4 : occupe sa case, relevable
        }

        // Persistant (fosse) : le piège reste en jeu, désormais visible de
        // tous ; usage unique : consommé définitivement.
        $persistant = $piege?->usage === 'persistant';
        $this->changerEtat($carte, $index, $persistant ? self::ETAT_DETECTE : self::ETAT_DECLENCHE);

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
        ];

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
     * RAYON_FOUILLE cases (Manhattan) autour du fouilleur.
     *
     * @return list<array{x: int, y: int, nom: string}> pièges révélés
     */
    public function revelerAutour(Groupe $groupe, Carte $carte, Personnage $personnage, int $x, int $y): array
    {
        return $this->reveler(
            $groupe,
            $carte,
            $personnage,
            fn (array $entree) => abs((int) $entree['x'] - $x) + abs((int) $entree['y'] - $y) <= self::RAYON_FOUILLE,
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

            $this->changerEtat($carte, (int) $index, self::ETAT_DESARME);

            $desarmes[] = [
                'x' => $x,
                'y' => $y,
                'nom' => (string) (Piege::find($entree['piege_id'] ?? null)?->nom ?? 'Piège'),
            ];
        }

        return $desarmes;
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
