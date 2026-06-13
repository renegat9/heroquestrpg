<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\HabillageMonstres;
use App\Events\EtatGroupeDiffuse;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Partie\EtatGroupe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Job IA : habille (renomme/redécrit) les instances de monstres déjà spawnées
 * par le moteur au démarrage de la quête (doc 06 §5, Q6).
 *
 * Le moteur reste autorité sur les stats et le placement : ce job ne fait que
 * poser `habillage.nom` / `habillage.description` sur les instances existantes,
 * groupées par bloc de catalogue (monstre_id). Best effort : sans LLM (ou en
 * cas d'échec), les instances gardent leur nom de catalogue — la quête tourne.
 */
class HabillerMonstres implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $groupeId,
        public readonly int $queteId,
    ) {}

    public function handle(HabillageMonstres $skill, ContexteAssembleur $assembleur): void
    {
        $groupe = Groupe::find($this->groupeId);

        if ($groupe === null) {
            return;
        }

        // Blocs de monstres présents dans la quête (un habillage par bloc).
        $blocs = InstanceMonstre::query()
            ->where('quete_id', $this->queteId)
            ->where('etat', 'actif')
            ->with('monstre')
            ->get();

        if ($blocs->isEmpty()) {
            return;
        }

        $aHabiller = $blocs
            ->unique('monstre_id')
            ->map(fn (InstanceMonstre $i) => [
                'monstre_id' => (int) $i->monstre_id,
                'nom_base' => $i->monstre->nom_base,
                'tier' => $i->monstre->tier,
            ])
            ->values()
            ->all();

        try {
            $contexte = $assembleur->assembler($groupe, extra: ['monstres_a_habiller' => $aHabiller]);
            $sortie = $skill->generer($contexte);
        } catch (\Throwable $e) {
            Log::warning('Habillage des monstres impossible — noms de catalogue conservés.', [
                'groupe' => $groupe->id,
                'erreur' => $e->getMessage(),
            ]);

            return;
        }

        $habillages = collect($sortie['habillages'] ?? [])->keyBy(fn ($h) => (int) $h['monstre_id']);

        if ($habillages->isEmpty()) {
            return; // repli : rien à appliquer.
        }

        foreach ($blocs as $instance) {
            $h = $habillages->get((int) $instance->monstre_id);

            if ($h === null) {
                continue;
            }

            $habillage = $instance->habillage ?? [];
            $habillage['nom'] = $h['nom'];
            $habillage['description'] = $h['description'];
            $instance->update(['habillage' => $habillage]);
        }

        // La table rafraîchit les noms affichés.
        broadcast(new EtatGroupeDiffuse($groupe, app(EtatGroupe::class)->payload($groupe->fresh())));
    }
}
