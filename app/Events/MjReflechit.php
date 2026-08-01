<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Groupe;
use App\Partie\EtatGroupe;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Indicateur « Le MJ réfléchit… » (doc 11 §4) : diffusé sur le canal de
 * groupe quand un job IA démarre (actif=true) et se termine (actif=false).
 * Rien ne bloque l'API pendant ce temps.
 *
 * ShouldBroadcastNow : l'indicateur doit partir immédiatement, sans repasser
 * par la file (il est émis depuis l'API ou depuis un job déjà asynchrone).
 *
 * L'indicateur est aussi mémorisé en cache pour que GET etat / le payload
 * EtatGroupe puissent renseigner `mj_reflechit` (reconnexion d'un écran
 * pendant que le MJ travaille).
 *
 * Écouté côté Vue (TableView) sous `.mj.reflechit`.
 */
class MjReflechit implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Groupe $groupe,
        public readonly bool $actif,
    ) {
        // Le drapeau est levé par l'écran de TABLE une fois la narration LUE
        // (POST /table/lecture-terminee) — verrou B1, délibéré : les joueurs
        // n'agissent pas avant que le narrateur ait parlé.
        //
        // Ce cache n'est donc qu'un FILET DE SÉCURITÉ, pour le cas où plus
        // personne ne lève le drapeau (onglet du narrateur fermé en cours de
        // quête, lecture audio bloquée par le navigateur). Il durait 10 minutes,
        // pendant lesquelles TOUTES les manettes restaient gelées — un test de
        // jeu s'y est arrêté. 90 s laissent le temps de lire une narration tout
        // en bornant la casse.
        Cache::put(EtatGroupe::cleMjReflechit($groupe->id), $actif, now()->addSeconds(90));
    }

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'mj.reflechit';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['actif' => $this->actif];
    }
}
