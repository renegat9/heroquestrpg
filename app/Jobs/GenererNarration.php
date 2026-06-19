<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Audio\TtsGemini;
use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\Narration;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Models\Groupe;
use App\Partie\Narration\BibliothequeNarration;
use App\Support\Journal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Job IA : mise en récit du dernier résultat moteur (doc 06 §1, étape 5 ;
 * doc 11 §4, étapes 4-5 du flux d'un tour).
 *
 * Journalise un événement de type `narration` puis diffuse le texte sur
 * le canal de groupe `groupe.{id}` (écran de table, TTS). Éteint
 * l'indicateur « le MJ réfléchit… » allumé par l'API au moment du choix.
 */
class GenererNarration implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $resultatMoteur  résultat déjà résolu par le moteur (jet, attaque, choix)
     */
    public function __construct(
        public readonly int $groupeId,
        public readonly array $resultatMoteur = [],
    ) {}

    public function handle(Narration $skill, ContexteAssembleur $assembleur, TtsGemini $tts, BibliothequeNarration $lib): void
    {
        $groupe = Groupe::findOrFail($this->groupeId);

        try {
            $contexte = $assembleur->assembler($groupe, extra: [
                'resultat_moteur' => $this->resultatMoteur,
            ]);

            $sortie = $skill->generer($contexte);
            $texte = (string) $sortie['texte'];

            $evenement = Journal::ajouter($groupe, 'narration', [
                'texte' => $texte,
                'ambiance' => $sortie['ambiance'] ?? null,
            ]);

            broadcast(new NarrationDiffusee(
                $groupe,
                $texte,
                ambiance: $sortie['ambiance'] ?? null,
                queteId: $evenement->quete_id,
                url: $this->voix($texte, $sortie['url'] ?? null, $tts, $lib),
            ));
        } finally {
            broadcast(new MjReflechit($groupe, false));
        }
    }

    /**
     * URL de la vraie voix de narrateur :
     *  - répliques SCRIPTÉES (repli) → audio pré-généré déjà résolu ($urlScript) ;
     *  - narration DYNAMIQUE de l'IA → synthèse au vol Gemini, mise en cache par
     *    hash du texte (best-effort). Sans clé / sur échec → null = lecture
     *    navigateur (Web Speech) côté table.
     */
    private function voix(string $texte, ?string $urlScript, TtsGemini $tts, BibliothequeNarration $lib): ?string
    {
        // Répliques scriptées → audio déjà résolu ; narration IA → synthèse au vol.
        return $urlScript ?? $lib->voixDynamique($texte, $tts);
    }
}
