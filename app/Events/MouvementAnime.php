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
 * Déplacements de figurines d'une résolution, diffusés sur `groupe.{id}` JUSTE
 * AVANT l'état (EtatGroupeDiffuse) pour une animation CASE PAR CASE côté table :
 * chaque entrée {type, id, depart, chemin} décrit le trajet réel d'un héros ou
 * d'un monstre. La table fait glisser la figurine de case en case le long du
 * chemin (les points d'un trajet en L ne sont plus « coupés »), puis l'état la
 * pose à sa position finale (source de vérité). Écouté sous `.mouvement.anime`.
 */
class MouvementAnime implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** Même file prioritaire que l'état, pour préserver l'ordre (anime puis état). */
    public string $broadcastQueue = 'temps-reel';

    /**
     * @param  list<array{type: string, id: int, depart: array{x: int, y: int}, chemin: list<array{x: int, y: int}>}>  $mouvements
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $mouvements,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'mouvement.anime';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['mouvements' => $this->mouvements];
    }
}
