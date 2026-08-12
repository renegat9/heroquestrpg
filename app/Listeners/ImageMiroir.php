<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Engine\Des\LanceurDes;
use App\Events\HerosVaSubirDegats;
use App\Partie\MoteurSorts;

/**
 * *Image double* (répertoire elfique) — le premier écouteur de
 * `HerosVaSubirDegats`, et la démonstration que l'événement sert.
 *
 * « It causes a life-like image of the hero to appear. If an attack against the
 * hero is successful, they roll 1 red die. On a 1, 2, or 3, THE IMAGE IS
 * ATTACKED, and the hero suffers no damage. »
 *
 * ⚠ C'est une annulation **automatique**, sur jet de dé, sans décision du
 * joueur — c'est ce qui la rend portable dès aujourd'hui, là où *Ailes
 * sombres* et *Torrent tournoyant* attendent qu'on sache interroger une
 * manette au milieu de la phase des monstres. Le sort ne se dépense pas non
 * plus : il protège tant qu'il tient, et « the spell is broken the moment the
 * hero can no longer see a monster » — sa durée s'en charge, pas cet écouteur.
 *
 * Une chance sur deux exactement : 1-3 sur un d6.
 */
class ImageMiroir
{
    public function __construct(
        private readonly MoteurSorts $sorts,
        private readonly LanceurDes $des,
    ) {}

    public function handle(HerosVaSubirDegats $evenement): void
    {
        if ($evenement->degats <= 0) {
            return;
        }

        // « If an ATTACK against the hero is successful » : l'image encaisse
        // les coups, pas les pièges — on ne trompe pas une fosse avec un mirage.
        if (! in_array($evenement->source, ['attaque_monstre', 'sort_dread', 'tir_ami'], true)) {
            return;
        }

        if (! $this->sorts->aBuff($evenement->heros, 'image_miroir')) {
            return;
        }

        if ($this->des->d6() <= 3) {
            $evenement->degats = 0;
        }
    }
}
