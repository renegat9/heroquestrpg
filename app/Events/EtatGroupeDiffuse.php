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
    /**
     * @param  list<array{type: string, id: int, depart: array{x: int, y: int}, chemin: list<array{x: int, y: int}>}>  $mouvements
     *                                                                                                                              trajets de figurines de la résolution qui a produit cet état, à
     *                                                                                                                              rejouer case par case AVANT de poser les positions finales.
     *
     * ⚠ DANS le même message que l'état, depuis le 2026-09-17. Ils partaient
     * dans un événement séparé (`.mouvement.anime`) « juste avant » : or la
     * file `temps-reel` est consommée par DEUX workers (`queue` et `queue-jeu`),
     * et rien ne garantissait l'ordre de publication. Un état arrivé le premier
     * posait les figurines à l'arrivée, puis l'animation les ramenait au départ.
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $etat,
        public readonly array $mouvements = [],
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
        return $this->mouvements === []
            ? $this->etat
            : $this->etat + ['mouvements' => $this->mouvements];
    }
}
