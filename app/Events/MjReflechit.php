<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Indicateur « Le MJ réfléchit… » (doc 11 §4) : diffusé sur le canal de
 * groupe quand un job IA démarre (actif=true) et se termine (actif=false).
 * Rien ne bloque l'API pendant ce temps.
 *
 * ShouldBroadcastNow : l'indicateur doit partir immédiatement, sans repasser
 * par la file (il est émis depuis l'API ou depuis un job déjà asynchrone).
 *
 * Écouté côté Vue (TableView) sous `.mj.reflechit`.
 */
class MjReflechit implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $groupeId,
        public readonly bool $actif,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupeId);
    }

    public function broadcastAs(): string
    {
        return 'mj.reflechit';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['actif' => $this->actif];
    }
}
