<?php

declare(strict_types=1);

namespace App\Partie\Images;

use Illuminate\Support\Str;

/**
 * Résolveur d'images du jeu : transforme une entité en URL d'asset (ou null si
 * l'image n'a pas été générée → le front retombe sur l'icône). Tout est basé
 * sur l'EXISTENCE de fichiers sous `public/images/` (aucune colonne DB) — comme
 * l'audio (cf. {@see \App\Partie\Audio\BanqueBarks}).
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

    /** URL publique d'un relatif sous public/images, ou null si absent. */
    public function url(string $relatif): ?string
    {
        $relatif = ltrim($relatif, '/');

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
