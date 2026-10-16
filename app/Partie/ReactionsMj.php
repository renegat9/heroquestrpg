<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\LanceurDes;
use App\Engine\MotsClesReactionMj as Mot;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\SortDread;
use Illuminate\Support\Collection;

/**
 * LA COUTURE « LE MJ RÉAGIT AU SORT D'UN HÉROS » (René, 2026-10-15).
 *
 * `MoteurReactions` ne parle qu'aux héros : il pose une offre sur un canal privé et
 * attend la réponse d'un téléphone. Ici c'est le MJ qui réagit, sans joueur à
 * consulter — la résolution est SYNCHRONE, dans la requête du héros qui lance, et
 * c'est le MOTEUR qui décide (le MJ IA ne choisit jamais une mécanique). Quatre
 * cartes y sont branchées, par un seul point d'entrée :
 *
 *  - *Dispel* de Gretzl (capacité, `annule_sort_ciblant`) : annule le sort qui la
 *    prend pour cible, sans jet ;
 *  - *Dispel* de Sinestra (sort de Dread `dissipe`) : Mind + 1 dé rouge de chaque
 *    côté, le plus haut total annule le sort du héros ;
 *  - *Mirror Magic* (sort de Dread `renvoie`) : le sort qui visait le lanceur du
 *    MJ retombe sur le héros qui l'a lancé ;
 *  - *Break* de Gruzbella (capacité, `rompt_sort_actif`) : défait, APRÈS l'effet,
 *    ce que le sort a posé sur elle.
 *
 * ## Où, et quand
 *
 * `ResolveurTour::lancerSort()` est le point de passage commun du sort connu ET du
 * parchemin. La couture s'y branche à l'instant que disent les cartes — « immediately
 * after the hero casts a spell » : le sort est donc DÉJÀ lancé (un sort connu est
 * épuisé, un parchemin consommé, l'action du héros dépensée), puis annulé ou
 * renvoyé. C'est le choix que le livret ne contredit nulle part et que le
 * déroulement du plateau impose : on ne peut pas annuler un sort qu'on n'a pas lancé.
 *
 * ## Politique du moteur (aucune carte ne dit QUAND Zargon réagit)
 *
 *  - Seul un sort lancé CONTRE le camp du MJ appelle une réaction — il prend au
 *    moins un monstre pour cible (cible unique, zone, rayon). Annuler un soin ou
 *    un soutien serait gaspiller une carte que les livrets ne chiffrent pas.
 *  - Ordre, premier qui joue gagne : la capacité du monstre visé (gratuite),
 *    puis *Mirror Magic* (certaine, et la plus cruelle), puis *Dispel*.
 *  - UNE seule tentative de *Dispel* par sort de héros — pas d'acharnement.
 *  - Un lanceur qui n'est pas visé ne dissipe que ce qu'il VOIT (règle des sorts
 *    de Dread : « un sort de Dread exige la vue sur sa cible »), et ni endormi ni
 *    paralysé ni passé aux ordres des héros. Les capacités « no action required »
 *    n'ont pas cette réserve — comme *Resilience*, qu'un monstre endormi joue
 *    encore.
 *
 * ## Ce qui se paie
 *
 * Une capacité est dépensée pour la rencontre (`capacites_reactives_utilisees`,
 * colonne, snapshot compris). Un sort de Dread coûte UN usage du budget du lanceur,
 * comme tout sort de Dread (`MoteurDread::depenserSortDeReaction()`), même quand le
 * jet échoue : la carte est jouée — et elle n'est jouée qu'UNE fois par rencontre
 * (`carteDeReaction()`), comme la carte le dit.
 *
 * ## Ce que la couture ne fait pas
 *
 * Elle ne MODIFIE pas le sort : elle rend une DÉCISION (`issue`), et c'est
 * `ResolveurTour` qui l'exécute — annuler, renvoyer, ou laisser partir. Elle ne
 * sait pas non plus ce que fait un sort : les monstres visés lui sont donnés.
 *
 * Contrat : `docs/contrat-api.md` §« Le MJ réagit au sort d'un héros ». Règle en
 * vigueur : `docs/regles/sorts-dread.md`.
 */
final class ReactionsMj
{
    /** Le sort est annulé : aucun effet. */
    public const ISSUE_ANNULE = 'annule';

    /** Le sort retombe sur son lanceur (*Mirror Magic*). */
    public const ISSUE_RENVOIE = 'renvoie';

    /** *Dispel* tentée, le héros l'emporte (ou égale) : le sort part quand même. */
    public const ISSUE_RESISTE = 'resiste';

    /** *Break* : le sort a pris, puis ses effets sont levés. */
    public const ISSUE_ROMPU = 'rompu';

    public function __construct(
        private readonly LanceurDes $des,
        private readonly MoteurSorts $sorts,
        private readonly MoteurDread $dread,
    ) {}

    /**
     * AVANT l'effet : le MJ réagit-il au sort que ce héros vient de lancer ?
     *
     * @param  Collection<int, InstanceMonstre>  $visees  les monstres que le sort prend pour CIBLE
     *                                                   (vide : un sort qui ne vise aucun monstre)
     * @param  bool  $cibleUnique  le sort ne vise qu'UNE figure (ni zone ni rayon)
     * @return array<string, mixed>|null  `null` si rien ne réagit, sinon la décision :
     *                                    `{issue, mecanique, par: {instance_id, nom}, carte, jet?}`
     */
    public function auSortDeHeros(
        Quete $quete,
        Personnage $lanceur,
        EtatPersonnageQuete $etat,
        Collection $visees,
        bool $cibleUnique,
    ): ?array {
        // POLITIQUE : un sort qui ne vise aucun monstre n'est pas dirigé contre le MJ.
        if ($visees->isEmpty()) {
            return null;
        }

        return $this->annulerSortCiblant($visees)
            ?? $this->renvoyer($quete, $visees, $cibleUnique)
            ?? $this->dissiper($quete, $lanceur, $etat, $visees);
    }

    /**
     * AVANT l'effet, une photo de ce que les monstres visés portent déjà — pour que
     * `apresSortDeHeros()` ne lève que ce que CE sort a posé. Rien n'est lu pour un
     * monstre sans *Break* disponible.
     *
     * @param  Collection<int, InstanceMonstre>  $visees
     * @return array<int, list<string>>  id d'instance → conditions déjà posées
     */
    public function photographier(Collection $visees): array
    {
        $photo = [];

        foreach ($visees as $visee) {
            if ($this->capaciteJouable($visee, Mot::ROMPT_SORT_ACTIF)) {
                $photo[(int) $visee->id] = $this->conditionsPosees($visee);
            }
        }

        return $photo;
    }

    /**
     * APRÈS l'effet : *Break* — « End the effects of any one spell active on
     * Gruzbella » (Against the Ogre Horde p. 25, « Use at any time »).
     *
     * Le moteur la joue à l'instant où un sort vient de poser un état sur elle : c'est
     * le premier moment où il y a quelque chose à rompre, et le seul où ce n'est pas
     * deviner. Elle ne prévient pas le sort — le héros a perdu son sort et son action —
     * et rien ne la rend quand le sort ne prend pas (un jet de Mind réussi, un monstre
     * déjà endormi : aucun état de plus, aucune capacité dépensée).
     *
     * Ce que le sort a posé, c'est le DIFFÉRENTIEL des conditions de l'instance avant
     * et après : un seul sort, donc toutes les conditions qu'il a ajoutées, et pas une
     * de celles qui y étaient avant lui.
     *
     * @param  array<int, list<string>>  $photo  voir `photographier()`
     * @return list<array<string, mixed>>  une décision `rompu` par monstre qui a rompu
     */
    public function apresSortDeHeros(array $photo): array
    {
        $decisions = [];

        foreach ($photo as $instanceId => $avant) {
            $instance = InstanceMonstre::with('monstre')->find($instanceId);

            if ($instance === null || $instance->etat !== 'actif' || ! $instance->reactionDisponible(Mot::ROMPT_SORT_ACTIF)) {
                continue;
            }

            $nouvelles = array_values(array_diff($this->conditionsPosees($instance), $avant));

            if ($nouvelles === []) {
                continue;
            }

            foreach ($nouvelles as $condition) {
                $this->sorts->retirerConditionMonstre($instance, $condition);
            }

            $instance->consommerReaction(Mot::ROMPT_SORT_ACTIF);
            // Le MOT de chaque état est décidé ici (jamais redérivé de la clé par le fil
            // ni par un client) : `{cle, libelle}`.
            $decisions[] = $this->decision(self::ISSUE_ROMPU, Mot::ROMPT_SORT_ACTIF, $instance, null, [
                'conditions_levees' => array_map(
                    fn (string $cle) => ['cle' => $cle, 'libelle' => MoteurSorts::libelleConditionMonstre($cle)],
                    $nouvelles,
                ),
            ]);
        }

        return $decisions;
    }

    // ------------------------------------------------------------------
    // Les trois réactions d'AVANT l'effet, dans l'ordre où la couture les joue
    // ------------------------------------------------------------------

    /**
     * *Dispel* de Gretzl — « Use when Gretzl would be the target of a spell to cancel
     * that spell » (Jungles of Delthrak p. 35). Pour tout sort qui la prend pour cible,
     * zone et rayon compris : « that spell » est annulé en entier.
     *
     * @param  Collection<int, InstanceMonstre>  $visees
     * @return array<string, mixed>|null
     */
    private function annulerSortCiblant(Collection $visees): ?array
    {
        foreach ($visees as $visee) {
            if (! $this->capaciteJouable($visee, Mot::ANNULE_SORT_CIBLANT)) {
                continue;
            }

            $visee->consommerReaction(Mot::ANNULE_SORT_CIBLANT);

            return $this->decision(self::ISSUE_ANNULE, Mot::ANNULE_SORT_CIBLANT, $visee);
        }

        return null;
    }

    /**
     * *Mirror Magic* — « This spell card can be used immediately after the hero casts a
     * spell AT THE DREAD SPELLCASTER. The hero then suffers the effect of the spell that
     * was intended for the spellcaster. »
     *
     * Le sort doit donc viser ce lanceur et lui seul : un rayon ou une zone qui le
     * traverse n'est pas « lancé sur lui ». La carte ne demande aucun jet.
     *
     * @param  Collection<int, InstanceMonstre>  $visees
     * @return array<string, mixed>|null
     */
    private function renvoyer(Quete $quete, Collection $visees, bool $cibleUnique): ?array
    {
        if (! $cibleUnique || $visees->count() !== 1) {
            return null;
        }

        /** @var InstanceMonstre $cible */
        $cible = $visees->first();
        $carte = $this->carteDeReaction($quete, $cible, 'sort_visant_le_lanceur', 'renvoie');

        if ($carte === null) {
            return null;
        }

        $this->jouerCarte($cible, $quete, $carte, 'renvoie');

        return $this->decision(self::ISSUE_RENVOIE, 'renvoie', $cible, $carte);
    }

    /**
     * *Dispel* — « First the Dread spellcaster rolls 1 red die and adds the result to
     * their Mind Points. Then the hero does the same. If the Dread spellcaster's total
     * is higher, the hero's spell is cancelled. »
     *
     * Le MJ lance D'ABORD, puis le héros — l'ordre est celui de la carte, et celui de la
     * file de dés. « Mind Points » est la JAUGE (`pv_mind`) des deux côtés, comme les
     * jets de rupture et le drain de *Leach Life* : un esprit entamé dissipe, ou résiste,
     * moins bien. Le dé rouge est un d6 BRUT. L'égalité profite au héros (« higher »).
     *
     * Les monstres visés tentent avant les autres ; le premier lanceur qui peut tenter
     * tente, et c'est tout.
     *
     * @param  Collection<int, InstanceMonstre>  $visees
     * @return array<string, mixed>|null
     */
    private function dissiper(Quete $quete, Personnage $lanceur, EtatPersonnageQuete $etat, Collection $visees): ?array
    {
        $ids = $visees->pluck('id')->map(fn ($id) => (int) $id)->all();

        $autres = $quete->instancesMonstres()->where('etat', 'actif')->where('revele', true)
            ->whereNull('controle_par')->whereNotIn('id', $ids)
            ->with('monstre')->orderBy('id')->get();

        foreach ($visees->concat($autres) as $candidat) {
            $estVisee = in_array((int) $candidat->id, $ids, true);

            if (! $this->peutLancerUnSort($candidat)) {
                continue;
            }

            $carte = $this->carteDeReaction($quete, $candidat, 'sort_de_heros', 'dissipe');

            if ($carte === null) {
                continue;
            }

            // Un lanceur qui n'est pas la cible ne dissipe que ce qu'il voit.
            if (! $estVisee && ! $this->dread->voitLeHeros($quete, $candidat, $etat)) {
                continue;
            }

            $this->jouerCarte($candidat, $quete, $carte, 'dissipe');

            $des = max(1, (int) data_get($carte->effet, 'dissipe.des_rouges', 1));
            $mj = $this->totalMind((int) $candidat->pv_mind, $des);
            $heros = $this->totalMind((int) $lanceur->pv_mind, $des);

            return $this->decision(
                $mj['total'] > $heros['total'] ? self::ISSUE_ANNULE : self::ISSUE_RESISTE,
                'dissipe',
                $candidat,
                $carte,
                ['jet' => ['lanceur' => $mj, 'heros' => $heros]],
            );
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Internes
    // ------------------------------------------------------------------

    /**
     * Mind + `$des` dés rouges (d6 bruts) : `{mind, des: [faces], de, total}`. `de` est la
     * somme des faces — la face unique du cas courant, celle que le fil montre.
     *
     * @return array{mind: int, des: list<int>, de: int, total: int}
     */
    private function totalMind(int $mind, int $des): array
    {
        $faces = [];

        for ($i = 0; $i < $des; $i++) {
            $faces[] = $this->des->d6();
        }

        $de = array_sum($faces);

        return ['mind' => $mind, 'des' => $faces, 'de' => $de, 'total' => $mind + $de];
    }

    /**
     * Le sort de réaction que ce lanceur peut jouer pour ce déclencheur, ou `null` — et que
     * `$mecanique` (la clé d'`effet` qui le fait : `dissipe`, `renvoie`) n'a pas déjà
     * servi pour cette rencontre.
     *
     * ⚠ UNE FOIS par rencontre, EN PLUS du budget d'usages : « Each monster may cast each
     * spell they know once per quest » (Rise of the Dread Moon p. 4). Le budget global tient
     * lieu de compteur par sort pour les sorts d'ACTION ; il ne le peut pas pour une réaction,
     * dont le déclencheur revient à chaque tour de héros — sans ce verrou, Sinestra aurait
     * renvoyé trois sorts de suite et vidé son budget sur sa seule carte de défense.
     */
    private function carteDeReaction(Quete $quete, InstanceMonstre $lanceur, string $declencheur, string $mecanique): ?SortDread
    {
        if (! $this->peutLancerUnSort($lanceur)
            || in_array($mecanique, (array) ($lanceur->capacites_reactives_utilisees ?? []), true)) {
            return null;
        }

        return $this->dread->sortsDeReaction($lanceur, $quete, $declencheur)
            ->first(fn (SortDread $s) => ! empty($s->effet[$mecanique]));
    }

    /**
     * Joue la carte : un usage du budget du lanceur (même compteur que tout sort de Dread),
     * et la carte est marquée jouée pour la rencontre (`capacites_reactives_utilisees`,
     * colonne, snapshot compris).
     */
    private function jouerCarte(InstanceMonstre $lanceur, Quete $quete, SortDread $carte, string $mecanique): void
    {
        $this->dread->depenserSortDeReaction($lanceur, $quete, $carte);
        $lanceur->consommerReaction($mecanique);
    }

    /**
     * Peut-il LANCER un sort ? Ni endormi ni paralysé, ni passé du côté des héros
     * (`controle_par`). Les autres états ne l'en empêchent pas : *Chains of Darkness* le
     * dit en toutes lettres (« They may defend or cast spells »).
     */
    private function peutLancerUnSort(InstanceMonstre $instance): bool
    {
        return $instance->etat === 'actif'
            && (bool) $instance->revele
            && $instance->controle_par === null
            && ! $this->sorts->monstreA($instance, MoteurSorts::MONSTRE_ENDORMI)
            && ! $this->sorts->monstreA($instance, MoteurSorts::MONSTRE_PARALYSE);
    }

    /**
     * Une CAPACITÉ « no action required » est-elle encore à jouer ? Aucune réserve
     * d'incapacité : comme *Resilience*, qu'un Gruzbella endormie joue encore. C'est même
     * ce qui rend *Break* utile contre *Sleep*.
     */
    private function capaciteJouable(InstanceMonstre $instance, string $mecanique): bool
    {
        return $instance->etat === 'actif'
            && (bool) $instance->revele
            && $instance->controle_par === null
            && $instance->reactionDisponible($mecanique);
    }

    /**
     * Les conditions de monstre actuellement posées (`habillage.conditions`).
     *
     * @return list<string>
     */
    private function conditionsPosees(InstanceMonstre $instance): array
    {
        return array_values(array_keys(array_filter((array) data_get($instance->habillage, 'conditions', []))));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function decision(string $issue, string $mecanique, InstanceMonstre $par, ?SortDread $carte = null, array $extra = []): array
    {
        return [
            'issue' => $issue,
            'mecanique' => $mecanique,
            'par' => ['instance_id' => (int) $par->id, 'nom' => $par->nomAffiche()],
            'carte' => $carte?->nom,
            ...$extra,
        ];
    }
}
