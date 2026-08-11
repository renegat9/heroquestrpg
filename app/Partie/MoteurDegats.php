<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\HerosVaSubirDegats;
use App\Models\Personnage;

/**
 * Point de passage UNIQUE des dégâts infligés à un HÉROS.
 *
 * Douze endroits écrivaient `pv_body` à la main — attaque de monstre, sort de
 * Dread, piège, tir ami, jetons de rejeton… Chacun calculait son « après » et
 * l'écrivait, ce qui interdisait deux choses :
 *
 *  1. **Intervenir avant** : `Personnage::booted()` observe la baisse une fois
 *     écrite. Assez pour expirer un buff (Peau de Pierre), inutile pour
 *     l'annuler — or *Dark Wings* et *Twisting Torrent* annulent des dégâts.
 *  2. **Savoir d'où ça vient** : l'observateur voit « −2 PV » et rien d'autre.
 *     Une réaction qui annule le coup d'un monstre ne doit pas annuler une
 *     chute dans une fosse.
 *
 * ⚠ `Personnage::booted()` RESTE en place et c'est voulu : il est le filet.
 * Ce moteur couvre les chemins de dégâts connus ; l'observateur rattrape tout
 * ce qui écrirait `pv_body` sans passer par ici, aujourd'hui ou demain. Les
 * deux ne font pas double emploi — l'un intercepte, l'autre constate.
 *
 * Voir `reference/19_mots_cles_effets.md` §Regain et §Dégâts.
 */
final class MoteurDegats
{
    /** Coup d'un monstre au corps à corps ou à distance. */
    public const SOURCE_ATTAQUE_MONSTRE = 'attaque_monstre';

    /** Sort lancé par le Dread (le maître du donjon). */
    public const SOURCE_SORT_DREAD = 'sort_dread';

    /** Piège marché, fosse, coffre piégé. */
    public const SOURCE_PIEGE = 'piege';

    /** Sort d'un héros qui touche un autre héros (doc 02 §5, S3). */
    public const SOURCE_TIR_AMI = 'tir_ami';

    /** Jetons de rejeton accrochés au héros — automatique, indéfendable. */
    public const SOURCE_REJETON = 'rejeton';

    /**
     * Applique `$degats` au héros et rend ce qui a RÉELLEMENT été retiré.
     *
     * Le retour n'est pas décoratif : un écouteur peut avoir réduit le coup, et
     * l'appelant doit journaliser ce qui s'est passé, pas ce qu'il avait prévu.
     * C'est pourquoi les payloads ne doivent plus publier `$resultat->pvBodyApres`
     * — valeur calculée par `Engine\Combat` avant toute réaction — mais les PV
     * relus après cet appel.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function infligerAHeros(
        Personnage $heros,
        int $degats,
        string $source,
        array $contexte = [],
    ): int {
        $degats = max(0, $degats);

        if ($degats === 0) {
            return 0;
        }

        // Interception : un écouteur peut réduire, voire annuler.
        // ⚠ `event()` et non `HerosVaSubirDegats::dispatch()` : la seconde
        // passe ses arguments au CONSTRUCTEUR, elle ne diffuse pas une instance
        // déjà bâtie — et on a justement besoin de relire l'objet ensuite.
        $evenement = new HerosVaSubirDegats($heros, $degats, $source, $contexte);
        event($evenement);

        $retenus = max(0, min($degats, $evenement->degats));

        if ($retenus === 0) {
            return 0;
        }

        $avant = (int) $heros->pv_body;
        $heros->update(['pv_body' => max(0, $avant - $retenus)]);

        // `Personnage::booted()` prend le relais pour `premier_degat_subi`.
        return $avant - (int) $heros->pv_body;
    }

}
