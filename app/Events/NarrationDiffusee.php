<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Narration du MJ diffusée sur le canal de groupe `groupe.{id}` (doc 11 §7) :
 * l'écran de table l'affiche et la lit (TTS).
 *
 * Écouté côté Vue (TableView) sous `.narration.diffusee`.
 */
class NarrationDiffusee implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $groupeId,
        public readonly string $texte,
        public readonly ?string $ambiance = null,
        public readonly ?int $queteId = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupeId);
    }

    public function broadcastAs(): string
    {
        return 'narration.diffusee';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'texte' => $this->texte,
            'ambiance' => $this->ambiance,
            'quete_id' => $this->queteId,
        ];
    }
}
