<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Image\ImageGemini;
use App\Models\Personnage;
use App\Partie\Images\BibliothequeImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Génère un PORTRAIT UNIQUE pour un héros (dyn/perso/{id}.png), depuis son nom
 * et sa classe. Prime sur l'image de classe par défaut. Déclenché à la demande
 * (bouton du roster) — exécuté en synchrone par le contrôleur pour renvoyer
 * l'URL aussitôt. Best-effort : sans clé/échec, le héros garde l'image de
 * classe (ou l'icône).
 */
class GenererPortraitHeros implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $personnageId,
    ) {}

    public function handle(ImageGemini $image, BibliothequeImages $biblio): void
    {
        if (! $image->estConfigure()) {
            return;
        }

        $p = Personnage::find($this->personnageId);
        if ($p === null) {
            return;
        }

        $chemin = $biblio->cheminDyn('perso', $p->id);
        $prompt = $biblio->prompt('portrait', ['nom' => $p->nom, 'detail' => $biblio->detailClasse($p->classe)]);

        try {
            $biblio->enregistrer($chemin['rel'], $image->generer($prompt));
        } catch (\Throwable $e) {
            Log::warning('Génération portrait héros échouée.', ['personnage' => $p->id, 'erreur' => $e->getMessage()]);
        }
    }
}
