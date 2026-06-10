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
 * Mise à jour de la phase marché (contrat docs/contrat-api.md) : diffusée à
 * CHAQUE changement de panier ou confirmation — la tablette recalcule le
 * panier consolidé et le total projeté en direct (doc 04 §5).
 *
 * Écouté côté Vue sous `.marche.maj`.
 */
class MarcheMaj implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>  $etatMarche  payload EtatMarche (App\Partie\Marche\PhaseMarche)
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $etatMarche,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'marche.maj';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->etatMarche;
    }
}
