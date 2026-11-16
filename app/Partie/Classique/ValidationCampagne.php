<?php

declare(strict_types=1);

namespace App\Partie\Classique;

use App\Engine\MotsClesQuete;
use App\Partie\Grille;

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
    public const CLE_PAS_ENCORE_PRISE = 'cle_pas_encore_prise';
    public const CLE_MANQUANTE = 'cle_manquante';
    public const TYPE_INVALIDE = 'type_invalide';
    public const VALEUR_INVALIDE = 'valeur_invalide';
    public const FORMAT_INCONNU = 'format_inconnu';
    public const SLUG_INVALIDE = 'slug_invalide';
    public const SLUG_EN_DOUBLE = 'slug_en_double';
    public const SALLE_HORS_PLATEAU = 'salle_hors_plateau';
    public const SALLE_ID_EN_DOUBLE = 'salle_id_en_double';
    public const SALLES_SE_CHEVAUCHENT = 'salles_se_chevauchent';
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

    /** @var list<string> */
    public const CODES = [
        self::CLE_INCONNUE, self::CLE_PAS_ENCORE_PRISE, self::CLE_MANQUANTE, self::TYPE_INVALIDE,
        self::VALEUR_INVALIDE, self::FORMAT_INCONNU, self::SLUG_INVALIDE, self::SLUG_EN_DOUBLE,
        self::SALLE_HORS_PLATEAU, self::SALLE_ID_EN_DOUBLE, self::SALLES_SE_CHEVAUCHENT, self::SALLE_INCONNUE,
        self::CATALOGUE_INCONNU, self::CLASSE_INCONNUE, self::HORS_PLATEAU, self::DANS_SALLE_FERMEE,
        self::BLOC_HORS_COULOIR, self::DEPART_INVALIDE, self::PORTE_SANS_BORD, self::SLUG_NON_CONCORDANT,
        self::EMPREINTE_INVALIDE,
        self::DEPART_HORS_SALLE,
        self::PORTE_DANS_LA_ROCHE, self::MOBILIER_HORS_SALLE, self::CASE_PARTAGEE, self::CIBLE_INCONNUE,
        self::CIBLE_DE_NOTE_INVALIDE, self::DRAPEAU_JAMAIS_POSE,
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
    ];
    /** Clés d'une quête qu'on peut omettre : une quête sans note est une quête sans note, pas une quête incomplète. */
    private const CLES_QUETE_OPTIONNELLES = ['notes', 'fausses_sorties'];
    private const CLES_NOTE = ['repere', 'quand', 'alors'];
    private const CLES_NOTE_OPTIONNELLES = ['une_fois'];
    private const CLES_QUAND = ['declencheur', 'cible'];
    private const CLES_QUAND_OPTIONNELLES = ['si_drapeau'];
    private const LONGUEUR_REPERE = 40;
    private const LONGUEUR_DRAPEAU = 40;
    private const CLES_PORTE = ['x', 'y', 'cote', 'type'];
    private const CLES_CASE = ['x', 'y'];
    private const CLES_MOBILIER = ['slug', 'x', 'y', 'rotation'];
    private const CLES_POSE = ['slug', 'x', 'y'];
    private const CLES_RECOMPENSE = ['or', 'partage'];

    /** Clés déjà prévues par `docs/plan-mode-classique.md` §3, pas encore lues : le lot qui les prendra. */
    private const LOTS_A_VENIR = ['regles' => 'K5', 'suite' => 'K4'];

    /**
     * Les conditions de fin qu'une quête peut déclarer : SEULEMENT celles que le moteur sait jouer
     * (`App\Models\Quete::OBJECTIFS_CONSTRUITS`, lu ici — une seule liste). Un type ajouté là sans lecteur serait une
     * règle promise au joueur et jamais tenue.
     */
    public const TYPES_FIN = ['vaincre', 'drapeau', 'sortir'];

    private const LONGUEUR_LIBELLE_FIN = 300;

    /** Décalage d'une case vers chaque côté d'une porte. */
    private const DECALAGES = ['n' => [0, -1], 'e' => [1, 0], 's' => [0, 1], 'o' => [-1, 0]];

    // ─── État d'un appel (remis à zéro par valider()) ─────────────────────────────────────────────────────────────

    /** @var list<array{chemin: string, code: string, message: string}> */
    private array $refus = [];

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

        $this->cles($document, '', self::CLES_CAMPAGNE);
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
            $this->cles($salle, $chemin, self::CLES_SALLE);

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

            foreach ($rectangles as $autre) {
                if ($x < $autre['x'] + $autre['l'] && $autre['x'] < $x + $l
                    && $y < $autre['y'] + $autre['h'] && $autre['y'] < $y + $h) {
                    $this->refuser($chemin, self::SALLES_SE_CHEVAUCHENT,
                        "la salle « $id » chevauche la salle « {$autre['id']} »");
                }
            }

            $rectangles[] = ['id' => $id, 'x' => $x, 'y' => $y, 'l' => $l, 'h' => $h, 'i' => (int) $i];
        }

        $this->salles = $rectangles;
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
            $this->slugCatalogue($quete, 'monstre_errant', $chemin, 'monstres', nullable: true);

            // Ordre FIXE : un élément ne partage une case qu'avec ce qui a été posé avant lui (`occuper()`). Les blocs
            // d'abord, puis l'escalier de départ, puis le reste : chaque refus de partage porte sur le dernier venu.
            $this->occupees = [];
            $this->embrasures = [];
            $this->sallesOuvertes($quete, $chemin);
            $this->portes($quete, $chemin);
            $this->blocs($quete, $chemin);
            $this->depart($quete, $chemin);
            $this->sortie($quete, $chemin);
            $this->faussesSorties($quete, $chemin);
            $this->poses($quete, $chemin, 'mobilier', 'mobilier', tourne: true);
            $this->poses($quete, $chemin, 'monstres', 'monstres');
            $this->poses($quete, $chemin, 'pieges', 'pieges');
            $this->poses($quete, $chemin, 'terrain', 'terrain');
            $this->recompense($quete, $chemin);
            // Les notes AVANT la fin : une fin « drapeau » n'est gagnable que si une note pose ce drapeau.
            $drapeauxPoses = $this->notes($quete, $chemin);
            $this->fin($quete, $chemin, $drapeauxPoses);
        }
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
                $drapeau = $this->nomDeDrapeau($quand, 'si_drapeau', "$position.quand");
                if ($drapeau !== null) {
                    $lus[$drapeau] ??= "$position.quand.si_drapeau";
                }
            }

            $alors = $this->enListeDe($note, 'alors', $position);
            if (array_key_exists('alors', $note) && is_array($note['alors']) && $alors === []) {
                $this->refuser("$position.alors", self::VALEUR_INVALIDE, 'Une note sans effet ne fait rien : il en faut au moins un.');
            }
            foreach ($alors as $j => $effet) {
                $drapeau = $this->effetDeNote($effet, "$position.alors.$j");
                if ($drapeau !== null) {
                    $poses[$drapeau] = true;
                }
            }
        }

        foreach ($lus as $drapeau => $ou) {
            if (!isset($poses[$drapeau])) {
                $this->refuser($ou, self::DRAPEAU_JAMAIS_POSE,
                    "aucune note de cette quête ne pose le drapeau « $drapeau » : cette note ne jouerait jamais");
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
            case 'porte':
            case 'monstre':
                $liste = $cles[0] === 'porte' ? 'portes' : 'monstres';
                $nombre = is_array($quete[$liste] ?? null) ? count($quete[$liste]) : 0;
                $index = $cible[$cles[0]];
                if (!is_int($index)) {
                    $this->refuser("$position.{$cles[0]}", self::TYPE_INVALIDE, "l'index (entier) d'un élément de `$liste` est attendu");
                } elseif ($index < 0 || $index >= $nombre) {
                    $this->refuser("$position.{$cles[0]}", self::CIBLE_INCONNUE,
                        "l'index $index ne désigne rien dans `$liste` : cette quête en pose $nombre");
                }
                break;
        }
    }

    /** Un effet de note. Rend le nom du drapeau qu'il pose, ou `null`. */
    private function effetDeNote(mixed $valeur, string $position): ?string
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
            case MotsClesQuete::OR:
                $this->entier($effet, 'or', $position, min: 1);
                break;
            case MotsClesQuete::OBJET:
                $this->slugCatalogue($effet, 'slug', $position, 'objets');
                $this->entier($effet, 'quantite', $position, min: 1);
                break;
            case MotsClesQuete::DEGATS:
                $this->entier($effet, 'body', $position, min: 1);
                break;
            case MotsClesQuete::DRAPEAU:
                return $this->nomDeDrapeau($effet, 'nom', $position);
            case MotsClesQuete::APPARITION:
                $monstres = $this->enListeDe($effet, 'monstres', $position);
                if (array_key_exists('monstres', $effet) && is_array($effet['monstres']) && $monstres === []) {
                    $this->refuser("$position.monstres", self::VALEUR_INVALIDE, 'Une apparition sans monstre ne fait rien : il en faut au moins un.');
                }
                foreach ($monstres as $k => $pose) {
                    $ici = "$position.monstres.$k";
                    if (($pose = $this->enObjet($pose, $ici)) === null) {
                        continue;
                    }
                    $this->cles($pose, $ici, self::CLES_POSE);
                    $this->slugCatalogue($pose, 'slug', $ici, 'monstres');
                    $case = $this->cellule($pose, $ici);
                    if ($case !== null && $this->salleFermeeEn($case[0], $case[1])) {
                        $this->refuser($ici, self::DANS_SALLE_FERMEE, "la case ({$case[0]}, {$case[1]}) est dans une salle fermée : la figure y serait dans la roche");
                    } elseif ($case !== null) {
                        // Sur un meuble ou un bloc, la figure ne peut pas paraître LÀ : le moteur la poserait sur la
                        // case libre la plus proche, ailleurs que là où l'auteur l'a dessinée. On le lui dit.
                        foreach ($this->occupees["{$case[0]},{$case[1]}"] ?? [] as $occupant) {
                            if (in_array($occupant['genre'], ['meuble', 'bloc'], true)) {
                                $this->refuser($ici, self::CASE_PARTAGEE,
                                    "la case ({$case[0]}, {$case[1]}) est prise par {$occupant['nom']} : une figure ne peut pas y apparaître");
                                break;
                            }
                        }
                    }
                }
                break;
        }

        return null;
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
        foreach ($this->enListeDe($quete, 'portes', $chemin) as $i => $valeur) {
            $position = self::chemin($chemin, 'portes').".$i";
            $porte = $this->enObjet($valeur, $position);
            if ($porte === null) {
                continue;
            }
            $this->cles($porte, $position, self::CLES_PORTE);

            $cote = $this->dans($porte, 'cote', $position, self::COTES);
            $this->dans($porte, 'type', $position, self::TYPES_PORTE);
            $cellule = $this->cellule($porte, $position);
            if ($cellule !== null && $cote !== null) {
                $this->porteSurUnBord($cellule, $cote, $position);
            }
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
        if ($voisine !== null && !isset($this->ouvertes[$voisine])) {
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
            $this->cles($pose, $position, $tourne ? self::CLES_MOBILIER : self::CLES_POSE);

            $slug = $this->slugCatalogue($pose, 'slug', $position, $couche);
            if ($slug === null) {
                continue; // refusé en le nommant : un slug inconnu n'occupe rien, donc aucun refus en cascade
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
            if ($salleAncre === null) {
                $this->refuser($position, self::MOBILIER_HORS_SALLE, "$nom est posé hors de toute salle");

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
        $this->acces($quete['sortie'], self::chemin($chemin, 'sortie'), 'sortie');
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
        $this->cles($recompense, $position, self::CLES_RECOMPENSE);

        $this->entier($recompense, 'or', $position, min: 0);
        $this->dans($recompense, 'partage', $position, self::PARTAGES);
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
     * (`cle_inconnue`), ou `cle_pas_encore_prise` pour une clé réservée à un lot ultérieur.
     */
    private function cles(array $objet, string $chemin, array $attendues, array $optionnelles = []): void
    {
        foreach (array_keys($objet) as $cle) {
            $cle = (string) $cle;
            if (in_array($cle, $attendues, true) || in_array($cle, $optionnelles, true)) {
                continue;
            }
            if (isset(self::LOTS_A_VENIR[$cle])) {
                $this->refuser(self::chemin($chemin, $cle), self::CLE_PAS_ENCORE_PRISE,
                    "« $cle » : pas encore pris en charge (lot ".self::LOTS_A_VENIR[$cle].')');
            } else {
                $this->refuser(self::chemin($chemin, $cle), self::CLE_INCONNUE, "clé inconnue « $cle »");
            }
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
            if ($x >= $salle['x'] && $x < $salle['x'] + $salle['l'] && $y >= $salle['y'] && $y < $salle['y'] + $salle['h']) {
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
