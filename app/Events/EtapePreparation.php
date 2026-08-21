<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Groupe;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Étape en cours de la PRÉPARATION d'une quête (René, 2026-08-21).
 *
 * Construire une quête prend une à deux minutes — habillage des monstres,
 * illustration de scène, récits, voix du narrateur — et l'écran de table ne
 * disait rien pendant ce temps : le groupe attendait devant un donjon muet
 * sans savoir si quelque chose se passait, ou si tout était figé. La séquence
 * est connue d'avance et chaque job la traverse : autant la montrer.
 *
 * ShouldBroadcastNow : un indicateur de progression qui repasse par la file
 * arriverait derrière les jobs dont il annonce l'avancement.
 *
 * ⚠ Mémorisé en cache comme `MjReflechit`, et pour la même raison : un écran
 * de table ouvert EN COURS de préparation doit pouvoir savoir où l'on en est.
 * Sans ça, un narrateur qui recharge sa page retombe sur un écran vide au
 * milieu de la séquence. TTL court — une préparation qui dépasse cinq minutes
 * a échoué, et mieux vaut ne plus rien afficher que mentir sur l'avancement.
 *
 * Écouté côté Vue (TableView) sous `.preparation.etape`.
 */
class EtapePreparation implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * La séquence, dans l'ordre où les jobs la traversent. `HabillerMonstres`
     * dispatche les trois suivantes (voir son `handle()`), et l'ordre de
     * dispatch EST l'ordre d'exécution puisqu'ils partagent la file `default`.
     */
    public const ETAPES = [
        'habillage' => 'Le maître du jeu peuple le donjon…',
        'scene' => 'Il en dessine les lieux…',
        'recits' => 'Il en écrit l’histoire…',
        'voix' => 'Il accorde sa voix…',
        'pret' => 'Tout est prêt.',
    ];

    public static function cle(int $groupeId): string
    {
        return "partie:preparation:{$groupeId}";
    }

    public function __construct(
        public readonly Groupe $groupe,
        public readonly string $etape,
    ) {
        $charge = $etape === 'pret' ? null : [
            'etape' => $etape,
            'libelle' => self::ETAPES[$etape] ?? $etape,
            'index' => array_search($etape, array_keys(self::ETAPES), true) ?: 0,
            'total' => count(self::ETAPES) - 1, // « pret » clôt, il ne compte pas
        ];

        $charge === null
            ? Cache::forget(self::cle($groupe->id))
            : Cache::put(self::cle($groupe->id), $charge, now()->addMinutes(5));
    }

    public function broadcastOn(): Channel
    {
        return new Channel('groupe.'.$this->groupe->identifiant);
    }

    public function broadcastAs(): string
    {
        return 'preparation.etape';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return Cache::get(self::cle($this->groupe->id))
            ?? ['etape' => 'pret', 'libelle' => self::ETAPES['pret'], 'index' => 0, 'total' => 0];
    }
}
