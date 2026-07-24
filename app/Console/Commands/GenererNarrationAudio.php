<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\Audio\TtsGemini;
use App\Agent\Exceptions\AppelLlmException;
use App\Partie\Narration\BibliothequeNarration;
use Illuminate\Console\Command;

/**
 * Génère la VRAIE VOIX DE NARRATEUR pour les répliques scriptées
 * (config/narration.php : cérémonie de lancement + repli par temps fort) en
 * fichiers audio Gemini TTS, dans public/audio/narration/{cle}/{index}.wav.
 *
 * Étape HORS-LIGNE, une fois (ou après édition du catalogue). Sans
 * GEMINI_API_KEY : ne fait rien ; l'écran de table lit alors le texte via
 * Web Speech. La narration DYNAMIQUE de l'IA, elle, est synthétisée au vol
 * et mise en cache par le job GenererNarration (pas par cette commande).
 *
 *   php artisan narration:generer [--force]
 */
final class GenererNarrationAudio extends Command
{
    protected $signature = 'narration:generer {--force : Régénère même les fichiers déjà présents}';

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

        $faits = 0;
        $ignores = 0;
        $echecs = 0;

        foreach ($groupes as $cle => $variantes) {
            foreach (array_values($variantes) as $index => $texte) {
                $dossier = public_path("audio/narration/{$cle}");
                $fichier = "{$dossier}/{$index}.wav";

                if (! $force && is_file($fichier)) {
                    $ignores++;

                    continue;
                }

                try {
                    $wav = $tts->synthetiser((string) $texte, $voix, $style);
                } catch (AppelLlmException $e) {
                    $this->error("✗ {$cle}/{$index} : ".$e->getMessage());
                    $echecs++;

                    continue;
                }

                if (! is_dir($dossier)) {
                    mkdir($dossier, 0775, true);
                }

                file_put_contents($fichier, $wav);
                $this->line("✓ {$cle}/{$index}  «".mb_strimwidth((string) $texte, 0, 50, '…').'»');
                $faits++;
            }
        }

        $this->info("Narration générée : {$faits} · ignorés : {$ignores} · échecs : {$echecs}");

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
