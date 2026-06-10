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
 * Clôture de la phase marché (contrat docs/contrat-api.md) :
 * `applique=true` quand tous les joueurs ont confirmé et que la transaction
 * groupée a été appliquée (suivi d'un `.groupe.etat`), `applique=false`
 * sur annulation — rien n'a été appliqué.
 *
 * Écouté côté Vue sous `.marche.finalise`.
 */
class MarcheFinalise implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Groupe $groupe,
        public readonly bool $applique,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'marche.finalise';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['applique' => $this->applique];
    }
}
