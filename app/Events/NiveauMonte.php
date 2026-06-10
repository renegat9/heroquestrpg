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
 * Montée de niveau du groupe (contrat docs/contrat-api.md) — émise à la
 * clôture VICTORIEUSE d'une quête à jalon (sous_boss / boss_final), AVANT le
 * `.groupe.etat` de fin de quête :
 * `{personnages: [{id, nom, niveau, points_competence, gains: [...]}]}`.
 *
 * Écouté côté Vue sous `.niveau.monte`.
 */
class NiveauMonte implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array{personnages: list<array<string, mixed>>}  $resultat
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
        return 'niveau.monte';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->resultat;
    }
}
