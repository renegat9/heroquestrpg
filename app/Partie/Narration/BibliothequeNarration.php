<?php

declare(strict_types=1);

namespace App\Partie\Narration;

use App\Agent\Audio\TtsGemini;
use Illuminate\Support\Facades\Log;

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

    /**
     * Vraie voix de narrateur pour un texte DYNAMIQUE (narration IA, prémisse) :
     * renvoie l'URL du cache si présent, sinon synthétise (Gemini, voix
     * narrateur), met en cache et renvoie l'URL. Best-effort : sans clé /
     * désactivé / sur échec → null (lecture navigateur côté table).
     */
    public function voixDynamique(string $texte, TtsGemini $tts): ?string
    {
        if (! config('narration.voix_dynamique', true) || ! $tts->estConfigure()) {
            return null;
        }

        if (($cache = $this->urlDynamiqueSiCache($texte)) !== null) {
            return $cache;
        }

        $voix = (string) config('narration.voix.voix', 'Iapetus');
        $style = (string) config('narration.voix.style', 'une voix de conteur, maître de jeu');

        try {
            $wav = $tts->synthetiser($texte, $voix, $style);
        } catch (\Throwable $e) {
            Log::warning('Synthèse voix narrateur (dynamique) impossible — lecture navigateur.', [
                'erreur' => $e->getMessage(),
            ]);

            return null;
        }

        $cible = $this->cheminDynamique($texte);
        $dossier = dirname($cible['absolu']);

        if (! is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }

        file_put_contents($cible['absolu'], $wav);

        return $cible['url'];
    }
}
