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
 * Bark (réplique courte) d'un monstre diffusé sur `groupe.{identifiant}`.
 * Pur AMBIANCE — aucune incidence mécanique. L'écran de table joue `url` si
 * présente, sinon lit `texte` via la synthèse vocale du navigateur (Web Speech).
 *
 * Écouté côté Vue (TableView) sous `.bark.diffuse`.
 */
class BarkDiffuse implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Groupe $groupe,
        public readonly string $profil,
        public readonly string $evenement,
        public readonly string $nom,
        public readonly ?string $texte = null,
        public readonly ?string $url = null,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'bark.diffuse';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'profil' => $this->profil,
            'evenement' => $this->evenement,
            'nom' => $this->nom,
            'texte' => $this->texte,
            'url' => $this->url,
        ];
    }
}
