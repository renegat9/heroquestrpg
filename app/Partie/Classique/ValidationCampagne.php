<?php

declare(strict_types=1);

namespace App\Partie\Classique;

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

    // Lot K2 — la géométrie du moteur (un mur est une case) : `docs/contrat-api.md` « Géométrie : une salle, son anneau ».
    public const ANNEAU_HORS_PLATEAU = 'anneau_hors_plateau';
    public const ANNEAU_SUR_SALLE = 'anneau_sur_salle';
    public const POSE_SUR_MUR = 'pose_sur_mur';
    public const DEPART_HORS_SALLE = 'depart_hors_salle';
    public const PORTE_DANS_LA_ROCHE = 'porte_dans_la_roche';
    public const MOBILIER_HORS_SALLE = 'mobilier_hors_salle';
    public const CASE_PARTAGEE = 'case_partagee';

    /** @var list<string> */
    public const CODES = [
        self::CLE_INCONNUE, self::CLE_PAS_ENCORE_PRISE, self::CLE_MANQUANTE, self::TYPE_INVALIDE,
        self::VALEUR_INVALIDE, self::FORMAT_INCONNU, self::SLUG_INVALIDE, self::SLUG_EN_DOUBLE,
        self::SALLE_HORS_PLATEAU, self::SALLE_ID_EN_DOUBLE, self::SALLES_SE_CHEVAUCHENT, self::SALLE_INCONNUE,
        self::CATALOGUE_INCONNU, self::CLASSE_INCONNUE, self::HORS_PLATEAU, self::DANS_SALLE_FERMEE,
        self::BLOC_HORS_COULOIR, self::DEPART_INVALIDE, self::PORTE_SANS_BORD, self::SLUG_NON_CONCORDANT,
        self::EMPREINTE_INVALIDE,
        self::ANNEAU_HORS_PLATEAU, self::ANNEAU_SUR_SALLE, self::POSE_SUR_MUR, self::DEPART_HORS_SALLE,
        self::PORTE_DANS_LA_ROCHE, self::MOBILIER_HORS_SALLE, self::CASE_PARTAGEE,
    ];

    /**
     * Couples d'éléments qui ne peuvent pas partager une case (lot K2). Un BLOC ne partage rien avec quoi que ce soit ;
     * les autres couples sont listés. Un piège ou un terrain n'est donc refusé que contre un bloc.
     */
    private const PARTAGES_INTERDITS = [
        ['meuble', 'meuble'], ['meuble', 'monstre'], ['monstre', 'monstre'], ['escalier', 'meuble'], ['escalier', 'monstre'],
    ];

    // ─── Vocabulaires fermés : publiés tels quels par GET /api/constructeur/catalogues ───────────────────────────

    public const FORMAT = 1;
    public const FORMATS_ACCEPTES = [self::FORMAT];
    public const ROTATIONS = [0, 90, 180, 270];
    public const COTES = ['n', 'e', 's', 'o'];
    public const TYPES_PORTE = ['normale', 'secrete'];
    public const PARTAGES = ['groupe', 'par_heros'];

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
        'monstres', 'pieges', 'terrain', 'depart', 'recompense',
    ];
    private const CLES_PORTE = ['x', 'y', 'cote', 'type'];
    private const CLES_CASE = ['x', 'y'];
    private const CLES_MOBILIER = ['slug', 'x', 'y', 'rotation'];
    private const CLES_POSE = ['slug', 'x', 'y'];
    private const CLES_RECOMPENSE = ['or', 'partage'];

    /** Clés déjà prévues par `docs/plan-mode-classique.md` §3, pas encore lues : le lot qui les prendra. */
    private const LOTS_A_VENIR = ['notes' => 'K3', 'regles' => 'K3', 'fin' => 'K3', 'suite' => 'K4'];

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

    /** @var array<string, true> embrasures déjà ouvertes par une porte de la quête en cours : « x,y » */
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
            } elseif ($this->dimensions !== null && ($x + $l + 1 > $this->dimensions[0] || $y + $h + 1 > $this->dimensions[1]
                || $x < 1 || $y < 1)) {
                // Lot K2 : l'intérieur tient sur le plateau, mais son ANNEAU de murs (une case de plus, tout autour) non.
                $this->refuser($chemin, self::ANNEAU_HORS_PLATEAU,
                    "l'anneau de mur de la salle « $id » sort du plateau (une salle reste à une case du bord)");
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
        $this->anneauxSurSalles();
    }

    /**
     * Lot K2 : l'anneau de mur d'une salle ne recouvre JAMAIS l'intérieur d'une autre (`anneau_sur_salle`). Deux anneaux
     * peuvent se partager des cases ; un anneau sur l'intérieur d'une autre salle ferait de celle-ci de la roche.
     * Testé dans les DEUX sens (A sur B, puis B sur A). Deux intérieurs qui se chevauchent sont déjà refusés en amont.
     */
    private function anneauxSurSalles(): void
    {
        foreach ($this->salles as $a) {
            foreach ($this->salles as $b) {
                if ($a['id'] === $b['id'] || $this->interieursSeChevauchent($a, $b)) {
                    continue;
                }
                // Intersection de l'anneau de A (le rectangle A élargi d'une case) avec l'intérieur de B.
                $recouvre = $a['x'] - 1 <= $b['x'] + $b['l'] - 1 && $b['x'] <= $a['x'] + $a['l']
                    && $a['y'] - 1 <= $b['y'] + $b['h'] - 1 && $b['y'] <= $a['y'] + $a['h'];
                if ($recouvre) {
                    $this->refuser("plateau.salles.{$a['i']}", self::ANNEAU_SUR_SALLE,
                        "l'anneau de mur de la salle « {$a['id']} » recouvre l'intérieur de la salle « {$b['id']} »");
                }
            }
        }
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
            $this->cles($quete, $chemin, self::CLES_QUETE);

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
            $this->poses($quete, $chemin, 'mobilier', 'mobilier', tourne: true);
            $this->poses($quete, $chemin, 'monstres', 'monstres');
            $this->poses($quete, $chemin, 'pieges', 'pieges');
            $this->poses($quete, $chemin, 'terrain', 'terrain');
            $this->recompense($quete, $chemin);
        }
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
     * `portes` (lot K2 — la géométrie du moteur, `docs/contrat-api.md` « Géométrie »). Une porte se pose sur une case
     * d'INTÉRIEUR d'une salle ouverte ; son embrasure est la case juste au-delà du côté, sur l'anneau de la salle.
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
     * La case d'intérieur (x, y) est dans une salle OUVERTE. E, la case juste au-delà du côté, est l'embrasure ; elle ne
     * peut pas être dans la même salle (`porte_sans_bord`). O, la case juste dehors, doit être du COULOIR ou l'intérieur
     * d'une autre salle ouverte, sinon la porte donne dans la roche (`porte_dans_la_roche`). Une O hors plateau reste
     * `porte_sans_bord`, comme en K1. Deux portes sur une même embrasure : `case_partagee`.
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

        [$dx, $dy] = self::DECALAGES[$cote];
        [$ex, $ey] = [$x + $dx, $y + $dy];
        [$ox, $oy] = [$x + 2 * $dx, $y + 2 * $dy];
        if (!$this->dansPlateau($ox, $oy)) {
            $this->refuser($position, self::PORTE_SANS_BORD, "le côté « $cote » donne hors du plateau");

            return;
        }
        if ($this->salleEn($ex, $ey) === $salle) {
            $this->refuser($position, self::PORTE_SANS_BORD, "le côté « $cote » reste dans la même salle");

            return;
        }

        $voisine = $this->salleEn($ox, $oy);
        $dansLaRoche = $voisine !== null ? !isset($this->ouvertes[$voisine]) : $this->dansUnAnneau($ox, $oy);
        if ($dansLaRoche) {
            $this->refuser($position, self::PORTE_DANS_LA_ROCHE,
                "le côté « $cote » donne sur de la roche : une salle fermée, ou le mur d'une salle qui n'est pas partagée");

            return;
        }

        if (isset($this->embrasures["$ex,$ey"])) {
            $this->refuser($position, self::CASE_PARTAGEE,
                "une autre porte ouvre déjà l'embrasure ($ex, $ey)");
        }
        $this->embrasures["$ex,$ey"] = true;
    }

    /**
     * `blocs` : cases de COULOIR, c'est-à-dire hors de toute salle (ouverte ou non) et hors de tout anneau (lot K2 :
     * `pose_sur_mur` sur un anneau). Un bloc ne partage sa case avec rien.
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
            } elseif ($this->dansUnAnneau($x, $y)) {
                $this->refuser($position, self::POSE_SUR_MUR, "un bloc se pose sur l'anneau de mur d'une salle");
            } else {
                $this->occuper([[$x, $y]], 'bloc', $position, 'le bloc');
            }
        }
    }

    /**
     * `mobilier`, `monstres`, `pieges`, `terrain` : un slug de catalogue (`catalogue_inconnu`), une case hors salle fermée
     * (`dans_salle_fermee`), un anneau de mur (`pose_sur_mur`, sauf pour le mobilier : son emprise répond à part, par
     * `mobilier_hors_salle`), et un partage de case (`case_partagee`). Seul un élément VALIDE occupe sa case.
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

            if ($couche !== 'mobilier' && $this->dansUnAnneau($x, $y)) {
                $this->refuser($position, self::POSE_SUR_MUR, "$nom tombe sur l'anneau de mur d'une salle");
                continue;
            }
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
        $position = self::chemin($chemin, 'depart');
        $depart = $this->enObjet($quete['depart'], $position);
        if ($depart === null) {
            return;
        }
        $this->cles($depart, $position, self::CLES_CASE);

        $cellule = $this->cellule($depart, $position);
        if ($cellule === null) {
            return;
        }
        [$x, $y] = $cellule;
        if ($this->salleFermeeEn($x, $y)) {
            $this->refuser($position, self::DEPART_INVALIDE, 'le départ tombe dans une salle fermée');

            return;
        }
        if (!$this->ouvertesFiables) {
            return;
        }

        $escalier = [[$x, $y], [$x + 1, $y], [$x, $y + 1], [$x + 1, $y + 1]];
        $salle = $this->salleEn($x, $y);
        $tient = $salle !== null && isset($this->ouvertes[$salle]);
        foreach ($escalier as [$cx, $cy]) {
            $tient = $tient && $this->salleEn($cx, $cy) === $salle;
        }
        if (!$tient) {
            $this->refuser($position, self::DEPART_HORS_SALLE,
                "le bloc 2×2 du départ doit tenir entier dans une salle ouverte (le départ depuis un couloir arrive avec le lot K5)");

            return;
        }
        $this->occuper($escalier, 'escalier', $position, "l'escalier de départ");
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

    /** Une case d'ANNEAU : dans le rectangle moteur d'une salle (mur compris), et pas dans son intérieur. */
    private function dansUnAnneau(int $x, int $y): bool
    {
        foreach ($this->salles as $salle) {
            $dansLeRectangle = $x >= $salle['x'] - 1 && $x <= $salle['x'] + $salle['l']
                && $y >= $salle['y'] - 1 && $y <= $salle['y'] + $salle['h'];
            $dansLInterieur = $x >= $salle['x'] && $x < $salle['x'] + $salle['l']
                && $y >= $salle['y'] && $y < $salle['y'] + $salle['h'];
            if ($dansLeRectangle && ! $dansLInterieur) {
                return true;
            }
        }

        return false;
    }

    /** `recompense` : `null` (aucune), ou `{or, partage}`. */
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
    private function cles(array $objet, string $chemin, array $attendues): void
    {
        foreach (array_keys($objet) as $cle) {
            $cle = (string) $cle;
            if (in_array($cle, $attendues, true)) {
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
