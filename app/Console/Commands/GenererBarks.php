<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\Audio\TtsGemini;
use App\Agent\Exceptions\AppelLlmException;
use Illuminate\Console\Command;

/**
 * Génère la banque de barks d'archétype (config/barks.php) en fichiers audio
 * via Gemini TTS, dans public/audio/barks/{profil}/{evenement}/{index}.wav.
 *
 * Étape HORS-LIGNE, exécutée une fois (ou après édition du catalogue). Sans
 * GEMINI_API_KEY : ne fait rien et l'explique — le jeu reste jouable, l'écran
 * de table lit alors le TEXTE des barks via Web Speech.
 *
 *   php artisan barks:generer          # ne (re)génère que les fichiers absents
 *   php artisan barks:generer --force  # régénère tout
 */
final class GenererBarks extends Command
{
    protected $signature = 'barks:generer {--force : Régénère même les fichiers déjà présents}';

    protected $description = 'Génère la banque de barks de monstres (audio Gemini TTS) dans public/audio/barks';

    public function handle(TtsGemini $tts): int
    {
        if (! $tts->estConfigure()) {
            $this->warn('GEMINI_API_KEY absente : aucune génération. Le jeu lira le texte des barks via Web Speech.');

            return self::SUCCESS;
        }

        $profils = (array) config('barks.profils', []);
        $lignes = (array) config('barks.lignes', []);
        $force = (bool) $this->option('force');

        $faits = 0;
        $ignores = 0;
        $echecs = 0;

        foreach ($lignes as $profil => $parEvenement) {
            $voix = $profils[$profil]['voix'] ?? $profils['defaut']['voix'] ?? 'Fenrir';
            $style = $profils[$profil]['style'] ?? $profils['defaut']['style'] ?? 'une voix de monstre menaçant';

            foreach ((array) $parEvenement as $evenement => $variantes) {
                foreach (array_values((array) $variantes) as $index => $texte) {
                    $dossier = public_path("audio/barks/{$profil}/{$evenement}");
                    $fichier = "{$dossier}/{$index}.wav";

                    if (! $force && is_file($fichier)) {
                        $ignores++;

                        continue;
                    }

                    try {
                        $wav = $tts->synthetiser((string) $texte, (string) $voix, (string) $style);
                    } catch (AppelLlmException $e) {
                        $this->error("✗ {$profil}/{$evenement}/{$index} : ".$e->getMessage());
                        $echecs++;

                        continue;
                    }

                    if (! is_dir($dossier)) {
                        mkdir($dossier, 0775, true);
                    }

                    file_put_contents($fichier, $wav);
                    $this->line("✓ {$profil}/{$evenement}/{$index}  «{$texte}» ({$voix})");
                    $faits++;
                }
            }
        }

        $this->info("Barks générés : {$faits} · ignorés (déjà présents) : {$ignores} · échecs : {$echecs}");

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
