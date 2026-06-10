<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Agent\Memoire\ContexteAssembleur;
use App\Agent\Skills\MenuChoix;
use App\Events\MenuPropose;
use App\Models\Groupe;
use App\Models\Personnage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Job IA : menu de choix contextuels d'un héros (doc 06 §1, étape 2).
 *
 * Diffuse le menu sur le canal PRIVÉ `joueur.{id}` du propriétaire du
 * personnage (doc 11 §7). Grâce au repli générique du skill MenuChoix
 * (Attendre / Fouiller / Continuer), ce job n'échoue que si la base
 * elle-même est indisponible : le joueur reçoit toujours un menu.
 */
class GenererMenu implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $groupeId,
        public readonly int $joueurId,
        public readonly ?int $personnageId = null,
    ) {}

    public function handle(MenuChoix $skill, ContexteAssembleur $assembleur): void
    {
        $groupe = Groupe::findOrFail($this->groupeId);
        $personnage = $this->personnage($groupe);

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
        ]);

        $menu = $skill->generer($contexte);

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
