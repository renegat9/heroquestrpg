<?php

declare(strict_types=1);

namespace App\Partie\Images;

use App\Partie\Audio\BanqueBarks;
use App\Partie\MoteurDanger;
use App\Partie\MoteurPieges;
use App\Partie\MoteurPortes;
use Illuminate\Support\Str;

/**
 * Résolveur d'images du jeu : transforme une entité en URL d'asset (ou null si
 * l'image n'a pas été générée → le front retombe sur l'icône). Tout est basé
 * sur l'EXISTENCE de fichiers sous `public/images/` (aucune colonne DB) — comme
 * l'audio (cf. {@see BanqueBarks}).
 *
 * Disposition :
 *  - FIXE (catalogue, pré-généré)   : images/catalogue/{type}/{id}-{slug}.png
 *                                     images/catalogue/classes/{classe}.png
 *  - DYNAMIQUE (jobs, en cache)     : images/dyn/{sousType}/{id}.png
 *      sousType : monstre (portrait de boss, clé = instance_id),
 *                 quete (scène, clé = quete_id), hub (clé = groupe_id),
 *                 perso (portrait unique, clé = personnage_id).
 */
final class BibliothequeImages
{
    public const FORMAT = 'png';

    /**
     * URL publique d'un relatif sous public/images, ou null si absent.
     *
     * Un jumeau **.webp** l'emporte quand il existe. Les images générées sont
     * des PNG de 1024×1024 pesant ~1,3 Mo pièce, affichées sur quelques dizaines
     * de pixels : trois d'entre elles suffisaient à faire télécharger 4 Mo à
     * l'écran de table, la machine la plus faible de la tablée (signalé par
     * René, 2026-08-07 — « la tablette prend vraiment du temps à charger »).
     *
     * La préférence est posée ICI plutôt que sur les chemins : aucun appelant ne
     * change, `FORMAT` reste `png` pour la génération, et un `.webp` absent
     * retombe simplement sur le PNG d'origine — la conversion est donc
     * réversible et peut rester partielle.
     */
    public function url(string $relatif): ?string
    {
        $relatif = ltrim($relatif, '/');
        $webp = preg_replace('/\.png$/i', '.webp', $relatif);

        if ($webp !== $relatif && is_file(public_path("images/{$webp}"))) {
            return "/images/{$webp}";
        }

        return is_file(public_path("images/{$relatif}"))
            ? "/images/{$relatif}"
            : null;
    }

    public static function slug(string $texte): string
    {
        return Str::slug($texte) ?: 'x';
    }

    // ---- Catalogue (fixe) -------------------------------------------------

    public function relatifCatalogue(string $type, int $id, string $nom): string
    {
        return "catalogue/{$type}/{$id}-".self::slug($nom).'.'.self::FORMAT;
    }

    public function relatifClasse(string $classe): string
    {
        return 'catalogue/classes/'.self::slug($classe).'.'.self::FORMAT;
    }

    public function urlClasse(?string $classe): ?string
    {
        return $classe
            ? $this->url($this->relatifClasse($classe)) ?? $this->vignette('classe', self::slug($classe))
            : null;
    }

    /**
     * Levier et porte n'ont PAS de table de catalogue — un levier n'est qu'un id
     * dans la grille, une porte une arête. Ils sont donc nommés comme les
     * classes, par un libellé fixe : il n'y a aucune ligne à numéroter.
     */
    public function relatifLevier(): string
    {
        return 'catalogue/leviers/levier.'.self::FORMAT;
    }

    public function urlLevier(): ?string
    {
        return $this->url($this->relatifLevier()) ?? $this->vignette('levier', 'levier');
    }

    public function relatifPorte(string $etat): string
    {
        return 'catalogue/portes/'.self::slug($etat).'.'.self::FORMAT;
    }

    /**
     * ⚠ Une image PAR ÉTAT : c'est l'état qui porte l'information (close,
     * verrouillée, dérobée), une image unique les rendrait indiscernables.
     * La graine du repli est l'état lui-même, donc deux états gardent deux
     * teintes différentes même sans illustration.
     */
    public function urlPorte(?string $etat): ?string
    {
        $etat = $etat ?: 'fermee';

        return $this->url($this->relatifPorte($etat)) ?? $this->vignette('porte', self::slug($etat));
    }

    public function urlMonstreCatalogue(?int $id, ?string $nomBase): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('monstres', $id, (string) $nomBase))
                ?? $this->vignette('monstre', $id)
            : null;
    }

    /**
     * ALLIÉ (2026-10-01) — le mercenaire du catalogue `mercenaires`. Aucune
     * illustration jusque-là : la scène d'attaque d'un allié montrait le
     * PORTRAIT DU HÉROS qui le contrôle sous le nom de l'allié (un loup qui
     * mord, avec la tête du barbare).
     */
    public function urlMercenaire(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('mercenaires', $id, (string) $nom)) ?? $this->vignette('allie', $id)
            : null;
    }

    public function urlObjet(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('objets', $id, (string) $nom)) ?? $this->vignette('objet', $id)
            : null;
    }

    public function urlPiege(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('pieges', $id, (string) $nom)) ?? $this->vignette('piege', $id)
            : null;
    }

    /**
     * Image d'un meuble, dans l'ordre : le DÉCOUPAGE DU LIVRET officiel s'il existe,
     * sinon l'illustration GÉNÉRÉE du catalogue, sinon l'emblème.
     *
     * ⚠ Le découpage est de l'art Hasbro : il vit SUR LA MACHINE qui l'a découpé, dans un
     * dossier que le dépôt (public) ne suit pas. Un dépôt fraîchement cloné n'en a aucun et
     * retombe sur le généré : rien ne casse.
     * ⚠ Le généré (vue de face, proportions fausses) ne sert plus qu'à défaut. Cet accesseur
     * est le seul point qui choisit l'image : aucun autre code ne lit ces dossiers.
     * ⚠ Le nom de fichier suit le slug du nom : renommer un meuble impose de refaire son découpage.
     */
    public function urlMobilier(?int $id, ?string $nom): ?string
    {
        if (! $id) {
            return null;
        }

        return $this->url($this->relatifLivretMobilier($id, (string) $nom))
            ?? $this->url($this->relatifCatalogue('mobiliers', $id, (string) $nom))
            ?? $this->vignette('mobilier', $id);
    }

    /** Découpage du livret officiel, vu du dessus : `livret/mobiliers/{id}-{slug}`, hors dépôt. */
    public function relatifLivretMobilier(int $id, string $nom): string
    {
        return "livret/mobiliers/{$id}-".self::slug($nom).'.'.self::FORMAT;
    }

    public function urlEpreuve(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('epreuves', $id, (string) $nom)) ?? $this->vignette('epreuve', $id)
            : null;
    }

    /** Terrain (doc 18 §4, phase 4a) — même patron que `urlMobilier()`/`urlEpreuve()`. */
    public function urlTerrain(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('terrains', $id, (string) $nom)) ?? $this->vignette('terrain', $id)
            : null;
    }

    public function urlSort(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('sorts', $id, (string) $nom)) ?? $this->vignette('sort', $id)
            : null;
    }

    /**
     * SORT DE DREAD (2026-10-16) — la magie du MJ, carte par carte. Même patron que
     * `urlSort()` : une image par ligne de `sorts_dread`, et l'emblème `sort_dread`
     * (un œil) en BOUT de chaîne. Un sort de Dread n'avait AUCUN chemin d'image : la
     * scène de table publiait l'emblème générique du genre `sort`, qui ne disait pas
     * que c'était la magie de Zargon.
     *
     * `null` seulement sans carte à montrer (`$id` absent) — jamais pour une carte
     * qui existe.
     */
    public function urlSortDread(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('sorts_dread', $id, (string) $nom)) ?? $this->vignette('sort_dread', $id)
            : null;
    }

    /**
     * Le RAPPORT d'emprise d'un meuble, EN MOTS, pour le gabarit `mobilier`.
     *
     * `largeur` est l'axe horizontal de la carte, `hauteur` sa profondeur — donc
     * l'axe vertical de l'image. Un chiffre (« 3 × 2 ») se fait peindre sur le meuble,
     * et Gemini étirait une table 3 × 2 jusqu'à 2,8 : 1 pour 1,5 : 1. La phrase donne
     * le rapport et le sens, et rien d'autre.
     */
    public static function formeMobilier(int $largeur, int $hauteur): string
    {
        $largeur = max(1, $largeur);
        $hauteur = max(1, $hauteur);

        if ($largeur === $hauteur) {
            return 'le meuble est carré : aussi large que haut';
        }

        $long = max($largeur, $hauteur);
        $court = min($largeur, $hauteur);
        $rapport = $long / $court;

        $multiple = match (true) {
            abs($rapport - 1.5) < 0.01 => 'une fois et demie',
            abs($rapport - 2) < 0.01 => 'deux fois',
            abs($rapport - 3) < 0.01 => 'trois fois',
            abs($rapport - 4) < 0.01 => 'quatre fois',
            default => str_replace('.', ',', rtrim(rtrim(number_format($rapport, 1, '.', ''), '0'), '.')).' fois',
        };

        // ⚠ « plus long que large » ne disait pas quel axe de l'IMAGE porte le long côté : la
        // table 3 × 2 est sortie DRESSÉE (verticale) alors qu'elle devait être couchée. D'où les
        // axes nommés, dans les mots de l'image.
        return $largeur > $hauteur
            ? "{$multiple} plus large que haut : son côté le plus long est HORIZONTAL dans l'image, de gauche à droite"
            : "{$multiple} plus haut que large : son côté le plus long est VERTICAL dans l'image, du haut vers le bas";
    }

    // ---- Symboles de livret : le PICTOGRAMME de chaque case (chantier symboles, 2026-10-24) ----

    /**
     * Les familles de la carte qui portent un symbole de livret : un dossier par famille sous
     * `public/images/livrets/`. FERMÉ — un type absent d'ici n'a pas de dossier, donc pas de symbole.
     * `monstres` viendra en phase 2 (la figurine d'un monstre n'est pas une case de carte).
     */
    public const TYPES_SYMBOLE = ['mobiliers', 'terrains', 'pieges', 'portes', 'leviers', 'escalier', 'dangers'];

    /** Clé de la porte de pierre encore fermée (`verrou.type` = `pierre` sur une porte `fermee`). */
    private const SYMBOLE_PORTE_PIERRE = 'verrou_pierre';

    /**
     * SYMBOLE DE LIVRET — le pictogramme officiel d'une case, tel qu'il est imprimé sur le livret de quête (René,
     * 2026-10-24 : « les vrais symboles des livrets à la place des icônes »).
     *
     * Un symbole n'est PAS une illustration. Il se dessine SUR la carte, dans la silhouette de sa famille ; l'illustration
     * reste dans la légende et l'aperçu de salle. Les fichiers vivent DANS le dépôt (René, 2026-10-24), mais le repli reste
     * obligatoire : le jeu doit rester lisible sans eux.
     *
     * ⚠ Absence = `null`, JAMAIS un emblème SVG : `null` veut dire « garde l'icône Material Symbols ». Un emblème ici
     * prétendrait « symbole manquant » là où aucun n'était attendu, et masquerait l'icône.
     * ⚠ Le nom du fichier est `Str::slug()` de la clé, comme pour tout le reste de la bibliothèque : `verrou_pierre` se lit
     * donc dans `verrou-pierre.webp`. Un fichier au nom littéral ne serait jamais servi.
     * ⚠ Seul le `.webp` est servi : c'est le jumeau que le jeu affiche, un `.png` seul rend `null`.
     */
    public function urlSymboleLivret(string $type, string $cle): ?string
    {
        $cle = trim($cle);

        if ($cle === '' || ! in_array($type, self::TYPES_SYMBOLE, true)) {
            return null;
        }

        $relatif = "livrets/{$type}/".self::slug($cle).'.webp';

        return is_file(public_path("images/{$relatif}")) ? "/images/{$relatif}" : null;
    }

    /** Mobilier : le symbole du NOM de catalogue (`Forge des nains` → `forge-des-nains`). */
    public function urlSymboleMobilier(?string $nom): ?string
    {
        return $nom === null ? null : $this->urlSymboleLivret('mobiliers', $nom);
    }

    /**
     * Terrain : le symbole du NOM de catalogue. Le choix de la case qui le porte (l'ancre d'une plaque, ou une case isolée)
     * n'est PAS ici : il est décidé par `EtatGroupe::terrain()`, où les ancres sont connues.
     */
    public function urlSymboleTerrain(?string $nom): ?string
    {
        return $nom === null ? null : $this->urlSymboleLivret('terrains', $nom);
    }

    /**
     * Piège : le symbole de son nom, dans les trois états où une ICÔNE se lit sur la case — détecté, désarmé, retenu par
     * des lianes. Un trou ouvert, une mare d'acide ou un bloc tombé n'en portent pas : la silhouette (trou, bloc) EST la
     * case, et le client n'affiche aucun glyphe pour ces états. Publier un symbole là serait une clé décorative.
     */
    public function urlSymbolePiege(?string $nom, string $etat): ?string
    {
        if ($nom === null || ! in_array($etat, [MoteurPieges::ETAT_DETECTE, MoteurPieges::ETAT_DESARME, MoteurPieges::ETAT_RETIENT], true)) {
            return null;
        }

        return $this->urlSymboleLivret('pieges', $nom);
    }

    /**
     * Danger mobile : le symbole de son nom TANT QU'IL ROULE. Un rocher arrêté est un bloc plein, obstacle permanent, sans
     * symbole — exactement comme la Chute de blocs déclenchée.
     */
    public function urlSymboleDanger(?string $nom, string $etat): ?string
    {
        if ($nom === null || $etat === MoteurDanger::ETAT_BLOC) {
            return null;
        }

        return $this->urlSymboleLivret('dangers', $nom);
    }

    /**
     * Porte : le symbole de son ÉTAT, une image par état comme `urlPorte()`. La porte de pierre encore fermée a le sien
     * (`verrou_pierre`) : une fois forcée, elle n'est plus qu'une porte ouverte.
     */
    public function urlSymbolePorte(string $etat, ?string $verrou): ?string
    {
        $cle = $etat === MoteurPortes::ETAT_FERMEE && $verrou === MoteurPortes::VERROU_PIERRE
            ? self::SYMBOLE_PORTE_PIERRE
            : $etat;

        return $this->urlSymboleLivret('portes', $cle);
    }

    /** Levier : un libellé fixe, puisqu'il n'a pas de table de catalogue (comme `urlLevier()`). */
    public function urlSymboleLevier(): ?string
    {
        return $this->urlSymboleLivret('leviers', 'levier');
    }

    /** Escalier d'entrée : un libellé fixe, comme `urlSymboleLevier()`. */
    public function urlSymboleEscalier(): ?string
    {
        return $this->urlSymboleLivret('escalier', 'escalier');
    }

    // ---- Dynamique (jobs / cache) ----------------------------------------

    public function relatifDyn(string $sousType, int|string $id): string
    {
        return "dyn/{$sousType}/{$id}.".self::FORMAT;
    }

    public function urlDyn(string $sousType, int|string $id): ?string
    {
        return $this->url($this->relatifDyn($sousType, $id));
    }

    /**
     * URL de la vignette SVG de remplacement (voir `PlaceholderController`).
     *
     * ⚠ Elle se pose TOUJOURS EN BOUT DE CHAÎNE, jamais au milieu : un
     * portrait de héros absent doit d'abord retomber sur l'illustration de sa
     * CLASSE, et un boss sans portrait sur l'image de son ARCHÉTYPE. Une vraie
     * image, même générique, vaut mieux qu'un emblème — c'est seulement quand
     * il n'en reste aucune que la vignette prend le relais.
     *
     * Le jeu tourne sans clé d'IA (règle du projet, pas mode dégradé) et les
     * crédits peuvent s'épuiser en pleine campagne : aucun cadre ne doit rester
     * vide pour autant.
     */
    public function vignette(string $type, int|string $graine): string
    {
        return "/api/placeholder/{$type}/{$graine}";
    }

    /** Comme {@see self::urlDyn()}, mais ne rend jamais `null`. */
    public function urlDynOuVignette(string $sousType, int|string $id): string
    {
        return $this->urlDyn($sousType, $id) ?? $this->vignette($sousType, $id);
    }

    /**
     * Chemins d'un asset dynamique (pour écriture par un job).
     *
     * @return array{rel: string, absolu: string, url: string}
     */
    public function cheminDyn(string $sousType, int|string $id): array
    {
        $rel = $this->relatifDyn($sousType, $id);

        return ['rel' => $rel, 'absolu' => public_path("images/{$rel}"), 'url' => "/images/{$rel}"];
    }

    /**
     * Écrit un asset (crée le dossier) ; renvoie son URL publique.
     *
     * ⚠ **Point de passage UNIQUE de toute image générée**, et c'est ce qui
     * permet au jumeau `.webp` de naître AVEC elle plutôt que d'être une étape
     * à penser après coup (`image-tools/webp.sh`). La suppression connaissait
     * déjà le jumeau — {@see self::effacerDyn()} efface les deux — ; seule la
     * création l'ignorait, et des illustrations de quête sont restées servies
     * en 1,3 Mo faute d'avoir relancé le script (René, 2026-09-13).
     *
     * La conversion est best-effort : sans support WebP dans GD, on garde le
     * PNG et {@see self::url()} le sert tel quel.
     */
    public function enregistrer(string $rel, string $octets): string
    {
        $absolu = public_path("images/{$rel}");
        if (! is_dir(dirname($absolu))) {
            mkdir(dirname($absolu), 0775, true);
        }
        file_put_contents($absolu, $octets);

        app(ConvertisseurWebp::class)->jumeler($absolu);

        return "/images/{$rel}";
    }

    /**
     * Efface l'illustration dynamique d'un sujet — le PNG **et son jumeau
     * .webp**.
     *
     * ⚠ Les deux, impérativement : `url()` fait gagner le `.webp` quand il
     * existe, donc n'effacer que le PNG laisserait le fichier servi en place et
     * ne corrigerait rien.
     *
     * Existe parce qu'un `dyn/{sousType}/{id}` est indexé sur une CLÉ
     * AUTO-INCRÉMENTÉE, et qu'InnoDB recalcule son compteur à `max(id)+1` au
     * redémarrage : après la purge d'une campagne, les ids repartent en
     * arrière et un sujet neuf hérite du fichier d'un sujet mort. Constaté le
     * 2026-08-22 — `quetes` en était à 43 quand le disque portait des scènes
     * jusqu'à 67, et le hub à 30 contre 89. Deux quêtes de test ont ouvert sur
     * l'illustration d'une campagne purgée depuis longtemps.
     *
     * @return bool `true` si au moins un fichier a été retiré.
     */
    public function supprimerDyn(string $sousType, int|string $id): bool
    {
        return $this->supprimer($this->relatifDyn($sousType, $id));
    }

    /**
     * Efface un relatif de `public/images` — le PNG **et son jumeau .webp**.
     *
     * Sert le catalogue autant que le dynamique : un fichier de catalogue porte
     * `{id}-{slug}`, donc RENOMMER une pièce en laisse un fantôme derrière elle
     * (14 objets fantômes comptés le 2026-08-22). Ce n'est pas le même défaut
     * que les ids recyclés — rien n'est réattribué — mais c'est le même déchet.
     *
     * @return bool `true` si au moins un fichier a été retiré.
     */
    public function supprimer(string $relatif): bool
    {
        $png = public_path('images/'.ltrim($relatif, '/'));
        $retire = false;

        foreach ([$png, preg_replace('/\.png$/i', '.webp', $png)] as $chemin) {
            if (is_file($chemin) && @unlink($chemin)) {
                $retire = true;
            }
        }

        return $retire;
    }

    /**
     * Portrait d'un héros : portrait UNIQUE (dyn/perso) s'il existe, sinon
     * l'image de CLASSE par défaut, sinon null (→ icône côté front).
     */
    public function urlHeros(int $personnageId, ?string $classe): string
    {
        return $this->urlDyn('perso', $personnageId)
            ?? $this->urlClasse($classe)
            ?? $this->vignette('heros', $personnageId);
    }

    /**
     * Image d'un monstre : portrait de BOSS dynamique (dyn/monstre par instance)
     * s'il existe, sinon l'image de CATALOGUE de l'archétype, sinon null.
     */
    public function urlMonstre(int $instanceId, ?int $monstreId, ?string $nomBase): string
    {
        return $this->urlDyn('monstre', $instanceId)
            ?? $this->urlMonstreCatalogue($monstreId, $nomBase)
            ?? $this->vignette('monstre', $instanceId);
    }

    // ---- Prompts (config/images.php) -------------------------------------

    /**
     * Construit le prompt d'un type donné en interpolant {style} + les champs.
     *
     * @param  array<string, string>  $champs
     */
    public function prompt(string $type, array $champs): string
    {
        $gabarit = (string) config("images.gabarits.{$type}", '{nom}. {style}');
        $champs['style'] = (string) config('images.style', '');

        return trim(strtr($gabarit, array_combine(
            array_map(fn ($k) => '{'.$k.'}', array_keys($champs)),
            array_values($champs),
        )));
    }

    /** Détail d'apparence d'une classe (pour le prompt). */
    public function detailClasse(string $classe): string
    {
        return (string) config('images.classes.'.Str::slug($classe), $classe);
    }
}
