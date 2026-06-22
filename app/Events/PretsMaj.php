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
 * Mise à jour des statuts « prêt » des joueurs (contrat §Statut « prêt »).
 *
 * Diffusée à chaque changement de statut prêt/pas-prêt d'un personnage,
 * sur le canal du groupe. Écouté côté Vue sous `.prets.maj`.
 */
class PretsMaj implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** File prioritaire : signal de boucle de jeu (cf. docker-compose `queue-jeu`). */
    public string $broadcastQueue = 'temps-reel';

    /**
     * @param  list<array{personnage_id: int, pret: bool}>  $prets
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $prets,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'prets.maj';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['prets' => $this->prets];
    }
}
