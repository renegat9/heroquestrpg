<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\HabillageMonstres;
use App\Events\EtapePreparation;
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

        // Première étape visible de la préparation : l'écran de table affiche
        // où l'on en est plutôt que de laisser le groupe devant un donjon muet.
        broadcast(new EtapePreparation($groupe, 'habillage'));

        // Blocs de monstres présents dans la quête (un habillage par bloc).
        $blocs = InstanceMonstre::query()
            ->where('quete_id', $this->queteId)
            ->where('etat', 'actif')
            ->with('monstre')
            ->get();

        if ($blocs->isEmpty()) {
            // Aucun monstre à habiller (budget de rencontres à 0, quête
            // atypique) : les salles restent à décrire quand même — un donjon
            // sans monstre a toujours des lieux et du mobilier. Même séquence
            // d'ouverture que la sortie nominale : la scène avant les récits.
            GenererImagesQuete::dispatch($this->groupeId, $this->queteId, 'scene');
            GenererRecitsQuete::dispatch($this->groupeId, $this->queteId);

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
            // Même séquence d'ouverture que la sortie nominale (voir plus bas
            // pour la raison) : scène, puis récits, puis le reste. Sans
            // habillage, RecitsQuete retombe sur les noms de catalogue.
            GenererImagesQuete::dispatch($this->groupeId, $this->queteId, 'scene');
            GenererRecitsQuete::dispatch($this->groupeId, $this->queteId);

            GenererBarksBoss::dispatch($this->queteId); // barks sur noms de catalogue
            GenererImagesQuete::dispatch($this->groupeId, $this->queteId, 'boss');

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

        // ⚠ L'ORDRE DE CES TROIS DISPATCHS EST LA SÉQUENCE D'OUVERTURE DE LA
        // QUÊTE. Ils partagent la file `default`, donc l'ordre de dispatch EST
        // l'ordre d'exécution.
        //
        // 1. L'IMAGE DE SCÈNE d'abord : l'écran de table ouvre la quête sur une
        //    carte plein cadre — illustration + texte de mise en scène (René,
        //    2026-08-21). Sans elle, cette carte n'aurait jamais son image sur
        //    une première quête, ce qui la viderait de sa raison d'être.
        GenererImagesQuete::dispatch($this->groupeId, $this->queteId, 'scene');

        // 2. Les RÉCITS, qui doivent citer les monstres par leur nom HABILLÉ
        //    (d'où le chaînage ici, après l'application ci-dessus). Ils
        //    déclenchent l'ouverture une fois écrits.
        //    ⚠ Ils passent devant les PORTRAITS : chronométré en campagne
        //    réelle (2026-08-20), le pack attendait deux générations d'images
        //    et n'arrivait qu'à t+4 min, laissant la quête se jouer quatre
        //    minutes sur le repli générique.
        GenererRecitsQuete::dispatch($this->groupeId, $this->queteId);

        // 3. Le reste, pur habillage, en arrière-plan.
        GenererBarksBoss::dispatch($this->queteId);
        GenererImagesQuete::dispatch($this->groupeId, $this->queteId, 'boss');
    }
}
