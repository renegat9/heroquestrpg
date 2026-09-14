<?php

declare(strict_types=1);

namespace App\Partie\Images;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Produit le jumeau **.webp** d'une image générée, au moment où elle est écrite.
 *
 * ⚠ PHP n'a ici NI gd NI imagick (`docker/app/Dockerfile`) : la conversion passe
 * par le binaire `cwebp` de `libwebp-tools`, ajouté à l'image pour cela. C'est
 * la même contrainte qui avait fait d'`image-tools/webp.sh` un script shell
 * plutôt qu'une commande artisan — sauf qu'un script est une **étape**, et
 * qu'une étape s'oublie : les illustrations des quêtes 98 et 99 sont restées
 * sans jumeau jusqu'au 2026-09-13, servies en 1,3 Mo là où 52 et 71 Ko
 * suffisaient (René : « ne faudrait-il pas toujours convertir les images quand
 * elles sont générées ? »). C'est le même défaut de forme que le ménage des
 * campagnes de harnais : le manque n'était pas un moyen de savoir QUOI
 * convertir, c'était le geste lui-même.
 *
 * ⚠ **BEST-EFFORT, jamais bloquant.** Sans le binaire — image pas encore
 * reconstruite, suite de tests dans un conteneur `composer:2` — on renvoie
 * `false` et l'appelant garde son PNG. `BibliothequeImages::url()` sert le webp
 * quand il existe et retombe sur le PNG sinon : il n'y a rien à casser, il n'y a
 * qu'un gain à ne pas prendre. Une génération d'image ne doit jamais échouer
 * parce qu'un outil de compression manque.
 *
 * `image-tools/webp.sh` reste utile et n'est pas remplacé : il **rattrape** le
 * parc déjà écrit, et sert à rejouer une qualité différente (`--force`).
 *
 * Volontairement NON `final` : la suite tournant sans `cwebp`, elle vérifie que
 * le convertisseur est APPELÉ — via un espion qui hérite d'ici — plutôt que
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

    /** Secondes : une image de 1024×1024 se convertit en bien moins que ça. */
    private const DELAI = 20;

    public function disponible(): bool
    {
        return $this->binaire() !== null;
    }

    /**
     * Écrit `<image>.webp` à côté de `<image>.png`.
     *
     * @param  string  $absolu  chemin absolu de l'image source
     * @return bool  vrai si le jumeau a été écrit
     */
    public function jumeler(string $absolu): bool
    {
        $binaire = $this->binaire();

        if ($binaire === null || ! is_file($absolu)) {
            return false;
        }

        $jumeau = preg_replace('/\.[^.\/]+$/', '', $absolu).'.webp';

        try {
            $process = new Process([
                $binaire, '-q', (string) self::QUALITE, '-quiet', $absolu, '-o', $jumeau,
            ]);
            $process->setTimeout(self::DELAI);
            $process->run();

            if (! $process->isSuccessful()) {
                // Pas un Log::warning bruyant : l'absence de jumeau ne casse
                // rien, et une génération de catalogue en produit des centaines.
                Log::info('Jumeau .webp impossible — le PNG est servi tel quel.', [
                    'image' => basename($absolu),
                    'erreur' => trim($process->getErrorOutput()),
                ]);

                return false;
            }
        } catch (\Throwable $e) {
            Log::info('Jumeau .webp impossible — le PNG est servi tel quel.', [
                'image' => basename($absolu),
                'erreur' => $e->getMessage(),
            ]);

            return false;
        }

        return is_file($jumeau);
    }

    /**
     * Chemin du binaire `cwebp`, ou null s'il n'est pas installé.
     *
     * Résolu à chaque appel plutôt que mémorisé : un worker `queue:work` vit des
     * heures, et l'image peut être reconstruite sous lui.
     */
    private function binaire(): ?string
    {
        foreach (['/usr/bin/cwebp', '/usr/local/bin/cwebp', '/bin/cwebp'] as $chemin) {
            if (is_executable($chemin)) {
                return $chemin;
            }
        }

        return null;
    }
}
