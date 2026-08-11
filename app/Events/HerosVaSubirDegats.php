<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Personnage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un héros VA subir des dégâts — émis AVANT que les PV ne bougent.
 *
 * ⚠ Ce n'est pas un événement de diffusion : rien ne part vers Reverb. C'est
 * un point d'interception interne, et le « va » du nom est tout l'intérêt.
 *
 * Il existait déjà un point de passage pour les dégâts d'un héros, mais il
 * arrivait TROP TARD : `Personnage::booted()` observe la baisse de `pv_body`
 * une fois écrite, ce qui suffit à expirer un buff (Peau de Pierre) et ne
 * suffit à rien d'autre. Deux cartes officielles demandent d'intervenir
 * pendant la résolution, pas après :
 *
 *  - *Dark Wings* (Warlock) : « Cast this spell on an enemies turn after you
 *    have suffered damage. **Reduce that damage to zero** and move instantly
 *    to any unoccupied square you can see. »
 *  - *Twisting Torrent* (Moine) : « Activate this technique when you take
 *    damage to **cancel that damage**. »
 *
 * Les deux annulent des dégâts **pendant le tour d'un monstre**, là où tout,
 * chez nous, se résout dans le tour de l'acteur. Cet événement est la moitié
 * moteur de ce chantier : `$degats` est **mutable**, un écouteur peut le
 * réduire jusqu'à 0, et `App\Partie\MoteurDegats` applique ce qu'il en reste.
 *
 * ⚠ La moitié INTERFACE n'existe pas encore : demander « veux-tu annuler ? »
 * suppose d'interroger une manette au milieu de la phase des monstres, ce que
 * la boucle de jeu ne sait pas faire. Un écouteur automatique (une charge que
 * l'on dépense sans choisir) fonctionnerait dès aujourd'hui ; un choix, non.
 * C'est dit ici pour que personne ne croie la mécanique complète.
 *
 * `source` sert à distinguer ce que l'observateur ne pouvait pas voir : un
 * piège n'est pas une attaque, et une réaction qui annule « les dégâts d'un
 * coup » ne doit pas annuler une chute dans une fosse.
 */
class HerosVaSubirDegats
{
    use Dispatchable;

    /**
     * @param  int  $degats  MUTABLE — un écouteur peut le réduire (0 = annulé)
     * @param  string  $source  `attaque_monstre` · `piege` · `sort_dread` ·
     *                          `tir_ami` · `rejeton` — jamais libre : voir
     *                          les constantes de `App\Partie\MoteurDegats`
     * @param  array<string, mixed>  $contexte  de quoi décider (nom du monstre…)
     */
    public function __construct(
        public readonly Personnage $heros,
        public int $degats,
        public readonly string $source,
        public readonly array $contexte = [],
    ) {}
}
