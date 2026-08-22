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
        return $classe ? $this->url($this->relatifClasse($classe)) : null;
    }

    public function urlMonstreCatalogue(?int $id, ?string $nomBase): ?string
    {
        return $id ? $this->url($this->relatifCatalogue('monstres', $id, (string) $nomBase)) : null;
    }

    public function urlObjet(?int $id, ?string $nom): ?string
    {
        return $id ? $this->url($this->relatifCatalogue('objets', $id, (string) $nom)) : null;
    }

    public function urlPiege(?int $id, ?string $nom): ?string
    {
        return $id ? $this->url($this->relatifCatalogue('pieges', $id, (string) $nom)) : null;
    }

    public function urlSort(?int $id, ?string $nom): ?string
    {
        return $id ? $this->url($this->relatifCatalogue('sorts', $id, (string) $nom)) : null;
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
     * Comme {@see self::urlDyn()}, mais ne rend JAMAIS `null` : à défaut
     * d'illustration générée, une vignette SVG de remplacement.
     *
     * Réservée aux endroits où un cadre vide se lit comme un bug — la carte
     * d'ouverture de quête, le panneau de hub. Ailleurs, `urlDyn()` et son
     * `null` gardent leur sens : un portrait de héros absent retombe sur
     * l'illustration de classe, ce qui vaut mieux qu'un emblème générique.
     *
     * Le jeu tourne sans clé d'IA — c'est une règle du projet, pas un mode
     * dégradé — et les crédits peuvent s'épuiser en pleine campagne : ces
     * cadres-là doivent tenir debout sans image.
     */
    public function urlDynOuVignette(string $sousType, int|string $id): string
    {
        return $this->urlDyn($sousType, $id) ?? "/api/placeholder/{$sousType}/{$id}";
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
     * Portrait d'un héros : portrait UNIQUE (dyn/perso) s'il existe, sinon
     * l'image de CLASSE par défaut, sinon null (→ icône côté front).
     */
    public function urlHeros(int $personnageId, ?string $classe): ?string
    {
        return $this->urlDyn('perso', $personnageId) ?? $this->urlClasse($classe);
    }

    /**
     * Image d'un monstre : portrait de BOSS dynamique (dyn/monstre par instance)
     * s'il existe, sinon l'image de CATALOGUE de l'archétype, sinon null.
     */
    public function urlMonstre(int $instanceId, ?int $monstreId, ?string $nomBase): ?string
    {
        return $this->urlDyn('monstre', $instanceId) ?? $this->urlMonstreCatalogue($monstreId, $nomBase);
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
