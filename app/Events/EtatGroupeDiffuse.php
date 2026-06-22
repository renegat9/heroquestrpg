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
 * État partagé du groupe diffusé sur le canal `groupe.{id}` après chaque
 * mutation d'état (contrat docs/contrat-api.md) : carte, entités, initiative,
 * dernière narration — table ET manettes le consomment.
 *
 * Le payload est le même « EtatGroupe » que GET /api/groupes/{identifiant}/etat
 * (construit par App\Partie\EtatGroupe, figé au moment du broadcast).
 *
 * Écouté côté Vue sous `.groupe.etat`.
 */
class EtatGroupeDiffuse implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** File prioritaire : signal de boucle de jeu (cf. docker-compose `queue-jeu`). */
    public string $broadcastQueue = 'temps-reel';

    /**
     * @param  array<string, mixed>  $etat  payload EtatGroupe (App\Partie\EtatGroupe::payload)
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $etat,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'groupe.etat';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->etat;
    }
}
