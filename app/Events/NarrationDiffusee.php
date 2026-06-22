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
 * Narration du MJ diffusée sur le canal de groupe `groupe.{identifiant}`
 * (doc 11 §7, docs/contrat-api.md) : l'écran de table l'affiche et la lit (TTS).
 *
 * Écouté côté Vue (TableView) sous `.narration.diffusee`.
 */
class NarrationDiffusee implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** File prioritaire : le texte de narration s'affiche tout de suite (l'audio
     *  TTS, lui, est généré à part par GenererNarration sur la file `default`). */
    public string $broadcastQueue = 'temps-reel';

    public function __construct(
        public readonly Groupe $groupe,
        public readonly string $texte,
        public readonly ?string $ambiance = null,
        public readonly ?int $queteId = null,
        public readonly ?string $url = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
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
            'url' => $this->url,
        ];
    }
}
