<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\HerosVaSubirDegats;
use App\Models\EtatPersonnageQuete;
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
 * `infligerMindAHeros()` (2026-09-06) est la MÉTHODE SŒUR pour la jauge
 * d'esprit, PAS un paramètre `$jauge` sur celle du Body : les deux jauges ne
 * partagent ni leurs réactions, ni leur réduction, ni leur mémoire — voir son
 * docblock pour ce qu'elle reprend et ce qu'elle laisse délibérément de côté.
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
     * ÉTREINTE DU YÉTI (The Frozen Horror, doc 18 §2) : « 2 Body Points
     * automatiques (sans jet de défense, sans action possible) à chaque tour
     * suivant du MJ, jusqu'à la mort du héros ou celle du Yéti ». Saigne par
     * le MÊME lecteur que le poison (`ResolveurTour::saignerParConditions()`,
     * `conditions.effet.degats_pv_body_par_tour`), sous une source DISTINCTE
     * — sinon la Plume anti-poison rendrait à l'étreinte des PV perdus au
     * poison, et réciproquement.
     *
     * ⚠ Hors de `SOURCES_REACTIVES`, même raison que le poison et les jetons
     * de Rejeton : la carte parle d'une PRISE qui serre, pas d'un coup qu'on
     * annule d'un battement d'aile.
     */
    public const SOURCE_ETREINTE = 'etreinte';

    /**
     * Sort du Dread qui entame l'ESPRIT plutôt que le corps — *Gel de l'Esprit*
     * (Mind Freeze, non encore porté : plan glace phase 2) en sera le premier
     * exemple.
     *
     * ⚠ Clé DISTINCTE de `SOURCE_SORT_DREAD` (Body) et pas une réutilisation :
     * `memoriser()` cumule par source dans `degats_subis`, et mélanger les deux
     * jauges sous la même clé ferait rendre à la Plume anti-poison des PV de
     * Body pour des points d'esprit perdus.
     */
    public const SOURCE_SORT_DREAD_MIND = 'sort_dread_mind';

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
     *
     * Rend l'état relu — `infligerMindAHeros()` s'en resert pour poser `tombe`
     * sans reproduire la même requête juste après.
     */
    private function memoriser(Personnage $heros, string $source, int $subis): ?EtatPersonnageQuete
    {
        $quete = $heros->groupeActif?->queteCourante;

        if ($subis <= 0 || $quete === null) {
            return null;
        }

        $etat = $quete->etatsPersonnages()->where('personnage_id', $heros->id)->first();

        if ($etat === null) {
            return null;
        }

        $cumul = (array) ($etat->degats_subis ?? []);
        $cumul[$source] = (int) ($cumul[$source] ?? 0) + $subis;

        $etat->update([
            'degats_subis' => $cumul,
            'dernier_degat' => ['source' => $source, 'montant' => $subis],
        ]);

        return $etat;
    }

    /**
     * Applique `$degats` au Mind d'un héros et rend ce qui a RÉELLEMENT été
     * retiré — pendant fidèle d'`infligerAHeros()` pour la jauge d'esprit.
     *
     * ⚠ MÉTHODE SŒUR, PAS un paramètre `$jauge` sur la méthode Body : Body et
     * Mind ne partagent ni leurs réactions, ni leur réduction, ni leur mémoire
     * (voir plus bas) — un `if ($jauge === …)` toutes les cinq lignes aurait
     * fabriqué deux méthodes déguisées en une, chacune plus dure à lire que
     * les deux séparées.
     *
     * Trois choses que le Body fait et que ceci NE FAIT PAS, et pourquoi :
     *
     *  1. **Pas de `reduction_degats`** (Cuir tanné, Peau de fer, Rempart) : les
     *     trois cartes disent « damage » dans un jeu où le seul dégât physique
     *     existe. L'étendre au Mind serait inventer une règle qu'aucune carte
     *     ne porte.
     *  2. **Pas de `HerosVaSubirDegats` / `MoteurReactions::proposer()`** :
     *     *Dark Wings* et *Twisting Torrent* annulent un COUP encaissé — aucune
     *     carte réactive ne parle de l'esprit. `ReactionEffet::SOURCES_REACTIVES`
     *     reste donc sans la moindre source Mind.
     *  3. **Pas de `soin_urgence`** par la même occasion : `soinsDisponibles()`
     *     est entièrement bâti sur `soin_pv_body` / `soin_pv_body_de`, et rien
     *     ici ne l'appelle — proposer un soin d'urgence Body sur une chute
     *     d'esprit offrirait une ressource que le joueur ne peut pas dépenser
     *     pour CETTE raison.
     *
     * Ce qui EST repris : `memoriser()`, mais sous une clé de SOURCE distincte
     * (jamais `SOURCE_SORT_DREAD` telle quelle) — sinon `degats_subis` mêlerait
     * les deux jauges, et la Plume anti-poison rendrait des PV de Body pour des
     * points d'esprit perdus.
     *
     * **Chute** (arbitrage de René, 2026-09-06) : un héros à 0 Mind tombe,
     * exactement comme à 0 Body — c'est la symétrie que `ResolveurTour::resoudreRelever()`
     * anticipe déjà (il traite les deux jauges depuis le début, en la
     * qualifiant lui-même de « correcte mais dormante »). ⚠ Contrairement à la
     * branche Body, où chacun des ~14 appelants pose `tombe` lui-même après
     * avoir relu `pv_body` (marqué `// C4`), on le fait ICI, au centre : il
     * n'existe encore aucun appelant réel (Gel de l'Esprit / Mind Freeze
     * arrive en phase 2 du plan glace), donc rien n'impose d'éclater cette
     * responsabilité entre plusieurs sites — et la centraliser évite de
     * l'oublier au premier producteur qui arrivera.
     *
     * ⚠ `Personnage::booted()` N'est PAS étendu au Mind : `premier_degat_subi`
     * nomme le premier dégât SUBI dans un vocabulaire où le dégât est
     * physique (Peau de Pierre) ; l'étendre ferait expirer ce buff sur un gel
     * mental que sa carte ne mentionne jamais.
     *
     * @param  array<string, mixed>  $contexte
     */
    public function infligerMindAHeros(
        Personnage $heros,
        int $degats,
        string $source,
        array $contexte = [],
    ): int {
        $degats = max(0, $degats);

        if ($degats === 0) {
            return 0;
        }

        $avant = (int) $heros->pv_mind;
        $heros->update(['pv_mind' => max(0, $avant - $degats)]);

        $subis = $avant - (int) $heros->pv_mind;

        if ($subis === 0) {
            return 0;
        }

        $etat = $this->memoriser($heros, $source, $subis);

        if ((int) $heros->pv_mind === 0) {
            $etat?->update(['tombe' => true]); // C4 — symétrique du Body, jauge Mind
        }

        return $subis;
    }
}
