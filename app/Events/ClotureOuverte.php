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
 * Ouverture de la fenêtre de clôture de campagne (doc 05 §6, contrat
 * docs/contrat-api.md) : automatique à la victoire du boss final, ou
 * manuelle par un membre (hub / abandon). L'EtatCloture complet part sur
 * le canal du groupe — la tablette affiche la répartition de l'or et des
 * équipements, les téléphones la confirmation.
 *
 * Écouté côté Vue sous `.cloture.ouverte`.
 */
class ClotureOuverte implements ShouldBroadcast
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
        return 'cloture.ouverte';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->etatCloture;
    }
}
