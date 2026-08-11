<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * « Tu viens d'encaisser — veux-tu activer ta réaction ? »
 *
 * Canal PRIVÉ du joueur concerné, comme le menu : c'est SA décision, et la
 * proposition ne regarde ni la table ni les autres manettes. Elle part pendant
 * la phase des monstres, donc au milieu du tour de quelqu'un d'autre — c'est
 * tout l'objet du dispositif (voir App\Partie\MoteurReactions).
 */
class ReactionProposee implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $reaction
     */
    public function __construct(
        public readonly int $joueurId,
        public readonly string $groupeIdentifiant,
        public readonly array $reaction,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('joueur.'.$this->joueurId);
    }

    public function broadcastAs(): string
    {
        return 'reaction.proposee';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['groupe' => $this->groupeIdentifiant, 'reaction' => $this->reaction];
    }
}
