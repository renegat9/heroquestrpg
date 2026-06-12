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
 * Fin de la clôture de campagne (doc 05 §6, contrat docs/contrat-api.md) :
 * émis par le job CloturerCampagne AVANT la suppression du groupe, avec le
 * résumé d'historique de chaque héros — les clients retournent à l'accueil.
 *
 * Écouté côté Vue sous `.cloture.terminee`.
 */
class ClotureTerminee implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<array{personnage_id: int, resume: string}>  $resumes
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $resumes,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'cloture.terminee';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['resumes' => $this->resumes];
    }
}
