<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\ResumeCampagne;
use App\Events\ClotureTerminee;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\Personnage;
use App\Models\PersonnageHistorique;
use App\Partie\ClotureCampagne;
use App\Support\Journal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Job de FINALISATION de la clôture de campagne (doc 05 §6, contrat
 * docs/contrat-api.md) — dispatché par ClotureCampagne::confirmer quand
 * TOUS les joueurs ont confirmé. Ordre du contrat :
 *
 *  1. réassignations d'équipement appliquées (atomique, avec l'or) ;
 *  2. or commun réparti vers `personnages.or` (parts égales, reste unité
 *     par unité aux premiers de l'initiative) ;
 *  3. résumé de campagne généré AVANT la purge (skill ResumeCampagne —
 *     repli factuel sans LLM depuis les statistiques réelles) ;
 *  4. une ligne `personnage_historique` par héros actif ;
 *  5. broadcast `.cloture.terminee` ({resumes}) AVANT la suppression du
 *     groupe, puis détachement + purge complète (données + caches + bible
 *     Qdrant best-effort) via ClotureCampagne::purger.
 */
class CloturerCampagne implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @param  array<string, mixed>  $phase  photo de la fenêtre confirmée
     *                                       {issue, or_a_partager, reassignations, confirmations}
     */
    public function __construct(
        public readonly int $groupeId,
        public readonly array $phase,
    ) {}

    public function handle(ClotureCampagne $service, ResumeCampagne $skill, ContexteAssembleur $assembleur): void
    {
        $groupe = Groupe::findOrFail($this->groupeId);

        $heros = $groupe->personnages()
            ->wherePivot('actif', true)
            ->orderBy('groupe_personnages.ordre_initiative')
            ->orderBy('personnages.id')
            ->get();

        $issue = (string) $this->phase['issue'];
        $orAPartager = (int) $this->phase['or_a_partager'];
        $parts = $service->parts($groupe, $orAPartager);

        // 1-2. Réassignations + répartition de l'or — atomique côté données.
        DB::transaction(function () use ($groupe, $heros, $parts, $orAPartager) {
            $actifs = $heros->pluck('id');

            foreach ($this->phase['reassignations'] as $inventaireId => $personnageId) {
                // Re-vérifié sur l'état réel ; une ligne devenue invalide
                // (objet consommé, héros parti) est simplement ignorée.
                if (! $actifs->contains((int) $personnageId)) {
                    continue;
                }

                Inventaire::query()
                    ->whereKey((int) $inventaireId)
                    ->whereIn('personnage_id', $actifs)
                    ->update(['personnage_id' => (int) $personnageId]);
            }

            foreach ($parts as $part) {
                if ($part['montant'] > 0) {
                    Personnage::whereKey($part['personnage_id'])->increment('or', $part['montant']);
                }
            }

            $groupe->update(['or' => max(0, (int) $groupe->or - $orAPartager)]);
        });

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'cloture_finalisee',
            'issue' => $issue,
            'or_partage' => $orAPartager,
            'parts' => $parts,
        ]);

        // 3. Résumé AVANT la purge (skill + repli factuel sans LLM) — les
        // statistiques réelles de la campagne accompagnent le journal.
        $contexte = $assembleur->assembler($groupe, requeteScene: $groupe->theme, extra: [
            'cloture' => [
                'issue' => $issue,
                'or_partage' => $orAPartager,
                'nb_quetes' => (int) $groupe->quetes()->count(),
                'nb_quetes_terminees' => (int) $groupe->quetes()->where('etat', 'terminee')->count(),
            ],
        ]);

        $resume = (string) $skill->generer($contexte)['resume'];

        // 4. Une ligne d'historique par héros actif : la seule mémoire qui
        // survit à la purge (doc 12 §8).
        $resumes = [];

        foreach ($heros as $personnage) {
            PersonnageHistorique::create([
                'personnage_id' => $personnage->id,
                'groupe_nom' => $groupe->nom,
                'theme' => $groupe->theme,
                'resume' => $resume,
                'issue' => $issue,
                'niveau_atteint' => (int) $personnage->niveau,
                'termine_le' => now(),
            ]);

            $resumes[] = ['personnage_id' => (int) $personnage->id, 'resume' => $resume];
        }

        // 5. Broadcast final AVANT la suppression du groupe (les clients
        // retournent à l'accueil), puis détachement + purge complète.
        broadcast(new ClotureTerminee($groupe, $resumes));

        $service->purger($groupe);
    }
}
