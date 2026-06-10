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
 * Avancement d'un vote (contrat docs/contrat-api.md) : à chaque bulletin,
 * `{decompte, exprimes, attendus}` part sur le canal du groupe — la table
 * affiche la progression sans révéler qui a voté quoi.
 *
 * Écouté côté Vue sous `.vote.maj`.
 */
class VoteMaj implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload  {decompte, exprimes, attendus}
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
        return 'vote.maj';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
