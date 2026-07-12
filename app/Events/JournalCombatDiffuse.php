<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Groupe;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Journal de combat MÉCANIQUE diffusé sur `groupe.{identifiant}` — écouté par
 * TOUTES les manettes (canal de groupe, pas le canal privé du seul acteur).
 *
 * Comble le trou du « combat instantané » : sans narration IA ni bark, un
 * joueur de manette ne percevait que ses PV bouger. Les lignes sont dérivées
 * du résultat moteur (App\Partie\JournalCombat), aucun LLM — donc compatible
 * avec la résolution synchrone du tour.
 *
 * Écouté côté Vue (ManetteView) sous `.combat.journal`.
 */
class JournalCombatDiffuse implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{texte: string, ton: string}>  $lignes
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $lignes,
        public readonly int $sequence,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'combat.journal';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'lignes' => $this->lignes,
            'sequence' => $this->sequence,
        ];
    }
}
