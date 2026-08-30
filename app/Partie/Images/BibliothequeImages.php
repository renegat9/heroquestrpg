<?php

declare(strict_types=1);

namespace App\Partie\Images;

use App\Partie\Audio\BanqueBarks;
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

    public function urlMobilier(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('mobiliers', $id, (string) $nom)) ?? $this->vignette('mobilier', $id)
            : null;
    }

    public function urlEpreuve(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('epreuves', $id, (string) $nom)) ?? $this->vignette('epreuve', $id)
            : null;
    }

    public function urlSort(?int $id, ?string $nom): ?string
    {
        return $id
            ? $this->url($this->relatifCatalogue('sorts', $id, (string) $nom)) ?? $this->vignette('sort', $id)
            : null;
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

    /** Écrit un asset (crée le dossier) ; renvoie son URL publique. */
    public function enregistrer(string $rel, string $octets): string
    {
        $absolu = public_path("images/{$rel}");
        if (! is_dir(dirname($absolu))) {
            mkdir(dirname($absolu), 0775, true);
        }
        file_put_contents($absolu, $octets);

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
