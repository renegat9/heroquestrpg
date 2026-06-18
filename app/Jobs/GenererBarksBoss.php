<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Audio\TtsGemini;
use App\Models\InstanceMonstre;
use App\Partie\Audio\BanqueBarks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Génère des barks NOMMÉS pour les boss/sous-boss d'une quête : les répliques
 * `lignes_boss` (placeholder {nom}) sont rendues avec le nom donné par l'IA
 * (instance.habillage.nom), synthétisées par Gemini TTS, puis écrites sous
 * public/audio/barks/quete-{id}/ avec un manifeste.php que {@see BanqueBarks}
 * lit en priorité sur la banque d'archétype.
 *
 * Best-effort : sans GEMINI_API_KEY (ou en cas d'échec), aucun asset n'est
 * produit → les boss utilisent les barks d'archétype, et l'écran de table lit
 * le texte via Web Speech. Chaîné après HabillerMonstres pour disposer des noms.
 */
class GenererBarksBoss implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $queteId,
    ) {}

    public function handle(TtsGemini $tts, BanqueBarks $banque): void
    {
        if (! $tts->estConfigure()) {
            return; // pas de génération sans clé — repli archétype/texte.
        }

        $boss = InstanceMonstre::query()
            ->where('quete_id', $this->queteId)
            ->whereHas('monstre', fn ($q) => $q->whereIn('tier', ['sous_boss', 'boss']))
            ->with('monstre')
            ->get();

        if ($boss->isEmpty()) {
            return;
        }

        $profils = (array) config('barks.profils', []);
        $lignesBoss = (array) config('barks.lignes_boss', []);
        $base = "audio/barks/quete-{$this->queteId}";
        $manifeste = [];

        foreach ($boss as $instance) {
            $profil = $banque->profil($instance->monstre?->nom_base ?? '');
            $nom = (string) ($instance->habillage['nom'] ?? $instance->monstre?->nom_base ?? 'le boss');
            $voix = (string) ($profils[$profil]['voix'] ?? 'Charon');
            $style = (string) ($profils[$profil]['style'] ?? 'une voix terrifiante de boss');
            $parEvenement = $lignesBoss[$profil] ?? null;

            if ($parEvenement === null) {
                continue; // pas de répliques nommées pour ce profil → archétype.
            }

            foreach ((array) $parEvenement as $evenement => $variantes) {
                foreach (array_values((array) $variantes) as $i => $modele) {
                    $texte = str_replace('{nom}', $nom, (string) $modele);
                    $relatif = "{$instance->id}/{$evenement}/{$i}.wav";

                    try {
                        $wav = $tts->synthetiser($texte, $voix, $style);
                    } catch (\Throwable $e) {
                        Log::warning('Génération bark boss impossible — repli archétype.', [
                            'quete' => $this->queteId, 'instance' => $instance->id, 'erreur' => $e->getMessage(),
                        ]);

                        return; // on abandonne proprement : repli complet.
                    }

                    $absolu = public_path("{$base}/{$relatif}");
                    $dossier = dirname($absolu);

                    if (! is_dir($dossier)) {
                        mkdir($dossier, 0775, true);
                    }

                    file_put_contents($absolu, $wav);
                    $manifeste[(string) $instance->id][$evenement][] = ['texte' => $texte, 'fichier' => $relatif];
                }
            }
        }

        if ($manifeste === []) {
            return;
        }

        $dossierBase = public_path($base);

        if (! is_dir($dossierBase)) {
            mkdir($dossierBase, 0775, true);
        }

        file_put_contents(
            "{$dossierBase}/manifeste.php",
            "<?php\n\nreturn ".var_export($manifeste, true).";\n",
        );
    }
}
