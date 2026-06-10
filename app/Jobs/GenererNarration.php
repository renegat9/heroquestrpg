<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\Narration;
use App\Events\MjReflechit;
use App\Events\NarrationDiffusee;
use App\Models\Groupe;
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

    public function handle(Narration $skill, ContexteAssembleur $assembleur): void
    {
        $groupe = Groupe::findOrFail($this->groupeId);

        try {
            $contexte = $assembleur->assembler($groupe, extra: [
                'resultat_moteur' => $this->resultatMoteur,
            ]);

            $sortie = $skill->generer($contexte);

            $evenement = Journal::ajouter($groupe, 'narration', [
                'texte' => $sortie['texte'],
                'ambiance' => $sortie['ambiance'] ?? null,
            ]);

            broadcast(new NarrationDiffusee(
                $groupe->id,
                (string) $sortie['texte'],
                ambiance: $sortie['ambiance'] ?? null,
                queteId: $evenement->quete_id,
            ));
        } finally {
            broadcast(new MjReflechit($groupe->id, false));
        }
    }
}
