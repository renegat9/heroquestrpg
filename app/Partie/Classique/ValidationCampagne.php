<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\MotsClesQuete;
use App\Partie\MoteurPortes;
use App\Partie\Grille;
use App\Partie\Salles;

/**
 * Validation d'un document de campagne construite (MODE CLASSIQUE, lot K1 — `docs/plan-mode-classique.md` §3,
 * `docs/contrat-api.md` « Constructeur de campagnes (lot K1) »).
 *
 * Rend la liste des refus `[{chemin, code, message}]` : VIDE = document valide. C'est le SEUL endroit où vit une règle
 * du format : les routes, l'import et (plus tard) le serveur MCP passent tous par ici, jamais par une copie.
 *
 * Une clé inconnue est refusée en la nommant, jamais ignorée : une clé que la validation accepterait sans la lire serait
 * une clé décorative. Les clés réservées à un lot ultérieur (`notes`, `regles`, `fin`, `suite`) ont leur propre code.
 *
 * Ce service ne touche à rien d'autre qu'au catalogue (lecture seule) : il ne pose ni ne joue aucune quête.
 */
final class ValidationCampagne
{
    // ─── Codes de refus. Un test les tient DANS LES DEUX SENS : chacun est produit par un cas, et tout refus produit
    // porte l'un d'eux (tests/Feature/Classique/ValidationCampagneTest.php). ──────────────────────────────────────

    public const CLE_INCONNUE = 'cle_inconnue';
    public const CLE_MANQUANTE = 'cle_manquante';
    public const TYPE_INVALIDE = 'type_invalide';
    public const VALEUR_INVALIDE = 'valeur_invalide';
    public const FORMAT_INCONNU = 'format_inconnu';
    public const SLUG_INVALIDE = 'slug_invalide';
    public const SLUG_EN_DOUBLE = 'slug_en_double';
    public const SALLE_HORS_PLATEAU = 'salle_hors_plateau';
    public const SALLE_ID_EN_DOUBLE = 'salle_id_en_double';
    public const SALLES_SE_CHEVAUCHENT = 'salles_se_chevauchent';
    /** Une partie de salle (salle en L) qui ne touche pas le reste de sa salle. */
    public const PARTIE_DETACHEE = 'partie_detachee';
    public const SALLE_INCONNUE = 'salle_inconnue';
    public const CATALOGUE_INCONNU = 'catalogue_inconnu';
    public const CLASSE_INCONNUE = 'classe_inconnue';
    public const HORS_PLATEAU = 'hors_plateau';
    public const DANS_SALLE_FERMEE = 'dans_salle_fermee';
    public const BLOC_HORS_COULOIR = 'bloc_hors_couloir';
    public const DEPART_INVALIDE = 'depart_invalide';
    public const PORTE_SANS_BORD = 'porte_sans_bord';
    public const SLUG_NON_CONCORDANT = 'slug_non_concordant';
    public const EMPREINTE_INVALIDE = 'empreinte_invalide';

    // Lot K2, repris le 2026-11-14 pour les MURS EN ARÊTES : une salle n'a plus d'anneau de murs — ses murs sont le
    // pourtour de ses cases. Les refus `anneau_hors_plateau`, `anneau_sur_salle` et `pose_sur_mur` n'existent plus.
    public const DEPART_HORS_SALLE = 'depart_hors_salle';
    public const PORTE_DANS_LA_ROCHE = 'porte_dans_la_roche';
    public const MOBILIER_HORS_SALLE = 'mobilier_hors_salle';
    public const CASE_PARTAGEE = 'case_partagee';

    // Première quête jouable (2026-11-14) — la condition de fin : une cible de `fin.cibles` ne désigne aucun monstre posé.
    public const CIBLE_INCONNUE = 'cible_inconnue';

    // Lot K3 (2026-11-15) — les notes de quête : une cible mal formée pour son déclencheur ; un drapeau lu (par une
    // autre note ou par la fin) qu'aucune note ne pose — la quête ne pourrait jamais le lever.
    public const CIBLE_DE_NOTE_INVALIDE = 'cible_de_note_invalide';
    public const DRAPEAU_JAMAIS_POSE = 'drapeau_jamais_pose';
    // Lot S1 — une quête SOLO (`regles.solo`) désigne une classe que la campagne n'exige pas (`prerequis_heros`) : un
    // groupe sans ce héros atteindrait une quête qu'il ne peut pas jouer.
    public const SOLO_SANS_PREREQUIS = 'solo_sans_prerequis';
    // Lot K5a — un VERROU sur une porte secrète : on la trouve, puis elle s'ouvre ; elle n'a rien à verrouiller.
    public const VERROU_SUR_PORTE_SECRETE = 'verrou_sur_porte_secrete';
    // Lot K5p (R10) — une QUÊTE DOUBLE mal liée : moitié absente, qui pointe ailleurs, la seconde avant la principale, ou
    // une seconde moitié visée par une `suite` (on n'y entre que par le passage).
    public const DOUBLE_INVALIDE = 'double_invalide';

    /** @var list<string> */
    public const CODES = [
        self::CLE_INCONNUE, self::CLE_MANQUANTE, self::TYPE_INVALIDE,
        self::VALEUR_INVALIDE, self::FORMAT_INCONNU, self::SLUG_INVALIDE, self::SLUG_EN_DOUBLE,
        self::SALLE_HORS_PLATEAU, self::SALLE_ID_EN_DOUBLE, self::SALLES_SE_CHEVAUCHENT, self::SALLE_INCONNUE,
        self::CATALOGUE_INCONNU, self::CLASSE_INCONNUE, self::HORS_PLATEAU, self::DANS_SALLE_FERMEE,
        self::BLOC_HORS_COULOIR, self::DEPART_INVALIDE, self::PORTE_SANS_BORD, self::SLUG_NON_CONCORDANT,
        self::EMPREINTE_INVALIDE,
        self::DEPART_HORS_SALLE,
        self::PORTE_DANS_LA_ROCHE, self::MOBILIER_HORS_SALLE, self::CASE_PARTAGEE, self::CIBLE_INCONNUE,
        self::CIBLE_DE_NOTE_INVALIDE, self::DRAPEAU_JAMAIS_POSE, self::SOLO_SANS_PREREQUIS,
        self::VERROU_SUR_PORTE_SECRETE, self::DOUBLE_INVALIDE,
    ];

    /**
     * Couples d'éléments qui ne peuvent pas partager une case (lot K2). Un BLOC ne partage rien avec quoi que ce soit ;
     * les autres couples sont listés. Un piège ou un terrain n'est donc refusé que contre un bloc.
     */
    private const PARTAGES_INTERDITS = [
        ['meuble', 'meuble'], ['meuble', 'monstre'], ['monstre', 'monstre'], ['escalier', 'meuble'], ['escalier', 'monstre'],
        // Deux accès (l'entrée et la sortie) ne se recouvrent pas : sinon `sortie` se laisse à `null`.
        ['escalier', 'escalier'],
    ];

    // ─── Vocabulaires fermés : publiés tels quels par GET /api/constructeur/catalogues ───────────────────────────

    public const FORMAT = 1;
    public const FORMATS_ACCEPTES = [self::FORMAT];
    public const ROTATIONS = [0, 90, 180, 270];
    public const COTES = ['n', 'e', 's', 'o'];
    public const TYPES_PORTE = ['normale', 'secrete'];

    /**
     * Lot K5a — les VERROUS qu'une porte construite peut porter (`portes[].verrou.type`) : ceux que le moteur joue déjà
     * (`MoteurPortes`), plus `note`, la porte que seule une note ouvre. Les conditions d'une porte `condition` sont les
     * clés de `MoteurPortes::CONDITIONS`, lues telles quelles (publiées `conditions_de_porte`).
     */
    public const VERROUS_DE_PORTE = [MoteurPortes::VERROU_NOTE, MoteurPortes::VERROU_CLE, MoteurPortes::VERROU_PIERRE, MoteurPortes::VERROU_CONDITION];
    public const PARTAGES = ['groupe', 'par_heros'];

    /**
     * Par où l'on entre (`depart`) et par où l'on sort (`sortie`) : un ESCALIER (bloc 2×2), ou une PORTE sur le mur
     * d'une salle ouverte. Les deux ont un lecteur : `CarteConstruite` pour l'entrée, `Quete::sortieDuDonjon()` pour
     * la sortie.
     */
    public const ACCES_ESCALIER = 'escalier';

    public const ACCES_PORTE = 'porte';

    public const TYPES_ACCES = [self::ACCES_ESCALIER, self::ACCES_PORTE];

    // ─── Bornes : celles des colonnes de la migration `campagnes_construites` ─────────────────────────────────────

    private const LONGUEUR_SLUG = 100;
    private const LONGUEUR_TITRE = 200;
    private const LONGUEUR_AUTEUR = 200;
    private const LONGUEUR_MENTION = 500;
    private const LONGUEUR_ID_SALLE = 40;

    // ─── Clés du format (les seules lues) ─────────────────────────────────────────────────────────────────────────

    private const CLES_CAMPAGNE = ['format', 'slug', 'titre', 'auteur', 'mention', 'prerequis_heros', 'plateau', 'quetes'];
    private const CLES_PLATEAU = ['largeur', 'hauteur', 'salles'];
    private const CLES_SALLE = ['id', 'x', 'y', 'l', 'h'];
    private const CLES_QUETE = [
        'slug', 'titre', 'ouverture', 'monstre_errant', 'salles_ouvertes', 'portes', 'blocs', 'mobilier',
        'monstres', 'pieges', 'terrain', 'depart', 'sortie', 'recompense', 'fin',
    ];
    /** Les clés de `fin`, selon son type : chacune est lue par `Quete::objectifAccompli()`. */
    private const CLES_FIN = [
        'vaincre' => ['type', 'cibles', 'libelle'],
        'drapeau' => ['type', 'drapeau', 'libelle'],
        // « sortir » : trouver la sortie et quitter le donjon (Kellar's Keep). Rien à désigner : la sortie de la quête.
        'sortir' => ['type', 'libelle'],
        // « ramener » (lot K5k, F4) : ramener la charge de la quête hors du donjon — exige `charge`.
        'ramener' => ['type', 'libelle'],
    ];
    /** Clés d'une quête qu'on peut omettre : une quête sans note est une quête sans note, pas une quête incomplète. */
    private const CLES_QUETE_OPTIONNELLES = ['notes', 'fausses_sorties', 'regles', 'suite', 'charge', 'captif', 'dangers', 'double', 'glyphes', 'salles_speciales', 'coffres_pieges', 'tournoi'];
    /** Lot E1 (2026-11-22) : le coffre piégé de l'auteur, `Classique\CoffresPieges`. */
    private const CLES_COFFRE_PIEGE_OPTIONNELLES = ['piege', 'degats', 'toute_la_salle'];
    /**
     * Les RÈGLES DE QUÊTE (`docs/plan-mode-classique.md` §2.3), valables toute la quête — seulement celles que le moteur
     * sait jouer. Lot S1 : `solo` (R7), la classe du seul héros qui entre. Les autres (R4–R6, R8…) viennent avec K5.
     */
    // Lot S1 : `solo` ; lot K5c : `marche_ferme` (R8, lu par `PhaseMarche::motifFermeture()`).
    private const CLES_REGLES = ['solo', 'marche_ferme', 'sans_fouille', 'confiscation', 'primes_monstres', 'portes_teleportation', 'fouille_piege_un_par_un', 'monstres_pacifies_par_drapeau', 'degats_en_mind', 'ordre_libre', 'obscurite_magique', 'deguisement', 'sans_restauration', 'brouillard_vivant', 'crevasse', 'richesses_des_coffres', 'bonus_attaque_monstres', 'planque', 'portes_a_frapper', 'marche_en_quete', 'receptacle_objets', 'activation_salles', 'chemin_impose'];
    private const CLES_MONSTRE_OPTIONNELLES = ['nom', 'deplacement', 'attaque', 'defense', 'pv_body', 'pv_mind', 'vulnerable_uniquement_a', 'invulnerable_casse_arme', 'fuite', 'inactif', 'sorts_dread', 'capacites', 'se_rend'];
    /** Lot E1 (2026-11-22) : un monstre errant en objet — plusieurs figures, profil d'auteur (ni refuge ni sommeil : il surgit). */
    private const CLES_ERRANT_OPTIONNELLES = ['nombre', 'nom', 'deplacement', 'attaque', 'defense', 'pv_body', 'pv_mind', 'vulnerable_uniquement_a', 'invulnerable_casse_arme', 'sorts_dread', 'capacites'];
    /** « Wandering Monster in this Quest: 4 Goblins » (F9528 q. 4) : le plus grand nombre lu dans les livrets, avec de la marge. */
    public const NOMBRE_ERRANTS_MAX = 6;
    private const CLES_NOTE = ['repere', 'quand', 'alors'];
    private const CLES_NOTE_OPTIONNELLES = ['une_fois'];
    private const CLES_QUAND = ['declencheur', 'cible'];
    private const CLES_QUAND_OPTIONNELLES = ['si_drapeau', 'sauf_drapeau', 'si_deguise', 'si_porte', 'si_sans_monstre'];
    private const LONGUEUR_REPERE = 40;
    private const LONGUEUR_DRAPEAU = 40;
    private const CLES_PORTE = ['x', 'y', 'cote', 'type'];
    private const CLES_PORTE_OPTIONNELLES = ['verrou', 'ouverte', 'revelee', 'cachee', 'frapper', 'assiegee'];
    private const CLES_CASE = ['x', 'y'];
    private const CLES_MOBILIER = ['slug', 'x', 'y', 'rotation'];
    private const CLES_POSE = ['slug', 'x', 'y'];
    // Lot K5d (R6) : les drapeaux d'un piège de quête, lus par `MoteurPieges::trouvable()` / `desarmable()`.
    private const CLES_PIEGE_OPTIONNELLES = ['introuvable', 'indesarmable', 'gouffre', 'par_note'];
    private const CLES_TERRAIN_OPTIONNELLES = ['paire_id', 'cachee', 'sortie'];
    private const CLES_RECOMPENSE = ['or', 'partage'];
    /** Lot K6 (*Dread Moon*) : `recompense.reputation` — des jetons de réputation pour le groupe. */
    private const CLES_RECOMPENSE_OPTIONNELLES = ['reputation', 'objets', 'si_drapeau'];
    /** Les mercenaires que la campagne offre (`mercenaires.offerts`) — lus par l'effet `debloquer_mercenaire`. @var list<string> */
    private array $mercenairesOfferts = [];
    /** Les drapeaux posés par les effets d'une OFFRE (imbriqués) — versés aux drapeaux posés de la quête. @var array<string, true> */
    private array $posesDOffres = [];

    /** Clés déjà prévues par `docs/plan-mode-classique.md` §3, pas encore lues : le lot qui les prendra. */

    /**
     * Les conditions de fin qu'une quête peut déclarer : SEULEMENT celles que le moteur sait jouer
     * (`App\Models\Quete::OBJECTIFS_CONSTRUITS`, lu ici — une seule liste). Un type ajouté là sans lecteur serait une
     * règle promise au joueur et jamais tenue.
     */
    public const TYPES_FIN = ['vaincre', 'drapeau', 'sortir', 'ramener'];

    private const LONGUEUR_LIBELLE_FIN = 300;

    /** Décalage d'une case vers chaque côté d'une porte. */
    private const DECALAGES = ['n' => [0, -1], 'e' => [1, 0], 's' => [0, 1], 'o' => [-1, 0]];

    // ─── État d'un appel (remis à zéro par valider()) ─────────────────────────────────────────────────────────────

    /** @var list<array{chemin: string, code: string, message: string}> */
    private array $refus = [];

    /**
     * Lot K5h : les drapeaux de CAMPAGNE posés par les quêtes DÉJÀ validées (dans l'ordre de jeu) — une quête peut les lire
     * (`si_drapeau`, fin `drapeau`). `$campagneDeLaQuete` : ceux que la quête en cours pose, versés après elle.
     *
     * @var array<string, true>
     */
    private array $drapeauxDeCampagne = [];

    /** @var array<string, true> */
    private array $campagneDeLaQuete = [];

    /** @var array{0: int, 1: int}|null largeur et hauteur du plateau, quand elles sont valides */
    private ?array $dimensions = null;

    /** @var list<array{id: string, x: int, y: int, l: int, h: int, i: int}> i = position dans `plateau.salles` */
    private array $salles = [];

    /** @var array<string, true> ids des salles ouvertes de la quête en cours de contrôle */
    private array $ouvertes = [];

    /**
     * Cases déjà occupées dans la quête en cours : « x,y » → [genre, nom] de chaque élément posé dessus. Le genre dit
     * avec quoi un élément partage sa case (`PARTAGES_INTERDITS`) ; seuls les éléments VALIDES y entrent.
     *
     * @var array<string, list<array{genre: string, nom: string}>>
     */
    private array $occupees = [];

    /** @var array<string, true> BORDS de case déjà pris par une porte de la quête en cours (`cleBord()`) */
    private array $embrasures = [];

    /**
     * Le plateau porte une liste de salles lisible. Sans elle, une salle citée n'est pas « inconnue » : elle n'a pas
     * pu être lue (la clé manquante est déjà refusée) — pas de refus en cascade.
     */
    private bool $sallesPosees = false;

    /**
     * La quête porte une liste de salles ouvertes lisible, sur un plateau lisible : seul alors un contrôle « dans une
     * salle fermée » est fondé. Sinon, toute case semblerait fermée à cause d'une clé absente.
     */
    private bool $ouvertesFiables = false;

    public function __construct(private readonly CatalogueConstructeur $catalogue) {}

    /**
     * Un fichier importé porte `empreinte` : elle doit égaler celle de son document (`empreinte_invalide`).
     * Appelé par l'import seulement, une fois le document reconnu valide.
     *
     * @param  array<string, mixed>  $document  le document, `empreinte` déjà retirée
     * @return list<array{chemin: string, code: string, message: string}>
     */
    public function verifierEmpreinte(array $document, mixed $declaree): array
    {
        $this->refus = [];
        if ($declaree !== EmpreinteCampagne::de($document)) {
            $this->refuser('empreinte', self::EMPREINTE_INVALIDE,
                "ne correspond pas au document : le fichier a été modifié après son export "
                ."(retirer la clé « empreinte » pour l'importer tel quel)");
        }

        return $this->refus;
    }

    /**
     * @param  array<string, mixed>  $document  le document décodé (l'objet `campagne`, sans clé englobante)
     * @param  string|null  $slugAttendu  le slug de l'URL, s'il y en a un : il doit égaler celui du document
     * @return list<array{chemin: string, code: string, message: string}>
     */
    public function valider(array $document, ?string $slugAttendu = null): array
    {
        $this->refus = [];
        $this->mercenairesOfferts = [];
        $this->posesDOffres = [];
        $this->dimensions = null;
        $this->salles = [];
        $this->ouvertes = [];
        $this->occupees = [];
        $this->embrasures = [];
        $this->sallesPosees = false;
        $this->ouvertesFiables = false;

        if ($this->enObjet($document, '') === null) {
            return $this->refus;
        }

        $this->cles($document, '', self::CLES_CAMPAGNE, ['mercenaires', 'cartes_tresor']);
        // Lot E1 : les boîtes dont les cartes de trésor rejoignent le paquet (`DeckFouille::BOITES_DE_CARTES`).
        if (array_key_exists('cartes_tresor', $document)) {
            foreach ((array) $this->enListe($document['cartes_tresor'], 'cartes_tresor') as $k => $boite) {
                if (! in_array($boite, \App\Partie\Fouille\DeckFouille::BOITES_DE_CARTES, true)) {
                    $this->refuser("cartes_tresor.$k", self::VALEUR_INVALIDE,
                        'boîte sans cartes de trésor sourcées — permises : '.implode(', ', \App\Partie\Fouille\DeckFouille::BOITES_DE_CARTES));
                }
            }
        }
        $this->format($document);

        $slug = $this->slug($document, 'slug', '');
        if ($slug !== null && $slugAttendu !== null && $slug !== $slugAttendu) {
            $this->refuser('slug', self::SLUG_NON_CONCORDANT,
                "le slug du document (« $slug ») ne vaut pas celui de l'URL (« $slugAttendu »)");
        }

        $this->texte($document, 'titre', '', nonVide: true, max: self::LONGUEUR_TITRE);
        $this->texte($document, 'auteur', '', nullable: true, max: self::LONGUEUR_AUTEUR);
        $this->texte($document, 'mention', '', nullable: true, max: self::LONGUEUR_MENTION);
        $this->prerequis($document);
        $this->mercenaires($document);
        $this->plateau($document);
        $this->quetes($document);

        return $this->refus;
    }

    // ─── Campagne ─────────────────────────────────────────────────────────────────────────────────────────────────

    private function format(array $document): void
    {
        if (!array_key_exists('format', $document)) {
            return;
        }
        $format = $document['format'];
        if (!is_int($format)) {
            $this->refuser('format', self::TYPE_INVALIDE, 'un entier est attendu');

            return;
        }
        if (!in_array($format, self::FORMATS_ACCEPTES, true)) {
            $this->refuser('format', self::FORMAT_INCONNU,
                "format $format inconnu (formats acceptés : ".implode(', ', self::FORMATS_ACCEPTES).')');
        }
    }

    /** Les régimes d'embauche (lot K6 ; René, 2026-11-19 : « chaque campagne choisit sa règle »). */
    public const REGIMES_MERCENAIRES = ['par_quete', 'retenu_moitie', 'debloques'];

    /**
     * `mercenaires` (facultative, lot K6) : `{regime, offerts: [slug…]}` — le régime d'embauche (*Frozen Horror*
     * `par_quete`, *Ogre Horde* `retenu_moitie`, *Dread Moon* `debloques`) et les mercenaires du catalogue qu'elle offre :
     * jamais un captif scénarisé ni un allié d'un seul effet.
     */
    private function mercenaires(array $document): void
    {
        if (!array_key_exists('mercenaires', $document)) {
            return;
        }
        $mercenaires = $this->enObjet($document['mercenaires'], 'mercenaires');
        if ($mercenaires === null) {
            return;
        }
        $this->cles($mercenaires, 'mercenaires', ['regime', 'offerts']);
        $this->mercenairesOfferts = array_values(array_filter((array) ($mercenaires['offerts'] ?? []), 'is_string'));
        $this->dans($mercenaires, 'regime', 'mercenaires', self::REGIMES_MERCENAIRES);
        $offerts = $this->enListeDe($mercenaires, 'offerts', 'mercenaires');
        if (array_key_exists('offerts', $mercenaires) && is_array($mercenaires['offerts']) && $offerts === []) {
            $this->refuser('mercenaires.offerts', self::VALEUR_INVALIDE, 'au moins un mercenaire est attendu');
        }
        foreach ($offerts as $i => $slug) {
            $mercenaire = is_string($slug) ? ($this->catalogue->index('mercenaires')[$slug] ?? null) : null;
            if ($mercenaire === null || $mercenaire->captif || $mercenaire->octroi_seul) {
                $this->refuser("mercenaires.offerts.$i", self::CATALOGUE_INCONNU,
                    '« '.(is_string($slug) ? $slug : '?').' » n\'est pas un mercenaire recrutable du catalogue');
            }
        }
    }

    private function prerequis(array $document): void
    {
        foreach ($this->enListeDe($document, 'prerequis_heros', '') as $i => $classe) {
            $chemin = "prerequis_heros.$i";
            if (!is_string($classe)) {
                $this->refuser($chemin, self::TYPE_INVALIDE, 'un nom de classe est attendu');
            } elseif (!$this->catalogue->contient('classes', $classe)) {
                $this->refuser($chemin, self::CLASSE_INCONNUE, "« $classe » n'est pas une classe de héros");
            }
        }
    }

    // ─── Plateau et salles ────────────────────────────────────────────────────────────────────────────────────────

    private function plateau(array $document): void
    {
        if (!array_key_exists('plateau', $document)) {
            return;
        }
        $plateau = $this->enObjet($document['plateau'], 'plateau');
        if ($plateau === null) {
            return;
        }
        $this->cles($plateau, 'plateau', self::CLES_PLATEAU);

        $largeur = $this->entier($plateau, 'largeur', 'plateau', min: 1);
        $hauteur = $this->entier($plateau, 'hauteur', 'plateau', min: 1);
        if ($largeur !== null && $hauteur !== null) {
            $this->dimensions = [$largeur, $hauteur];
        }

        $this->salles($plateau);
    }

    /** La quête en cours de validation a des portes à téléportation (`regles.portes_teleportation`) : lu par `porteSurUnBord()`. */
    private bool $portesTeleportent = false;

    private function salles(array $plateau): void
    {
        $this->sallesPosees = is_array($plateau['salles'] ?? null) && array_is_list($plateau['salles']);
        $rectangles = [];
        $ids = [];

        foreach ($this->enListeDe($plateau, 'salles', 'plateau') as $i => $valeur) {
            $chemin = "plateau.salles.$i";
            $salle = $this->enObjet($valeur, $chemin);
            if ($salle === null) {
                continue;
            }
            // `parties` (René, 2026-11-19 : « respecte le plan du board ») : les rectangles de plus d'une salle qui n'en est
            // pas un — la salle EN L du plateau officiel. Facultative.
            $this->cles($salle, $chemin, self::CLES_SALLE, ['parties']);

            $id = $this->identifiant($salle, 'id', $chemin);
            $x = $this->entier($salle, 'x', $chemin);
            $y = $this->entier($salle, 'y', $chemin);
            $l = $this->entier($salle, 'l', $chemin, min: 1);
            $h = $this->entier($salle, 'h', $chemin, min: 1);
            if ($id === null || $x === null || $y === null || $l === null || $h === null) {
                continue;
            }

            if (isset($ids[$id])) {
                $this->refuser($chemin.'.id', self::SALLE_ID_EN_DOUBLE,
                    "l'id « $id » est déjà pris par une autre salle");

                continue;
            }
            $ids[$id] = true;

            if ($x < 0 || $y < 0 || ($this->dimensions !== null
                && ($x + $l > $this->dimensions[0] || $y + $h > $this->dimensions[1]))) {
                $this->refuser($chemin, self::SALLE_HORS_PLATEAU, "la salle « $id » sort du plateau");
            }

            $parties = $this->partiesDeSalle($salle, $chemin, $id, ['x' => $x, 'y' => $y, 'l' => $l, 'h' => $h]);
            $nouvelle = ['id' => $id, 'x' => $x, 'y' => $y, 'l' => $l, 'h' => $h, 'i' => (int) $i, 'parties' => $parties];

            foreach ($rectangles as $autre) {
                foreach (Salles::rectangles($nouvelle) as $a) {
                    foreach (Salles::rectangles($autre) as $b) {
                        if ($this->interieursSeChevauchent($a, $b)) {
                            $this->refuser($chemin, self::SALLES_SE_CHEVAUCHENT,
                                "la salle « $id » chevauche la salle « {$autre['id']} »");

                            continue 3;
                        }
                    }
                }
            }

            $rectangles[] = $nouvelle;
        }

        $this->salles = $rectangles;
    }

    /**
     * Les `parties` d'une salle : des rectangles `{x, y, l, h}` dans le plateau, qui ne chevauchent ni la salle ni une
     * autre de ses parties, et dont chacune TOUCHE (par un bord commun) le rectangle principal ou une partie déjà admise.
     *
     * @param  array<string, mixed>  $salle
     * @param  array{x: int, y: int, l: int, h: int}  $principal
     * @return list<array{x: int, y: int, l: int, h: int}>
     */
    private function partiesDeSalle(array $salle, string $chemin, string $id, array $principal): array
    {
        if (! array_key_exists('parties', $salle)) {
            return [];
        }

        $admises = [];
        foreach ($this->enListeDe($salle, 'parties', $chemin) as $j => $valeur) {
            $ici = "$chemin.parties.$j";
            $partie = $this->enObjet($valeur, $ici);
            if ($partie === null) {
                continue;
            }
            $this->cles($partie, $ici, ['x', 'y', 'l', 'h']);
            $x = $this->entier($partie, 'x', $ici);
            $y = $this->entier($partie, 'y', $ici);
            $l = $this->entier($partie, 'l', $ici, min: 1);
            $h = $this->entier($partie, 'h', $ici, min: 1);
            if ($x === null || $y === null || $l === null || $h === null) {
                continue;
            }
            $r = ['x' => $x, 'y' => $y, 'l' => $l, 'h' => $h];

            if ($x < 0 || $y < 0 || ($this->dimensions !== null
                && ($x + $l > $this->dimensions[0] || $y + $h > $this->dimensions[1]))) {
                $this->refuser($ici, self::SALLE_HORS_PLATEAU, "une partie de la salle « $id » sort du plateau");

                continue;
            }

            $deja = [$principal, ...$admises];
            if (collect($deja)->contains(fn (array $autre) => $this->interieursSeChevauchent($r, $autre))) {
                $this->refuser($ici, self::SALLES_SE_CHEVAUCHENT, "une partie de la salle « $id » chevauche la salle elle-même");

                continue;
            }
            if (! collect($deja)->contains(fn (array $autre) => self::seTouchent($r, $autre))) {
                $this->refuser($ici, self::PARTIE_DETACHEE, "une partie de la salle « $id » ne touche pas le reste de la salle");

                continue;
            }

            $admises[] = $r;
        }

        return $admises;
    }

    /** Deux rectangles disjoints partagent-ils un bord (au moins une case de long) ? */
    private static function seTouchent(array $a, array $b): bool
    {
        $chevaucheX = $a['x'] < $b['x'] + $b['l'] && $b['x'] < $a['x'] + $a['l'];
        $chevaucheY = $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];

        return ($chevaucheX && ($a['y'] + $a['h'] === $b['y'] || $b['y'] + $b['h'] === $a['y']))
            || ($chevaucheY && ($a['x'] + $a['l'] === $b['x'] || $b['x'] + $b['l'] === $a['x']));
    }

    /** @param  array{x: int, y: int, l: int, h: int}  $a  @param  array{x: int, y: int, l: int, h: int}  $b */
    private function interieursSeChevauchent(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['l'] && $b['x'] < $a['x'] + $a['l']
            && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];
    }

    // ─── Quêtes ───────────────────────────────────────────────────────────────────────────────────────────────────

    private function quetes(array $document): void
    {
        $slugs = [];
        $this->drapeauxDeCampagne = [];
        // Lot K5i : un embranchement peut viser une quête PLUS LOIN dans la liste — on connaît tous les slugs d'avance.
        $tousLesSlugs = array_values(array_filter(array_map(
            fn ($q) => is_array($q) && is_string($q['slug'] ?? null) ? $q['slug'] : null,
            is_array($document['quetes'] ?? null) ? $document['quetes'] : [],
        )));

        // Lot K5p : les SECONDES moitiés de quête double (leur autre moitié vient avant elles dans la liste).
        $secondes = [];
        $listeQuetes = is_array($document['quetes'] ?? null) ? array_values($document['quetes']) : [];
        foreach ($listeQuetes as $j => $q) {
            foreach ($listeQuetes as $k => $autre) {
                if (is_array($q) && is_array($autre) && $k < $j && is_string($q['slug'] ?? null)
                    && ($autre['slug'] ?? null) === data_get($q, 'double.quete') && data_get($autre, 'double.quete') === $q['slug']) {
                    $secondes[$q['slug']] = true;
                }
            }
        }

        foreach ($this->enListeDe($document, 'quetes', '') as $i => $valeur) {
            $chemin = "quetes.$i";
            $quete = $this->enObjet($valeur, $chemin);
            if ($quete === null) {
                continue;
            }
            $this->cles($quete, $chemin, self::CLES_QUETE, self::CLES_QUETE_OPTIONNELLES);

            $slug = $this->slug($quete, 'slug', $chemin);
            if ($slug !== null) {
                if (isset($slugs[$slug])) {
                    $this->refuser("$chemin.slug", self::SLUG_EN_DOUBLE,
                        "« $slug » est déjà le slug de la quête n° {$slugs[$slug]}");
                } else {
                    $slugs[$slug] = $i + 1;
                }
            }

            $this->texte($quete, 'titre', $chemin, nonVide: true, max: self::LONGUEUR_TITRE);
            $this->texte($quete, 'ouverture', $chemin);
            // MONSTRE ERRANT (lot E1) : un slug, ou `{slug, nombre?, …profil}` — « 2 Skeletons » (F5815 p. 8).
            if (is_array($quete['monstre_errant'] ?? null)) {
                $ici = self::chemin($chemin, 'monstre_errant');
                $errant = $quete['monstre_errant'];
                $this->cles($errant, $ici, ['slug'], self::CLES_ERRANT_OPTIONNELLES);
                $this->slugCatalogue($errant, 'slug', $ici, 'monstres');
                $nombre = $this->entier($errant, 'nombre', $ici, min: 1);
                if ($nombre !== null && $nombre > self::NOMBRE_ERRANTS_MAX) {
                    $this->refuser(self::chemin($ici, 'nombre'), self::VALEUR_INVALIDE, 'au plus '.self::NOMBRE_ERRANTS_MAX.' figures');
                }
                $this->profilDeMonstre($errant, $ici);
            } else {
                $this->slugCatalogue($quete, 'monstre_errant', $chemin, 'monstres', nullable: true);
            }

            // Ordre FIXE : un élément ne partage une case qu'avec ce qui a été posé avant lui (`occuper()`). Les blocs
            // d'abord, puis l'escalier de départ, puis le reste : chaque refus de partage porte sur le dernier venu.
            $this->occupees = [];
            $this->embrasures = [];
            // SALLES SPÉCIALES (lot K6, R11) : posées EN TÊTE de la liste des salles le temps de cette quête — la première
            // salle qui contient une case la possède (`salleEn()`), et les salles du plateau qu'elles touchent sont absentes.
            $sallesDuPlateau = $this->salles;
            $speciales = $this->sallesSpeciales($quete, $chemin, $sallesDuPlateau);
            $this->salles = [...$speciales, ...$sallesDuPlateau];
            $this->sallesOuvertes($quete, $chemin);
            foreach ($speciales as $speciale) {
                $this->ouvertes[$speciale['id']] = true;
            }
            // Après les salles ouvertes : `regles.sans_fouille` les lit (lot K5e).
            $this->regles($quete, $chemin, $document);
            $this->portes($quete, $chemin);
            $this->blocs($quete, $chemin);
            $this->depart($quete, $chemin);
            $this->sortie($quete, $chemin);
            // Lot K5p (R10) : la quête double — `principale`, `seconde` ou `null`.
            $moitie = $this->double($quete, $chemin, (int) $i, is_array($document['quetes'] ?? null) ? array_values($document['quetes']) : []);
            $this->faussesSorties($quete, $chemin);
            $this->poses($quete, $chemin, 'mobilier', 'mobilier', tourne: true);
            $this->poses($quete, $chemin, 'monstres', 'monstres');
            $this->poses($quete, $chemin, 'pieges', 'pieges');
            $this->poses($quete, $chemin, 'terrain', 'terrain');
            $this->dangers($quete, $chemin);
            $this->glyphes($quete, $chemin);
            $this->coffresPieges($quete, $chemin);
            $this->tournoi($quete, $chemin);
            $this->recompense($quete, $chemin);
            // Les notes AVANT la fin : une fin « drapeau » n'est gagnable que si une note pose ce drapeau — de cette quête,
            // ou de campagne par une quête précédente (lot K5h).
            $this->campagneDeLaQuete = [];
            $drapeauxPoses = $this->notes($quete, $chemin) + $this->drapeauxDeCampagne;
            // Les deux moitiés d'une quête double partagent leurs drapeaux (`Quete::aDrapeau()`) : ceux que l'autre moitié
            // pose comptent ici aussi.
            if ($moitie !== null) {
                $drapeauxPoses += self::drapeauxDesNotes($this->moitieJumelle($quete, is_array($document['quetes'] ?? null) ? array_values($document['quetes']) : []));
            }
            if (is_array($pacifies = data_get($quete, 'regles.monstres_pacifies_par_drapeau'))) {
                foreach ($pacifies as $slugMonstre => $drapeauPacifie) {
                    if (is_string($drapeauPacifie) && $drapeauPacifie !== '' && ! isset($drapeauxPoses[$drapeauPacifie])) {
                        $this->refuser("$chemin.regles.monstres_pacifies_par_drapeau.$slugMonstre", self::DRAPEAU_JAMAIS_POSE,
                            "aucune note de cette quête, ni drapeau de campagne d'une quête précédente, ne pose « $drapeauPacifie »");
                    }
                }
            }
            // Une SECONDE moitié n'a pas de fin à elle (`fin: null`, vérifié par `double()`) : c'est celle de la principale.
            if ($moitie !== 'seconde') {
                $this->fin($quete, $chemin, $drapeauxPoses);
            }
            // Lot K5k (R5) : la charge, `{nom, x, y}` sur une case où un objet peut être posé — comme une apparition.
            if (array_key_exists('charge', $quete) && ($charge = $this->enObjet($quete['charge'], "$chemin.charge")) !== null) {
                $this->cles($charge, "$chemin.charge", ['nom', 'x', 'y']);
                $this->texte($charge, 'nom', "$chemin.charge", nonVide: true, max: 40);
                $this->caseDArrivee($charge, "$chemin.charge", 'la charge');
            }
            // LE CAPTIF À ESCORTER (René, 2026-11-20 — Sir Ragnar) : `{mercenaire, x, y}`, un captif du catalogue des mercenaires
            // (mode figurine), sur une case où une figure peut paraître. `Classique\Escorte`.
            if (array_key_exists('captif', $quete) && ($captif = $this->enObjet($quete['captif'], "$chemin.captif")) !== null) {
                // Lot E1 : `echec_si_mort` (sa mort fait perdre la quête), `persiste` (il reste allié pour la campagne).
                $this->cles($captif, "$chemin.captif", ['mercenaire', 'x', 'y'], ['echec_si_mort', 'persiste']);
                foreach (['echec_si_mort', 'persiste'] as $drapeauCaptif) {
                    if (array_key_exists($drapeauCaptif, $captif) && $captif[$drapeauCaptif] !== true) {
                        $this->refuser("$chemin.captif.$drapeauCaptif", self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
                    }
                }
                if ($this->slugCatalogue($captif, 'mercenaire', "$chemin.captif", 'mercenaires')) {
                    $m = $this->catalogue->index('mercenaires')[(string) $captif['mercenaire']];
                    if (! $m->captif || $m->mode_captif !== \App\Models\Mercenaire::MODE_CAPTIF_FIGURINE) {
                        $this->refuser("$chemin.captif.mercenaire", self::VALEUR_INVALIDE, "« {$captif['mercenaire']} » n'est pas un captif à escorter du catalogue");
                    }
                }
                $this->caseDArrivee($captif, "$chemin.captif", 'le captif');
            }
            // Lot K5i (C2) : les embranchements, après les notes (leurs drapeaux) et avec les slugs de TOUTE la campagne.
            $this->suite($quete, $chemin, $drapeauxPoses + $this->campagneDeLaQuete, $tousLesSlugs, is_string($quete['slug'] ?? null) ? $quete['slug'] : null);
            // Lot K5p : une seconde moitié de quête double ne s'atteint que par le passage — jamais par une `suite`.
            foreach ((array) ($quete['suite'] ?? []) as $k => $branche) {
                if (is_array($branche) && is_string($branche['quete'] ?? null) && isset($secondes[$branche['quete']])) {
                    $this->refuser("$chemin.suite.$k.quete", self::DOUBLE_INVALIDE,
                        "« {$branche['quete']} » est la seconde moitié d'une quête double : on n'y entre que par son passage");
                }
            }
            $this->drapeauxDeCampagne += $this->campagneDeLaQuete;
            $this->salles = $sallesDuPlateau;
        }
    }

    /**
     * `quete.salles_speciales` (lot K6, R11 — René, 2026-11-19 : « quand on assigne une salle spéciale sur le plateau, elle
     * remplace les salles du plateau en dessous pour la quête ») : `[{id, x, y, l, h}]` — la place publique de *Dread Moon*,
     * la salle tournante et la salle du trône de *Witch Lord*, la salle du Gremlin de *Frozen Horror*. Toujours ouverte. Une
     * salle du plateau qu'elle touche, même en partie, est ABSENTE de la quête : jamais ouverte (refus), son reste est de la
     * roche, murée au bord de la salle spéciale. Rend les rectangles valides.
     *
     * @param  list<array{id: string, x: int, y: int, l: int, h: int}>  $plateau
     * @return list<array{id: string, x: int, y: int, l: int, h: int}>
     */
    private function sallesSpeciales(array $quete, string $chemin, array $plateau): array
    {
        if (!array_key_exists('salles_speciales', $quete)) {
            return [];
        }
        $rectangles = [];
        $idsPlateau = array_column($plateau, 'id');
        foreach ($this->enListeDe($quete, 'salles_speciales', $chemin) as $i => $valeur) {
            $ici = self::chemin($chemin, 'salles_speciales').".$i";
            $salle = $this->enObjet($valeur, $ici);
            if ($salle === null) {
                continue;
            }
            // `place` (lot K6, *Dread Moon*) : une place publique — ses monstres restent inactifs (`Classique\Deguisement`).
            $this->cles($salle, $ici, self::CLES_SALLE, ['place', 'sans_magie']);
            // Lot E2 (G0052 q. 10 G) : l'arène de pierre de Kertz, où ni sort, ni artefact, ni équipement ne servent.
            if (array_key_exists('sans_magie', $salle) && $salle['sans_magie'] !== true) {
                $this->refuser("$ici.sans_magie", self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
            }
            if (array_key_exists('place', $salle) && $salle['place'] !== true) {
                $this->refuser("$ici.place", self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
            }
            $id = $this->identifiant($salle, 'id', $ici);
            $x = $this->entier($salle, 'x', $ici, min: 0);
            $y = $this->entier($salle, 'y', $ici, min: 0);
            $l = $this->entier($salle, 'l', $ici, min: 1);
            $h = $this->entier($salle, 'h', $ici, min: 1);
            if ($id === null || $x === null || $y === null || $l === null || $h === null) {
                continue;
            }
            if (in_array($id, $idsPlateau, true) || in_array($id, array_column($rectangles, 'id'), true)) {
                $this->refuser("$ici.id", self::SALLE_ID_EN_DOUBLE, "l'id « $id » est déjà pris par une autre salle");

                continue;
            }
            if ($this->dimensions !== null && ($x + $l > $this->dimensions[0] || $y + $h > $this->dimensions[1])) {
                $this->refuser($ici, self::SALLE_HORS_PLATEAU, "la salle spéciale « $id » sort du plateau");
            }
            $rect = ['id' => $id, 'x' => $x, 'y' => $y, 'l' => $l, 'h' => $h, 'i' => (int) $i];
            foreach ($rectangles as $autre) {
                if ($this->interieursSeChevauchent($rect, $autre)) {
                    $this->refuser($ici, self::SALLES_SE_CHEVAUCHENT, "la salle spéciale « $id » chevauche la salle spéciale « {$autre['id']} »");
                }
            }
            // Une salle du plateau qu'elle touche est absente de la quête : elle ne peut pas être ouverte.
            foreach ($plateau as $dessous) {
                $recouvre = collect(Salles::rectangles($dessous))->contains(fn (array $r) => $this->interieursSeChevauchent($rect, $r));
                if ($recouvre && in_array($dessous['id'], (array) ($quete['salles_ouvertes'] ?? []), true)) {
                    $this->refuser($ici, self::SALLES_SE_CHEVAUCHENT,
                        "la salle spéciale « $id » recouvre « {$dessous['id']} », qui ne peut donc pas être ouverte dans cette quête");
                }
            }
            $rectangles[] = $rect;
        }

        return $rectangles;
    }

    /**
     * `double` (lot K5p, R10 — *The Frozen Horror* q. 9-10) : rend `principale`, `seconde` ou `null`. La PREMIÈRE moitié
     * dans l'ordre est la principale : `{quete, passage}` ; la seconde : `{quete}`, et `fin`, `sortie`, `recompense` à
     * `null` (ce sont ceux de la principale), ni `suite` ni `regles.solo`.
     *
     * @param  list<mixed>  $quetes
     */
    private function double(array $quete, string $chemin, int $index, array $quetes): ?string
    {
        if (! array_key_exists('double', $quete)) {
            return null;
        }
        $ici = self::chemin($chemin, 'double');
        $double = $this->enObjet($quete['double'], $ici);
        if ($double === null) {
            return null;
        }
        $vise = $double['quete'] ?? null;
        $cible = null;
        foreach ($quetes as $j => $autre) {
            if (is_array($autre) && is_string($vise) && ($autre['slug'] ?? null) === $vise && $j !== $index) {
                $cible = $j;
            }
        }
        if ($cible === null) {
            $this->cles($double, $ici, ['quete'], ['passage']);
            $this->refuser("$ici.quete", self::DOUBLE_INVALIDE, 'la quête double doit nommer une AUTRE quête de la campagne');

            return null;
        }
        if (data_get($quetes[$cible], 'double.quete') !== ($quete['slug'] ?? null)) {
            $this->cles($double, $ici, ['quete'], ['passage']);
            $this->refuser("$ici.quete", self::DOUBLE_INVALIDE, "« $vise » ne désigne pas cette quête comme son autre moitié");

            return null;
        }

        if ($cible > $index) {
            // PRINCIPALE : son passage vers la seconde moitié, au format d'une sortie.
            $this->cles($double, $ici, ['quete', 'passage']);
            if (array_key_exists('passage', $double)) {
                $this->acces($double['passage'], "$ici.passage", 'le passage');
            }

            return 'principale';
        }

        // SECONDE : on y entre par son départ, qui est aussi le passage du retour.
        $this->cles($double, $ici, ['quete']);
        foreach (['fin', 'sortie', 'recompense'] as $cle) {
            if (($quete[$cle] ?? null) !== null) {
                $this->refuser(self::chemin($chemin, $cle), self::VALEUR_INVALIDE,
                    "la seconde moitié d'une quête double n'a pas de « $cle » à elle : null, c'est celle de la principale");
            }
        }
        if (array_key_exists('suite', $quete) || data_get($quete, 'regles.solo') !== null) {
            $this->refuser($ici, self::DOUBLE_INVALIDE, 'la seconde moitié d\'une quête double n\'a ni suite ni règle solo : ce sont celles de la principale');
        }

        return 'seconde';
    }

    /** L'autre moitié d'une quête double, ou `[]`. @param  list<mixed>  $quetes */
    private function moitieJumelle(array $quete, array $quetes): array
    {
        foreach ($quetes as $autre) {
            if (is_array($autre) && ($autre['slug'] ?? null) === data_get($quete, 'double.quete')) {
                return $autre;
            }
        }

        return [];
    }

    /** Les drapeaux de quête que posent les notes d'une quête (sans les valider : `notes()` le fait chez elle). */
    private static function drapeauxDesNotes(array $quete): array
    {
        $poses = [];
        foreach ((array) ($quete['notes'] ?? []) as $note) {
            foreach ((array) ($note['alors'] ?? []) as $effet) {
                if (($effet['effet'] ?? null) === MotsClesQuete::DRAPEAU && is_string($effet['nom'] ?? null) && empty($effet['campagne'])) {
                    $poses[$effet['nom']] = true;
                }
            }
        }

        return $poses;
    }

    /**
     * `notes` (lot K3) : « quand → alors », dans le vocabulaire fermé de `App\Engine\MotsClesQuete` — la SEULE liste,
     * celle que `NotesDeQuete` joue. Rend les noms des drapeaux qu'au moins une note pose.
     *
     * @return array<string, true>
     */
    private function notes(array $quete, string $chemin): array
    {
        $notes = $this->enListeDe($quete, 'notes', $chemin);
        $poses = [];
        $lus = []; // drapeau lu par `si_drapeau` → chemin de sa première lecture

        foreach ($notes as $i => $valeur) {
            $position = self::chemin($chemin, 'notes').".$i";
            $note = $this->enObjet($valeur, $position);
            if ($note === null) {
                continue;
            }
            $this->cles($note, $position, self::CLES_NOTE, self::CLES_NOTE_OPTIONNELLES);
            $this->texte($note, 'repere', $position, nonVide: true, max: self::LONGUEUR_REPERE);
            if (array_key_exists('une_fois', $note) && !is_bool($note['une_fois'])) {
                $this->refuser(self::chemin($position, 'une_fois'), self::TYPE_INVALIDE, 'true ou false est attendu');
            }

            if (array_key_exists('quand', $note) && ($quand = $this->enObjet($note['quand'], "$position.quand")) !== null) {
                $this->cles($quand, "$position.quand", self::CLES_QUAND, self::CLES_QUAND_OPTIONNELLES);
                $declencheur = $this->dans($quand, 'declencheur', "$position.quand", array_keys(MotsClesQuete::DECLENCHEURS));
                if ($declencheur !== null && array_key_exists('cible', $quand)) {
                    $this->cibleDeNote($quete, $quand['cible'], "$position.quand.cible", (string) $declencheur);
                }
                if (array_key_exists('si_deguise', $quand) && !is_bool($quand['si_deguise'])) {
                    $this->refuser("$position.quand.si_deguise", self::TYPE_INVALIDE, 'true ou false est attendu');
                }
                // Lot E2 : aucune figure active dans cette salle (F9528 q. 5 C) — une salle ouverte de la quête.
                if (array_key_exists('si_sans_monstre', $quand) && (! is_string($quand['si_sans_monstre'])
                    || ($this->ouvertesFiables && ! isset($this->ouvertes[$quand['si_sans_monstre']])))) {
                    $this->refuser("$position.quand.si_sans_monstre", self::SALLE_INCONNUE, 'une salle ouverte de la quête est attendue');
                }
                // Lot E1 : le héros qui déclenche PORTE cet objet (« the hero carrying the Moon Charm », F6646 ; G0052).
                if (array_key_exists('si_porte', $quand)) {
                    $this->slugCatalogue($quand, 'si_porte', "$position.quand", 'objets');
                }
                foreach (['si_drapeau', 'sauf_drapeau'] as $cleCondition) {
                    $drapeau = $this->nomDeDrapeau($quand, $cleCondition, "$position.quand");
                    if ($drapeau !== null) {
                        $lus[$drapeau] ??= "$position.quand.$cleCondition";
                    }
                }
            }

            $alors = $this->enListeDe($note, 'alors', $position);
            if (array_key_exists('alors', $note) && is_array($note['alors']) && $alors === []) {
                $this->refuser("$position.alors", self::VALEUR_INVALIDE, 'Une note sans effet ne fait rien : il en faut au moins un.');
            }
            foreach ($alors as $j => $effet) {
                $drapeau = $this->effetDeNote($effet, "$position.alors.$j", $quete);
                if ($drapeau !== null) {
                    $poses[$drapeau] = true;
                }
            }
        }

        // OFFRES (lot K6) : les drapeaux que posent les effets d'une offre acceptée comptent aussi.
        $poses += $this->posesDOffres;
        $this->posesDOffres = [];

        // GLYPHES (lot K6) : détruire un glyphe pose le drapeau que l'auteur lui donne — lisible par les notes.
        foreach ((array) ($quete['glyphes'] ?? []) as $glyphe) {
            if (is_array($glyphe) && is_string($glyphe['drapeau'] ?? null)) {
                $poses[$glyphe['drapeau']] = true;
            }
        }

        foreach ($lus as $drapeau => $ou) {
            if (!isset($poses[$drapeau]) && !isset($this->drapeauxDeCampagne[$drapeau])) {
                $this->refuser($ou, self::DRAPEAU_JAMAIS_POSE,
                    "aucune note de cette quête, ni drapeau de campagne d'une quête précédente, ne pose « $drapeau » : cette note ne jouerait jamais");
            }
        }

        return $poses;
    }

    /** La cible d'une note : UNE clé, parmi celles que son déclencheur accepte, et qui désigne quelque chose de posé. */
    private function cibleDeNote(array $quete, mixed $valeur, string $position, string $declencheur): void
    {
        $cible = $this->enObjet($valeur, $position);
        if ($cible === null) {
            return;
        }
        $permises = MotsClesQuete::DECLENCHEURS[$declencheur]['cibles'];
        $cles = array_map('strval', array_keys($cible));
        if (count($cles) !== 1 || !in_array($cles[0], $permises, true)) {
            $this->refuser($position, self::CIBLE_DE_NOTE_INVALIDE,
                "« $declencheur » vise exactement une de ces cibles : ".implode(', ', $permises));

            return;
        }

        switch ($cles[0]) {
            case 'salle':
                if (!is_string($cible['salle'])) {
                    $this->refuser("$position.salle", self::TYPE_INVALIDE, 'un identifiant de salle est attendu');
                } elseif ($this->ouvertesFiables && !isset($this->ouvertes[$cible['salle']])) {
                    $this->refuser("$position.salle", self::SALLE_INCONNUE,
                        "« {$cible['salle']} » n'est pas une salle ouverte de cette quête : rien ne peut s'y produire");
                }
                break;
            case 'case':
                if (($case = $this->enObjet($cible['case'], "$position.case")) !== null) {
                    $this->cles($case, "$position.case", self::CLES_CASE);
                    $this->cellule($case, "$position.case");
                }
                break;
            // Lot E2 : une liste de cases (« any of these corridors », F9528 q. 6 B).
            case 'cases':
                $liste = $this->enListe($cible['cases'], "$position.cases");
                foreach ((array) $liste as $k => $c) {
                    if (($case = $this->enObjet($c, "$position.cases.$k")) !== null) {
                        $this->cles($case, "$position.cases.$k", self::CLES_CASE);
                        $this->cellule($case, "$position.cases.$k");
                    }
                }
                if ($liste === []) {
                    $this->refuser("$position.cases", self::VALEUR_INVALIDE, 'au moins une case');
                }
                break;
            case 'porte':
            case 'monstre':
                $this->indexDe($quete, $cles[0] === 'porte' ? 'portes' : 'monstres', $cible[$cles[0]], "$position.{$cles[0]}");
                break;
            case 'tour':
                $this->entier($cible, 'tour', $position, min: 1);
                break;
            // Lot E1 : des figures posées (toutes tombées), un meuble posé, un captif du catalogue.
            case 'monstres':
                $liste = $this->enListe($cible['monstres'], "$position.monstres");
                foreach ((array) $liste as $k => $index) {
                    $this->indexDe($quete, 'monstres', $index, "$position.monstres.$k");
                }
                if ($liste === []) {
                    $this->refuser("$position.monstres", self::VALEUR_INVALIDE, 'au moins une figure');
                }
                break;
            case 'mobilier':
                $this->indexDe($quete, 'mobilier', $cible['mobilier'], "$position.mobilier");
                break;
            case 'captif':
                $this->slugCatalogue($cible, 'captif', $position, 'mercenaires');
                break;
            // Lot E2 : les portes où chaque héros se tient (G0052 q. 10 G).
            case 'portes':
                $liste = $this->enListe($cible['portes'], "$position.portes");
                foreach ((array) $liste as $k => $index) {
                    $this->indexDe($quete, 'portes', $index, "$position.portes.$k");
                }
                if ($liste === []) {
                    $this->refuser("$position.portes", self::VALEUR_INVALIDE, 'au moins une porte');
                }
                break;
            // Lot E2 : l'objet jeté dans le réceptacle (F9528 q. 8).
            case 'objet':
                $this->slugCatalogue($cible, 'objet', $position, 'objets');
                break;
        }
    }

    /** `$index` désigne un élément de `quete[$liste]` (une porte, un monstre posé) : un entier, dans la liste. */
    private function indexDe(array $quete, string $liste, mixed $index, string $chemin): void
    {
        $nombre = is_array($quete[$liste] ?? null) ? count($quete[$liste]) : 0;
        if (!is_int($index)) {
            $this->refuser($chemin, self::TYPE_INVALIDE, "l'index (entier) d'un élément de `$liste` est attendu");
        } elseif ($index < 0 || $index >= $nombre) {
            $this->refuser($chemin, self::CIBLE_INCONNUE, "l'index $index ne désigne rien dans `$liste` : cette quête en pose $nombre");
        }
    }

    /** Un effet de note. Rend le nom du drapeau qu'il pose, ou `null`. */
    /** Les effets imbriqués d'un effet composé (lot E1) : validés comme ceux d'une note, jamais un autre effet composé. */
    private function sousEffets(array $effet, string $cle, string $position, array $quete): void
    {
        foreach ($this->enListeDe($effet, $cle, $position) as $k => $sousEffet) {
            if (is_array($sousEffet) && in_array($sousEffet['effet'] ?? null, MotsClesQuete::EFFETS_COMPOSES, true)) {
                $this->refuser("$position.$cle.$k", self::VALEUR_INVALIDE, 'un effet composé (offre, jet) ne s\'imbrique pas');

                continue;
            }
            $drapeau = $this->effetDeNote($sousEffet, "$position.$cle.$k", $quete);
            if ($drapeau !== null) {
                $this->posesDOffres[$drapeau] = true;
            }
        }
    }

    private function effetDeNote(mixed $valeur, string $position, array $quete): ?string
    {
        $effet = $this->enObjet($valeur, $position);
        if ($effet === null) {
            return null;
        }
        if (!array_key_exists('effet', $effet)) {
            $this->refuser("$position.effet", self::CLE_MANQUANTE, 'clé obligatoire absente : « effet »');

            return null;
        }
        $nom = $this->dans($effet, 'effet', $position, array_keys(MotsClesQuete::EFFETS));
        if ($nom === null) {
            return null;
        }
        $definition = MotsClesQuete::EFFETS[$nom];
        $this->cles($effet, $position, ['effet', ...$definition['parametres']], $definition['optionnels']);

        switch ($nom) {
            case MotsClesQuete::TEXTE:
                $this->texte($effet, 'texte', $position, nonVide: true);
                break;
            // Lot K6 (*Dread Moon*) : la réputation, l'offre déclarée, le mercenaire débloqué.
            case MotsClesQuete::REPUTATION:
                $jetons = $this->entier($effet, 'jetons', $position);
                if ($jetons === 0) {
                    $this->refuser("$position.jetons", self::VALEUR_INVALIDE, 'un nombre de jetons non nul est attendu');
                }
                break;
            case MotsClesQuete::OFFRE:
                $this->texte($effet, 'libelle', $position, nonVide: true, max: 120);
                if (array_key_exists('groupe', $effet)) {
                    $this->identifiant($effet, 'groupe', $position);
                }
                if (array_key_exists('cout', $effet) && ($cout = $this->enObjet($effet['cout'], "$position.cout")) !== null) {
                    $this->cles($cout, "$position.cout", [], ['or', 'reputation']);
                    $this->entier($cout, 'or', "$position.cout", min: 0);
                    $this->entier($cout, 'reputation', "$position.cout", min: 0);
                }
                $sousEffets = $this->enListeDe($effet, 'alors', $position);
                if (array_key_exists('alors', $effet) && is_array($effet['alors']) && $sousEffets === []) {
                    $this->refuser("$position.alors", self::VALEUR_INVALIDE, 'une offre sans effet ne fait rien : il en faut au moins un');
                }
                foreach ($sousEffets as $k => $sousEffet) {
                    if (is_array($sousEffet) && ($sousEffet['effet'] ?? null) === MotsClesQuete::OFFRE) {
                        $this->refuser("$position.alors.$k", self::VALEUR_INVALIDE, 'une offre ne déclare pas une autre offre');

                        continue;
                    }
                    $drapeauImbrique = $this->effetDeNote($sousEffet, "$position.alors.$k", $quete);
                    if ($drapeauImbrique !== null) {
                        $this->posesDOffres[$drapeauImbrique] = true;
                    }
                }
                break;
            case MotsClesQuete::DEBLOQUER_MERCENAIRE:
                if (array_key_exists('slug', $effet) && !in_array($effet['slug'], $this->mercenairesOfferts, true)) {
                    $this->refuser("$position.slug", self::CATALOGUE_INCONNU, 'ce mercenaire n\'est pas offert par la campagne (`mercenaires.offerts`)');
                }
                break;
            case MotsClesQuete::OR:
                $this->entier($effet, 'or', $position, min: 1);
                // Lot E1 : partagé entre les héros vivants de la quête (`groupe`), ou autant à chacun (`par_heros`).
                $this->dans($effet, 'partage', $position, self::PARTAGES);
                break;
            case MotsClesQuete::OBJET:
                $this->slugCatalogue($effet, 'slug', $position, 'objets');
                $this->entier($effet, 'quantite', $position, min: 1);
                if (array_key_exists('si_absent_du_groupe', $effet) && ! is_bool($effet['si_absent_du_groupe'])) {
                    $this->refuser("$position.si_absent_du_groupe", self::TYPE_INVALIDE, 'true ou false est attendu');
                }
                // SINON (lot E1) : les effets joués quand le groupe a déjà l'objet.
                if (array_key_exists('sinon', $effet)) {
                    $this->sousEffets($effet, 'sinon', $position, $quete);
                }
                break;
            // Lot E1 : retirer des figures posées, un meuble posé, murer une porte, retirer un bloc.
            case MotsClesQuete::RETIRER:
                $liste = $this->enListe($effet['monstres'] ?? null, "$position.monstres");
                foreach ((array) $liste as $k => $index) {
                    $this->indexDe($quete, 'monstres', $index, "$position.monstres.$k");
                }
                if ($liste === []) {
                    $this->refuser("$position.monstres", self::VALEUR_INVALIDE, 'au moins une figure à retirer');
                }
                break;
            case MotsClesQuete::RETIRER_MEUBLE:
                $this->indexDe($quete, 'mobilier', $effet['mobilier'] ?? null, "$position.mobilier");
                break;
            case MotsClesQuete::MURER_PORTE:
                $this->indexDe($quete, 'portes', $effet['porte'] ?? null, "$position.porte");
                break;
            case MotsClesQuete::RETIRER_BLOC:
                $this->entier($effet, 'x', $position, min: 0);
                $this->entier($effet, 'y', $position, min: 0);
                break;
            case MotsClesQuete::DECLENCHER_PIEGE:
                $this->cellule($effet, $position);
                break;
            case MotsClesQuete::VOLER:
                $this->entier($effet, 'or', $position, min: 1);
                break;
            case MotsClesQuete::LIBERER_CAPTIF:
                if (! is_array($quete['captif'] ?? null)) {
                    $this->refuser($position, self::VALEUR_INVALIDE, 'cette quête n\'a pas de captif à libérer (`captif`)');
                }
                break;
            case MotsClesQuete::OBJET_AU_HASARD:
                $parmi = $this->enListeDe($effet, 'parmi', $position);
                foreach ($parmi as $k => $slug) {
                    if (! is_string($slug) || ! $this->catalogue->contient('objets', $slug)) {
                        $this->refuser("$position.parmi.$k", self::CATALOGUE_INCONNU, "un slug du catalogue « objets » est attendu");
                    }
                }
                if (array_key_exists('parmi', $effet) && count($parmi) < 2) {
                    $this->refuser("$position.parmi", self::VALEUR_INVALIDE, 'au moins deux objets parmi lesquels tirer');
                }
                $this->entier($effet, 'quantite', $position, min: 1);
                break;
            case MotsClesQuete::JET:
                $des = $this->entier($effet, 'des', $position, min: 1);
                if ($des !== null && $des > 2) {
                    $this->refuser("$position.des", self::VALEUR_INVALIDE, 'un ou deux dés rouges');
                }
                $max = 6 * ($des ?? 1);
                $couvertes = [];
                foreach ($this->enListeDe($effet, 'issues', $position) as $k => $valeurIssue) {
                    $ici = "$position.issues.$k";
                    $issue = $this->enObjet($valeurIssue, $ici);
                    if ($issue === null) {
                        continue;
                    }
                    $this->cles($issue, $ici, ['de', 'a', 'alors']);
                    $de = $this->entier($issue, 'de', $ici, min: 1);
                    $a = $this->entier($issue, 'a', $ici, min: 1);
                    if ($de !== null && $a !== null) {
                        if ($a < $de || $a > $max) {
                            $this->refuser("$ici.a", self::VALEUR_INVALIDE, "la plage doit aller de « de » à au plus $max");
                        }
                        for ($v = $de; $v <= min($a, $max); $v++) {
                            if (isset($couvertes[$v])) {
                                $this->refuser($ici, self::VALEUR_INVALIDE, "le total $v est déjà couvert par une autre issue");
                                break;
                            }
                            $couvertes[$v] = true;
                        }
                    }
                    $this->sousEffets($issue, 'alors', $ici, $quete);
                }
                break;
            case MotsClesQuete::DEGATS:
                $this->entier($effet, 'body', $position, min: 1);
                foreach (['sur_jet', 'avec_casque'] as $cleJet) {
                    $jet = $this->entier($effet, $cleJet, $position, min: 1);
                    if ($jet !== null && $jet > 6) {
                        $this->refuser("$position.$cleJet", self::VALEUR_INVALIDE, 'doit être entre 1 et 6');
                    }
                }
                if (array_key_exists('toute_la_salle', $effet) && ! is_bool($effet['toute_la_salle'])) {
                    $this->refuser("$position.toute_la_salle", self::TYPE_INVALIDE, 'true ou false est attendu');
                }
                break;
            // Lot E2 (*Prophecy of Telor*) : le talisman — sort appris, Esprit du livret, objet changé en un autre.
            case MotsClesQuete::APPRENDRE_SORT_DREAD:
                $slug = $this->slugCatalogue($effet, 'sort', $position, 'sorts_dread');
                $sort = $slug === null ? null : ($this->catalogue->index('sorts_dread')[$slug] ?? null);
                if ($sort !== null && ! \App\Partie\SortsDreadPorteur::jouable($sort)) {
                    $this->refuser("$position.sort", self::VALEUR_INVALIDE,
                        "« {$sort->nom} » ne se lance pas par un héros : seuls un rayon à dégâts fixes ou un sort de salle le peuvent");
                }
                break;
            case MotsClesQuete::AVANCER_SUR_CHEMIN:
                $this->entier($effet, 'des', $position, min: 1);
                if (is_int($effet['des'] ?? null) && $effet['des'] > 4) {
                    $this->refuser("$position.des", self::VALEUR_INVALIDE, 'au plus 4 dés');
                }
                if (! is_array(data_get($quete, 'regles.chemin_impose'))) {
                    $this->refuser("$position.effet", self::VALEUR_INVALIDE, 'la quête n\'a pas de chemin imposé (règle « chemin imposé »)');
                }
                break;
            case MotsClesQuete::METAMORPHOSER:
                $this->slugCatalogue($effet, 'en', $position, 'monstres');
                $this->entier($effet, 'pv_body', $position, min: 1);
                if (array_key_exists('sauf_declencheur', $effet) && ! is_bool($effet['sauf_declencheur'])) {
                    $this->refuser("$position.sauf_declencheur", self::TYPE_INVALIDE, 'true ou false est attendu');
                }
                break;
            case MotsClesQuete::FRANCHIR_PORTES:
                $liste = $this->enListe($effet['portes'] ?? null, "$position.portes");
                foreach ((array) $liste as $k => $index) {
                    $this->indexDe($quete, 'portes', $index, "$position.portes.$k");
                }
                if ($liste === []) {
                    $this->refuser("$position.portes", self::VALEUR_INVALIDE, 'au moins une porte');
                }
                break;
            case MotsClesQuete::ESPRIT:
                if ($this->entier($effet, 'points', $position) === 0) {
                    $this->refuser("$position.points", self::VALEUR_INVALIDE, 'un nombre de points non nul est attendu (négatif : en moins)');
                }
                break;
            case MotsClesQuete::REMPLACER_OBJET:
                $de = $this->slugCatalogue($effet, 'slug', $position, 'objets');
                $par = $this->slugCatalogue($effet, 'par', $position, 'objets');
                if ($de !== null && $de === $par) {
                    $this->refuser("$position.par", self::VALEUR_INVALIDE, 'l\'objet de remplacement doit être un autre objet');
                }
                break;
            case MotsClesQuete::DETRUIRE_OBJET:
                $this->slugCatalogue($effet, 'slug', $position, 'objets');
                if (array_key_exists('toute_la_salle', $effet) && ! is_bool($effet['toute_la_salle'])) {
                    $this->refuser("$position.toute_la_salle", self::TYPE_INVALIDE, 'true ou false est attendu');
                }
                break;
            case MotsClesQuete::DRAPEAU:
                if (array_key_exists('campagne', $effet) && !is_bool($effet['campagne'])) {
                    $this->refuser(self::chemin($position, 'campagne'), self::TYPE_INVALIDE, 'true ou false est attendu');
                }
                $nom = $this->nomDeDrapeau($effet, 'nom', $position);
                if ($nom !== null && ($effet['campagne'] ?? false) === true) {
                    $this->campagneDeLaQuete[$nom] = true; // lot K5h : lisible par les quêtes suivantes
                }

                return $nom;
            case MotsClesQuete::OUVRIR_PORTE:
                // `"sortie"` : la porte de sortie verrouillée (René, 2026-11-20) — seule une sortie qui porte un verrou.
                if (($effet['porte'] ?? null) === 'sortie') {
                    if (! is_array(data_get($quete, 'sortie.verrou'))) {
                        $this->refuser("$position.porte", self::VALEUR_INVALIDE, 'la sortie de cette quête n\'a pas de verrou à ouvrir');
                    }
                    break;
                }
                $this->indexDe($quete, 'portes', $effet['porte'] ?? null, "$position.porte");
                break;
            case MotsClesQuete::REVELER_PORTE:
                $index = $effet['porte'] ?? null;
                $this->indexDe($quete, 'portes', $index, "$position.porte");
                if (is_int($index) && is_array($quete['portes'][$index] ?? null) && ($quete['portes'][$index]['type'] ?? null) !== 'secrete') {
                    $this->refuser("$position.porte", self::VALEUR_INVALIDE, "la porte $index n'est pas secrète : il n'y a rien à révéler");
                }
                break;
            case MotsClesQuete::BLOC:
                $case = $this->cellule($effet, $position);
                if ($case !== null && $this->salleFermeeEn($case[0], $case[1])) {
                    $this->refuser($position, self::DANS_SALLE_FERMEE, "la case ({$case[0]}, {$case[1]}) est dans une salle fermée : c'est déjà de la roche");
                }
                break;
            case MotsClesQuete::EVEILLER:
                $liste = $this->enListe($effet['monstres'] ?? null, "$position.monstres");
                foreach ((array) $liste as $j => $index) {
                    $this->indexDe($quete, 'monstres', $index, "$position.monstres.$j");
                    if (is_int($index) && is_array($quete['monstres'][$index] ?? null) && ($quete['monstres'][$index]['inactif'] ?? false) !== true) {
                        $this->refuser("$position.monstres.$j", self::VALEUR_INVALIDE, "le monstre $index n'est pas posé `inactif` : il n'y a rien à éveiller");
                    }
                }
                if ($liste === []) {
                    $this->refuser("$position.monstres", self::VALEUR_INVALIDE, 'au moins un monstre à éveiller');
                }
                break;
            case MotsClesQuete::APPARITION:
                if (array_key_exists('nombre_d6', $effet) && ! is_bool($effet['nombre_d6'])) {
                    $this->refuser("$position.nombre_d6", self::TYPE_INVALIDE, 'true ou false est attendu');
                }
                $monstres = $this->enListeDe($effet, 'monstres', $position);
                if (array_key_exists('monstres', $effet) && is_array($effet['monstres']) && $monstres === []) {
                    $this->refuser("$position.monstres", self::VALEUR_INVALIDE, 'Une apparition sans monstre ne fait rien : il en faut au moins un.');
                }
                foreach ($monstres as $k => $pose) {
                    $ici = "$position.monstres.$k";
                    if (($pose = $this->enObjet($pose, $ici)) === null) {
                        continue;
                    }
                    $this->cles($pose, $ici, self::CLES_POSE, self::CLES_MONSTRE_OPTIONNELLES);
                    $this->slugCatalogue($pose, 'slug', $ici, 'monstres');
                    $this->caseDArrivee($pose, $ici, 'la figure');
                    $this->profilDeMonstre($pose, $ici);
                }
                break;
            case MotsClesQuete::TELEPORTER:
                $this->caseDArrivee($effet, $position, 'le héros');
                if (array_key_exists('destinations_d6', $effet)) {
                    $ici = "$position.destinations_d6";
                    $table = $this->enObjet($effet['destinations_d6'], $ici);
                    if ($table !== null) {
                        $attendues = array_map('strval', range(1, 6));
                        $this->cles($table, $ici, $attendues);
                        foreach ($attendues as $face) {
                            if (array_key_exists($face, $table) && ($dest = $this->enObjet($table[$face], "$ici.$face")) !== null) {
                                $this->cles($dest, "$ici.$face", self::CLES_CASE);
                                $this->caseDArrivee($dest, "$ici.$face", 'le héros');
                            }
                        }
                    }
                }
                break;
        }

        return null;
    }

    /**
     * La case où une figure PARAÎT (apparition, téléportation — lot K5f) : dans le plateau, hors salle fermée, et ni sur
     * un meuble ni sur un bloc — le moteur la poserait alors sur la case libre la plus proche, ailleurs que là où l'auteur
     * l'a dessinée. On le lui dit.
     */
    private function caseDArrivee(array $objet, string $ici, string $qui): void
    {
        $case = $this->cellule($objet, $ici);
        if ($case === null) {
            return;
        }
        if ($this->salleFermeeEn($case[0], $case[1])) {
            $this->refuser($ici, self::DANS_SALLE_FERMEE, "la case ({$case[0]}, {$case[1]}) est dans une salle fermée : $qui y serait dans la roche");

            return;
        }
        foreach ($this->occupees["{$case[0]},{$case[1]}"] ?? [] as $occupant) {
            if (in_array($occupant['genre'], ['meuble', 'bloc'], true)) {
                $this->refuser($ici, self::CASE_PARTAGEE,
                    "la case ({$case[0]}, {$case[1]}) est prise par {$occupant['nom']} : $qui ne peut pas y paraître");

                return;
            }
        }
    }

    /** Le nom d'un drapeau : lettres, chiffres, _ et -. Rend le nom, ou `null` (absent, ou refusé). */
    private function nomDeDrapeau(array $objet, string $cle, string $chemin): ?string
    {
        $valeur = $this->texte($objet, $cle, $chemin);
        if ($valeur === null) {
            return null;
        }
        if (!preg_match('/\A[A-Za-z0-9_-]{1,'.self::LONGUEUR_DRAPEAU.'}\z/', $valeur)) {
            $this->refuser(self::chemin($chemin, $cle), self::VALEUR_INVALIDE,
                '« '.$valeur.' » : lettres, chiffres, _ et - seulement, '.self::LONGUEUR_DRAPEAU.' caractères au plus');

            return null;
        }

        return $valeur;
    }

    /** Pose les salles ouvertes de la quête (`$this->ouvertes`) ; une salle inconnue est refusée en la nommant. */
    private function sallesOuvertes(array $quete, string $chemin): void
    {
        $this->ouvertes = [];
        $this->ouvertesFiables = false;
        if (!array_key_exists('salles_ouvertes', $quete) || !is_array($quete['salles_ouvertes'])
            || !array_is_list($quete['salles_ouvertes'])) {
            return; // absente ou illisible : déjà refusée (cle_manquante / type_invalide)
        }
        $this->ouvertesFiables = $this->sallesPosees;

        $connues = array_column($this->salles, 'id');
        foreach ($quete['salles_ouvertes'] as $i => $id) {
            $position = self::chemin($chemin, 'salles_ouvertes').".$i";
            if (!is_string($id)) {
                $this->refuser($position, self::TYPE_INVALIDE, 'un identifiant de salle est attendu');
            } elseif (!in_array($id, $connues, true)) {
                if ($this->sallesPosees) {
                    $this->refuser($position, self::SALLE_INCONNUE, "« $id » n'est pas une salle du plateau");
                }
            } else {
                $this->ouvertes[$id] = true;
            }
        }
    }

    /**
     * `portes` (`docs/contrat-api.md` « Géométrie »). Une porte se pose sur une case d'une salle ouverte, du côté
     * `cote` : c'est le BORD COMMUN de cette case et de sa voisine (murs en arêtes, 2026-11-14).
     */
    private function portes(array $quete, string $chemin): void
    {
        // PORTES À TÉLÉPORTATION (*Castle of Mystery*, René 2026-11-20) : une porte ne s'y ouvre jamais — l'essayer téléporte —,
        // elle peut donc donner sur de la roche, comme au livret.
        $this->portesTeleportent = is_array(data_get($quete, 'regles.portes_teleportation'));
        foreach ($this->enListeDe($quete, 'portes', $chemin) as $i => $valeur) {
            $position = self::chemin($chemin, 'portes').".$i";
            $porte = $this->enObjet($valeur, $position);
            if ($porte === null) {
                continue;
            }
            $this->cles($porte, $position, self::CLES_PORTE, self::CLES_PORTE_OPTIONNELLES);

            $cote = $this->dans($porte, 'cote', $position, self::COTES);
            $this->dans($porte, 'type', $position, self::TYPES_PORTE);
            if (array_key_exists('verrou', $porte)) {
                $this->verrou($porte, "$position.verrou");
            }
            // ÉTAT DE DÉPART (lot E1) : `ouverte` (« the highlighted doors are open », F9907 q. 3 ; F6646 ; FL) — une porte
            // normale sans verrou ; `revelee` (« the secret doors are already discovered », FL q. 9) — une porte secrète.
            // `cachee` (lot E2, F5815 q. 5 C) : une porte secrète que seule une note révèle — jamais déjà découverte.
            if (($porte['cachee'] ?? false) === true && ($porte['revelee'] ?? false) === true) {
                $this->refuser("$position.cachee", self::VALEUR_INVALIDE, 'une porte cachée ne peut pas être déjà découverte');
            }
            foreach (['ouverte' => 'normale', 'revelee' => 'secrete', 'cachee' => 'secrete'] as $cle => $typeAttendu) {
                if (! array_key_exists($cle, $porte)) {
                    continue;
                }
                if ($porte[$cle] !== true) {
                    $this->refuser("$position.$cle", self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
                } elseif (($porte['type'] ?? null) !== $typeAttendu) {
                    $this->refuser("$position.$cle", self::VALEUR_INVALIDE, $cle === 'ouverte'
                        ? 'seule une porte normale peut être ouverte au départ' : 'seule une porte secrète peut être '.($cle === 'cachee' ? 'cachée' : 'déjà découverte'));
                } elseif ($cle === 'ouverte' && array_key_exists('verrou', $porte)) {
                    $this->refuser("$position.ouverte", self::VALEUR_INVALIDE, 'une porte ouverte au départ ne porte pas de verrou');
                }
            }
            // PORTES D'UN LIVRET (lot E2, F6646 q. 3 et q. 8 F) : sur une porte normale, sans verrou ni autre état de départ.
            foreach (['frapper', 'assiegee'] as $cleSpeciale) {
                if (! array_key_exists($cleSpeciale, $porte)) {
                    continue;
                }
                if (($porte['type'] ?? null) !== 'normale' || array_key_exists('verrou', $porte) || array_key_exists('ouverte', $porte)) {
                    $this->refuser("$position.$cleSpeciale", self::VALEUR_INVALIDE, 'seule une porte normale, sans verrou et close au départ, peut porter cette règle');
                }
            }
            if (array_key_exists('frapper', $porte) && $porte['frapper'] !== true) {
                $this->refuser("$position.frapper", self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
            }
            if (array_key_exists('frapper', $porte) && array_key_exists('assiegee', $porte)) {
                $this->refuser("$position.assiegee", self::VALEUR_INVALIDE, 'une porte qu\'on frappe n\'est pas assiégée');
            }
            if (array_key_exists('assiegee', $porte)) {
                $ici = "$position.assiegee";
                $siege = $this->enObjet($porte['assiegee'], $ici);
                if ($siege !== null) {
                    $this->cles($siege, $ici, ['pv', 'defense'], ['drapeau']);
                    $this->entier($siege, 'pv', "$ici.pv", min: 1);
                    $this->entier($siege, 'defense', "$ici.defense", min: 0);
                    if (array_key_exists('drapeau', $siege) && (! is_string($siege['drapeau']) || $siege['drapeau'] === '')) {
                        $this->refuser("$ici.drapeau", self::TYPE_INVALIDE, 'un nom de drapeau est attendu');
                    }
                }
            }
            $cellule = $this->cellule($porte, $position);
            if ($cellule !== null && $cote !== null) {
                $this->porteSurUnBord($cellule, $cote, $position);
            }
        }
    }

    /** Le verrou d'une porte (lot K5a) : un type de `VERROUS_DE_PORTE`, et les seules clés que ce type lit. */
    private function verrou(array $porte, string $chemin): void
    {
        $verrou = $this->enObjet($porte['verrou'], $chemin);
        if ($verrou === null) {
            return;
        }
        if (($porte['type'] ?? null) === 'secrete') {
            $this->refuser($chemin, self::VERROU_SUR_PORTE_SECRETE,
                'une porte secrète ne porte pas de verrou : on la trouve, puis elle s\'ouvre');

            return;
        }
        if (!array_key_exists('type', $verrou)) {
            $this->refuser("$chemin.type", self::CLE_MANQUANTE, 'clé obligatoire absente : « type »');

            return;
        }
        $type = $this->dans($verrou, 'type', $chemin, self::VERROUS_DE_PORTE);
        switch ($type) {
            case MoteurPortes::VERROU_CLE:
                $this->cles($verrou, $chemin, ['type', 'objet']);
                $this->slugCatalogue($verrou, 'objet', $chemin, 'objets');
                break;
            case MoteurPortes::VERROU_CONDITION:
                $this->cles($verrou, $chemin, ['type', 'condition']);
                $this->dans($verrou, 'condition', $chemin, array_keys(MoteurPortes::CONDITIONS));
                break;
            case MoteurPortes::VERROU_NOTE:
            case MoteurPortes::VERROU_PIERRE:
                $this->cles($verrou, $chemin, ['type']);
                break;
        }
    }

    /**
     * La case (x, y) est dans une salle OUVERTE. Sa VOISINE du côté `cote`, à un pas, ne peut pas être dans la même
     * salle ni hors du plateau (`porte_sans_bord`) ; elle doit être du COULOIR ou une case d'une autre salle ouverte,
     * sinon la porte donne dans la roche (`porte_dans_la_roche`). Deux portes sur un même bord : `case_partagee`.
     */
    private function porteSurUnBord(array $cellule, string $cote, string $position): void
    {
        if (!$this->ouvertesFiables) {
            return;
        }
        [$x, $y] = $cellule;
        $salle = $this->salleEn($x, $y);
        if ($salle === null || !isset($this->ouvertes[$salle])) {
            $this->refuser($position, self::PORTE_SANS_BORD, "la porte doit être sur une case d'une salle ouverte");

            return;
        }

        // MURS EN ARÊTES : la porte est le BORD de la case (x, y) du côté `cote`. La case VOISINE, à un pas, est ce sur
        // quoi elle donne : un couloir, ou une case d'une autre salle ouverte quand les deux salles se touchent.
        [$dx, $dy] = self::DECALAGES[$cote];
        [$ox, $oy] = [$x + $dx, $y + $dy];
        if (!$this->dansPlateau($ox, $oy)) {
            $this->refuser($position, self::PORTE_SANS_BORD, "le côté « $cote » donne hors du plateau");

            return;
        }
        $voisine = $this->salleEn($ox, $oy);
        if ($voisine === $salle) {
            $this->refuser($position, self::PORTE_SANS_BORD, "le côté « $cote » reste dans la même salle");

            return;
        }
        if ($voisine !== null && !isset($this->ouvertes[$voisine]) && ! $this->portesTeleportent) {
            $this->refuser($position, self::PORTE_DANS_LA_ROCHE,
                "le côté « $cote » donne sur de la roche : la salle « $voisine » est fermée dans cette quête");

            return;
        }

        $bord = self::cleBord($x, $y, $cote);
        if (isset($this->embrasures[$bord])) {
            $this->refuser($position, self::CASE_PARTAGEE, "une autre porte occupe déjà ce bord de la case ($x, $y)");
        }
        $this->embrasures[$bord] = true;
    }

    /** La clé d'un BORD de case — le même pour les deux cases qui le partagent. */
    private static function cleBord(int $x, int $y, string $cote): string
    {
        [$dx, $dy] = self::DECALAGES[$cote];
        $a = "$x,$y";
        $b = ($x + $dx).','.($y + $dy);

        return $a <= $b ? "$a|$b" : "$b|$a";
    }

    /**
     * `blocs` : cases de COULOIR, c'est-à-dire hors de toute salle (ouverte ou non). Un bloc ne partage sa case avec
     * rien.
     */
    private function blocs(array $quete, string $chemin): void
    {
        foreach ($this->enListeDe($quete, 'blocs', $chemin) as $i => $valeur) {
            $position = self::chemin($chemin, 'blocs').".$i";
            $bloc = $this->enObjet($valeur, $position);
            if ($bloc === null) {
                continue;
            }
            $this->cles($bloc, $position, self::CLES_CASE);

            $cellule = $this->cellule($bloc, $position);
            if ($cellule === null) {
                continue;
            }
            [$x, $y] = $cellule;
            if ($this->salleEn($x, $y) !== null) {
                $this->refuser($position, self::BLOC_HORS_COULOIR,
                    'un bloc se pose sur une case de couloir, pas dans une salle');
            } else {
                $this->occuper([[$x, $y]], 'bloc', $position, 'le bloc');
            }
        }
    }

    /**
     * `mobilier`, `monstres`, `pieges`, `terrain` : un slug de catalogue (`catalogue_inconnu`), une case hors salle fermée
     * (`dans_salle_fermee`), l'emprise d'un meuble entière dans sa salle (`mobilier_hors_salle`), et un partage de
     * case (`case_partagee`). Seul un élément VALIDE occupe sa case.
     */
    private function poses(array $quete, string $chemin, string $cle, string $couche, bool $tourne = false): void
    {
        foreach ($this->enListeDe($quete, $cle, $chemin) as $i => $valeur) {
            $position = self::chemin($chemin, $cle).".$i";
            $pose = $this->enObjet($valeur, $position);
            if ($pose === null) {
                continue;
            }
            $this->cles($pose, $position, $tourne ? self::CLES_MOBILIER : self::CLES_POSE,
                $couche === 'pieges' ? [...self::CLES_PIEGE_OPTIONNELLES, 'zone'] : ($couche === 'monstres' ? self::CLES_MONSTRE_OPTIONNELLES : ($couche === 'terrain' ? self::CLES_TERRAIN_OPTIONNELLES : [])));
            if ($couche === 'monstres') {
                $this->profilDeMonstre($pose, $position);
            }
            // E5 (2026-11-18) : `cachee: true` — la tuile n'est posée qu'au premier pas d'un héros (*Slippery Ice*, *Ice Slide*).
            if ($couche === 'terrain' && array_key_exists('cachee', $pose) && $pose['cachee'] !== true) {
                $this->refuser(self::chemin($position, 'cachee'), self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
            }
            if ($couche === 'terrain' && array_key_exists('paire_id', $pose)) {
                $this->identifiant($pose, 'paire_id', $position);
            }
            // Lot E2 : la case d'arrivée d'une GLISSIÈRE (une tuile `sens_unique`), « the last square of the slide ».
            if ($couche === 'terrain' && array_key_exists('sortie', $pose)) {
                $ici = self::chemin($position, 'sortie');
                $tuile = is_string($pose['slug'] ?? null) ? ($this->catalogue->index('terrain')[$pose['slug']] ?? null) : null;
                if ($tuile !== null && data_get($tuile->effet, 'sens_unique') !== true) {
                    $this->refuser($ici, self::VALEUR_INVALIDE, 'seule une glissière (tuile à sens unique) a une case d\'arrivée');
                } elseif (($sortie = $this->enObjet($pose['sortie'], $ici)) !== null) {
                    $this->cles($sortie, $ici, self::CLES_CASE);
                    $this->cellule($sortie, $ici);
                }
            }
            foreach ($couche === 'pieges' ? self::CLES_PIEGE_OPTIONNELLES : [] as $drapeau) {
                if (array_key_exists($drapeau, $pose) && !is_bool($pose[$drapeau])) {
                    $this->refuser(self::chemin($position, $drapeau), self::TYPE_INVALIDE, 'true ou false est attendu');
                }
            }

            $slug = $this->slugCatalogue($pose, 'slug', $position, $couche);
            if ($slug === null) {
                continue; // refusé en le nommant : un slug inconnu n'occupe rien, donc aucun refus en cascade
            }
            // Lot E2 : la ZONE d'une lame balançoire (F9528 p. 4 : « slicing any heroes on one of the squares marked with a white
            // or red blade symbol ») — exigée pour un piège à zone (`effet.zone_lames`), refusée pour les autres.
            if ($couche === 'pieges') {
                $aZone = data_get($this->catalogue->index('pieges')[$slug]->effet, 'zone_lames') !== null;
                if ($aZone && ! array_key_exists('zone', $pose)) {
                    $this->refuser(self::chemin($position, 'zone'), self::VALEUR_INVALIDE, 'une lame balançoire a sa zone : les cases qu\'elle balaie');
                } elseif (! $aZone && array_key_exists('zone', $pose)) {
                    $this->refuser(self::chemin($position, 'zone'), self::VALEUR_INVALIDE, 'seule une lame balançoire a une zone');
                } elseif ($aZone) {
                    $liste = $this->enListe($pose['zone'], self::chemin($position, 'zone'));
                    foreach ((array) $liste as $k => $c) {
                        if (($case = $this->enObjet($c, self::chemin($position, "zone.$k"))) !== null) {
                            $this->cles($case, self::chemin($position, "zone.$k"), self::CLES_CASE);
                            $this->cellule($case, self::chemin($position, "zone.$k"));
                        }
                    }
                    if ($liste === []) {
                        $this->refuser(self::chemin($position, 'zone'), self::VALEUR_INVALIDE, 'au moins une case');
                    }
                }
            }
            // Lot E2 : `gouffre` — seulement sur une fosse qu'on saute (`effet.franchissable`), « jumped over as a pit ».
            if ($couche === 'pieges' && ($pose['gouffre'] ?? false) === true
                && data_get($this->catalogue->index('pieges')[$slug]->effet, 'franchissable') === null) {
                $this->refuser(self::chemin($position, 'gouffre'), self::VALEUR_INVALIDE, 'seule une fosse qu\'on saute peut être un gouffre sans fond');
            }
            $rotation = $tourne ? $this->dans($pose, 'rotation', $position, self::ROTATIONS) : null;

            $cellule = $this->cellule($pose, $position);
            if ($cellule === null) {
                continue;
            }
            [$x, $y] = $cellule;
            $nom = "« $slug »";

            if ($this->salleFermeeEn($x, $y)) {
                $this->refuser($position, self::DANS_SALLE_FERMEE, "$nom tombe dans une salle fermée");
                continue;
            }

            if ($tourne) {
                if ($rotation === null) {
                    continue;
                }
                $this->meuble($slug, $rotation, $x, $y, $position, $nom);
                continue;
            }
            $this->occuper([[$x, $y]], $couche === 'monstres' ? 'monstre' : ($couche === 'pieges' ? 'piege' : 'terrain'),
                $position, $nom);
        }
    }

    /**
     * Lot K5m (R9) : surcharges optionnelles d'un monstre posé ou apparu (`nom`, `deplacement`, `attaque`, `defense`,
     * `pv_body`, `pv_mind`, `vulnerable_uniquement_a`, `fuite`).
     */
    private function profilDeMonstre(array $pose, string $position): void
    {
        $this->texte($pose, 'nom', $position, nonVide: true, max: self::LONGUEUR_SLUG);
        $this->entier($pose, 'deplacement', $position, min: 0);
        $this->entier($pose, 'attaque', $position, min: 0);
        $this->entier($pose, 'defense', $position, min: 1);
        $this->entier($pose, 'pv_body', $position, min: 1);
        $this->entier($pose, 'pv_mind', $position, min: 0);

        foreach (['vulnerable_uniquement_a', 'invulnerable_casse_arme'] as $cleListe) {
            if (array_key_exists($cleListe, $pose)) {
                $ici = self::chemin($position, $cleListe);
                $sources = $this->enListe($pose[$cleListe], $ici);
                if ($sources !== null) {
                    // `vulnerable_uniquement_a` : une arme OU un sort (René, 2026-11-20) ; `invulnerable_casse_arme` : des armes.
                    $avecSorts = $cleListe === 'vulnerable_uniquement_a';
                    foreach ($sources as $i => $slug) {
                        $cheminSource = "$ici.$i";
                        if (! is_string($slug)) {
                            $this->refuser($cheminSource, self::TYPE_INVALIDE, 'un slug d\'objet est attendu');
                        } elseif ($avecSorts && $this->catalogue->contient('objets', $slug) && $this->catalogue->contient('sorts', $slug)) {
                            $this->refuser($cheminSource, self::VALEUR_INVALIDE, "« $slug » désigne à la fois un objet et un sort");
                        } elseif (! $this->catalogue->contient('objets', $slug) && ! ($avecSorts && $this->catalogue->contient('sorts', $slug))) {
                            $this->refuser($cheminSource, self::CATALOGUE_INCONNU, $avecSorts
                                ? "« $slug » n'existe ni dans le catalogue « objets » ni dans « sorts »"
                                : "« $slug » n'existe pas dans le catalogue « objets »");
                        }
                    }
                }
            }
        }

        // SORTS DE DREAD de l'auteur (lot E1) : des slugs du catalogue `sorts_dread`, sans doublon.
        if (array_key_exists('sorts_dread', $pose)) {
            $ici = self::chemin($position, 'sorts_dread');
            $sorts = $this->enListe($pose['sorts_dread'], $ici);
            foreach ((array) $sorts as $i => $slug) {
                if (! is_string($slug)) {
                    $this->refuser("$ici.$i", self::TYPE_INVALIDE, 'un slug de sort de Dread est attendu');
                } elseif (! $this->catalogue->contient('sorts_dread', $slug)) {
                    $this->refuser("$ici.$i", self::CATALOGUE_INCONNU, "« $slug » n'existe pas dans le catalogue « sorts_dread »");
                }
            }
            if (is_array($sorts) && count($sorts) !== count(array_unique(array_filter($sorts, 'is_string')))) {
                $this->refuser($ici, self::VALEUR_INVALIDE, 'un même sort est nommé deux fois');
            }
        }
        // CAPACITÉS de l'auteur (lot E1) : la liste fermée `InstanceMonstre::CAPACITES_DE_PROFIL`.
        if (array_key_exists('capacites', $pose)) {
            $ici = self::chemin($position, 'capacites');
            foreach ((array) $this->enListe($pose['capacites'], $ici) as $i => $nom) {
                if (! is_string($nom) || ! isset(\App\Models\InstanceMonstre::CAPACITES_DE_PROFIL[$nom])) {
                    $this->refuser("$ici.$i", self::VALEUR_INVALIDE, 'capacité inconnue — permises : '
                        .implode(', ', array_keys(\App\Models\InstanceMonstre::CAPACITES_DE_PROFIL)));
                }
            }
        }

        // REDDITION (lot E2) : `se_rend {objet?}` — attaquée au contact, la figure se rend, s'enfuit et laisse l'objet.
        if (array_key_exists('se_rend', $pose)) {
            $ici = self::chemin($position, 'se_rend');
            $rend = $this->enObjet($pose['se_rend'], $ici);
            if ($rend !== null) {
                $this->cles($rend, $ici, [], ['objet']);
                if (array_key_exists('objet', $rend)) {
                    $this->slugCatalogue($rend, 'objet', $ici, 'objets');
                }
            }
        }

        // INACTIF (René, 2026-11-20) : ni ne bouge, ni n'attaque, ni ne peut être blessée, jusqu'à l'effet de note `eveiller`.
        if (array_key_exists('inactif', $pose) && $pose['inactif'] !== true) {
            $this->refuser(self::chemin($position, 'inactif'), self::TYPE_INVALIDE, 'true est attendu (absente, la figure est active)');
        }

        if (array_key_exists('fuite', $pose)) {
            $ici = self::chemin($position, 'fuite');
            $fuite = $this->enObjet($pose['fuite'], $ici);
            if ($fuite !== null) {
                $this->cles($fuite, $ici, self::CLES_CASE);
                $this->caseDArrivee($fuite, $ici, 'le refuge de fuite');
            }
        }
    }

    /**
     * L'emprise d'un meuble (lot K2) : `l`×`h` du catalogue pour une rotation 0 ou 180, `h`×`l` pour 90 ou 270. Toute
     * l'emprise doit tenir dans l'intérieur de la salle OUVERTE de l'ancre (`mobilier_hors_salle`). Seule la vérification
     * « dans une salle ouverte » dépend des salles ouvertes : sans elles, l'emprise est seulement enregistrée.
     */
    private function meuble(string $slug, int $rotation, int $x, int $y, string $position, string $nom): void
    {
        $type = $this->catalogue->index('mobilier')[$slug];
        [$l, $h] = self::empriseMeuble((int) $type->largeur, (int) $type->hauteur, $rotation);
        $cellules = Grille::cellulesEmprise($x, $y, $l, $h);

        if ($this->ouvertesFiables) {
            $salleAncre = $this->salleEn($x, $y);
            // UN MEUBLE DANS UN COULOIR (René, 2026-11-20 : « il faut pouvoir mettre des meubles dans les corridors » — le
            // coffre du couloir sud, *Witch Lord* q. 2 ; le réduit secret, *Kellar's Keep* q. 4) : toute son emprise est
            // alors du couloir, dans le plateau ; un bloc la refuse (`case_partagee`, plus bas).
            if ($salleAncre === null) {
                foreach ($cellules as $c) {
                    $dedans = $this->dimensions === null || ($c['x'] >= 0 && $c['y'] >= 0
                        && $c['x'] < $this->dimensions[0] && $c['y'] < $this->dimensions[1]);
                    if (! $dedans || $this->salleEn($c['x'], $c['y']) !== null) {
                        $this->refuser($position, self::MOBILIER_HORS_SALLE,
                            "l'emprise de $nom ({$l}×{$h}, rotation $rotation), posée dans un couloir, doit y rester entière");

                        return;
                    }
                }
                $this->occuper(array_map(fn (array $c): array => [$c['x'], $c['y']], $cellules), 'meuble', $position, $nom);

                return;
            }
            foreach ($cellules as $c) {
                if ($this->salleEn($c['x'], $c['y']) !== $salleAncre) {
                    $this->refuser($position, self::MOBILIER_HORS_SALLE,
                        "l'emprise de $nom ({$l}×{$h}, rotation $rotation) sort de l'intérieur de sa salle");

                    return;
                }
            }
        }

        $this->occuper(array_map(fn (array $c): array => [$c['x'], $c['y']], $cellules), 'meuble', $position, $nom);
    }

    /**
     * `depart` (lot K2) : le coin haut-gauche d'un escalier 2×2, ENTIÈREMENT dans l'intérieur d'UNE salle ouverte. Dans une
     * salle fermée : `depart_invalide` (K1). Ailleurs, couloir compris : `depart_hors_salle`.
     */
    private function depart(array $quete, string $chemin): void
    {
        if (!array_key_exists('depart', $quete)) {
            return;
        }
        $this->acces($quete['depart'], self::chemin($chemin, 'depart'), 'départ');
    }

    /**
     * `sortie` : par où l'on quitte le donjon. `null` = par où l'on est entré. Sinon un accès comme le départ — un
     * escalier, ou une porte de sortie (René, 2026-11-14 : certaines campagnes entrent et sortent par des portes).
     */
    private function sortie(array $quete, string $chemin): void
    {
        if (!array_key_exists('sortie', $quete) || $quete['sortie'] === null) {
            return;
        }
        $sortie = $quete['sortie'];
        // LE VERROU DE LA SORTIE (René, 2026-11-20 : « des conditions pour la porte de sortie comme les portes barrées ») :
        // le vocabulaire des portes (`verrou()`), sur une PORTE de sortie seulement — un escalier ne se ferme pas.
        if (is_array($sortie) && array_key_exists('verrou', $sortie)) {
            $ici = self::chemin($chemin, 'sortie').'.verrou';
            if (($sortie['type'] ?? self::ACCES_ESCALIER) !== self::ACCES_PORTE) {
                $this->refuser($ici, self::VALEUR_INVALIDE, 'seule une porte de sortie se verrouille, pas un escalier');
            } else {
                // La forme d'une porte ordinaire, que `verrou()` sait lire (une sortie n'est jamais secrète).
                $this->verrou(['verrou' => $sortie['verrou']], $ici);
            }
            unset($sortie['verrou']);
        }
        // SORTIE CACHÉE (lot E1) : « This exit door only appears once a hero has found the Elixir of Life » (G0053 q. 10-13) —
        // le nom du drapeau qui la fait paraître (posé par une note).
        if (is_array($sortie) && array_key_exists('cachee_jusqua', $sortie)) {
            $this->nomDeDrapeau($sortie, 'cachee_jusqua', self::chemin($chemin, 'sortie'));
            unset($sortie['cachee_jusqua']);
        }
        $this->acces($sortie, self::chemin($chemin, 'sortie'), 'sortie');
    }

    /**
     * `fausses_sorties` (facultative, René 2026-11-16 : « une quête avait 2 portes de sortie mais l'une était une
     * fausse ») : des portes `{x, y, cote}` posées exactement comme une porte de sortie — sur le mur d'une salle
     * ouverte — mais qui ne s'ouvrent pas (livret de base : les fausses portes « cannot be opened at all »). Mêmes
     * contrôles qu'une porte de sortie : `acces()`, rôle « fausse sortie ». Elles ne partagent ni la case ni le bord
     * d'un autre accès.
     */
    private function faussesSorties(array $quete, string $chemin): void
    {
        foreach ($this->enListeDe($quete, 'fausses_sorties', $chemin) as $i => $valeur) {
            $position = self::chemin($chemin, 'fausses_sorties').".$i";
            $porte = $this->enObjet($valeur, $position);
            if ($porte === null) {
                continue;
            }
            $this->cles($porte, $position, ['x', 'y', 'cote']);
            if (array_key_exists('x', $porte) && array_key_exists('y', $porte) && array_key_exists('cote', $porte)) {
                $this->acces(['type' => self::ACCES_PORTE] + $porte, $position, 'fausse sortie');
            }
        }
    }

    /**
     * UN ACCÈS — l'entrée ou la sortie. Deux formes, selon `type` (absent = `escalier`, la forme d'origine du départ) :
     *
     *  - `escalier` `{x, y}` : le coin haut-gauche d'un bloc 2×2, entier dans l'intérieur d'UNE salle ouverte ;
     *  - `porte` `{type, x, y, cote}` : une porte sur le mur d'une salle ouverte, côté `cote` de la case d'intérieur
     *    (x, y) — ou sur une case de COULOIR, contre le bord du plateau, de la roche ou un bloc (René, 2026-11-16 :
     *    sortie d'abord, puis entrée). Elle mène HORS du donjon : ce qu'il y a derrière ne compte pas, et elle ne s'ouvre jamais comme une
     *    porte ordinaire (elle n'entre pas dans `portes`). Le héros entre et sort depuis la case (x, y).
     *
     * Les cases d'un accès ne portent ni meuble ni monstre, et deux accès ne se recouvrent pas.
     */
    private function acces(mixed $valeur, string $position, string $role): void
    {
        $acces = $this->enObjet($valeur, $position);
        if ($acces === null) {
            return;
        }

        $type = array_key_exists('type', $acces) ? $acces['type'] : self::ACCES_ESCALIER;
        if (!in_array($type, self::TYPES_ACCES, true)) {
            $this->refuser(self::chemin($position, 'type'), self::VALEUR_INVALIDE,
                'le type d\'un accès est « escalier » ou « porte »');

            return;
        }

        $attendues = $type === self::ACCES_PORTE ? ['type', 'x', 'y', 'cote'] : (array_key_exists('type', $acces) ? ['type', 'x', 'y'] : self::CLES_CASE);
        $this->cles($acces, $position, $attendues);

        $cellule = $this->cellule($acces, $position);
        if ($cellule === null) {
            return;
        }
        [$x, $y] = $cellule;
        if ($this->salleFermeeEn($x, $y)) {
            $this->refuser($position, self::DEPART_INVALIDE, "$role : la case tombe dans une salle fermée");

            return;
        }
        if (!$this->ouvertesFiables) {
            return;
        }

        $salle = $this->salleEn($x, $y);
        $ouverte = $salle !== null && isset($this->ouvertes[$salle]);

        if ($type === self::ACCES_PORTE) {
            $cote = $this->dans($acces, 'cote', $position, self::COTES);
            if ($cote === null) {
                return;
            }
            [$dx, $dy] = self::DECALAGES[$cote];
            // UNE PORTE D'ACCÈS MÈNE HORS DU PLATEAU (*Kellar's Keep*, F4543 : la porte de fer et la porte de bois sont
            // « on the edge of the gameboard »). Où qu'elle soit posée — case de salle ouverte ou de couloir (René,
            // 2026-11-16 : « la porte de sortie peut aussi être dans un corridor », puis « la porte d'entrée peut aussi
            // être sur un mur d'un corridor ») —, son côté donne sur ce qui n'est pas le donjon : le bord du plateau, de
            // la roche (une salle fermée) ou un bloc. Un côté qui donne sur du sol — un couloir, une autre salle ouverte —
            // n'est pas un bord du plateau : la porte s'ouvrirait sur le donjon lui-même. Entrée en couloir : les héros
            // s'installent dans ce couloir (`CarteConstruite::spawnsCouloir()`).
            if ($salle !== null && !$ouverte) {
                $this->refuser($position, self::DEPART_HORS_SALLE, "$role : la porte doit être sur une case d'une salle ouverte");

                return;
            }
            $nx = $x + $dx;
            $ny = $y + $dy;
            if ($salle !== null && $this->salleEn($nx, $ny) === $salle) {
                $this->refuser($position, self::PORTE_SANS_BORD, "$role : le côté « $cote » reste dans la même salle");

                return;
            }
            $dehors = !$this->dansPlateau($nx, $ny) || $this->salleFermeeEn($nx, $ny)
                || collect($this->occupees["$nx,$ny"] ?? [])->contains(fn (array $o) => $o['genre'] === 'bloc');
            if (!$dehors) {
                $this->refuser($position, self::PORTE_SANS_BORD,
                    "$role : une porte d'accès mène hors du plateau — elle se pose contre le bord du plateau, de la roche ou un bloc ; le côté « $cote » donne sur du sol");

                return;
            }
            $bord = self::cleBord($x, $y, $cote);
            if (isset($this->embrasures[$bord])) {
                $this->refuser($position, self::CASE_PARTAGEE, "$role : une autre porte occupe déjà ce bord de la case ($x, $y)");

                return;
            }
            $this->embrasures[$bord] = true;
            $this->occuper([[$x, $y]], 'escalier', $position, "la case de la porte de $role");

            return;
        }

        $escalier = [[$x, $y], [$x + 1, $y], [$x, $y + 1], [$x + 1, $y + 1]];
        $tient = $ouverte;
        foreach ($escalier as [$cx, $cy]) {
            $tient = $tient && $this->salleEn($cx, $cy) === $salle;
        }
        if (!$tient) {
            $this->refuser($position, self::DEPART_HORS_SALLE,
                "$role : le bloc 2×2 de l'escalier doit tenir entier dans une salle ouverte");

            return;
        }
        $this->occuper($escalier, 'escalier', $position, "l'escalier de $role");
    }

    /**
     * Enregistre les cases d'un élément VALIDE. Un élément qui partage une case avec un élément déjà posé d'un genre
     * interdit (`PARTAGES_INTERDITS`, ou un bloc) est refusé (`case_partagee`) et n'occupe rien : pas de refus en cascade.
     *
     * @param  list<array{0: int, 1: int}>  $cellules
     */
    private function occuper(array $cellules, string $genre, string $position, string $nom): void
    {
        foreach ($cellules as [$x, $y]) {
            foreach ($this->occupees["$x,$y"] ?? [] as $autre) {
                if (self::partageInterdit($genre, $autre['genre'])) {
                    $this->refuser($position, self::CASE_PARTAGEE,
                        "$nom partage la case ($x, $y) avec {$autre['nom']}");

                    return;
                }
            }
        }
        foreach ($cellules as [$x, $y]) {
            $this->occupees["$x,$y"][] = ['genre' => $genre, 'nom' => $nom];
        }
    }

    private static function partageInterdit(string $a, string $b): bool
    {
        if ($a === 'bloc' || $b === 'bloc') {
            return true;
        }
        foreach (self::PARTAGES_INTERDITS as [$x, $y]) {
            if (($a === $x && $b === $y) || ($a === $y && $b === $x)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Largeur et hauteur de l'emprise d'un meuble (lot K2) : `largeur`×`hauteur` pour une rotation 0 ou 180, échangées
     * pour 90 ou 270. Une seule règle, lue par la validation ET par `CarteConstruite`.
     *
     * @return array{0: int, 1: int}
     */
    public static function empriseMeuble(int $largeur, int $hauteur, int $rotation): array
    {
        return in_array($rotation, [90, 270], true) ? [$hauteur, $largeur] : [$largeur, $hauteur];
    }

    /** `recompense` : `null` (aucune), ou `{or, partage}`. */
    /**
     * LA CONDITION DE FIN — `fin: {type: "vaincre", cibles: [index…], libelle}`. Obligatoire : une quête qui ne peut pas
     * se gagner n'est pas jouable. `cibles` désigne des monstres POSÉS de cette quête, par leur index dans `monstres` ;
     * il en faut au moins une, sans doublon. `libelle` est la consigne dite aux joueurs, écrite par l'auteur : le moteur
     * ne nomme jamais lui-même une cible, ce serait révéler une figure que le groupe n'a pas vue.
     */
    /** @param  array<string, true>  $drapeauxPoses  les drapeaux qu'une note de la quête pose ({@see notes()}) */
    private function fin(array $quete, string $chemin, array $drapeauxPoses = []): void
    {
        if (!array_key_exists('fin', $quete)) {
            return; // déjà refusé par `cles()` : `cle_manquante`
        }
        $position = self::chemin($chemin, 'fin');
        $fin = $this->enObjet($quete['fin'], $position);
        if ($fin === null) {
            return;
        }
        // Les clés dépendent du type : « vaincre » désigne des cibles, « drapeau » un drapeau qu'une note pose.
        $type = is_string($fin['type'] ?? null) && isset(self::CLES_FIN[$fin['type']]) ? $fin['type'] : 'vaincre';
        $this->cles($fin, $position, self::CLES_FIN[$type]);

        $this->dans($fin, 'type', $position, self::TYPES_FIN);
        $this->texte($fin, 'libelle', $position, nonVide: true, max: self::LONGUEUR_LIBELLE_FIN);

        if ($type === 'sortir') {
            return; // la sortie (`sortie`, ou l'entrée quand elle vaut `null`) est déjà validée comme accès
        }

        // « ramener » (lot K5k) : la sortie comme « sortir », et une CHARGE à ramener.
        if ($type === 'ramener') {
            if (!array_key_exists('charge', $quete) && !array_key_exists('captif', $quete)) {
                $this->refuser(self::chemin($chemin, 'charge'), self::CLE_MANQUANTE, 'la fin « ramener » demande une charge (`charge`) ou un captif (`captif`) à ramener');
            }

            return;
        }

        if ($type === 'drapeau') {
            $drapeau = $this->nomDeDrapeau($fin, 'drapeau', $position);
            if ($drapeau !== null && !isset($drapeauxPoses[$drapeau])) {
                $this->refuser(self::chemin($position, 'drapeau'), self::DRAPEAU_JAMAIS_POSE,
                    "aucune note de cette quête ne pose le drapeau « $drapeau » : la quête ne pourrait pas se gagner");
            }

            return;
        }

        $nbMonstres = is_array($quete['monstres'] ?? null) ? count($quete['monstres']) : 0;
        $cibles = $this->enListeDe($fin, 'cibles', $position);
        $cheminCibles = self::chemin($position, 'cibles');

        if (array_key_exists('cibles', $fin) && is_array($fin['cibles']) && $cibles === []) {
            $this->refuser($cheminCibles, self::CIBLE_INCONNUE, 'Il faut au moins une cible : une quête sans cible à vaincre ne peut pas se gagner.');
        }

        $vues = [];
        foreach ($cibles as $i => $cible) {
            $ici = "$cheminCibles.$i";
            if (!is_int($cible)) {
                $this->refuser($ici, self::TYPE_INVALIDE, 'Une cible est l\'index (entier) d\'un monstre de `monstres`.');

                continue;
            }
            if ($cible < 0 || $cible >= $nbMonstres) {
                $this->refuser($ici, self::CIBLE_INCONNUE, "La cible $cible ne désigne aucun monstre posé : cette quête en pose $nbMonstres (index 0 à ".max(0, $nbMonstres - 1).').');
            } elseif (isset($vues[$cible])) {
                $this->refuser($ici, self::CIBLE_INCONNUE, "Le monstre $cible est déjà désigné comme cible.");
            }
            $vues[$cible] = true;
        }
    }

    /**
     * `regles` (facultative, lot S1) : `{solo: classe}` — une quête SOLO (*The Frozen Horror*, *The Mage of the Mirror*) :
     * seul le premier héros de cette classe, dans l'ordre du tour, entre ; les autres regardent
     * (`DemarreurQueteConstruite`, `quetes.participants`). La classe doit être EXIGÉE par la campagne
     * (`prerequis_heros`) : sans quoi un groupe sans elle atteindrait une quête qu'il ne peut pas jouer.
     */
    private function regles(array $quete, string $chemin, array $document): void
    {
        if (!array_key_exists('regles', $quete)) {
            return;
        }
        $position = self::chemin($chemin, 'regles');
        $regles = $this->enObjet($quete['regles'], $position);
        if ($regles === null) {
            return;
        }
        $this->cles($regles, $position, [], self::CLES_REGLES);

        if (array_key_exists('marche_ferme', $regles) && !is_bool($regles['marche_ferme'])) {
            $this->refuser(self::chemin($position, 'marche_ferme'), self::TYPE_INVALIDE, 'true ou false est attendu');
        }
        // Lot K6 (*Spirit Queen's Torment*) : `degats_en_mind` — `true` (tout dégât) ou une liste de slugs de monstres.
        if (array_key_exists('degats_en_mind', $regles)) {
            $ici = self::chemin($position, 'degats_en_mind');
            $valeur = $regles['degats_en_mind'];
            if ($valeur !== true && !is_array($valeur)) {
                $this->refuser($ici, self::TYPE_INVALIDE, 'true (tout dégât) ou une liste de monstres est attendu');
            } elseif (is_array($valeur)) {
                if ($valeur === [] || !array_is_list($valeur)) {
                    $this->refuser($ici, self::VALEUR_INVALIDE, 'au moins un monstre, en liste, est attendu');
                }
                foreach ($valeur as $k => $slug) {
                    if (!is_string($slug) || !$this->catalogue->contient('monstres', $slug)) {
                        $this->refuser("$ici.$k", self::CATALOGUE_INCONNU, '« '.(is_string($slug) ? $slug : '?').' » n\'existe pas dans le catalogue « monstres »');
                    }
                }
            }
        }
        // Lot K6 (*The Crypt of Perpetual Darkness*) : `obscurite_magique: {salle}` — une salle OUVERTE de la quête.
        if (array_key_exists('obscurite_magique', $regles)) {
            $ici = self::chemin($position, 'obscurite_magique');
            $obscurite = $this->enObjet($regles['obscurite_magique'], $ici);
            if ($obscurite !== null) {
                $this->cles($obscurite, $ici, ['salle']);
                if (array_key_exists('salle', $obscurite) && !in_array($obscurite['salle'], (array) ($quete['salles_ouvertes'] ?? []), true)) {
                    $this->refuser("$ici.salle", self::SALLE_INCONNUE, 'la salle obscure doit être une salle ouverte de la quête');
                }
            }
        }
        // Lot K6 (*Dread Moon*) : `deguisement` — présent seulement quand il vaut true.
        if (array_key_exists('deguisement', $regles) && $regles['deguisement'] !== true) {
            $this->refuser(self::chemin($position, 'deguisement'), self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
        }
        // Lot K6 (*Jungles of Delthrak*, « Order of Play ») : `ordre_libre` — présent seulement quand il vaut true.
        // Lot E2 : les salles de brouillard vivant (F5815 q. 7, 9) et à crevasse (q. 4, 6, 8) — des salles ouvertes de la quête.
        foreach (['brouillard_vivant', 'crevasse'] as $cleSalles) {
            if (! array_key_exists($cleSalles, $regles)) {
                continue;
            }
            foreach ((array) $this->enListe($regles[$cleSalles], self::chemin($position, $cleSalles)) as $k => $id) {
                if (! is_string($id) || ($this->ouvertesFiables && ! isset($this->ouvertes[$id]))) {
                    $this->refuser(self::chemin($position, $cleSalles).".$k", self::SALLE_INCONNUE, 'une salle ouverte de la quête est attendue');
                }
            }
        }
        // Lot E1 : la quête ne remet pas le Corps ni l'Esprit à neuf (F9907 q. 11-12, 13-14, 15-16 ; F6646 ; G0052).
        if (array_key_exists('sans_restauration', $regles) && $regles['sans_restauration'] !== true) {
            $this->refuser(self::chemin($position, 'sans_restauration'), self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
        }
        // Lot E2 : les richesses elfiques (F7539 p. 5) — l'or de la première fouille d'une salle à coffre que rien ne nomme.
        $this->entier($regles, 'richesses_des_coffres', self::chemin($position, 'richesses_des_coffres'), min: 1);
        // Lot E2 : les cases où l'on JETTE un objet (la Fosse de l'Effroi, F9528 q. 8) — des cases du plateau.
        if (array_key_exists('receptacle_objets', $regles)) {
            $ici = self::chemin($position, 'receptacle_objets');
            $liste = $this->enListe($regles['receptacle_objets'], $ici);
            foreach ((array) $liste as $k => $c) {
                if (($case = $this->enObjet($c, "$ici.$k")) !== null) {
                    $this->cles($case, "$ici.$k", self::CLES_CASE);
                    $this->cellule($case, "$ici.$k");
                }
            }
            if ($liste === []) {
                $this->refuser($ici, self::VALEUR_INVALIDE, 'au moins une case');
            }
        }
        // Lot E2 : les portes qu'on frappe (F6646 q. 3) — le seuil de succès et le drapeau posé à la bonne porte.
        if (array_key_exists('portes_a_frapper', $regles)) {
            $ici = self::chemin($position, 'portes_a_frapper');
            $frappe = $this->enObjet($regles['portes_a_frapper'], $ici);
            if ($frappe !== null) {
                $this->cles($frappe, $ici, [], ['succes', 'drapeau']);
                $this->entier($frappe, 'succes', "$ici.succes", min: 1);
                if (array_key_exists('drapeau', $frappe) && (! is_string($frappe['drapeau']) || $frappe['drapeau'] === '')) {
                    $this->refuser("$ici.drapeau", self::TYPE_INVALIDE, 'un nom de drapeau est attendu');
                }
            }
        }
        // Lot E2 : la planque (F6646 p. 7) et le marché souterrain en quête (F6646 p. 2, q. 2 G) — une salle ouverte.
        foreach (['planque', 'marche_en_quete'] as $cleSalle) {
            if (array_key_exists($cleSalle, $regles) && (! is_string($regles[$cleSalle])
                || ($this->ouvertesFiables && ! isset($this->ouvertes[$regles[$cleSalle]])))) {
                $this->refuser(self::chemin($position, $cleSalle), self::SALLE_INCONNUE, 'une salle ouverte de la quête est attendue');
            }
        }
        // Lot E2 : « monsters in this quest roll one additional Attack die » (F6646 q. 7, 9, 10).
        $this->entier($regles, 'bonus_attaque_monstres', self::chemin($position, 'bonus_attaque_monstres'), min: 1);
        // Lot E2 : le chemin imposé au porteur (G0052 q. 6) — un objet porté, des cases qui se suivent, des arrêts croissants.
        if (array_key_exists('chemin_impose', $regles)) {
            $ici = self::chemin($position, 'chemin_impose');
            $chemin = $this->enObjet($regles['chemin_impose'], $ici);
            if ($chemin !== null) {
                $this->cles($chemin, $ici, ['porteur', 'cases', 'arrets']);
                $this->slugCatalogue($chemin, 'porteur', $ici, 'objets');
                $cases = array_key_exists('cases', $chemin) ? $this->enListe($chemin['cases'], "$ici.cases") : null;
                $precedente = null;
                foreach ((array) $cases as $k => $c) {
                    if (($case = $this->enObjet($c, "$ici.cases.$k")) === null) {
                        continue;
                    }
                    $this->cles($case, "$ici.cases.$k", self::CLES_CASE);
                    $this->cellule($case, "$ici.cases.$k");
                    if ($precedente !== null && is_int($case['x'] ?? null) && is_int($case['y'] ?? null)
                        // Géométrique, sans carte : le chemin du livret franchit des portes (elles s'ouvrent à son passage).
                        && ! \App\Partie\Grille::sontAdjacentes($case['x'], $case['y'], (int) $precedente['x'], (int) $precedente['y'])) {
                        $this->refuser("$ici.cases.$k", self::VALEUR_INVALIDE, 'chaque case du chemin touche la précédente par un côté');
                    }
                    $precedente = $case;
                }
                if (is_array($cases) && count($cases) < 2) {
                    $this->refuser("$ici.cases", self::VALEUR_INVALIDE, 'au moins deux cases');
                }
                $arrets = array_key_exists('arrets', $chemin) ? $this->enListe($chemin['arrets'], "$ici.arrets") : null;
                $avant = -1;
                foreach ((array) $arrets as $k => $a) {
                    if (! is_int($a) || $a <= $avant || (is_array($cases) && $a >= count($cases))) {
                        $this->refuser("$ici.arrets.$k", self::VALEUR_INVALIDE, 'un rang de case du chemin, plus loin que l\'arrêt précédent');
                        break;
                    }
                    $avant = $a;
                }
                if ($arrets === []) {
                    $this->refuser("$ici.arrets", self::VALEUR_INVALIDE, 'au moins un arrêt (le premier tour)');
                }
            }
        }
        // Lot E2 : les salles que Zargon réveille à chaque tour (F9528 q. 10).
        if (array_key_exists('activation_salles', $regles) && $regles['activation_salles'] !== true) {
            $this->refuser(self::chemin($position, 'activation_salles'), self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
        }
        if (array_key_exists('ordre_libre', $regles) && $regles['ordre_libre'] !== true) {
            $this->refuser(self::chemin($position, 'ordre_libre'), self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
        }
        if (array_key_exists('fouille_piege_un_par_un', $regles) && !is_bool($regles['fouille_piege_un_par_un'])) {
            $this->refuser(self::chemin($position, 'fouille_piege_un_par_un'), self::TYPE_INVALIDE, 'true ou false est attendu');
        }
        if (array_key_exists('monstres_pacifies_par_drapeau', $regles)) {
            $ici = self::chemin($position, 'monstres_pacifies_par_drapeau');
            $pacifies = $this->enObjet($regles['monstres_pacifies_par_drapeau'], $ici);
            if ($pacifies !== null) {
                if ($pacifies === []) {
                    $this->refuser($ici, self::VALEUR_INVALIDE, 'au moins un monstre est attendu');
                }
                foreach ($pacifies as $slug => $drapeau) {
                    $cleSlug = (string) $slug;
                    if (! $this->catalogue->contient('monstres', $cleSlug)) {
                        $this->refuser("$ici.$cleSlug", self::CATALOGUE_INCONNU, "« $cleSlug » n'existe pas dans le catalogue « monstres »");
                    }
                    $this->nomDeDrapeau($pacifies, $cleSlug, $ici);
                }
            }
        }
        if (array_key_exists('sans_fouille', $regles)) {
            $this->sansFouille($quete, $regles['sans_fouille'], self::chemin($position, 'sans_fouille'));
        }
        // Lot K5j (R4) : l'équipement confisqué attend dans une salle OUVERTE, qu'on peut fouiller.
        if (array_key_exists('confiscation', $regles)) {
            $ici = self::chemin($position, 'confiscation');
            $confiscation = $this->enObjet($regles['confiscation'], $ici);
            if ($confiscation !== null) {
                $this->cles($confiscation, $ici, ['salle']);
                $salle = $confiscation['salle'] ?? null;
                if (array_key_exists('salle', $confiscation) && !is_string($salle)) {
                    $this->refuser("$ici.salle", self::TYPE_INVALIDE, 'un identifiant de salle est attendu');
                } elseif (is_string($salle) && $this->ouvertesFiables && !isset($this->ouvertes[$salle])) {
                    $this->refuser("$ici.salle", self::SALLE_INCONNUE, "« $salle » n'est pas une salle ouverte de cette quête : les affaires y seraient hors d'atteinte");
                } elseif (is_string($salle) && in_array($salle, (array) ($regles['sans_fouille'] ?? []), true)) {
                    $this->refuser("$ici.salle", self::VALEUR_INVALIDE, "« $salle » ne se fouille pas : personne ne pourrait y reprendre ses affaires");
                }
            }
        }
        // Lot K5n : primes par monstre abattu (*Bastion of Dread*, q. 11) — `{slug_monstre: or >= 1}`.
        if (array_key_exists('primes_monstres', $regles)) {
            $ici = self::chemin($position, 'primes_monstres');
            $primes = $this->enObjet($regles['primes_monstres'], $ici);
            if ($primes !== null) {
                if ($primes === []) {
                    $this->refuser($ici, self::VALEUR_INVALIDE, 'au moins un monstre est attendu');
                }
                foreach ($primes as $slug => $or) {
                    $cleSlug = (string) $slug;
                    if (! $this->catalogue->contient('monstres', $cleSlug)) {
                        $this->refuser("$ici.$cleSlug", self::CATALOGUE_INCONNU, "« $cleSlug » n'existe pas dans le catalogue « monstres »");
                    }
                    $this->entier($primes, $cleSlug, $ici, min: 1);
                }
            }
        }
        // Lot K5n : portes à téléportation `2d6` (*Castle of Mystery*, q. 10) — clés « 2 » à « 12 » vers `{x, y}`.
        if (array_key_exists('portes_teleportation', $regles)) {
            $ici = self::chemin($position, 'portes_teleportation');
            $table = $this->enObjet($regles['portes_teleportation'], $ici);
            if ($table !== null) {
                $attendues = array_map('strval', range(2, 12));
                $this->cles($table, $ici, $attendues);
                foreach ($attendues as $somme) {
                    if (array_key_exists($somme, $table) && ($dest = $this->enObjet($table[$somme], "$ici.$somme")) !== null) {
                        $this->cles($dest, "$ici.$somme", self::CLES_CASE);
                        $case = $this->cellule($dest, "$ici.$somme");
                        if ($case !== null && $this->salleFermeeEn($case[0], $case[1])) {
                            $this->refuser("$ici.$somme", self::DANS_SALLE_FERMEE, "la case ({$case[0]}, {$case[1]}) est dans une salle fermée");
                        }
                    }
                }
            }
        }

        if (!array_key_exists('solo', $regles)) {
            return;
        }
        $classe = $regles['solo'];
        $ici = self::chemin($position, 'solo');
        if (!is_string($classe)) {
            $this->refuser($ici, self::TYPE_INVALIDE, 'un nom de classe est attendu');
        } elseif (!$this->catalogue->contient('classes', $classe)) {
            $this->refuser($ici, self::CLASSE_INCONNUE, "« $classe » n'est pas une classe de héros");
        } elseif (!in_array($classe, (array) ($document['prerequis_heros'] ?? []), true)) {
            $this->refuser($ici, self::SOLO_SANS_PREREQUIS,
                "quête solo pour « $classe » : la campagne doit exiger cette classe (prerequis_heros), sinon un groupe sans elle ne pourrait pas la jouer");
        }
    }

    /** `regles.sans_fouille` (lot K5e) : des salles OUVERTES, sans doublon, qu'aucune note de fouille de trésor n'attend. */
    private function sansFouille(array $quete, mixed $valeur, string $chemin): void
    {
        if (!is_array($valeur) || !array_is_list($valeur) || $valeur === []) {
            $this->refuser($chemin, self::TYPE_INVALIDE, 'une liste non vide d\'identifiants de salle est attendue');

            return;
        }
        $vues = [];
        foreach ($valeur as $i => $id) {
            $ici = "$chemin.$i";
            if (!is_string($id)) {
                $this->refuser($ici, self::TYPE_INVALIDE, 'un identifiant de salle est attendu');
            } elseif (isset($vues[$id])) {
                $this->refuser($ici, self::VALEUR_INVALIDE, "« $id » est déjà dans la liste");
            } elseif ($this->ouvertesFiables && !isset($this->ouvertes[$id])) {
                $this->refuser($ici, self::SALLE_INCONNUE, "« $id » n'est pas une salle ouverte de cette quête");
            } else {
                $vues[$id] = true;
            }
        }
        foreach ((array) ($quete['notes'] ?? []) as $note) {
            if (is_array($note) && data_get($note, 'quand.declencheur') === MotsClesQuete::FOUILLE_TRESOR
                && isset($vues[(string) data_get($note, 'quand.cible.salle')])) {
                $this->refuser($chemin, self::VALEUR_INVALIDE,
                    'la salle « '.data_get($note, 'quand.cible.salle').' » ne se fouille pas, mais la note « '.($note['repere'] ?? '?').' » attend sa fouille de trésor : elle ne jouerait jamais');
            }
        }
    }

    /**
     * `quete.suite` (lot K5i, C2) : des embranchements `{quete: slug | fin: true, si_drapeau?}`, lus dans l'ordre à la
     * victoire (`FinDeQueteConstruite::rangSuivant()`).
     *
     * @param  array<string, true>  $drapeauxPoses  posés par cette quête, ou « de campagne » par une quête précédente
     * @param  list<string>  $slugs  ceux de toutes les quêtes de la campagne
     */
    private function suite(array $quete, string $chemin, array $drapeauxPoses, array $slugs, ?string $soi): void
    {
        if (!array_key_exists('suite', $quete)) {
            return;
        }
        $position = self::chemin($chemin, 'suite');
        $branches = $this->enListeDe($quete, 'suite', $chemin);
        if (is_array($quete['suite']) && $branches === []) {
            $this->refuser($position, self::VALEUR_INVALIDE, 'une suite sans embranchement ne dit rien : retirez la clé');

            return;
        }
        $derniere = count($branches) - 1;
        foreach ($branches as $i => $valeur) {
            $ici = "$position.$i";
            $branche = $this->enObjet($valeur, $ici);
            if ($branche === null) {
                continue;
            }
            $this->cles($branche, $ici, [], ['quete', 'fin', 'si_drapeau']);
            $versQuete = array_key_exists('quete', $branche);
            $versFin = array_key_exists('fin', $branche);
            if ($versQuete === $versFin) {
                $this->refuser($ici, self::VALEUR_INVALIDE, 'un embranchement mène à une quête (`quete`) OU à la fin (`fin: true`), un seul');
            } elseif ($versFin && $branche['fin'] !== true) {
                $this->refuser("$ici.fin", self::TYPE_INVALIDE, 'true est attendu');
            } elseif ($versQuete) {
                $cible = $branche['quete'];
                if (!is_string($cible)) {
                    $this->refuser("$ici.quete", self::TYPE_INVALIDE, 'le slug d\'une quête est attendu');
                } elseif ($cible === $soi || !in_array($cible, $slugs, true)) {
                    $this->refuser("$ici.quete", self::CIBLE_INCONNUE,
                        $cible === $soi ? 'une quête ne mène pas à elle-même' : "« $cible » n'est le slug d'aucune quête de la campagne");
                }
            }
            $drapeau = $this->nomDeDrapeau($branche, 'si_drapeau', $ici);
            if ($drapeau !== null && !isset($drapeauxPoses[$drapeau])) {
                $this->refuser("$ici.si_drapeau", self::DRAPEAU_JAMAIS_POSE,
                    "aucune note de cette quête, ni drapeau de campagne d'une quête précédente, ne pose « $drapeau »");
            }
            if (!array_key_exists('si_drapeau', $branche) && $i !== $derniere) {
                $this->refuser($ici, self::VALEUR_INVALIDE, 'un embranchement sans drapeau est toujours pris : il doit être le dernier');
            }
        }
    }

    private function recompense(array $quete, string $chemin): void
    {
        if (!array_key_exists('recompense', $quete) || $quete['recompense'] === null) {
            return;
        }
        $position = self::chemin($chemin, 'recompense');
        $recompense = $this->enObjet($quete['recompense'], $position);
        if ($recompense === null) {
            return;
        }
        $this->cles($recompense, $position, self::CLES_RECOMPENSE, self::CLES_RECOMPENSE_OPTIONNELLES);

        $this->entier($recompense, 'or', $position, min: 0);
        $this->entier($recompense, 'reputation', $position, min: 1);
        $this->dans($recompense, 'partage', $position, self::PARTAGES);
        // Lot E1 : des OBJETS en récompense (`[{slug, chacun?}]`), et une récompense CONDITIONNELLE (`si_drapeau`).
        if (array_key_exists('objets', $recompense)) {
            foreach ($this->enListeDe($recompense, 'objets', $position) as $k => $valeur) {
                $ici = self::chemin($position, 'objets').".$k";
                $objet = $this->enObjet($valeur, $ici);
                if ($objet === null) {
                    continue;
                }
                $this->cles($objet, $ici, ['slug'], ['chacun']);
                $this->slugCatalogue($objet, 'slug', $ici, 'objets');
                if (array_key_exists('chacun', $objet) && $objet['chacun'] !== true) {
                    $this->refuser("$ici.chacun", self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
                }
            }
        }
        if (array_key_exists('si_drapeau', $recompense)) {
            $this->nomDeDrapeau($recompense, 'si_drapeau', $position);
        }
    }

    /**
     * `quete.tournoi` (facultative, lot TC2 — *Against the Ogre Horde* q. 1-3, `docs/plan-tournoi-classique.md`) :
     * `{camp_heros: [{x, y}], camp_defender: [{x, y}], trophees?: [{x, y}], vagues?: …}`. Les deux tuiles de départ ont au
     * moins une case ; les trophées au plus une case par tuile du lot (`TropheesArene::LOT`).
     */
    private function tournoi(array $quete, string $chemin): void
    {
        if (! array_key_exists('tournoi', $quete)) {
            return;
        }
        $ici = self::chemin($chemin, 'tournoi');
        $tournoi = $this->enObjet($quete['tournoi'], $ici);
        if ($tournoi === null) {
            return;
        }
        $this->cles($tournoi, $ici, ['camp_heros', 'camp_defender'], ['trophees', 'vagues']);
        foreach (['camp_heros', 'camp_defender', 'trophees'] as $cle) {
            if (! array_key_exists($cle, $tournoi)) {
                continue;
            }
            $liste = $this->enListe($tournoi[$cle], "$ici.$cle");
            foreach ((array) $liste as $k => $c) {
                if (($case = $this->enObjet($c, "$ici.$cle.$k")) !== null) {
                    $this->cles($case, "$ici.$cle.$k", self::CLES_CASE);
                    $this->cellule($case, "$ici.$cle.$k");
                }
            }
            if ($liste === [] && $cle !== 'trophees') {
                $this->refuser("$ici.$cle", self::VALEUR_INVALIDE, 'au moins une case');
            }
            if ($cle === 'trophees' && count((array) $liste) > count(\App\Partie\Arene\TropheesArene::LOT)) {
                $this->refuser("$ici.$cle", self::VALEUR_INVALIDE, 'au plus '.count(\App\Partie\Arene\TropheesArene::LOT).' tuiles trophées');
            }
        }
        // Les VAGUES suivantes (lot TC4, F9528 q. 1) : une liste de vagues, chacune une liste de figures — un slug et le
        // profil d'auteur d'un monstre posé, sans case (elles entrent sur la tuile du Defender).
        if (array_key_exists('vagues', $tournoi)) {
            foreach ((array) $this->enListe($tournoi['vagues'], "$ici.vagues") as $v => $vague) {
                $figures = $this->enListe($vague, "$ici.vagues.$v");
                if ($figures === []) {
                    $this->refuser("$ici.vagues.$v", self::VALEUR_INVALIDE, 'une vague a au moins une figure');
                }
                foreach ((array) $figures as $f => $figure) {
                    $pos = "$ici.vagues.$v.$f";
                    if (($fig = $this->enObjet($figure, $pos)) === null) {
                        continue;
                    }
                    $this->cles($fig, $pos, ['slug'], array_values(array_diff(self::CLES_MONSTRE_OPTIONNELLES, ['inactif', 'fuite'])));
                    $this->slugCatalogue($fig, 'slug', $pos, 'monstres');
                    $this->profilDeMonstre($fig, $pos);
                }
            }
        }
    }

    /**
     * `quete.coffres_pieges` (facultative, lot E1 — tous les livrets) : `[{salle, piege?, degats?, toute_la_salle?}]`, un
     * coffre piégé par salle ouverte. `piege` : un piège de coffre du catalogue (`declencheur: ouverture_tresor`) ;
     * `degats` : 1 à 10 PV fixes, à la place de ceux du piège (pas pour un piège qui lance un sort) ; `toute_la_salle` :
     * chaque héros de la salle les subit — il faut alors écrire `degats`.
     */
    private function coffresPieges(array $quete, string $chemin): void
    {
        if (! array_key_exists('coffres_pieges', $quete)) {
            return;
        }
        $salles = [];
        foreach ($this->enListeDe($quete, 'coffres_pieges', $chemin) as $i => $valeur) {
            $ici = self::chemin($chemin, 'coffres_pieges').".$i";
            $coffre = $this->enObjet($valeur, $ici);
            if ($coffre === null) {
                continue;
            }
            $this->cles($coffre, $ici, ['salle'], self::CLES_COFFRE_PIEGE_OPTIONNELLES);
            $salle = $coffre['salle'] ?? null;
            if (! is_string($salle) || ! in_array($salle, (array) ($quete['salles_ouvertes'] ?? []), true)) {
                $this->refuser("$ici.salle", self::SALLE_INCONNUE, 'le coffre piégé doit être dans une salle ouverte de la quête');
            } elseif (isset($salles[$salle])) {
                $this->refuser("$ici.salle", self::VALEUR_INVALIDE, "la salle « $salle » a déjà son coffre piégé");
            }
            $salles[(string) $salle] = true;
            $avecSort = false;
            if (array_key_exists('piege', $coffre) && $this->slugCatalogue($coffre, 'piege', $ici, 'pieges') !== null) {
                $piege = $this->catalogue->index('pieges')[(string) $coffre['piege']];
                if (data_get($piege->effet, 'declencheur') !== 'ouverture_tresor') {
                    $this->refuser("$ici.piege", self::VALEUR_INVALIDE, "« {$coffre['piege']} » n'est pas un piège de coffre (il se pose au sol)");
                }
                $avecSort = data_get($piege->effet, 'sort_dread') !== null;
            }
            $degats = $this->entier($coffre, 'degats', $ici, min: 1);
            if ($degats !== null && $degats > 10) {
                $this->refuser("$ici.degats", self::VALEUR_INVALIDE, 'au plus 10 PV');
            }
            if ($degats !== null && $avecSort) {
                $this->refuser("$ici.degats", self::VALEUR_INVALIDE, 'un piège qui lance un sort fait les dégâts du sort');
            }
            if (array_key_exists('toute_la_salle', $coffre) && $coffre['toute_la_salle'] !== true) {
                $this->refuser("$ici.toute_la_salle", self::TYPE_INVALIDE, 'true est attendu (ou la clé absente)');
            } elseif (($coffre['toute_la_salle'] ?? false) === true && $degats === null) {
                $this->refuser("$ici.degats", self::VALEUR_INVALIDE, 'pour toute la salle, écrire les dégâts');
            }
        }
    }

    /**
     * `quete.glyphes` (facultative, lot K6 — *First Light*) : `[{id, salle, detruit_par: parchemin|cheminee, drapeau}]`.
     * La salle est une salle OUVERTE ; « cheminee » exige une Cheminée posée dans cette salle.
     */
    private function glyphes(array $quete, string $chemin): void
    {
        if (! array_key_exists('glyphes', $quete)) {
            return;
        }
        $ids = [];
        foreach ($this->enListeDe($quete, 'glyphes', $chemin) as $i => $valeur) {
            $ici = self::chemin($chemin, 'glyphes').".$i";
            $glyphe = $this->enObjet($valeur, $ici);
            if ($glyphe === null) {
                continue;
            }
            $this->cles($glyphe, $ici, ['id', 'salle', 'detruit_par', 'drapeau']);
            $id = $this->identifiant($glyphe, 'id', $ici);
            if ($id !== null && isset($ids[$id])) {
                $this->refuser("$ici.id", self::VALEUR_INVALIDE, "« $id » est déjà l'identifiant d'un autre glyphe");
            }
            $ids[(string) $id] = true;
            if (array_key_exists('salle', $glyphe) && !in_array($glyphe['salle'], (array) ($quete['salles_ouvertes'] ?? []), true)) {
                $this->refuser("$ici.salle", self::SALLE_INCONNUE, 'le glyphe doit être dans une salle ouverte de la quête');
            }
            $moyen = $this->dans($glyphe, 'detruit_par', $ici, \App\Partie\Classique\Glyphes::MOYENS);
            $this->nomDeDrapeau($glyphe, 'drapeau', $ici);
            if ($moyen === \App\Partie\Classique\Glyphes::CHEMINEE && is_string($glyphe['salle'] ?? null)) {
                $salle = collect($this->salles)->firstWhere('id', $glyphe['salle']);
                $avecCheminee = $salle !== null && collect((array) ($quete['mobilier'] ?? []))->contains(fn ($m) => is_array($m)
                    && ($m['slug'] ?? null) === 'cheminee'
                    && isset($m['x'], $m['y']) && Salles::contientCase($salle, (int) $m['x'], (int) $m['y']));
                if (! $avecCheminee) {
                    $this->refuser("$ici.detruit_par", self::VALEUR_INVALIDE, 'aucune Cheminée n\'est posée dans la salle de ce glyphe');
                }
            }
        }
    }

    /** `quete.dangers` (facultative, lot K5o) : rocher roulant (`{type: "rocher", run: [{x, y}…]}`) et brume de la mort (`{type: "brume", x, y}`). */
    private function dangers(array $quete, string $chemin): void
    {
        if (! array_key_exists('dangers', $quete)) {
            return;
        }
        foreach ($this->enListeDe($quete, 'dangers', $chemin) as $i => $valeur) {
            $ici = self::chemin($chemin, 'dangers').".$i";
            $danger = $this->enObjet($valeur, $ici);
            if ($danger === null) {
                continue;
            }
            $type = $this->dans($danger, 'type', $ici, ['rocher', 'brume']);
            if ($type === 'brume') {
                $this->cles($danger, $ici, ['type', 'x', 'y']);
                $case = $this->cellule($danger, $ici);
                if ($case !== null && $this->sallesPosees && $this->salleEn($case[0], $case[1]) !== null) {
                    $this->refuser($ici, self::BLOC_HORS_COULOIR, 'la brume de la mort ne se pose que dans un couloir');
                }
            } elseif ($type === 'rocher') {
                $this->cles($danger, $ici, ['type', 'run']);
                $run = $this->enListeDe($danger, 'run', $ici);
                if (array_key_exists('run', $danger) && is_array($danger['run']) && count($run) < 2) {
                    $this->refuser("$ici.run", self::VALEUR_INVALIDE, 'le trajet d\'un rocher roulant compte au moins 2 cases');
                }
                foreach ($run as $k => $pt) {
                    $casePt = $this->enObjet($pt, "$ici.run.$k");
                    if ($casePt !== null) {
                        $this->cles($casePt, "$ici.run.$k", self::CLES_CASE);
                        $coord = $this->cellule($casePt, "$ici.run.$k");
                        if ($coord !== null && $this->sallesPosees && $this->salleEn($coord[0], $coord[1]) !== null) {
                            $this->refuser("$ici.run.$k", self::BLOC_HORS_COULOIR, 'le rocher roulant ne roule que dans un couloir');
                        }
                    }
                }
            } else {
                $this->cles($danger, $ici, ['type'], ['x', 'y', 'run']);
            }
        }
    }

    // ─── Outils communs : chacun est la SEULE copie de sa règle ───────────────────────────────────────────────────

    /** Un objet (tableau associatif, ou `{}`). Une liste non vide est refusée. */
    private function enObjet(mixed $valeur, string $chemin): ?array
    {
        if (is_array($valeur) && ($valeur === [] || !array_is_list($valeur))) {
            return $valeur;
        }
        $this->refuser($chemin, self::TYPE_INVALIDE, 'un objet est attendu');

        return null;
    }

    /** Une liste JSON (tableau séquentiel). `[]` vaut liste vide. */
    private function enListe(mixed $valeur, string $chemin): ?array
    {
        if (is_array($valeur) && array_is_list($valeur)) {
            return $valeur;
        }
        $this->refuser($chemin, self::TYPE_INVALIDE, 'une liste est attendue');

        return null;
    }

    /** La liste d'une clé ; `[]` si la clé est absente (déjà signalé) ou n'est pas une liste (déjà signalé). */
    private function enListeDe(array $objet, string $cle, string $chemin): array
    {
        if (!array_key_exists($cle, $objet)) {
            return [];
        }

        return $this->enListe($objet[$cle], self::chemin($chemin, $cle)) ?? [];
    }

    /**
     * Présence des clés obligatoires (`cle_manquante`) et contrôle des clés inconnues : refusées en les nommant
     * (`cle_inconnue`). Plus aucune clé n'est réservée à un lot ultérieur depuis le lot K5i (`cle_pas_encore_prise` retiré).
     */
    private function cles(array $objet, string $chemin, array $attendues, array $optionnelles = []): void
    {
        foreach (array_keys($objet) as $cle) {
            $cle = (string) $cle;
            if (in_array($cle, $attendues, true) || in_array($cle, $optionnelles, true)) {
                continue;
            }
            $this->refuser(self::chemin($chemin, $cle), self::CLE_INCONNUE, "clé inconnue « $cle »");
        }

        foreach ($attendues as $cle) {
            if (!array_key_exists($cle, $objet)) {
                $this->refuser(self::chemin($chemin, $cle), self::CLE_MANQUANTE, "clé obligatoire absente : « $cle »");
            }
        }
    }

    /** Un texte. `null` n'est accepté que si `$nullable`. Rend le texte, ou `null` s'il est refusé ou absent. */
    private function texte(array $objet, string $cle, string $chemin, bool $nonVide = false, bool $nullable = false,
        int $max = 0): ?string
    {
        if (!array_key_exists($cle, $objet)) {
            return null;
        }
        $valeur = $objet[$cle];
        $position = self::chemin($chemin, $cle);

        if ($valeur === null && $nullable) {
            return null;
        }
        if (!is_string($valeur)) {
            $this->refuser($position, self::TYPE_INVALIDE, $nullable ? 'un texte ou null est attendu' : 'un texte est attendu');

            return null;
        }
        if ($nonVide && trim($valeur) === '') {
            $this->refuser($position, self::VALEUR_INVALIDE, 'ne peut pas être vide');

            return null;
        }
        if ($max > 0 && mb_strlen($valeur) > $max) {
            $this->refuser($position, self::VALEUR_INVALIDE, "$max caractères au plus");

            return null;
        }

        return $valeur;
    }

    /** Un slug de campagne ou de quête : `[a-z0-9-]+`, 100 caractères au plus (la colonne `slug`). */
    private function slug(array $objet, string $cle, string $chemin): ?string
    {
        $valeur = $this->texte($objet, $cle, $chemin);
        if ($valeur === null) {
            return null;
        }
        if (!preg_match('/\A[a-z0-9-]+\z/', $valeur) || strlen($valeur) > self::LONGUEUR_SLUG) {
            $this->refuser(self::chemin($chemin, $cle), self::SLUG_INVALIDE,
                "« $valeur » : lettres minuscules, chiffres et tirets seulement (100 caractères au plus)");

            return null;
        }

        return $valeur;
    }

    /** Un identifiant de salle : `[A-Za-z0-9_-]`, 40 caractères au plus. */
    private function identifiant(array $objet, string $cle, string $chemin): ?string
    {
        $valeur = $this->texte($objet, $cle, $chemin);
        if ($valeur === null) {
            return null;
        }
        if (!preg_match('/\A[A-Za-z0-9_-]{1,'.self::LONGUEUR_ID_SALLE.'}\z/', $valeur)) {
            $this->refuser(self::chemin($chemin, $cle), self::VALEUR_INVALIDE,
                '« '.$valeur.' » : lettres, chiffres, _ et - seulement, 40 caractères au plus');

            return null;
        }

        return $valeur;
    }

    /** Un slug de catalogue : il doit exister dans la couche (`catalogue_inconnu`, nommé). */
    private function slugCatalogue(array $objet, string $cle, string $chemin, string $couche, bool $nullable = false): ?string
    {
        $valeur = $this->texte($objet, $cle, $chemin, nullable: $nullable);
        if ($valeur === null) {
            return null;
        }
        if (!$this->catalogue->contient($couche, $valeur)) {
            $this->refuser(self::chemin($chemin, $cle), self::CATALOGUE_INCONNU,
                "« $valeur » n'existe pas dans le catalogue « $couche »");

            return null;
        }

        return $valeur;
    }

    /** Une valeur prise dans un vocabulaire fermé ; rend la valeur, ou `null` si elle est refusée ou absente. */
    private function dans(array $objet, string $cle, string $chemin, array $permises): mixed
    {
        if (!array_key_exists($cle, $objet)) {
            return null;
        }
        $valeur = $objet[$cle];
        if (in_array($valeur, $permises, true)) {
            return $valeur;
        }
        $this->refuser(self::chemin($chemin, $cle), self::VALEUR_INVALIDE,
            'valeur non permise (attendu : '.implode(', ', $permises).')');

        return null;
    }

    /** Un entier. `$min` borne par le bas (une coordonnée négative n'est PAS refusée ici : elle sort du plateau). */
    private function entier(array $objet, string $cle, string $chemin, ?int $min = null): ?int
    {
        if (!array_key_exists($cle, $objet)) {
            return null;
        }
        $valeur = $objet[$cle];
        $position = self::chemin($chemin, $cle);
        if (!is_int($valeur)) {
            $this->refuser($position, self::TYPE_INVALIDE, 'un entier est attendu');

            return null;
        }
        if ($min !== null && $valeur < $min) {
            $this->refuser($position, self::VALEUR_INVALIDE, "doit être ≥ $min");

            return null;
        }

        return $valeur;
    }

    /** Une case `{x, y}` : entiers, et dans le plateau (`hors_plateau` sinon). Rend [x, y], ou `null`. */
    private function cellule(array $objet, string $chemin): ?array
    {
        $x = $this->entier($objet, 'x', $chemin);
        $y = $this->entier($objet, 'y', $chemin);
        if ($x === null || $y === null) {
            return null;
        }
        if (!$this->dansPlateau($x, $y)) {
            $this->refuser($chemin, self::HORS_PLATEAU, "la case ($x, $y) est hors du plateau");

            return null;
        }

        return [$x, $y];
    }

    private function dansPlateau(int $x, int $y): bool
    {
        if ($x < 0 || $y < 0) {
            return false;
        }
        if ($this->dimensions === null) {
            // Dimensions invalides : le plateau est déjà refusé ; rien à borner de plus.
            return true;
        }

        return $x < $this->dimensions[0] && $y < $this->dimensions[1];
    }

    /** L'id de la salle qui contient la case, ou `null` (couloir). La première salle gagne si deux se chevauchent. */
    private function salleEn(int $x, int $y): ?string
    {
        foreach ($this->salles as $salle) {
            if (Salles::contientCase($salle, $x, $y)) {
                return $salle['id'];
            }
        }

        return null;
    }

    private function salleFermeeEn(int $x, int $y): bool
    {
        if (!$this->ouvertesFiables) {
            return false;
        }
        $salle = $this->salleEn($x, $y);

        return $salle !== null && !isset($this->ouvertes[$salle]);
    }

    private function refuser(string $chemin, string $code, string $message): void
    {
        $this->refus[] = ['chemin' => $chemin, 'code' => $code, 'message' => $message];
    }

    private static function chemin(string $parent, string $cle): string
    {
        return $parent === '' ? $cle : "$parent.$cle";
    }
}
