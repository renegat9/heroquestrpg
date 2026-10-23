<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\Audio\TtsGemini;
use App\Partie\Audio\BanqueAudio;
use App\Partie\Narration\BibliothequeNarration;
use Illuminate\Console\Command;

/**
 * Génère la VRAIE VOIX DE NARRATEUR pour les répliques scriptées
 * (config/narration.php : cérémonie de lancement + repli par temps fort) en
 * fichiers audio Gemini TTS, dans public/audio/narration/{cle}/{index}.wav.
 *
 * Étape HORS-LIGNE, une fois (ou après édition du catalogue). Sans
 * GEMINI_API_KEY : ne fait rien ; l'écran de table lit alors le texte via
 * Web Speech. Les descriptions de salles PRÉ-GÉNÉRÉES par quête, elles, sont
 * synthétisées par le job `GenererVoixQuete` (pas par cette commande) — et
 * elles seules : depuis la bascule du 2026-08-18, les répliques à
 * placeholders ne sont jamais synthétisées, leur texte n'existant qu'une
 * fois substitué au runtime.
 *
 * Chaque fichier porte l'empreinte du texte qu'il prononce (`BanqueAudio`) : une
 * réplique retouchée est régénérée, une réplique retirée ou à variables voit son
 * fichier supprimé. Le premier passage ADOPTE les fichiers d'avant ce dispositif.
 *
 *   php artisan narration:generer [--force]
 */
final class GenererNarrationAudio extends Command
{
    protected $signature = 'narration:generer {--force : Régénère même les fichiers à jour}';

    protected $description = 'Génère la voix de narrateur des répliques scriptées (audio Gemini TTS) dans public/audio/narration';

    public function handle(TtsGemini $tts, BibliothequeNarration $lib): int
    {
        if (! $tts->estConfigure()) {
            $this->warn('GEMINI_API_KEY absente : aucune génération. Le narrateur sera lu via Web Speech.');

            return self::SUCCESS;
        }

        // Surcharge du panneau Réglages (narration_voix) : CONTRAIREMENT aux
        // illustrations, cette commande OFFLINE doit elle aussi la respecter —
        // c'est le seul moyen de propager un changement de voix aux répliques
        // scriptées pré-générées (public/audio/narration/{cle}/{i}.wav).
        $voix = $lib->voixNarrateur();
        $style = (string) config('narration.voix.style', 'une voix de conteur, maître de jeu');
        $force = (bool) $this->option('force');

        // Cérémonie de lancement + tous les temps forts de repli.
        $groupes = ['lancement' => (array) config('narration.lancement.variantes', [])];
        foreach ((array) config('narration.repli', []) as $cle => $def) {
            $groupes[$cle] = (array) ($def['variantes'] ?? []);
        }

        // Texte → fichier par EMPREINTE (`BanqueAudio`) : ce qui a changé est
        // régénéré, ce qui ne correspond plus à rien est supprimé, une réplique à
        // variables n'a jamais d'audio. Plus de `rm -r` à la main après une retouche.
        $bilan = BanqueAudio::synchroniser(
            'narration',
            $groupes,
            fn (string $cle, string $texte) => $tts->synthetiser($texte, $voix, $style),
            $force,
            fn (string $ligne) => str_starts_with($ligne, '✗') ? $this->error($ligne) : $this->line($ligne),
        );

        $this->info(BanqueAudio::resume('Narration générée', $bilan));
        $echecs = $bilan['echecs'];

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
