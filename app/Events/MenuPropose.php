<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Menu de choix personnel diffusé sur le canal PRIVÉ `joueur.{id}` (doc 11 §7) :
 * chaque téléphone (manette) reçoit SON menu — autorisation dans
 * routes/channels.php (propriétaire du canal uniquement).
 *
 * Écouté côté Vue (manette) sous `.menu.propose`.
 */
class MenuPropose implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** File prioritaire : signal de boucle de jeu (cf. docker-compose `queue-jeu`). */
    public string $broadcastQueue = 'temps-reel';

    /**
     * @param  array<string, mixed>  $menu  sortie validée du skill MenuChoix (situation + options)
     */
    public function __construct(
        public readonly int $joueurId,
        public readonly int $groupeId,
        public readonly int $personnageId,
        public readonly array $menu,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('joueur.'.$this->joueurId);
    }

    public function broadcastAs(): string
    {
        return 'menu.propose';
    }

    /**
     * @return array<string, mixed>
     *
     * Le menu est NESTÉ sous `menu` (contrat §canaux : `.menu.propose` =
     * `{menu: {situation, options}}`). La manette lit `e.menu` (comme le
     * rattrapage REST GET /menu renvoie `{menu}`) — un payload plat laissait
     * `e.menu` undefined, donc aucun menu en temps réel (visible seulement
     * après rechargement). Voir resources/js/views/ManetteView.vue.
     */
    public function broadcastWith(): array
    {
        return [
            'menu' => $this->menu,
            'groupe_id' => $this->groupeId,
            'personnage_id' => $this->personnageId,
        ];
    }
}
