<?php

declare(strict_types=1);

namespace App\Partie\Classique;

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

    /** @var list<string> */
    public const CODES = [
        self::CLE_INCONNUE, self::CLE_PAS_ENCORE_PRISE, self::CLE_MANQUANTE, self::TYPE_INVALIDE,
        self::VALEUR_INVALIDE, self::FORMAT_INCONNU, self::SLUG_INVALIDE, self::SLUG_EN_DOUBLE,
        self::SALLE_HORS_PLATEAU, self::SALLE_ID_EN_DOUBLE, self::SALLES_SE_CHEVAUCHENT, self::SALLE_INCONNUE,
        self::CATALOGUE_INCONNU, self::CLASSE_INCONNUE, self::HORS_PLATEAU, self::DANS_SALLE_FERMEE,
        self::BLOC_HORS_COULOIR, self::DEPART_INVALIDE, self::PORTE_SANS_BORD, self::SLUG_NON_CONCORDANT,
        self::EMPREINTE_INVALIDE,
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

    /** @var list<array{id: string, x: int, y: int, l: int, h: int}> */
    private array $salles = [];

    /** @var array<string, true> ids des salles ouvertes de la quête en cours de contrôle */
    private array $ouvertes = [];

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

            $rectangles[] = ['id' => $id, 'x' => $x, 'y' => $y, 'l' => $l, 'h' => $h];
        }

        $this->salles = $rectangles;
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

            $this->sallesOuvertes($quete, $chemin);
            $this->portes($quete, $chemin);
            $this->blocs($quete, $chemin);
            $this->poses($quete, $chemin, 'mobilier', 'mobilier', tourne: true);
            $this->poses($quete, $chemin, 'monstres', 'monstres');
            $this->poses($quete, $chemin, 'pieges', 'pieges');
            $this->poses($quete, $chemin, 'terrain', 'terrain');
            $this->depart($quete, $chemin);
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

    /** `portes` : sur une case d'une salle ouverte, dont le côté mène à une case du plateau qui n'est pas dans la même salle. */
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
        $vx = $x + $dx;
        $vy = $y + $dy;
        if (!$this->dansPlateau($vx, $vy)) {
            $this->refuser($position, self::PORTE_SANS_BORD, "le côté « $cote » donne hors du plateau");
        } elseif ($this->salleEn($vx, $vy) === $salle) {
            $this->refuser($position, self::PORTE_SANS_BORD, "le côté « $cote » reste dans la même salle");
        }
    }

    /** `blocs` : cases de COULOIR — hors de toute salle, ouverte ou non. */
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
            if ($cellule !== null && $this->salleEn($cellule[0], $cellule[1]) !== null) {
                $this->refuser($position, self::BLOC_HORS_COULOIR,
                    'un bloc se pose sur une case de couloir, pas dans une salle');
            }
        }
    }

    /** `mobilier`, `monstres`, `pieges`, `terrain` : un slug de catalogue, une case hors salle fermée. */
    private function poses(array $quete, string $chemin, string $cle, string $couche, bool $tourne = false): void
    {
        foreach ($this->enListeDe($quete, $cle, $chemin) as $i => $valeur) {
            $position = self::chemin($chemin, $cle).".$i";
            $pose = $this->enObjet($valeur, $position);
            if ($pose === null) {
                continue;
            }
            $this->cles($pose, $position, $tourne ? self::CLES_MOBILIER : self::CLES_POSE);

            $this->slugCatalogue($pose, 'slug', $position, $couche);
            if ($tourne) {
                $this->dans($pose, 'rotation', $position, self::ROTATIONS);
            }

            $cellule = $this->cellule($pose, $position);
            if ($cellule !== null && $this->salleFermeeEn($cellule[0], $cellule[1])) {
                $nom = is_string($pose['slug'] ?? null) ? "« {$pose['slug']} »" : 'cette pose';
                $this->refuser($position, self::DANS_SALLE_FERMEE, "$nom tombe dans une salle fermée");
            }
        }
    }

    /** `depart` : dans une salle ouverte ou dans un couloir. */
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
        if ($cellule !== null && $this->salleFermeeEn($cellule[0], $cellule[1])) {
            $this->refuser($position, self::DEPART_INVALIDE, 'le départ tombe dans une salle fermée');
        }
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
