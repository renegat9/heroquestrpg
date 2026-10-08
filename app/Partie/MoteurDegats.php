<?php

declare(strict_types=1);

namespace App\Partie;

use App\Events\HerosVaSubirDegats;
use App\Models\EtatPersonnageQuete;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Support\Journal;

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
 * `infligerAMonstre()` (2026-10-04) est une TROISIÈME méthode sœur, pour les
 * dégâts à un MONSTRE — même raison de famille que `infligerMindAHeros()` :
 * aucune réaction hors tour, aucune réduction de talent (un monstre n'a ni
 * l'un ni l'autre), mais elle existe pour une toute autre règle, propre aux
 * monstres : DOUZE endroits (`ResolveurTour`, `MoteurDread`) décidaient
 * chacun pour leur compte si une instance à 0 Body devait porter `etat:
 * vaincu` — exactement le défaut que ce fichier corrige pour les héros depuis
 * 2026-09-xx, mais côté monstre. Un monstre À PHASES (Against the Ogre Horde
 * p. 6 : « adopt new statistics […] still considered the same monster »)
 * n'y meurt pas : il change de forme. `infligerAMonstre()` est l'UNIQUE point
 * de passage qui le sait, quel que soit le coup qui l'amène à 0 — attaque de
 * héros, sort, allié, reflet de sort, eau bénite, attaque d'un autre monstre.
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
     * (Mind Freeze, `MoteurDread::sortDreadMind()`) en est le premier exemple.
     *
     * ⚠ Clé DISTINCTE de `SOURCE_SORT_DREAD` (Body) et pas une réutilisation :
     * `memoriser()` cumule par source dans `degats_subis`, et mélanger les deux
     * jauges sous la même clé ferait rendre à la Plume anti-poison des PV de
     * Body pour des points d'esprit perdus.
     */
    public const SOURCE_SORT_DREAD_MIND = 'sort_dread_mind';

    // ------------------------------------------------------------------
    // Sources des dégâts à un MONSTRE (infligerAMonstre(), 2026-10-04) —
    // purement INFORMATIVES (le journal des réactions/phases les cite), aucune
    // ne pilote de logique : contrairement aux sources ci-dessus, rien ici ne
    // dépend de QUI a frappé pour décider d'une réduction ou d'une réaction.
    // ------------------------------------------------------------------

    /** Un héros (ou son arme lancée) frappe un monstre au contact ou à distance. */
    public const SOURCE_ATTAQUE_HEROS = 'attaque_heros';

    /** Un sort de HÉROS blesse un monstre (cible directe ou collatérale d'une zone). */
    public const SOURCE_SORT_HEROS = 'sort_heros';

    /** Un allié (mercenaire, animal) frappe un monstre. */
    public const SOURCE_ATTAQUE_ALLIE = 'attaque_allie';

    /** Un monstre (sbire dominé, reflet de sort) en frappe un autre. */
    public const SOURCE_ATTAQUE_MONSTRE_SUR_MONSTRE = 'attaque_monstre_sur_monstre';

    /** Eau bénite — tue instantanément un mort-vivant (doc 16 §10). */
    public const SOURCE_EAU_BENITE = 'eau_benite';

    /**
     * Faveur « Hold the Line » (Hopekins Rest, Wizards of Morcar) — 1 dégât
     * FIXE, non résistable (la carte ne mentionne aucune défense), sur un
     * monstre qui quitte les 8 cases autour du porteur. Voir
     * App\Partie\FaveursHopekins::tenterHoldTheLine() — l'unique appelant.
     */
    public const SOURCE_FAVEUR_HOLD_THE_LINE = 'faveur_hold_the_line';

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

    /**
     * Les modificateurs de TALENT du DERNIER appel à {@see self::infligerAHeros()}
     * — `docs/contrat-api.md` §« Un talent qui s'active tout seul se VOIT »
     * (groupe 3, `des.modificateurs`). Une seule case, jamais une pile : cette
     * méthode est appelée puis relue SYNCHRONE par l'appelant, dans la même
     * requête, avant le prochain coup — pas de concurrence à couvrir.
     *
     * @var list<array{source: string, valeur: int, sur: string}>
     */
    private array $dernierModificateurs = [];

    /** @return list<array{source: string, valeur: int, sur: string}> */
    public function dernierModificateurs(): array
    {
        return $this->dernierModificateurs;
    }

    public function infligerAHeros(
        Personnage $heros,
        int $degats,
        string $source,
        array $contexte = [],
    ): int {
        $this->dernierModificateurs = [];
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
            // ⚠ `valeur()` reste la SOMME de tous les nœuds qui portent la
            // mécanique — la grille en autorise deux — et c'est elle qui doit
            // rester la RÈGLE appliquée. `noeud()` ne sert qu'à NOMMER le
            // modificateur : sur le cas réel (un seul porteur), les deux
            // coïncident.
            $valeurReduction = min($retenus, app(Talents::class)->valeur($heros, 'reduction_degats'));

            if ($valeurReduction > 0) {
                $retenus -= $valeurReduction;
                $nom = app(Talents::class)->noeud($heros, 'reduction_degats')?->nom ?? 'Réduction de dégâts';
                $this->dernierModificateurs[] = ['source' => $nom, 'valeur' => -$valeurReduction, 'sur' => 'degats'];
            }
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
     * Rend l'état relu — `infligerAHeros()` n'en a pas besoin aujourd'hui,
     * mais le signe est bon marché et un futur appelant (un autre producteur
     * de dégâts) pourrait vouloir l'état sans reproduire la requête juste
     * après. `infligerMindAHeros()` ne pose plus `tombe` dessus depuis que
     * 0 Mind met en ÉTAT DE CHOC (`Personnage::estEnChoc()`) plutôt que de
     * faire tomber (René, 2026-10-01).
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
     * **ÉTAT DE CHOC, pas une chute** (René, 2026-10-01, qui REVIENT sur son
     * arbitrage du 2026-09-06 : « un héros à 0 Mind tombe »). La compilation
     * d'erratas 2021 cite *Against the Ogre Horde* p. 9, confirmée par Hasbro
     * applicable à « every creature » : « When a creature reaches 0 Mind
     * Points, they go into shock. While at 0 Mind Points, they can only roll
     * one red movement die, 1 Attack die, and 2 Defend dice. [...] If the
     * creature later restores Mind Points, they are no longer in shock. »
     * Rien à écrire ICI pour ça : `Personnage::estEnChoc()` est un état
     * DÉRIVÉ de `pv_mind`, jamais une colonne — poser `tombe = true` est
     * donc supprimé, pas remplacé par un autre `update()`. Le plafond de dés
     * et le déplacement sans d6 sont câblés à leurs propres points de passage
     * (`ResolveurTour::frapper()`, `MoteurSorts::desDefenseHerosDetail()`,
     * `MenuMoteur::deplacementDuTour()`), qui appellent `estEnChoc()` au
     * moment du jet plutôt que de lire une colonne figée au moment du coup.
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

        $this->memoriser($heros, $source, $subis);

        return $subis;
    }

    /**
     * Applique `$degats` au Body d'un MONSTRE et rend ce qui s'est RÉELLEMENT
     * passé — l'UNIQUE point de passage par lequel un monstre peut mourir,
     * quel que soit le chemin de dégâts (attaque de héros, sort de héros ou
     * de Dread, allié, reflet de sort, eau bénite, un monstre qui en frappe un
     * autre). Voir le docblock de la classe pour pourquoi il existe.
     *
     * À 0 Body (ou moins), dans cet ORDRE — chacun scopé à CETTE rencontre
     * (`instances_monstres.capacites_reactives_utilisees`), jamais remis à
     * zéro par un simple changement de phase :
     *
     *  1. **Résilience** (`reactions_defense` contient `ignore_degats_attaque`
     *     — Gruzbella/*Resilience*, Gretzl/*Demon Wings*) : encore
     *     disponible, le coup entier est ignoré. Politique de PORTAGE
     *     délibérée (aucune carte ne dit QUAND Zargon doit la jouer, et rien
     *     dans ce projet ne lui fait choisir une mécanique) : elle ne se
     *     déclenche JAMAIS avant le coup qui achèverait sinon la phase
     *     courante — jamais gaspillée sur une égratignure.
     *  2. **Increvable une fois** (`increvable_une_fois` — Sir Ragnar, Rise
     *     of the Dread Moon p. 31 : « The first time […] reduced to 0, they
     *     are instead reduced to 1 ») : plancher à 1, une fois.
     *  3. **Phases** (`monstres.phase_suivante` déclaré sur la ligne de
     *     catalogue COURANTE) : adopte la statistique suivante — nouveau
     *     `monstre_id`, Body ET Mind pleins de la nouvelle phase, tout le
     *     reste de l'instance (position, conditions, habillage, usages déjà
     *     dépensés) inchangé, puisque « still considered the same monster ».
     *     Annoncé par un évènement de journal DÉDIÉ, en plus du retour —
     *     garanti même si un futur appelant oublie de le relayer dans son
     *     propre payload.
     *  4. **Mort réelle** (dernière phase, ou monstre ordinaire) :
     *     `recompense_reddition` (Gruzbella vaincue « s'incline et paie
     *     1000 po », jamais une vraie mort) crédite le groupe avant de poser
     *     `etat: vaincu`.
     *
     * ⚠ Ce que cette méthode NE FAIT PAS, et pourquoi, comme pour
     * `infligerMindAHeros()` : pas de `HerosVaSubirDegats` (aucune carte de
     * héros ne réagit à un coup porté à un monstre), pas de
     * `Talents::valeur('reduction_degats')` (un monstre n'en porte pas), pas
     * de `memoriser()` (`degats_subis` est un compteur de HÉROS, lu par la
     * Plume anti-poison — un monstre n'a pas d'inventaire à soigner).
     *
     * `$auteurHeros` (2026-10-08) : le héros à qui créditer la faveur
     * **Peacekeeper** (Hopekins Rest) si ce coup achève RÉELLEMENT le
     * monstre — « the Realm rewards you with 25 gold coins per monster
     * defeated ». Lu ICI, au point de passage unique, plutôt qu'aux deux
     * endroits qui le faisaient avant (2026-10-06 : l'arme au contact/à
     * distance et le sort à cible unique) : ceux-ci ne couvraient que 2 des
     * 12 chemins de dégâts, en laissant la flèche de Vindication, l'eau
     * bénite, le Toucher du Brasier et les sorts de zone — « vous » sans
     * jamais être crédité. `null` pour les chemins où personne ne frappe EN
     * SON NOM : un allié recruté (`SOURCE_ATTAQUE_ALLIE`), un piège
     * (`SOURCE_PIEGE`), un sort du Dread (`SOURCE_SORT_DREAD`) ou un monstre
     * qui en frappe un autre (`SOURCE_ATTAQUE_MONSTRE_SUR_MONSTRE`) —
     * décision de portage délibérée : la carte dit « you », jamais une
     * créature recrutée ou un mécanisme impersonnel. Le paramètre reste
     * optionnel : passé aussi sur une reddition (Gruzbella), le monstre
     * étant bien « reduced to 0 Body Points » selon la même donnée.
     *
     * @param  array<string, mixed>  $contexte
     * @return array{degats: int, pv_body: int, pv_body_max: int, etat: string,
     *     vaincu: bool, changement_phase: array{avant: string, apres: string}|null,
     *     reaction: string|null, survie_increvable: bool, reddition: bool, or_gagne: int}
     */
    public function infligerAMonstre(
        InstanceMonstre $instance,
        int $degats,
        string $source,
        array $contexte = [],
        ?Personnage $auteurHeros = null,
    ): array {
        $degats = max(0, $degats);
        $avant = (int) $instance->pv_body;
        $apres = max(0, $avant - $degats);

        $neutre = fn (int $degatsRetenus): array => [
            'degats' => $degatsRetenus,
            'pv_body' => (int) $instance->pv_body,
            'pv_body_max' => $instance->pvBodyMax(),
            'etat' => $instance->etat,
            'vaincu' => $instance->etat === 'vaincu',
            'changement_phase' => null,
            'reaction' => null,
            'survie_increvable' => false,
            'reddition' => false,
            'or_gagne' => 0,
        ];

        if ($degats === 0 || $apres > 0) {
            if ($apres !== $avant) {
                $instance->update(['pv_body' => $apres, 'etat' => 'actif']);
            }

            return $neutre($avant - $apres);
        }

        // Le coup achèverait la phase courante (ou tuerait un monstre
        // ordinaire) — politique de secours, dans l'ORDRE documenté plus haut.

        if ($instance->reactionDisponible('ignore_degats_attaque')) {
            $instance->consommerReaction('ignore_degats_attaque');
            $this->journaliserDefenseMonstre($instance, 'ignore_degats_attaque', $source, $contexte);

            return [
                'degats' => 0,
                'pv_body' => (int) $instance->pv_body,
                'pv_body_max' => $instance->pvBodyMax(),
                'etat' => 'actif',
                'vaincu' => false,
                'changement_phase' => null,
                'reaction' => 'ignore_degats_attaque',
                'survie_increvable' => false,
                'reddition' => false,
                'or_gagne' => 0,
            ];
        }

        if ($instance->reactionDisponible('increvable_une_fois')) {
            $instance->consommerReaction('increvable_une_fois');
            $instance->update(['pv_body' => 1, 'etat' => 'actif']);
            $this->journaliserDefenseMonstre($instance, 'increvable_une_fois', $source, $contexte);

            return [
                'degats' => $avant - 1,
                'pv_body' => 1,
                'pv_body_max' => $instance->pvBodyMax(),
                'etat' => 'actif',
                'vaincu' => false,
                'changement_phase' => null,
                'reaction' => null,
                'survie_increvable' => true,
                'reddition' => false,
                'or_gagne' => 0,
            ];
        }

        $prochaine = $instance->monstre->monstrePhaseSuivante();

        if ($prochaine !== null) {
            $nomAvant = $instance->monstre->nom_base;
            $nouveauMax = $this->pvMaxPhaseSuivante($instance, $prochaine);

            $instance->update([
                'monstre_id' => $prochaine->id,
                'pv_body' => $nouveauMax,
                'pv_body_max' => $nouveauMax,
                'pv_mind' => (int) $prochaine->pv_mind,
                'etat' => 'actif',
            ]);
            // La relation `monstre` reste en cache sur l'instance tant qu'on ne
            // la force pas à relire — sans ce rafraîchissement, l'appelant qui
            // relit `$instance->attaqueEffective()` juste après verrait encore
            // les dés de l'ANCIENNE phase.
            $instance->setRelation('monstre', $prochaine);

            $this->journaliserChangementPhase($instance, $nomAvant, $prochaine->nom_base);

            return [
                'degats' => $avant,
                'pv_body' => $nouveauMax,
                'pv_body_max' => $nouveauMax,
                'etat' => 'actif',
                'vaincu' => false,
                'changement_phase' => ['avant' => $nomAvant, 'apres' => $prochaine->nom_base],
                'reaction' => null,
                'survie_increvable' => false,
                'reddition' => false,
                'or_gagne' => 0,
            ];
        }

        // Mort réelle — dernière phase, ou monstre qui n'en a aucune.
        $reddition = $instance->capaciteParametree('recompense_reddition');
        $orGagne = 0;

        if (is_array($reddition) && (int) ($reddition['or'] ?? 0) > 0) {
            $orGagne = (int) $reddition['or'];
            $groupe = $instance->quete?->groupe;

            if ($groupe !== null) {
                $groupe->update(['or' => (int) $groupe->or + $orGagne]);
            }

            $this->journaliserReddition($instance, $orGagne);
        }

        $instance->update(['pv_body' => 0, 'etat' => 'vaincu']);

        // FAVEUR « Peacekeeper » (Hopekins Rest) : lue ICI, au seul point de
        // passage de la mort d'un monstre — voir le docblock de la méthode.
        // Résolue par le conteneur plutôt qu'injectée au constructeur :
        // `FaveursHopekins` dépend elle-même de `MoteurDegats` (pour
        // `tenterHoldTheLine()`), et une injection directe boucherait les
        // deux classes l'une dans l'autre.
        if ($auteurHeros !== null) {
            $groupePourFaveur = $instance->quete?->groupe;

            if ($groupePourFaveur !== null) {
                app(FaveursHopekins::class)->compterPeacekeeper(
                    $groupePourFaveur, $instance, true, $auteurHeros,
                );
            }
        }

        return [
            'degats' => $avant,
            'pv_body' => 0,
            'pv_body_max' => $instance->pvBodyMax(),
            'etat' => 'vaincu',
            'vaincu' => true,
            'changement_phase' => null,
            'reaction' => null,
            'survie_increvable' => false,
            'reddition' => $orGagne > 0,
            'or_gagne' => $orGagne,
        ];
    }

    /**
     * Bonus de Body élite appliqué à une NOUVELLE phase — même valeur que
     * `InstanceMonstre::BONUS_ELITE`, dupliquée en constante plutôt
     * qu'importée : les deux classes ne partagent aujourd'hui aucune
     * dépendance l'une vers l'autre, et il ne s'agit que d'un entier (1) fixé
     * par la règle 3.6, pas d'une donnée qui pourrait diverger.
     */
    private const BONUS_ELITE_PHASE = 1;

    /**
     * Le Body MAX de la phase suivante — à la même ÉCHELLE que la phase
     * courante, pas la valeur BRUTE du catalogue.
     *
     * `DemarreurQuete::pvAdapte()` ajuste le Body d'un boss/sous-boss à la
     * taille du groupe au PLACEMENT (`pv_catalogue × nb_héros /
     * taille_reference`, plancher 40 %) — une seule fois, jamais revisité
     * depuis. Sans ce calcul, Gruzbella posée devant un duo (Body adapté à 3
     * sur sa première phase, catalogue 5) RETROUVERAIT le Body catalogue
     * PLEIN de sa forme suivante au premier changement de phase — un boss
     * adouci pour un petit groupe durcirait soudain, EN PLEIN COMBAT, sans
     * qu'aucune règle ne le demande.
     *
     * On retrouve le ratio déjà appliqué (Body max actuel ÷ Body catalogue
     * actuel, bonus élite mis à part) et on le réapplique à la nouvelle
     * phase — ce qui restitue exactement `pvAdapte()` pour un monstre non
     * élite, et reste cohérent pour les trois phases de Gruzbella (Body 5
     * identique partout, donc un ratio de 1).
     */
    private function pvMaxPhaseSuivante(InstanceMonstre $instance, Monstre $prochaine): int
    {
        $bonusElite = $instance->elite ? self::BONUS_ELITE_PHASE : 0;
        $maxActuelSansElite = max(1, $instance->pvBodyMax() - $bonusElite);
        $catalogueActuel = max(1, (int) $instance->monstre->pv_body);
        $ratio = $maxActuelSansElite / $catalogueActuel;

        return max(1, (int) round((int) $prochaine->pv_body * $ratio)) + $bonusElite;
    }

    /**
     * Annonce un CHANGEMENT DE PHASE — journal dédié, garanti même si
     * l'appelant oublie de relayer `changement_phase` dans son propre
     * payload. « Zargon, do not reveal the second set of statistics » (Ogre
     * Horde p. 6) : le texte nomme la créature et sa nouvelle forme, jamais
     * ses dés.
     */
    private function journaliserChangementPhase(InstanceMonstre $instance, string $nomAvant, string $nomApres): void
    {
        $groupe = $instance->quete?->groupe;

        if ($groupe === null) {
            return;
        }

        Journal::ajouter($groupe, 'combat', [
            'type' => 'changement_phase',
            'instance_id' => (int) $instance->id,
            'nom' => $instance->nomAffiche(),
            'changement_phase' => ['avant' => $nomAvant, 'apres' => $nomApres],
        ]);
    }

    /**
     * Annonce une DÉFENSE RÉACTIVE consommée (Résilience/Demon Wings,
     * Increvable une fois) — même raison qu'une capacité automatique de
     * héros : un effet que rien n'annonce est injouable.
     */
    private function journaliserDefenseMonstre(
        InstanceMonstre $instance,
        string $mecanique,
        string $source,
        array $contexte,
    ): void {
        $groupe = $instance->quete?->groupe;

        if ($groupe === null) {
            return;
        }

        Journal::ajouter($groupe, 'combat', [
            'type' => 'reaction_monstre',
            'instance_id' => (int) $instance->id,
            'nom' => $instance->nomAffiche(),
            'mecanique' => $mecanique,
            'source_degats' => $source,
        ] + (isset($contexte['sort']) ? ['sort' => $contexte['sort']] : []));
    }

    /** Annonce une REDDITION (Gruzbella) — une mort qui n'en est pas une. */
    private function journaliserReddition(InstanceMonstre $instance, int $or): void
    {
        $groupe = $instance->quete?->groupe;

        if ($groupe === null) {
            return;
        }

        Journal::ajouter($groupe, 'combat', [
            'type' => 'reddition',
            'instance_id' => (int) $instance->id,
            'nom' => $instance->nomAffiche(),
            'or_gagne' => $or,
        ]);
    }
}
