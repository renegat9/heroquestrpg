<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Image\ImageGemini;
use App\Events\EtatGroupeDiffuse;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Quete;
use App\Partie\EtatGroupe;
use App\Partie\Images\BibliothequeImages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Génère EN ARRIÈRE-PLAN les images dynamiques d'une quête : une illustration
 * de SCÈNE (par quête) et un PORTRAIT pour chaque BOSS/sous-boss (depuis le nom
 * donné par l'IA, instance.habillage.nom). Écrit sous public/images/dyn/ ;
 * {@see BibliothequeImages} les sert en priorité sur l'image de catalogue.
 *
 * Best-effort : sans GEMINI_API_KEY (ou en cas d'échec), aucun asset n'est
 * produit → la table affiche l'image de catalogue / l'icône. Chaîné après
 * HabillerMonstres (pour disposer des noms de boss). À la fin, rediffuse
 * `.groupe.etat` pour que la table récupère les nouvelles URLs.
 */
class GenererImagesQuete implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $groupeId,
        public readonly int $queteId,
    ) {}

    public function handle(ImageGemini $image, BibliothequeImages $biblio, EtatGroupe $etatGroupe): void
    {
        if (! $image->estConfigure()) {
            return;
        }

        $groupe = Groupe::find($this->groupeId);
        $quete = Quete::find($this->queteId);
        if ($groupe === null || $quete === null) {
            return;
        }

        $produit = false;

        // Scène de quête (ambiance) — une par quête.
        $scene = $biblio->cheminDyn('quete', $quete->id);
        if (! is_file($scene['absolu'])) {
            $intro = trim($quete->titre.($groupe->theme ? ' — '.$groupe->theme : ''));
            $produit = $this->generer($image, $biblio, $scene['rel'], $biblio->prompt('scene', ['intro' => $intro]),
                ['quete' => $quete->id]) || $produit;
        }

        // Portraits de boss / sous-boss — un par instance nommée.
        $boss = InstanceMonstre::query()
            ->where('quete_id', $quete->id)
            ->whereHas('monstre', fn ($q) => $q->whereIn('tier', ['sous_boss', 'boss']))
            ->with('monstre')
            ->get();

        foreach ($boss as $instance) {
            $chemin = $biblio->cheminDyn('monstre', $instance->id);
            if (is_file($chemin['absolu'])) {
                continue;
            }

            $nom = (string) ($instance->habillage['nom'] ?? $instance->monstre?->nom_base ?? 'le boss');
            $desc = (string) ($instance->habillage['description'] ?? '');
            $produit = $this->generer($image, $biblio, $chemin['rel'],
                $biblio->prompt('boss', ['nom' => $nom, 'description' => $desc]),
                ['boss' => $instance->id]) || $produit;
        }

        // Nouvelles images disponibles → la table re-récupère les image_url.
        if ($produit) {
            broadcast(new EtatGroupeDiffuse($groupe->fresh(), $etatGroupe->payload($groupe->fresh())));
        }
    }

    /**
     * @param  array<string, mixed>  $contexteLog
     */
    private function generer(ImageGemini $image, BibliothequeImages $biblio, string $rel, string $prompt, array $contexteLog): bool
    {
        try {
            $biblio->enregistrer($rel, $image->generer($prompt));

            return true;
        } catch (\Throwable $e) {
            Log::warning('Génération image de quête sautée.', $contexteLog + ['erreur' => $e->getMessage()]);

            return false;
        }
    }
}
