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
 * Ouverture de la phase marché (doc 04 §5, contrat docs/contrat-api.md) :
 * l'EtatMarche complet (profil, inventaire dérivé du catalogue, paniers
 * vides) part sur le canal du groupe — la tablette affiche l'étal, les
 * téléphones ouvrent la saisie des paniers.
 *
 * Écouté côté Vue sous `.marche.ouvert`.
 */
class MarcheOuvert implements ShouldBroadcast
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
        return 'marche.ouvert';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->etatMarche;
    }
}
