<?php

declare(strict_types=1);

namespace App\Partie\Images;

use Illuminate\Support\Facades\Log;

/**
 * Produit le jumeau **.webp** d'une image générée, au moment où elle est écrite.
 *
 * ⚠ Pourquoi la conversion vit ICI, et non dans un script à relancer : un geste
 * à penser est un geste oublié. `image-tools/webp.sh` se lance APRÈS coup, et
 * les illustrations des quêtes 98 et 99 sont restées servies en 1,3 Mo là où 52
 * et 71 Ko suffisaient (René, 2026-09-13 : « ne faudrait-il pas toujours
 * convertir les images quand elles sont générées ? »). C'est le même défaut de
 * forme que le ménage des campagnes de harnais : ce qui manquait n'était pas un
 * moyen de savoir QUOI convertir, c'était le geste lui-même.
 *
 * ⚠ **GD, pas un binaire externe** (René, 2026-09-13 : « pourquoi tu
 * n'installes pas php avec gd ou imagick ? »). La première version lançait
 * `cwebp` en sous-processus, au motif de garder le MÊME encodeur
 * qu'`image-tools/webp.sh`. Mesuré sur une vraie illustration : GD et cwebp
 * rendent des fichiers **rigoureusement identiques** — 74 706 octets des deux
 * côtés — parce que GD encode le WebP avec libwebp, exactement comme cwebp.
 * L'argument de continuité ne tenait donc pas, et il restait un sous-processus,
 * un chemin de binaire à sonder et un délai d'attente à gérer pour rien.
 *
 * ⚠ **Imagick, non** : ImageMagick entier plus une compilation PECL, pour
 * convertir un PNG en WebP. GD fait exactement ce qu'il faut, et rien de plus.
 *
 * ⚠ **BEST-EFFORT, jamais bloquant.** Sans support WebP dans GD — image pas
 * reconstruite, ou suite de tests dans un conteneur `composer:2` qui n'a même
 * pas GD — on renvoie `false` et l'appelant garde son PNG.
 * {@see BibliothequeImages::url()} sert le webp quand il existe et retombe sur
 * le PNG sinon : il n'y a rien à casser, il n'y a qu'un gain à ne pas prendre.
 * Une génération d'image ne doit jamais échouer parce que la compression manque.
 *
 * `image-tools/webp.sh` n'est pas remplacé : il **rattrape** le parc déjà écrit
 * et rejoue une qualité différente (`--force`). Il tourne dans un conteneur
 * alpine jetable, côté hôte, donc il garde son `cwebp` — les deux produisent le
 * même octet, la mesure ci-dessus le dit.
 *
 * Volontairement NON `final` : la suite tournant sans GD, elle vérifie que le
 * convertisseur est APPELÉ — via un espion qui hérite d'ici — plutôt que
 * l'existence du fichier.
 */
class ConvertisseurWebp
{
    /**
     * Qualité 85 — la même que `image-tools/webp.sh`, qui backfille exactement
     * les mêmes fichiers : deux valeurs différentes rendraient le parc bigarré
     * selon qu'un fichier a été converti à l'écriture ou après coup. Calibrée le
     * 2026-08-22 : en dessous de 80 les aplats se dégradent, au-dessus de 90 le
     * fichier triple sans gain visible à la taille d'affichage.
     */
    public const QUALITE = 85;

    public function disponible(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromstring');
    }

    /**
     * Écrit `<image>.webp` à côté de `<image>.png`.
     *
     * @param  string  $absolu  chemin absolu de l'image source
     * @return bool  vrai si le jumeau a été écrit
     */
    public function jumeler(string $absolu): bool
    {
        if (! $this->disponible() || ! is_file($absolu)) {
            return false;
        }

        $jumeau = preg_replace('/\.[^.\/]+$/', '', $absolu).'.webp';
        $image = null;

        try {
            // `imagecreatefromstring` reconnaît le format tout seul : le
            // catalogue est en PNG (BibliothequeImages::FORMAT), mais rien ici
            // n'a besoin de le savoir.
            $image = @imagecreatefromstring((string) file_get_contents($absolu));

            if ($image === false) {
                Log::info('Jumeau .webp impossible — image source illisible.', [
                    'image' => basename($absolu),
                ]);

                return false;
            }

            // ⚠ Deux gestes obligatoires avant d'encoder, et silencieux si on
            // les oublie : un PNG en palette indexée ne s'encode pas en WebP,
            // et sans `savealpha` la transparence ressort en noir — nos
            // illustrations d'objets et de portes en ont.
            imagepalettetotruecolor($image);
            imagealphablending($image, false);
            imagesavealpha($image, true);

            if (! imagewebp($image, $jumeau, self::QUALITE)) {
                return false;
            }
        } catch (\Throwable $e) {
            Log::info('Jumeau .webp impossible — le PNG est servi tel quel.', [
                'image' => basename($absolu),
                'erreur' => $e->getMessage(),
            ]);

            return false;
        } finally {
            if ($image instanceof \GdImage) {
                imagedestroy($image);
            }
        }

        return is_file($jumeau);
    }
}
