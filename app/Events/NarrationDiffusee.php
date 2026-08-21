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
 * Narration du MJ diffusée sur le canal de groupe `groupe.{identifiant}`
 * (doc 11 §7, docs/contrat-api.md) : l'écran de table l'affiche et la lit (TTS).
 *
 * Écouté côté Vue (TableView) sous `.narration.diffusee`.
 */
class NarrationDiffusee implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** File prioritaire : le texte de narration s'affiche tout de suite. L'audio
     *  n'est plus synthétisé au vol depuis le 2026-08-18 — il est pré-généré par
     *  quête (`GenererVoixQuete`) et l'`url` ci-dessous n'est qu'une recherche en
     *  cache ; absente, la table lit le texte en Web Speech. */
    public string $broadcastQueue = 'temps-reel';

    public function __construct(
        public readonly Groupe $groupe,
        public readonly string $texte,
        public readonly ?string $ambiance = null,
        public readonly ?int $queteId = null,
        public readonly ?string $url = null,
        /** Séquence du journal (Evenement.sequence) : permet au client
         *  d'ignorer une narration arrivée EN RETARD derrière une plus
         *  récente déjà affichée (jobs asynchrones, ordre non garanti —
         *  ex. la cérémonie de lancement de la quête suivante, instantanée,
         *  peut devancer la narration — lente — du coup fatal précédent). */
        public readonly ?int $sequence = null,
        /** Ouverture de quête : l'écran de table l'affiche en GRAND, avec
         *  l'illustration de scène, plutôt que dans le bandeau habituel
         *  (René, 2026-08-21). C'est le texte qui plante le donjon — il
         *  méritait mieux qu'une ligne au bas de l'écran. */
        public readonly bool $ouverture = false,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'narration.diffusee';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'texte' => $this->texte,
            'ambiance' => $this->ambiance,
            'quete_id' => $this->queteId,
            'url' => $this->url,
            'sequence' => $this->sequence,
            'ouverture' => $this->ouverture,
        ];
    }
}
