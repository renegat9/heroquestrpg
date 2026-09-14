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
 * Scène illustrée de l'événement qui vient d'être résolu, pour l'ÉCRAN DE TABLE
 * seul (`docs/contrat-api.md`, `.table.scene`).
 *
 * ⚠ Pourquoi un événement PARALLÈLE et non une ligne de journal enrichie : le
 * moteur rend un résultat structuré — attaquant, défenseur, faces de dés
 * réellement tombées, objet tiré, piège déclenché — et
 * {@see \App\Partie\JournalCombat::depuisResultat()} l'APLATIT en une phrase.
 * Les identités y meurent, donc plus aucune image n'y est résolvable. Et une
 * ligne de journal est un résumé destiné à DÉFILER, lu aussi par les manettes :
 * lui faire porter la mise en scène d'un écran qui n'est pas le sien en ferait
 * la seule structure du projet à servir deux métiers opposés.
 *
 * ⚠ Le payload est **déjà décidé** : URL d'images résolues, titre écrit, issue
 * nommée. La table n'a aucun identifiant à joindre ni aucune règle à redériver
 * — c'est la classe de défaut la plus répétée du projet côté front.
 *
 * ⚠ Aucune durée ici. Le retour à la carte se fait au CLIC sur l'écran du
 * narrateur, ou après un délai réglé dans ses paramètres (défaut 5 s, décision
 * de René) : c'est une préférence d'appareil, comme le volume et la voix, pas
 * une règle de jeu.
 *
 * Diffusé en SYNCHRONE depuis le résolveur (file `temps-reel`), sans LLM.
 */
class SceneTable implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** La scène doit arriver avec le coup, pas derrière un job lent. */
    public string $broadcastQueue = 'temps-reel';

    /**
     * @param  array<string, mixed>  $scene  déjà construite par {@see \App\Partie\SceneDeTable}
     */
    public function __construct(
        public readonly Groupe $groupe,
        public readonly array $scene,
        public readonly int $sequence,
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'table.scene';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->scene + ['sequence' => $this->sequence];
    }
}
