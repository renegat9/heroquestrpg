<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Image\ImageGemini;
use App\Events\EtatGroupeDiffuse;
use App\Models\Groupe;
use App\Models\Parametre;
use App\Partie\EtatGroupe;
use App\Partie\Images\BibliothequeImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Génère EN ARRIÈRE-PLAN l'illustration du LIEU DE REPOS (hub) d'une campagne,
 * depuis la prémisse du plan de campagne. Une seule par groupe (réutilisée
 * entre les quêtes). Best-effort : sans clé/échec, la table garde le fond par
 * défaut. Rediffuse `.groupe.etat` quand l'image est prête.
 */
class GenererImageHub implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $groupeId,
    ) {}

    public function handle(ImageGemini $image, BibliothequeImages $biblio, EtatGroupe $etatGroupe): void
    {
        if (! $image->estConfigure() || ! $this->imagesActives()) {
            return;
        }

        $groupe = Groupe::find($this->groupeId);
        if ($groupe === null) {
            return;
        }

        $chemin = $biblio->cheminDyn('hub', $groupe->id);
        if (is_file($chemin['absolu'])) {
            return; // déjà générée
        }

        $premisse = (string) data_get($groupe->plan_campagne, 'premisse', '');

        try {
            $biblio->enregistrer($chemin['rel'], $image->generer($biblio->prompt('hub', ['premisse' => $premisse])));
        } catch (\Throwable $e) {
            Log::warning('Génération image hub sautée.', ['groupe' => $groupe->id, 'erreur' => $e->getMessage()]);

            return;
        }

        broadcast(new EtatGroupeDiffuse($groupe->fresh(), $etatGroupe->payload($groupe->fresh())));
    }

    /**
     * Bascule « génération d'illustrations IA en cours de partie » (panneau
     * Réglages, Parametre::images_actif) — RUNTIME uniquement : ne gate PAS
     * la commande offline `images:generer` (ImageGemini::estConfigure()
     * reste inchangé), un opérateur qui la lance explicitement veut générer.
     * Best-effort, repli actif (comportement d'aujourd'hui) si la table est
     * indisponible.
     */
    private function imagesActives(): bool
    {
        try {
            return Parametre::actuel()->images_actif;
        } catch (\Throwable) {
            return true;
        }
    }
}
