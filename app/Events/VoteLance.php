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
 * Lancement d'un vote de groupe (doc 05 §5, contrat docs/contrat-api.md) :
 * le payload `{vote}` (type, question, options, votants attendus) part sur
 * le canal du groupe — chaque téléphone affiche son bulletin.
 *
 * Écouté côté Vue sous `.vote.lance`.
 */
class VoteLance implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  {vote: payload public du vote}
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $payload,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'vote.lance';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
