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
 * Résultat d'un vote résolu (contrat docs/contrat-api.md) :
 * `{option_id, applique}` — suivi d'un `.groupe.etat` si l'état du groupe a
 * changé (retrait appliqué).
 *
 * Écouté côté Vue sous `.vote.resultat`.
 */
class VoteResultat implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{option_id: string, applique: bool}  $resultat
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $resultat,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'vote.resultat';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->resultat;
    }
}
