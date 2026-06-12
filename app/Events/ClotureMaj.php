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
 * Mise à jour de la fenêtre de clôture (contrat docs/contrat-api.md) :
 * diffusée à chaque réassignation d'équipement ou confirmation — la
 * tablette suit la répartition et l'avancement des confirmations en direct.
 *
 * Écouté côté Vue sous `.cloture.maj`.
 */
class ClotureMaj implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $etatCloture  payload EtatCloture (App\Partie\ClotureCampagne)
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $etatCloture,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'cloture.maj';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->etatCloture;
    }
}
