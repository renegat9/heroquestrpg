<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Agent\Audio\TtsGemini;
use App\Partie\Audio\BanqueAudio;
use Illuminate\Console\Command;

/**
 * Génère la banque de barks d'archétype (config/barks.php) en fichiers audio
 * via Gemini TTS, dans public/audio/barks/{profil}/{evenement}/{index}.wav.
 *
 * Étape HORS-LIGNE, exécutée une fois (ou après édition du catalogue). Sans
 * GEMINI_API_KEY : ne fait rien et l'explique — le jeu reste jouable, l'écran
 * de table lit alors le TEXTE des barks via Web Speech.
 *
 * Chaque fichier porte l'empreinte du texte qu'il prononce (`BanqueAudio`) : une
 * réplique retouchée est régénérée, une réplique retirée voit son fichier supprimé.
 *
 *   php artisan barks:generer          # ne (re)génère que l'absent et le périmé
 *   php artisan barks:generer --force  # régénère tout
 */
final class GenererBarks extends Command
{
    protected $signature = 'barks:generer {--force : Régénère même les fichiers à jour}';

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

        // Voix PAR PROFIL : le dossier `{profil}/{evenement}` la désigne.
        $groupes = [];
        foreach ($lignes as $profil => $parEvenement) {
            foreach ((array) $parEvenement as $evenement => $variantes) {
                $groupes["{$profil}/{$evenement}"] = array_map('strval', array_values((array) $variantes));
            }
        }

        $voixDe = function (string $dossier) use ($profils): array {
            $profil = explode('/', $dossier)[0];

            return [
                (string) ($profils[$profil]['voix'] ?? $profils['defaut']['voix'] ?? 'Fenrir'),
                (string) ($profils[$profil]['style'] ?? $profils['defaut']['style'] ?? 'une voix de monstre menaçant'),
            ];
        };

        // Texte → fichier par EMPREINTE (`BanqueAudio`), comme la narration.
        $bilan = BanqueAudio::synchroniser(
            'barks',
            $groupes,
            function (string $dossier, string $texte) use ($tts, $voixDe) {
                [$voix, $style] = $voixDe($dossier);

                return $tts->synthetiser($texte, $voix, $style);
            },
            $force,
            fn (string $ligne) => str_starts_with($ligne, '✗') ? $this->error($ligne) : $this->line($ligne),
        );

        $this->info(BanqueAudio::resume('Barks générés', $bilan));
        $echecs = $bilan['echecs'];

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }
}
