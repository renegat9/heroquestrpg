<?php

declare(strict_types=1);

namespace App\Partie\Narration;

/**
 * Répliques scriptées du narrateur (config/narration.php) : cérémonie de
 * lancement + repli par temps fort, avec variantes. Renvoie toujours le TEXTE
 * et, si l'asset a été pré-généré, l'URL audio de la vraie voix de narrateur
 * (sinon null → lecture navigateur côté table).
 *
 * Gère aussi le chemin de cache de la narration DYNAMIQUE de l'IA (synthèse au
 * vol par GenererNarration), indexée par hash du texte.
 */
final class BibliothequeNarration
{
    /**
     * Réplique de cérémonie de lancement (variante aléatoire).
     *
     * @return array{cle: string, texte: string, ambiance: string, url: ?string}
     */
    public function lancement(): array
    {
        $variantes = array_values((array) config('narration.lancement.variantes', []));
        $ambiance = (string) config('narration.lancement.ambiance', 'epique');
        $index = $variantes === [] ? 0 : array_rand($variantes);

        return [
            'cle' => 'lancement',
            'texte' => (string) ($variantes[$index] ?? ''),
            'ambiance' => $ambiance,
            'url' => $this->urlScript('lancement', $index),
        ];
    }

    /**
     * Réplique de repli pour un temps fort (variante aléatoire), ou null si la
     * clé est inconnue.
     *
     * @return array{cle: string, texte: string, ambiance: string, url: ?string}|null
     */
    public function repli(string $cle): ?array
    {
        $variantes = array_values((array) config("narration.repli.{$cle}.variantes", []));

        if ($variantes === []) {
            return null;
        }

        $index = array_rand($variantes);

        return [
            'cle' => $cle,
            'texte' => (string) $variantes[$index],
            'ambiance' => (string) config("narration.repli.{$cle}.ambiance", 'tension'),
            'url' => $this->urlScript($cle, $index),
        ];
    }

    /** URL publique de l'audio scripté (cle/index) s'il existe, sinon null. */
    public function urlScript(string $cle, int $index): ?string
    {
        $rel = "narration/{$cle}/{$index}.wav";

        return is_file(public_path("audio/{$rel}")) ? "/audio/{$rel}" : null;
    }

    /**
     * Chemin de cache de la narration dynamique de l'IA (par hash du texte).
     *
     * @return array{rel: string, absolu: string, url: string}
     */
    public function cheminDynamique(string $texte): array
    {
        $hash = sha1($texte);
        $rel = "narration/dyn/{$hash}.wav";

        return [
            'rel' => $rel,
            'absolu' => public_path("audio/{$rel}"),
            'url' => "/audio/{$rel}",
        ];
    }

    /** URL de la narration dynamique si déjà en cache, sinon null. */
    public function urlDynamiqueSiCache(string $texte): ?string
    {
        $c = $this->cheminDynamique($texte);

        return is_file($c['absolu']) ? $c['url'] : null;
    }
}
