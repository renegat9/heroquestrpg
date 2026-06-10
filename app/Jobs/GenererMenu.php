<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\MenuChoix;
use App\Events\MenuPropose;
use App\Models\Groupe;
use App\Models\Personnage;
use App\Partie\MenuMoteur;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Job IA : menu de choix contextuels d'un héros (doc 06 §1, étape 2).
 *
 * Le MOTEUR construit d'abord un menu générique exécutable depuis l'état
 * exact (MenuMoteur : Se déplacer / Attaquer / Fouiller / Attendre) ; le
 * skill MenuChoix tente de l'enrichir via le LLM. En cas d'échec (pas de
 * clé API, erreur, sortie invalide), le menu moteur sert de repli — l'API
 * ne dépend jamais du LLM, le joueur reçoit toujours un menu.
 *
 * Le menu retenu est MÉMORISÉ (cache, clé groupe+joueur) : c'est contre ce
 * dernier menu proposé que POST choix valide l'option (contrat, doc 08 §2).
 * Puis il est diffusé sur le canal PRIVÉ `joueur.{id}` (doc 11 §7).
 */
class GenererMenu implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** Durée de vie du dernier menu proposé (séance de jeu). */
    public const TTL_MENU_MINUTES = 360;

    public function __construct(
        public readonly int $groupeId,
        public readonly int $joueurId,
        public readonly ?int $personnageId = null,
    ) {}

    /** Clé du dernier menu proposé à un joueur dans un groupe. */
    public static function cleMenu(int $groupeId, int $joueurId): string
    {
        return "partie:menu:{$groupeId}:{$joueurId}";
    }

    public function handle(MenuChoix $skill, ContexteAssembleur $assembleur, MenuMoteur $menuMoteur): void
    {
        $groupe = Groupe::findOrFail($this->groupeId);
        $personnage = $this->personnage($groupe);

        // Menu générique du moteur : toujours exécutable, sert de repli.
        $menuGenerique = $menuMoteur->generer($groupe, $personnage);

        try {
            $contexte = $assembleur->assembler($groupe, extra: [
                'personnage' => [
                    'id' => $personnage->id,
                    'nom' => $personnage->nom,
                    'classe' => $personnage->classe,
                    'niveau' => $personnage->niveau,
                    'attribut_body' => $personnage->attribut_body,
                    'attribut_mind' => $personnage->attribut_mind,
                    'pv_body' => "{$personnage->pv_body}/{$personnage->pv_body_max}",
                    'pv_mind' => "{$personnage->pv_mind}/{$personnage->pv_mind_max}",
                ],
                'menu_moteur' => $menuGenerique,
            ]);

            $menu = $skill->generer($contexte);
        } catch (Throwable $e) {
            Log::warning('Menu IA indisponible — repli sur le menu moteur.', [
                'groupe_id' => $groupe->id,
                'personnage_id' => $personnage->id,
                'erreur' => $e->getMessage(),
            ]);

            $menu = $menuGenerique;
        }

        // Dernier menu proposé : référence de validation de POST choix.
        Cache::put(self::cleMenu($groupe->id, $this->joueurId), [
            'personnage_id' => $personnage->id,
            'menu' => $menu,
        ], now()->addMinutes(self::TTL_MENU_MINUTES));

        broadcast(new MenuPropose($this->joueurId, $groupe->id, $personnage->id, $menu));
    }

    /**
     * Personnage ciblé, ou premier personnage actif du joueur dans ce groupe.
     */
    private function personnage(Groupe $groupe): Personnage
    {
        if ($this->personnageId !== null) {
            return Personnage::findOrFail($this->personnageId);
        }

        return $groupe->personnages()
            ->wherePivot('actif', true)
            ->where('joueur_id', $this->joueurId)
            ->firstOrFail();
    }
}
