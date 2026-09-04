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
     * Le héros se blesse LUI-MÊME pour payer une capacité — la *Furie* du
     * Berserker, « you may lose up to 2 Body Points to immediately make an
     * attack ».
     *
     * ⚠ Volontairement hors de `ReactionEffet::SOURCES_REACTIVES` : annuler
     * d'une réaction le prix qu'on vient de payer rendrait la capacité gratuite.
     */
    public const SOURCE_SACRIFICE = 'sacrifice';

    /**
     * POISON — le saignement d'une condition (`degats_pv_body_par_tour`), infligé
     * en fin de tour par `ResolveurTour::saignerParConditions()`.
     *
     * ⚠ Hors de `SOURCES_REACTIVES`, et pour la même raison que les jetons de
     * Rejeton : on n'annule pas un poison d'un coup d'aile sombre, on le subit.
     * Les cartes réactives parlent d'un COUP reçu ; ceci est une hémorragie.
     */
    public const SOURCE_POISON = 'poison';

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
    public function __construct(private readonly MoteurReactions $reactions) {}

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

        // `reduction_degats` (Cuir tanné du barbare, Peau de fer du moine,
        // Rempart du chevalier) : −N par coup subi, plancher zéro.
        //
        // ⚠ Après l'écouteur et jamais avant : une réaction qui ANNULE le coup
        // doit ramener à zéro, et soustraire d'abord ferait qu'un coup de 1
        // dégât déjà réduit à 0 par le talent déclencherait quand même l'offre
        // de réaction — le joueur se verrait proposer d'annuler un coup qui ne
        // l'a jamais touché.
        //
        // ⚠ `SOURCE_SACRIFICE` est exclu : la *Furie* du Berserker fait payer
        // un prix, et une armure qui protège de sa propre décision rendrait la
        // capacité gratuite. Même raison qui l'exclut des réactions.
        if ($retenus > 0 && $source !== self::SOURCE_SACRIFICE) {
            $retenus = max(0, $retenus - app(Talents::class)->valeur($heros, 'reduction_degats'));
        }

        if ($retenus === 0) {
            return 0;
        }

        $avant = (int) $heros->pv_body;
        $heros->update(['pv_body' => max(0, $avant - $retenus)]);

        $subis = $avant - (int) $heros->pv_body;

        // ⚠ MÉMOIRE DES DÉGÂTS (René, 2026-09-03) : on retient la SOURCE et le
        // MONTANT du dernier coup, et le cumul par source pour la quête en
        // cours. Sans cela, une carte comme la Plume anti-poison — « restores
        // ANY of the owner's Body Points lost by poisoning » — ne peut pas
        // savoir combien rendre, et se rabat sur un forfait qui n'est pas ce
        // que la carte dit.
        //
        // Le dernier coup sert de GARDE (« may be consumed immediately after
        // being poisoned »), le cumul sert de MONTANT. Deux questions
        // différentes, deux réponses distinctes.
        $this->memoriser($heros, $source, $subis);

        // Réaction HORS TOUR : le coup a porté, on propose au joueur de
        // l'annuler (Dark Wings, Twisting Torrent). La proposition part sur son
        // canal privé et attend — la phase des monstres, elle, continue : rien
        // ici ne bloque la résolution en cours.
        $this->reactions->proposer($heros, $subis, $source, $contexte);

        // `Personnage::booted()` prend le relais pour `premier_degat_subi`.
        return $subis;
    }

    /**
     * Retient sur l'état de quête ce que le héros vient d'encaisser.
     *
     * ⚠ Sur l'ÉTAT DE QUÊTE et non sur le personnage : la mémoire meurt avec la
     * quête, comme les jetons de Rejeton et les compteurs de capacités. Un cumul
     * de poison qui traverserait le hub ferait rendre à la Plume des PV perdus
     * dans un donjon précédent.
     */
    private function memoriser(Personnage $heros, string $source, int $subis): void
    {
        $quete = $heros->groupeActif?->queteCourante;

        if ($subis <= 0 || $quete === null) {
            return;
        }

        $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->first();

        if ($etat === null) {
            return;
        }

        $cumul = (array) ($etat->degats_subis ?? []);
        $cumul[$source] = (int) ($cumul[$source] ?? 0) + $subis;

        $etat->update([
            'degats_subis' => $cumul,
            'dernier_degat' => ['source' => $source, 'montant' => $subis],
        ]);
    }
}
