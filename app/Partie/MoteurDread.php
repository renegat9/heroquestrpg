<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Des\LanceurDes;
use App\Engine\SortMental;
use App\Engine\TypeFigurine;
use App\Models\Competence;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Monstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\SortDread;
use App\Support\Journal;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moteur de la magie du Chaos et des capacités de boss (doc 09 §4, contrat
 * « Sorts de Dread & capacités des boss »).
 *
 * USAGES : un lanceur (tier sous_boss ou boss) dispose d'un nombre limité
 * d'usages par rencontre — départ playtest :
 *   - sous_boss : USAGES_SOUS_BOSS = 2
 *   - boss      : USAGES_BOSS      = 3
 * Ils vivent en COLONNE (`instances_monstres.usages_dread`, et les deux
 * verrous `invocation_dread_utilisee`/`fuite_dread_utilisee`), réarmés à
 * chaque démarrage de quête par DemarreurQuete. ⚠ Ils tenaient auparavant en
 * cache (`Cache::forever`) : la règle consolidée du projet l'interdit, et le
 * symptôme aurait été indétectable — un compteur perdu retombe à 0, donc le
 * boss cesse silencieusement de lancer ses sorts pour le reste de la quête.
 * Étant en colonne, ils entrent AUSSI dans le snapshot : une reprise ne rend
 * plus au boss des sorts qu'il avait déjà dépensés.
 *
 * PALIER : `sorts_dread.palier` est un tier MINIMUM (doc 09 §4 — « les
 * sous-boss lancent déjà les sorts mineurs, le boss final ajoute les sorts
 * vilains »). Un sous-boss ne lance donc jamais Invocation, Commandement ni
 * Fuite, même si son répertoire les liste : c'est ce qui fait qu'un même
 * archétype nommé (config/archetypes_lanceurs.php) monte en puissance avec le
 * tier de la créature qui le porte, au lieu de donner tout, tout de suite.
 *
 * LIGNE DE VUE : un sort de Dread exige la vue sur sa cible, exactement comme
 * un sort de héros (« nécessaire pour lancer un sort ou observer une cible »,
 * LR p. 14). Le filtre est le MÊME des deux côtés — figures interposées
 * bloquantes —, sans quoi le MJ jouerait une autre règle que les joueurs : le
 * boss foudroyait un héros à l'autre bout du donjon, dans une salle jamais
 * ouverte, à travers les murs.
 *
 * SORTS DE DREAD — résolution identique aux sorts héros (doc 02 §5, S2) :
 *   - dégâts (Trait de Chaos, Tempête de feu) : Engine\Combat, défense
 *     applicable, type héros ;
 *   - contrôle (Frayeur, Sommeil, Commandement) : Engine\SortMental sur le
 *     Mind du héros (attribut_mind) — binaire, Mind 0 immunisé ;
 *   - invocation (Invocation de morts-vivants) : 2 Squelettes sur cases
 *     libres adjacentes, 1×/rencontre (usage séparé en cache) ;
 *   - fuite (Fuite) : téléportation sur la case libre la plus éloignée,
 *     1×/rencontre.
 *
 * CAPACITÉS (monstres.capacites JSON) :
 *   - invocation     : même mécanique que le sort, sbires de base — elle
 *     PARTAGE le verrou 1×/rencontre du sort, pour qu'un Seigneur qui porte
 *     les deux n'invoque pas deux fois ; elle ne coûte en revanche aucun
 *     usage de Dread, c'est une capacité, pas un sort ;
 *   - frappe_de_zone : attaque touche TOUS les héros adjacents (un jet/cible) ;
 *   - regeneration   : +1 PV Body au début de son tour (plafonné au max du catalogue) ;
 *   - resistance_magique : +2 dés de défense quand un héros lui lance un sort de
 *     dégâts — branchement dans MoteurSorts::bonusDefenseResistanceMagique() ;
 *   - charge         : si hors contact mais joignable ce tour : déplacement
 *     puis attaque à +1 dé ;
 *
 * CAPACITÉS DES EXTENSIONS (Jungles of Delthrak, livret p. 48-49 — règles
 * citées dans reference/18_extensions.md, portées le 2026-08-10) :
 *   - agile          : « ignore terrain gênant/mobilier/héros en se
 *     déplaçant » — les murs, eux, tiennent ;
 *   - tacticien      : « +1 dé d'attaque contre une cible flanquée par un
 *     autre monstre » (la seconde moitié de la carte, bouger AVANT et APRÈS
 *     son action, n'est pas portée : le tour de monstre ne fractionne pas) ;
 *   - venimeux       : « dégât = paralysie, jet de 1 dé rouge pour résister
 *     sur 5-6, sinon jeton venin jusqu'à la fin du tour suivant » ;
 *   - racines_entravantes : « un héros entrant dans une case adjacente au
 *     monstre voit son mouvement stoppé net » — appliqué côté héros, dans
 *     ResolveurTour, là où les pièges tronquent déjà le chemin.
 *
 * DÉCISION DE SORT (choisirSort) — priorités, premier match gagne, sur les
 * seuls héros EN VUE :
 *   1. Tempête de feu si ≥ 2 héros DANS SA ZONE (case du lanceur + 4
 *      orthogonales) — le compte se fait sur la zone réelle, pas sur le nombre
 *      de héros debout dans la quête : la priorité 1 était sinon accordée à un
 *      sort qui ne touchait personne, et l'usage était brûlé pour rien ;
 *   2. Trait de Chaos sur un héros en vue ;
 *   3. Tempête de feu même pour un seul héros dans la zone ;
 *   4. Sommeil / Frayeur / Commandement sur le héros au Mind le plus FAIBLE
 *      **que ce sort peut encore affecter** — la cible est choisie par
 *      `cibleControle()`, le MÊME point de passage que la résolution, sinon
 *      le moteur renonce au sort en regardant un héros et le lance sur un
 *      autre ;
 *   5. Invocation si ≤ 1 autre monstre actif (verrou 1×/rencontre) ;
 *   6. Fuite si pv_body < 25 % du max (verrou 1×/rencontre).
 */
final class MoteurDread
{
    /** Usages de sorts de Dread par rencontre — valeurs playtest. */
    public const USAGES_SOUS_BOSS = 2;

    public const USAGES_BOSS = 3;

    /** Bonus de dés de défense de la capacité Résistance magique. */
    public const BONUS_RESISTANCE_MAGIQUE = 2;

    /** Condition posée par une créature venimeuse (Jungles of Delthrak). */
    public const CONDITION_ENVENIME = 'Envenimé';

    /** Nombre de sbires invoqués par sort/capacité d'invocation. */
    public const NB_SBIRES_INVOQUES = 2;

    /** Seuil de PV (fraction du max) déclenchant la Fuite. */
    public const SEUIL_FUITE = 0.25;

    /**
     * Ordre des paliers de `sorts_dread.palier` : un palier est un tier
     * MINIMUM. Un palier inconnu vaut 0 — fail open, comme partout ailleurs
     * dans le moteur : une donnée de référence manquante ne doit jamais
     * durcir le jeu en silence.
     */
    private const RANG_PALIER = ['sous_boss' => 1, 'boss' => 2];

    public function __construct(
        private readonly LanceurDes $des,
        private readonly MoteurSorts $sorts,
        private readonly MoteurDegats $degats,
        private readonly Talents $talents,
    ) {}

    // ------------------------------------------------------------------
    // Gestion des usages (DemarreurQuete + fin de rencontre)
    // ------------------------------------------------------------------

    /**
     * Réarme les usages de Dread de tous les lanceurs de la quête.
     * Appelé par DemarreurQuete au démarrage de chaque quête.
     */
    public function reinitialiserUsages(Quete $quete): void
    {
        foreach ($quete->instancesMonstres()->with('monstre')->get() as $instance) {
            $this->reinitialiserUsagesInstance($instance, $quete);
        }
    }

    /**
     * Réarme les usages d'une instance précise (utile à l'invocation de sbires,
     * qui sont créés en cours de quête — les sbires de base n'ont pas de sorts).
     */
    public function reinitialiserUsagesInstance(InstanceMonstre $instance, Quete $quete): void
    {
        $tier = $instance->monstre->tier ?? 'base';

        if (! in_array($tier, ['sous_boss', 'boss'], true)) {
            return;
        }

        $instance->update([
            'usages_dread' => $tier === 'boss' ? self::USAGES_BOSS : self::USAGES_SOUS_BOSS,
            'invocation_dread_utilisee' => false,
            'fuite_dread_utilisee' => false,
        ]);
    }

    /** Usages restants pour cette instance (0 si jamais réarmée). */
    public function usagesRestants(InstanceMonstre $instance, Quete $quete): int
    {
        return (int) $instance->usages_dread;
    }

    /** Consomme un usage (ne descend pas en dessous de 0). */
    private function consommerUsage(InstanceMonstre $instance, Quete $quete): void
    {
        $instance->update(['usages_dread' => max(0, (int) $instance->usages_dread - 1)]);
    }

    // ------------------------------------------------------------------
    // Point d'entrée : tour scripté d'un boss/sous-boss (appelé par ResolveurTour)
    // ------------------------------------------------------------------

    /**
     * Joue le tour Dread d'un boss/sous-boss : régénération d'abord, puis
     * sort de Dread si usages restants, sinon Charge si applicable, sinon
     * le comportement de base est laissé à ResolveurTour::jouerMonstre.
     *
     * Retourne null si aucune action Dread n'a été jouée (résolveur de base
     * prend le relais), ou le payload de l'action jouée.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles  héros debout non cachés
     * @return array<string, mixed>|null
     */
    public function jouerTourDread(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
    ): ?array {
        $nomMonstre = $instance->nomAffiche();
        $acteur = ['type' => 'monstre', 'id' => $instance->id, 'nom' => $nomMonstre];

        // Collecte les actions Dread jouées ce tour (régénération + action principale).
        $actions = [];

        // 1. Régénération : +1 PV Body au DÉBUT du tour (avant toute action).
        //    Une créature BRÛLÉE ne régénère plus : « damage done by fire is
        //    permanent and cannot be regenerated » (carte du troll). Le feu est
        //    la réponse au troll, et il faut que ça se voie en jeu.
        if ($this->aCapacite($instance, 'regeneration') && ! $instance->brule) {
            $regenPayload = $this->appliquerRegeneration($groupe, $instance, $acteur);

            if ($regenPayload !== null) {
                $actions[] = $regenPayload;
            }
        }

        // 2. Sort de Dread si usages restants et répertoire non vide (archétype
        //    nommé inclus — 3.8 : le répertoire peut venir de l'archétype même si
        //    le champ brut sorts_dread est vide).
        //
        //    ⚠ LES CIBLES DE SORT SONT CELLES QU'IL VOIT, pas tous les héros
        //    debout de la quête. `$cibles` reste la liste complète : elle sert
        //    encore à la Fuite (on ne se sauve pas d'un ennemi au prétexte
        //    qu'un mur le masque) et à la Charge (qui a besoin d'un chemin, pas
        //    d'une ligne de vue).
        if ($this->repertoireSorts($instance->monstre) !== [] && $this->usagesRestants($instance, $quete) > 0) {
            $enVue = $this->ciblesEnVue($quete, $instance, $cibles);
            $sortChoisi = $this->choisirSort($groupe, $quete, $instance, $cibles, $enVue);

            if ($sortChoisi !== null) {
                $this->consommerUsage($instance, $quete);
                $actions[] = $this->lancerSortDread($groupe, $quete, $instance, $sortChoisi, $cibles, $enVue, $acteur);

                return $this->fusionnerActions($actions);
            }
        }

        // 3. Capacité Invocation (monstres.capacites) : même mécanique que le
        //    sort, sbires de base, MAIS sans coûter d'usage de Dread — c'est
        //    une capacité. Elle partage le verrou 1×/rencontre du sort, sans
        //    quoi un Seigneur qui porte les deux invoquerait deux fois. Elle
        //    n'avait AUCUN lecteur jusqu'ici : deux monstres du catalogue la
        //    déclaraient et seul le sort invoquait vraiment.
        //    ⚠ Jamais AU CONTACT : un boss encerclé qui passe son tour à
        //    appeler des renforts au lieu de frapper les deux héros collés à
        //    lui est un cadeau, pas une menace. Même arbitrage que `pondre()`
        //    pour le Spawn — on engendre quand on n'a personne sous la main.
        if ($this->aCapacite($instance, 'invocation')
            && ! $instance->invocation_dread_utilisee
            && ! $this->auContact($instance, $cibles)
            && $this->assezSeulPourInvoquer($quete, $instance)) {
            $actions[] = $this->invocationCapacite($groupe, $quete, $instance, $acteur);

            return $this->fusionnerActions($actions);
        }

        // 4. Capacité Charge si hors contact mais joignable.
        if ($this->aCapacite($instance, 'charge') && $cibles->isNotEmpty()) {
            $charge = $this->tentativeCharge($groupe, $quete, $instance, $cibles, $acteur);

            if ($charge !== null) {
                $actions[] = $charge;

                return $this->fusionnerActions($actions);
            }
        }

        // Si seule la régénération a eu lieu, on la retourne seule.
        if (! empty($actions)) {
            return $this->fusionnerActions($actions);
        }

        return null; // comportement de base (approche + attaque normale)
    }

    /**
     * Fusionne plusieurs actions Dread : si une seule, la retourne telle quelle ;
     * si plusieurs (régénération + action principale), encapsule dans un payload
     * multi-actions.
     *
     * @param  list<array<string, mixed>>  $actions
     * @return array<string, mixed>
     */
    private function fusionnerActions(array $actions): array
    {
        if (count($actions) === 1) {
            return $actions[0];
        }

        // Payload composite : première action = régénération, dernière = action principale.
        return [
            'type' => 'actions_composites',
            'actions' => $actions,
        ];
    }

    // ------------------------------------------------------------------
    // Tour d'un héros sous condition de Dread (appelé par ResolveurTour)
    // ------------------------------------------------------------------

    /**
     * Vérifie si le héros est sous condition `commande` — si oui, le moteur
     * joue à sa place et consomme la condition. Retourne l'action jouée ou null.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $allies  autres héros debout (hors ce héros)
     * @return array<string, mixed>|null
     */
    public function jouerHerosSousCommandement(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        Collection $allies,
    ): ?array {
        if (! $this->herosSousCondition($personnage, 'Commandé')) {
            return null;
        }

        // Consomme la condition Commandé (durée 1, mais on la retire immédiatement).
        $this->retirerConditionHeros($personnage, 'Commandé');

        $acteur = ['type' => 'personnage', 'id' => $personnage->id, 'nom' => $personnage->nom];

        // Cible : allié adjacent ou, à défaut, allié le plus proche.
        $cible = $this->allieAdjacentOuPlusProche($groupe, $quete, $personnage, $etat, $allies);

        if ($cible === null) {
            $payload = [
                'type' => 'commandement_sans_cible',
                'personnage' => $personnage->nom,
                'action' => 'commandement_inefficace',
            ];
            Journal::ajouter($groupe, 'action', $payload, $acteur);

            return $payload;
        }

        [$ciblePersonnage, $cibleEtat, $adjacent] = $cible;

        if (! $adjacent) {
            // Avancer vers l'allié le plus proche (déplacement de base, 1 pas).
            $grille = $this->grilleQuete($quete, exceptPersonnageId: $personnage->id);
            $chemin = $this->cheminVersHeros(
                $grille,
                (int) $etat->position_x, (int) $etat->position_y,
                (int) $cibleEtat->position_x, (int) $cibleEtat->position_y,
            );

            if ($chemin !== null && count($chemin) > 0) {
                $arrivee = $chemin[0]; // 1 pas
                $etat->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);
            }

            $payload = [
                'type' => 'commandement_deplacement',
                'personnage' => $personnage->nom,
                'vers_allié' => $ciblePersonnage->nom,
                'vers' => ['x' => $etat->position_x, 'y' => $etat->position_y],
            ];
            Journal::ajouter($groupe, 'action', $payload, $acteur);

            return $payload;
        }

        // Attaquer l'allié adjacent.
        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: (int) $personnage->des_attaque,
            desDefense: $this->sorts->desDefenseHeros($ciblePersonnage),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $ciblePersonnage->pv_body,
        );

        $subis = $this->degats->infligerAHeros(
            $ciblePersonnage, $resultat->degats, MoteurDegats::SOURCE_SORT_DREAD,
            ['par' => $personnage->nom, 'commandement' => true],
        );
        $this->sorts->reveillerHeros($ciblePersonnage); // être attaqué réveille

        if ((int) $ciblePersonnage->pv_body === 0 && $subis > 0) {
            $cibleEtat->update(['tombe' => true]);
        }

        $payload = [
            'type' => 'commandement_attaque',
            'personnage' => $personnage->nom,
            'cible' => ['personnage_id' => $ciblePersonnage->id, 'nom' => $ciblePersonnage->nom],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $subis,
            'pv_body_apres' => (int) $ciblePersonnage->pv_body,
            'cible_tombee' => (int) $ciblePersonnage->pv_body === 0 && $subis > 0,
            ...$resultat->pourJournal(),
        ];
        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    // ------------------------------------------------------------------
    // Helpers publics pour ResolveurTour / MoteurSorts
    // ------------------------------------------------------------------

    /** Le héros est-il sous la condition Commandé ? */
    public function herosSousCondition(Personnage $personnage, string $nomCondition): bool
    {
        return $personnage->conditions()
            ->where('nom', $nomCondition)
            ->exists();
    }

    /**
     * Malus de dés d'attaque dû à Frayeur (condition « Apeuré » — effet
     * malus_des_attaque) : somme des malus actifs sur le héros.
     */
    public function malusDesAttaqueFrayeur(Personnage $personnage): int
    {
        $malus = 0;
        foreach ($personnage->conditions()->get() as $condition) {
            $malus += (int) data_get($condition->effet, 'malus_des_attaque', 0);
        }

        return $malus;
    }

    /**
     * Vérifie si le monstre possède la capacité Résistance magique.
     * Utilisé par MoteurSorts pour ajouter les dés de défense.
     */
    public function bonusDefenseResistanceMagique(InstanceMonstre $instance): int
    {
        return $this->aCapacite($instance, 'resistance_magique') ? self::BONUS_RESISTANCE_MAGIQUE : 0;
    }

    // ------------------------------------------------------------------
    // Capacités
    // ------------------------------------------------------------------

    /**
     * `monstres.capacites` se déclare en LISTE (`['charge']`) ou en MAP quand
     * la capacité porte des paramètres (`['spawn' => ['creature' => …]]`).
     * Les deux formes coexistent dans le catalogue : ne lire que les valeurs,
     * comme avant, rendait toute capacité paramétrée invisible à ce test —
     * c'est le piège dans lequel `spawn` était déjà tombé, et `pondre()` le
     * contournait avec un `data_get()` de son côté.
     */
    public function aCapacite(InstanceMonstre $instance, string $capacite): bool
    {
        $capacites = (array) ($instance->monstre->capacites ?? []);

        return in_array($capacite, $capacites, true) || array_key_exists($capacite, $capacites);
    }

    /**
     * Frappe de zone : attaque TOUS les héros adjacents, un jet par cible.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @return array<string, mixed>
     */
    public function frappeDeZone(
        Groupe $groupe,
        InstanceMonstre $instance,
        Collection $cibles,
        array $acteur,
    ): array {
        $nomMonstre = $instance->nomAffiche();
        $adjacents = $cibles->filter(function (EtatPersonnageQuete $c) use ($instance) {
            return abs((int) $instance->position_x - (int) $c->position_x)
                + abs((int) $instance->position_y - (int) $c->position_y) === 1;
        });

        $resultats = [];

        foreach ($adjacents as $cible) {
            $personnage = $cible->personnage;

            // Tacticien : « +1 dé contre une cible flanquée par un autre
            // monstre ». Le flanc, c'est un SECOND assaillant au contact — pas
            // le monstre qui frappe, sinon tout monstre serait son propre flanc.
            $bonusFlanc = $this->aCapacite($instance, 'tacticien')
                && $this->cibleFlanquee($quete, $instance, $cible) ? 1 : 0;

            $resultat = (new Combat($this->des))->resoudreAttaque(
                desAttaque: $instance->attaqueEffective() + $bonusFlanc,
                desDefense: $this->sorts->desDefenseHeros($personnage),
                typeDefenseur: TypeFigurine::Heros,
                pvBodyDefenseur: (int) $personnage->pv_body,
            );

            $subis = $this->degats->infligerAHeros(
                $personnage, $resultat->degats, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
                ['monstre' => $instance->nomAffiche(), 'instance_id' => (int) $instance->id],
            );
            $this->sorts->reveillerHeros($personnage);

            // Venimeux : le venin ne passe QUE si le coup a porté.
            $venin = $resultat->degats > 0 && $this->appliquerVenin($instance, $personnage);

            $tombe = (int) $personnage->pv_body === 0 && $subis > 0;

            if ($tombe) {
                $cible->update(['tombe' => true]);
            }

            $resultats[] = [
                'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
                'touches' => $resultat->touches,
                'boucliers' => $resultat->boucliers,
                'degats' => $subis,
                'pv_body_apres' => (int) $personnage->pv_body,
                'cible_tombee' => $tombe,
            ];
        }

        $payload = [
            'type' => 'frappe_de_zone',
            'monstre' => $nomMonstre,
            'resultats' => $resultats,
        ];
        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    // ------------------------------------------------------------------
    // Internals — sélection du sort
    // ------------------------------------------------------------------

    /**
     * Choisit le sort de Dread le plus pertinent à lancer selon les priorités
     * documentées en tête de classe.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles  tous les héros debout
     * @param  Collection<int, EtatPersonnageQuete>  $enVue  ceux qu'il VOIT
     */
    private function choisirSort(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
        Collection $enVue,
    ): ?SortDread {
        $sortsDisponibles = $this->sortsDisponibles($instance, $quete);

        if ($sortsDisponibles->isEmpty() || $cibles->isEmpty()) {
            return null;
        }

        // Priorité 1 et 3 : Tempête de feu. Le compte se fait sur la ZONE
        // RÉELLE — `ciblesDansZone()` est le même point de passage que la
        // résolution. Compter les héros debout de la quête (l'ancienne règle)
        // accordait la priorité 1 à un sort qui n'atteignait personne : l'usage
        // partait, le journal annonçait une tempête, et aucun héros n'était
        // touché.
        $tempete = $sortsDisponibles->firstWhere('nom', 'Tempête de feu');
        $dansZone = $tempete === null ? 0 : $this->ciblesDansZone($instance, $enVue)->count();

        if ($tempete !== null && $dansZone >= 2) {
            return $tempete;
        }

        // Priorité 2 : Trait de Chaos sur un héros en vue.
        $trait = $sortsDisponibles->firstWhere('nom', 'Trait de Chaos');

        if ($trait !== null && $enVue->isNotEmpty()) {
            return $trait;
        }

        // Priorité 3 : Tempête de feu même sur un seul héros de la zone.
        if ($tempete !== null && $dansZone >= 1) {
            return $tempete;
        }

        // Priorité 4 : Sommeil / Frayeur / Commandement. ⚠ La cible se cherche
        // POUR CHAQUE SORT — l'ancienne version ne regardait que le héros au
        // Mind le plus faible du groupe : s'il portait déjà les trois
        // conditions, le boss renonçait au contrôle alors qu'un autre héros
        // était parfaitement endormissable.
        foreach (['Sommeil', 'Frayeur', 'Commandement'] as $nomSort) {
            $sort = $sortsDisponibles->firstWhere('nom', $nomSort);

            if ($sort !== null && $this->cibleControle($sort, $enVue) !== null) {
                return $sort;
            }
        }

        // Priorité 5 : Invocation si ≤ 1 autre monstre actif.
        $invocation = $sortsDisponibles->firstWhere('nom', 'Invocation de morts-vivants');

        if ($invocation !== null
            && ! $instance->invocation_dread_utilisee
            && $this->assezSeulPourInvoquer($quete, $instance)) {
            return $invocation;
        }

        // Priorité 6 : Fuite si PV < 25 % du max.
        $fuite = $sortsDisponibles->firstWhere('nom', 'Fuite');

        if ($fuite !== null
            && (int) $instance->pv_body < max(1, (int) ($instance->pvBodyMax() * self::SEUIL_FUITE))
            && ! $instance->fuite_dread_utilisee) {
            return $fuite;
        }

        return null;
    }

    /**
     * Héros que le lanceur VOIT — même règle et même filtre que les sorts des
     * héros (`MoteurSorts::filtrerLigneDeVue()`) : figures interposées
     * bloquantes, mobilier opaque bloquant, murs et portes closes bloquants.
     * Le lanceur ne se bloque pas lui-même (`exceptInstanceId`).
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @return Collection<int, EtatPersonnageQuete>
     */
    private function ciblesEnVue(Quete $quete, InstanceMonstre $instance, Collection $cibles): Collection
    {
        if ($instance->position_x === null) {
            return $cibles;
        }

        $grille = FabriqueGrille::pour($quete, exceptInstanceId: $instance->id);
        $ix = (int) $instance->position_x;
        $iy = (int) $instance->position_y;

        return $cibles->filter(fn (EtatPersonnageQuete $c) => $c->position_x !== null
            && $grille->ligneDeVue(
                $ix, $iy, (int) $c->position_x, (int) $c->position_y, figuresBloquent: true,
            ))->values();
    }

    /**
     * Cases balayées par la Tempête de feu : celle du lanceur + les 4
     * orthogonales. SEUL point de passage — le choix du sort et sa résolution
     * lisent la même zone, sans quoi le moteur peut choisir un sort qui ne
     * touchera personne.
     *
     * @return list<array{x: int, y: int}>
     */
    private function casesZone(InstanceMonstre $instance): array
    {
        $cx = (int) $instance->position_x;
        $cy = (int) $instance->position_y;

        return [
            ['x' => $cx, 'y' => $cy],
            ['x' => $cx + 1, 'y' => $cy],
            ['x' => $cx - 1, 'y' => $cy],
            ['x' => $cx, 'y' => $cy + 1],
            ['x' => $cx, 'y' => $cy - 1],
        ];
    }

    /**
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @return Collection<int, EtatPersonnageQuete>
     */
    private function ciblesDansZone(InstanceMonstre $instance, Collection $cibles): Collection
    {
        $zone = $this->casesZone($instance);

        return $cibles->filter(function (EtatPersonnageQuete $c) use ($zone) {
            foreach ($zone as $case) {
                if ($case['x'] === (int) $c->position_x && $case['y'] === (int) $c->position_y) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Cible d'un sort de contrôle : le héros au Mind le plus FAIBLE qui ne
     * porte pas déjà la condition posée par ce sort. SEUL point de passage —
     * `choisirSort()` et `sortDreadControle()` doivent répondre la même chose,
     * sinon le moteur écarte un sort en regardant un héros et le résout sur un
     * autre.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     */
    private function cibleControle(SortDread $sort, Collection $cibles): ?EtatPersonnageQuete
    {
        $condition = (string) data_get($sort->effet, 'condition_appliquee', 'Étourdi');

        return $cibles
            ->filter(fn (EtatPersonnageQuete $e) => ! $this->herosSousCondition($e->personnage, $condition))
            ->sortBy(fn (EtatPersonnageQuete $e) => (int) $e->personnage->attribut_mind)
            ->first();
    }

    /**
     * Un héros est-il au contact ? MÊME notion qu'ailleurs dans le moteur —
     * Manhattan = 1, orthogonal strict.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     */
    private function auContact(InstanceMonstre $instance, Collection $cibles): bool
    {
        return $cibles->contains(fn (EtatPersonnageQuete $c) => abs((int) $instance->position_x - (int) $c->position_x)
            + abs((int) $instance->position_y - (int) $c->position_y) === 1);
    }

    /** L'invocation ne se déclenche que si le lanceur est presque seul (≤ 1 autre monstre actif). */
    private function assezSeulPourInvoquer(Quete $quete, InstanceMonstre $instance): bool
    {
        return $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->whereKeyNot($instance->id)
            ->count() <= 1;
    }

    /**
     * Sorts de Dread disponibles pour cette instance : ceux du répertoire,
     * matchés dans le catalogue SortDread, PUIS filtrés sur le palier.
     *
     * `palier` est un tier MINIMUM (doc 09 §4) et n'avait jusqu'ici aucun
     * lecteur : un sous-boss pouvait lancer les sorts vilains réservés au boss
     * final. Le filtre est aussi ce qui fait vivre les archétypes nommés — le
     * Chamane Gobelin (sous-boss) et un chaman de rang boss partagent le
     * répertoire `chaman_orque`, seul le second commande les héros.
     *
     * @return \Illuminate\Support\Collection<int, SortDread>
     */
    private function sortsDisponibles(InstanceMonstre $instance, Quete $quete): \Illuminate\Support\Collection
    {
        $noms = $this->repertoireSorts($instance->monstre);

        if (empty($noms)) {
            return collect();
        }

        $rangLanceur = self::RANG_PALIER[$instance->monstre->tier ?? 'base'] ?? 0;

        return SortDread::whereIn('nom', $noms)->get()
            ->filter(fn (SortDread $s) => (self::RANG_PALIER[$s->palier] ?? 0) <= $rangLanceur)
            ->keyBy('nom')->values();
    }

    /**
     * Répertoire de sorts de Dread d'un monstre (3.8) : si un archétype lanceur
     * nommé est défini ET connu de config/archetypes_lanceurs.php, on prend son
     * répertoire COMPLET ; sinon la liste per-monstre `sorts_dread` du catalogue.
     *
     * @return list<string>
     */
    private function repertoireSorts(Monstre $monstre): array
    {
        $archetype = $monstre->archetype_lanceur;

        if (is_string($archetype) && $archetype !== '') {
            $sorts = config("archetypes_lanceurs.{$archetype}.sorts");
            if (is_array($sorts) && $sorts !== []) {
                return array_values($sorts);
            }
        }

        return array_values((array) ($monstre->sorts_dread ?? []));
    }

    // ------------------------------------------------------------------
    // Internals — lancement des sorts
    // ------------------------------------------------------------------

    /**
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function lancerSortDread(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        Collection $cibles,
        Collection $enVue,
        array $acteur,
    ): array {
        // ⚠ Dégâts et contrôle visent `$enVue`, la Fuite raisonne sur `$cibles` :
        // se téléporter loin des seuls héros VISIBLES reviendrait à sauter dans
        // les bras de celui qu'un mur masquait.
        return match ($sort->type) {
            'degats' => $this->sortDreadDegats($groupe, $quete, $instance, $sort, $enVue, $acteur),
            'controle' => $this->sortDreadControle($groupe, $instance, $sort, $enVue, $acteur),
            'invocation' => $this->sortDreadInvocation($groupe, $quete, $instance, $sort, $acteur),
            'fuite' => $this->sortDreadFuite($groupe, $quete, $instance, $sort, $cibles, $acteur),
            default => $this->sortDreadGenericJournal($groupe, $sort, $acteur),
        };
    }

    /**
     * Sorts de dégâts (Trait de Chaos, Tempête de feu).
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadDegats(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        Collection $cibles,
        array $acteur,
    ): array {
        $desDegats = (int) data_get($sort->effet, 'des_degats', 2);

        // Tempête de feu : zone (case ciblée + 4 orthogonales autour du lanceur).
        if ($sort->nom === 'Tempête de feu') {
            return $this->tempeteDeFeu($groupe, $quete, $instance, $sort, $cibles, $acteur, $desDegats);
        }

        // Trait de Chaos : 1 héros en vue — le PLUS PROCHE, départage par les PV
        // les plus bas. Le commentaire annonçait déjà « le plus proche / le plus
        // faible » et le code prenait `first()`, c'est-à-dire l'ordre des id en
        // base : le boss visait le fondateur du groupe toute la campagne.
        $cible = $cibles
            ->sortBy([
                fn (EtatPersonnageQuete $e) => abs((int) $instance->position_x - (int) $e->position_x)
                    + abs((int) $instance->position_y - (int) $e->position_y),
                fn (EtatPersonnageQuete $e) => (int) $e->personnage->pv_body,
            ])
            ->first();

        if ($cible === null) {
            return $this->sortDreadGenericJournal($groupe, $sort, $acteur);
        }

        $personnage = $cible->personnage;

        // Anneau de Feu : la carte vise « Fire OR CHAOS FIRE spells » — les
        // sorts de Dread comptent donc autant que ceux des héros. Même lecteur
        // des deux côtés, pour qu'un anneau ne protège pas d'un feu sur deux.
        if ($this->sorts->absorbeDegat($personnage, data_get($sort->effet, 'type_degat'))) {
            return $this->sortDreadGenericJournal($groupe, $sort, $acteur);
        }

        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: $desDegats,
            desDefense: $this->sorts->desDefenseHeros($personnage),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $personnage->pv_body,
        );

        $subis = $this->degats->infligerAHeros(
            $personnage, $resultat->degats, MoteurDegats::SOURCE_SORT_DREAD,
            ['sort' => $sort['nom'] ?? null],
        );
        $this->sorts->reveillerHeros($personnage);

        if ((int) $personnage->pv_body === 0 && $subis > 0) {
            $cible->update(['tombe' => true]);
        }

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
            'des_degats' => $desDegats,
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $subis,
            'pv_body_apres' => (int) $personnage->pv_body,
            'cible_tombee' => (int) $personnage->pv_body === 0 && $subis > 0,
            ...$resultat->pourJournal(),
        ];
        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    /**
     * Tempête de feu : case ciblée + 4 orthogonales du lanceur, 2 dés par héros présent.
     * La case "ciblée" est le lanceur lui-même (zone centrée sur le boss).
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function tempeteDeFeu(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        Collection $cibles,
        array $acteur,
        int $desDegats,
    ): array {
        // Cases affectées : la case du lanceur + 4 orthogonales — MÊME calcul
        // que celui qui a fait choisir le sort (`casesZone()`).
        $casesAffectees = $this->casesZone($instance);

        $resultats = [];

        foreach ($this->ciblesDansZone($instance, $cibles) as $cible) {
            $personnage = $cible->personnage;
            $resultat = (new Combat($this->des))->resoudreAttaque(
                desAttaque: $desDegats,
                desDefense: $this->sorts->desDefenseHeros($personnage),
                typeDefenseur: TypeFigurine::Heros,
                pvBodyDefenseur: (int) $personnage->pv_body,
            );

            $subis = $this->degats->infligerAHeros(
                $personnage, $resultat->degats, MoteurDegats::SOURCE_SORT_DREAD,
                ['sort' => 'Tempête de feu'],
            );
            $this->sorts->reveillerHeros($personnage);

            if ((int) $personnage->pv_body === 0 && $subis > 0) {
                $cible->update(['tombe' => true]);
            }

            $resultats[] = [
                'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
                'des_degats' => $desDegats,
                'touches' => $resultat->touches,
                'boucliers' => $resultat->boucliers,
                'degats' => $subis,
                'pv_body_apres' => (int) $personnage->pv_body,
                'cible_tombee' => (int) $personnage->pv_body === 0 && $subis > 0,
                ...$resultat->pourJournal(),
            ];
        }

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'cases_affectees' => $casesAffectees,
            'resultats' => $resultats,
        ];
        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    /**
     * Sorts de contrôle (Frayeur, Sommeil, Commandement) : jet de Mind du héros,
     * binaire (S2). Cible = héros au Mind le plus faible non déjà sous cette condition.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadControle(
        Groupe $groupe,
        InstanceMonstre $instance,
        SortDread $sort,
        Collection $cibles,
        array $acteur,
    ): array {
        $conditionNom = (string) data_get($sort->effet, 'condition_appliquee', 'Étourdi');

        // Ciblage : `cibleControle()`, le point de passage partagé avec le choix.
        $cible = $this->cibleControle($sort, $cibles);

        if ($cible === null) {
            return $this->sortDreadGenericJournal($groupe, $sort, $acteur);
        }

        $personnage = $cible->personnage;
        $mindHeros = (int) $personnage->attribut_mind;
        $resultat = (new SortMental($this->des))->resoudre($mindHeros);

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
            'mind_cible' => $mindHeros,
            'issue' => $resultat->issue->value,
            'succes' => $resultat->succes,
            'effet_applique' => $resultat->effetApplique(),
            'faces' => array_map(fn ($f) => $f->value, $resultat->faces),
        ];

        if ($resultat->effetApplique()) {
            // `annuler_effet_magique` (Contresort du magicien et du warlock,
            // Verbe ancien du druide) : la résistance naturelle a échoué — une
            // SECONDE chance, jet de Mind indépendant, annule l'effet magique
            // avant qu'il ne soit posé.
            $contresort = null;
            if ($this->talents->a($personnage, 'annuler_effet_magique')) {
                $jetContresort = (new SortMental($this->des))->resoudre($mindHeros);
                $contresort = [
                    'reussi' => ! $jetContresort->effetApplique(),
                    'faces' => array_map(fn ($f) => $f->value, $jetContresort->faces),
                ];
                $payload['contresort'] = $contresort;
            }

            if ($contresort === null || ! $contresort['reussi']) {
                $duree = (int) data_get($sort->effet, 'duree_tours', 0);
                $this->poserConditionHeros($personnage, $conditionNom, $duree, 'sort_dread:'.$sort->nom);
                $payload['condition'] = $conditionNom;
            }
        }

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Invocation de morts-vivants : 2 Squelettes sur cases libres adjacentes
     * au lanceur, 1×/rencontre.
     *
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadInvocation(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        array $acteur,
    ): array {
        $invoques = $this->invoquerSbires(
            $quete,
            $instance,
            (array) data_get($sort->effet, 'invoque', ['Squelette']),
            (int) data_get($sort->effet, 'nombre', self::NB_SBIRES_INVOQUES),
        );

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'invoques' => $invoques,
        ];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Capacité `invocation` (`monstres.capacites`) — même geste que le sort,
     * mais sans usage de Dread. Elle peut nommer ses sbires comme `spawn` le
     * fait (`['invocation' => ['creature' => 'Zombie']]`) ; sans précision, ce
     * sont les morts-vivants de base.
     *
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function invocationCapacite(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        array $acteur,
    ): array {
        $creature = data_get($instance->monstre?->capacites, 'invocation.creature');
        $noms = $creature === null ? ['Squelette', 'Zombie'] : [(string) $creature];

        $invoques = $this->invoquerSbires($quete, $instance, $noms, self::NB_SBIRES_INVOQUES);

        $payload = [
            'type' => 'capacite_dread',
            'capacite' => 'invocation',
            'invoques' => $invoques,
        ];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * **Spawn** (Jungles of Delthrak, p. 48) : « le monstre crée un Spawnling
     * adjacent OU déplace tous ses Spawnlings actifs, **en alternative** à
     * chaque tour ».
     *
     * « En alternative » est la limite écrite, et la seule : pas de plafond de
     * population dans le livret. On n'en invente donc pas — le monstre pond au
     * lieu d'agir, ce qui lui coûte son attaque. Le CHOIX entre les deux options
     * de la carte est le nôtre : il pond quand il n'a personne au contact
     * (attaquer lui est de toute façon interdit), et frappe sinon. Déplacer ses
     * rejetons n'est pas porté — nos monstres se déplacent déjà seuls.
     *
     * La créature engendrée est nommée par la capacité
     * (`['spawn' => ['creature' => 'Rejeton putride']]`) : notre capacité
     * `invocation` ne sait invoquer que ce que dit un SORT, c'est-à-dire des
     * morts-vivants — elle aurait fait cracher des squelettes au serpent.
     *
     * @return array<string, mixed>|null
     */
    public function pondre(Groupe $groupe, Quete $quete, InstanceMonstre $instance, array $acteur): ?array
    {
        $creature = (string) data_get($instance->monstre?->capacites, 'spawn.creature', '');

        if ($creature === '') {
            return null;
        }

        $catalogue = Monstre::where('nom_base', $creature)->first();

        if ($catalogue === null) {
            return null; // catalogue non semé : on n'invente pas de créature
        }

        $grille = $this->grilleQuete($quete, exceptInstanceId: $instance->id);
        $libre = null;

        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $x = (int) $instance->position_x + $dx;
            $y = (int) $instance->position_y + $dy;

            if ($grille->estTraversable($x, $y)) {
                $libre = ['x' => $x, 'y' => $y];
                break;
            }
        }

        if ($libre === null) {
            return null; // ceinturé : rien à faire de ce tour
        }

        $rejeton = InstanceMonstre::create([
            'quete_id' => $quete->id,
            'monstre_id' => $catalogue->id,
            'pv_body' => $catalogue->pv_body,
            'pv_mind' => $catalogue->pv_mind,
            'position_x' => $libre['x'],
            'position_y' => $libre['y'],
            'etat' => 'actif',
            'revele' => true,
        ]);

        $this->reinitialiserUsagesInstance($rejeton, $quete);

        $payload = [
            'type' => 'spawn',
            'monstre' => $instance->nomAffiche(),
            'engendre' => ['instance_id' => $rejeton->id, 'nom' => $catalogue->nom_base,
                'x' => $libre['x'], 'y' => $libre['y']],
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * **Le rejeton s'ACCROCHE** au lieu de frapper (Jungles of Delthrak).
     *
     * Sa fiche officielle porte **Attaque 0** : il ne fait aucun dégât de
     * combat. Sa menace est le JETON — « un jeton posé sur la fiche d'un héros
     * inflige 1 Body Point automatique et indéfendable à chaque fin de tour tant
     * qu'il reste en sa possession, cumulable ». Sur son tour, adjacent à un
     * héros, la figurine devient donc ce jeton et quitte le plateau.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>|null
     */
    public function accrocher(Groupe $groupe, InstanceMonstre $instance, Collection $cibles, array $acteur): ?array
    {
        if (! $this->aCapacite($instance, 's_accroche')) {
            return null;
        }

        $porteur = $cibles->first(fn (EtatPersonnageQuete $c) => abs((int) $c->position_x - (int) $instance->position_x)
            + abs((int) $c->position_y - (int) $instance->position_y) === 1);

        if ($porteur === null) {
            return null; // personne au contact : il avance, il n'accroche pas
        }

        $porteur->update(['jetons_rejeton' => (int) $porteur->jetons_rejeton + 1]);
        $instance->update(['etat' => 'vaincu']); // la figurine devient le jeton

        $payload = [
            'type' => 'rejeton_accroche',
            'monstre' => $instance->nomAffiche(),
            'cible' => ['personnage_id' => $porteur->personnage_id, 'nom' => $porteur->personnage?->nom],
            'jetons' => (int) $porteur->fresh()->jetons_rejeton,
        ];

        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    /**
     * **Tacticien** — la cible est-elle FLANQUÉE, c'est-à-dire au contact d'un
     * second monstre actif ?
     *
     * L'assaillant lui-même ne compte pas : sans cette exclusion, tout monstre
     * serait son propre flanc et le bonus vaudrait tout le temps.
     */
    public function cibleFlanquee(Quete $quete, InstanceMonstre $assaillant, EtatPersonnageQuete $cible): bool
    {
        return $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true)
            ->whereKeyNot($assaillant->id)
            ->get()
            ->contains(fn (InstanceMonstre $autre) => abs((int) $autre->position_x - (int) $cible->position_x)
                + abs((int) $autre->position_y - (int) $cible->position_y) === 1);
    }

    /**
     * **Venimeux** — « dégât = paralysie, jet de 1 dé rouge pour résister sur
     * 5-6, sinon jeton venin jusqu'à la fin du tour suivant ».
     *
     * Le seuil est lu sur le d6 BRUT (5 ou 6) et non sur une face de combat :
     * nos faces regroupent 4-5 en bouclier blanc, ce qui écraserait la moitié
     * de la règle. Rend `true` si le venin a pris.
     */
    public function appliquerVenin(InstanceMonstre $instance, Personnage $personnage): bool
    {
        if (! $this->aCapacite($instance, 'venimeux')) {
            return false;
        }

        if ($this->des->d6() >= 5) {
            return false; // résisté au jet
        }

        // …et la résistance NOMMÉE d'un talent s'applique aussi : le venin
        // posait sa condition en direct, court-circuitant `Competence::resisteA`
        // par lequel passent tous les autres chemins (pièges, sorts de Dread).
        // Un talent de résistance ne doit pas dépendre de QUI applique l'effet
        // (audit des talents, 2026-08-10).
        if (Competence::resisteA($personnage, self::CONDITION_ENVENIME)) {
            return false;
        }

        $condition = Condition::where('nom', self::CONDITION_ENVENIME)->first();

        if ($condition === null) {
            return false; // catalogue non semé : on n'invente pas de condition
        }

        $personnage->conditions()->syncWithoutDetaching([
            $condition->id => ['duree' => max(1, (int) $condition->duree_defaut), 'source' => 'venin'],
        ]);

        return true;
    }

    /**
     * Fuite : téléportation du lanceur sur la case libre la plus éloignée
     * des héros (distance de Manhattan maximale), 1×/rencontre.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadFuite(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        Collection $cibles,
        array $acteur,
    ): array {
        $instance->update(['fuite_dread_utilisee' => true]);

        $caseCible = $this->caseLaPlusEloignee($quete, $instance, $cibles);

        if ($caseCible !== null) {
            $instance->update(['position_x' => $caseCible['x'], 'position_y' => $caseCible['y']]);
        }

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'vers' => $caseCible ?? ['x' => $instance->position_x, 'y' => $instance->position_y],
        ];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    // ------------------------------------------------------------------
    // Capacité Charge
    // ------------------------------------------------------------------

    /**
     * Charge : si le monstre est hors contact mais peut atteindre un héros
     * ce tour (déplacement fixe du catalogue), déplacement + attaque +1 dé.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>|null null si charge non applicable
     */
    private function tentativeCharge(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
        array $acteur,
    ): ?array {
        $nomMonstre = $instance->nomAffiche();
        $grille = $this->grilleQuete($quete, exceptInstanceId: $instance->id);

        // Agile : ni le mobilier ni les figures ne barrent plus le chemin.
        if ($this->aCapacite($instance, 'agile')) {
            $grille->autoriserFranchissement();
        }

        // Vérifier que le monstre n'est pas déjà adjacent.
        foreach ($cibles as $cible) {
            if ($grille->sontAdjacentes(
                (int) $instance->position_x, (int) $instance->position_y,
                (int) $cible->position_x, (int) $cible->position_y,
            )) {
                return null; // Déjà au contact : pas de charge.
            }
        }

        // Chercher la cible joignable la plus proche.
        $meilleure = null;

        foreach ($cibles as $cible) {
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $cx = (int) $cible->position_x + $dx;
                $cy = (int) $cible->position_y + $dy;
                $chemin = $grille->chemin(
                    (int) $instance->position_x, (int) $instance->position_y,
                    $cx, $cy,
                );

                if ($chemin !== null && count($chemin) <= (int) $instance->monstre->deplacement) {
                    if ($meilleure === null || count($chemin) < count($meilleure[1])) {
                        $meilleure = [$cible, $chemin];
                    }
                }
            }
        }

        if ($meilleure === null) {
            return null; // hors de portée même en chargeant
        }

        [$cible, $chemin] = $meilleure;

        // Déplacement jusqu'à la case adjacente à la cible.
        if (count($chemin) > 0) {
            $arrivee = end($chemin);
            $instance->update(['position_x' => $arrivee['x'], 'position_y' => $arrivee['y']]);
        }

        // Attaque +1 dé.
        $personnage = $cible->personnage;
        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: (int) $instance->monstre->attaque + 1,
            desDefense: $this->sorts->desDefenseHeros($personnage),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $personnage->pv_body,
        );

        $subis = $this->degats->infligerAHeros(
            $personnage, $resultat->degats, MoteurDegats::SOURCE_ATTAQUE_MONSTRE,
            ['monstre' => $nomMonstre, 'charge' => true, 'instance_id' => (int) $instance->id],
        );
        $this->sorts->reveillerHeros($personnage);

        if ((int) $personnage->pv_body === 0 && $subis > 0) {
            $cible->update(['tombe' => true]);
        }

        $payload = [
            'type' => 'charge',
            'monstre' => $nomMonstre,
            'vers' => ['x' => $instance->position_x, 'y' => $instance->position_y],
            'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
            'des_attaque' => (int) $instance->monstre->attaque + 1,
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $subis,
            'pv_body_apres' => (int) $personnage->pv_body,
            'cible_tombee' => (int) $personnage->pv_body === 0 && $subis > 0,
            ...$resultat->pourJournal(),
        ];
        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    // ------------------------------------------------------------------
    // Internals — invocation de sbires
    // ------------------------------------------------------------------

    /**
     * Fait apparaître des sbires sur les cases libres adjacentes au lanceur.
     * Le verrou 1×/rencontre est posé ICI, quel que soit l'appelant (sort ou
     * capacité) : un Seigneur qui porte les deux invoque une fois, pas deux.
     *
     * @param  list<string>  $nomsInvocables
     * @return list<array<string, mixed>>
     */
    private function invoquerSbires(
        Quete $quete,
        InstanceMonstre $lanceur,
        array $nomsInvocables,
        int $nombre,
    ): array {
        $lanceur->update(['invocation_dread_utilisee' => true]);

        // Récupère le premier monstre de base dont le nom est dans la liste.
        /** @var Monstre|null $catalogueSbire */
        $catalogueSbire = Monstre::query()
            ->where('tier', 'base')
            ->whereIn('nom_base', $nomsInvocables)
            ->orderBy('id')
            ->first();

        if ($catalogueSbire === null) {
            // Repli : n'importe quel monstre de base.
            $catalogueSbire = Monstre::query()->where('tier', 'base')->orderBy('id')->first();
        }

        if ($catalogueSbire === null) {
            return [];
        }

        // Cases libres adjacentes au lanceur.
        $casesLibres = $this->casesLibresAdjacentes($quete, $lanceur);
        $invoques = [];

        for ($i = 0; $i < $nombre && isset($casesLibres[$i]); $i++) {
            $case = $casesLibres[$i];
            InstanceMonstre::create([
                'quete_id' => $quete->id,
                'monstre_id' => $catalogueSbire->id,
                'pv_body' => $catalogueSbire->pv_body,
                'pv_mind' => $catalogueSbire->pv_mind,
                'position_x' => $case['x'],
                'position_y' => $case['y'],
                'etat' => 'actif',
            ]);

            $invoques[] = ['monstre' => $catalogueSbire->nom_base, 'x' => $case['x'], 'y' => $case['y']];
        }

        return $invoques;
    }

    // ------------------------------------------------------------------
    // Internals — conditions des héros
    // ------------------------------------------------------------------

    /**
     * Pose une condition du catalogue sur un héros (via personnage_conditions).
     * Si duree = 0, utilise la duree_defaut du catalogue.
     */
    private function poserConditionHeros(
        Personnage $personnage,
        string $nomCondition,
        int $dureeOverride,
        string $source,
    ): void {
        $condition = Condition::where('nom', $nomCondition)->first();

        if ($condition === null || Competence::resisteA($personnage, $nomCondition)) {
            return; // condition inconnue, ou résistance nommée (Sang robuste vs Empoisonné)
        }

        $duree = $dureeOverride > 0 ? $dureeOverride : (int) $condition->duree_defaut;

        $personnage->conditions()->attach($condition->id, [
            'duree' => $duree,
            'source' => $source,
        ]);
    }

    /** Retire toutes les lignes d'une condition par son nom. */
    private function retirerConditionHeros(Personnage $personnage, string $nomCondition): void
    {
        $ids = Condition::where('nom', $nomCondition)->pluck('id');

        DB::table('personnage_conditions')
            ->where('personnage_id', $personnage->id)
            ->whereIn('condition_id', $ids)
            ->delete();
    }

    // ------------------------------------------------------------------
    // Internals — Régénération
    // ------------------------------------------------------------------

    /**
     * Applique la régénération et retourne le payload si des PV ont été
     * récupérés, null sinon (déjà au max).
     *
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>|null
     */
    public function appliquerRegeneration(
        Groupe $groupe,
        InstanceMonstre $instance,
        array $acteur,
    ): ?array {
        $maxPv = $instance->pvBodyMax();
        $avant = (int) $instance->pv_body;

        if ($avant >= $maxPv) {
            return null; // déjà au max
        }

        $apres = min($maxPv, $avant + 1);
        $instance->update(['pv_body' => $apres]);

        $payload = [
            'type' => 'regeneration',
            'monstre' => $instance->nomAffiche(),
            'pv_avant' => $avant,
            'pv_apres' => $apres,
        ];

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    // ------------------------------------------------------------------
    // Internals — géométrie
    // ------------------------------------------------------------------

    /**
     * Case libre la plus éloignée des héros (distance de Manhattan max).
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @return array{x: int, y: int}|null
     */
    private function caseLaPlusEloignee(
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
    ): ?array {
        $cases = $quete->carte?->grille['cases'] ?? [];
        $hauteur = count($cases);
        $largeur = isset($cases[0]) ? count($cases[0]) : 0;

        if ($hauteur === 0 || $largeur === 0) {
            return null;
        }

        // Occupées (hors le lanceur lui-même).
        $occupees = [];
        foreach ($quete->etatsPersonnages()->get() as $etat) {
            $occupees["{$etat->position_x},{$etat->position_y}"] = true;
        }
        foreach ($quete->instancesMonstres()->where('etat', 'actif')->get() as $m) {
            if ($m->id !== $instance->id) {
                $occupees["{$m->position_x},{$m->position_y}"] = true;
            }
        }

        $meilleure = null;
        $maxDist = -1;

        for ($y = 0; $y < $hauteur; $y++) {
            for ($x = 0; $x < $largeur; $x++) {
                $type = $cases[$y][$x] ?? 'm';

                if ($type === 'm' || isset($occupees["{$x},{$y}"])) {
                    continue;
                }

                // Distance minimale aux héros.
                $distMin = PHP_INT_MAX;
                foreach ($cibles as $cible) {
                    $d = abs($x - (int) $cible->position_x) + abs($y - (int) $cible->position_y);
                    $distMin = min($distMin, $d);
                }

                if ($distMin > $maxDist) {
                    $maxDist = $distMin;
                    $meilleure = ['x' => $x, 'y' => $y];
                }
            }
        }

        return $meilleure;
    }

    /**
     * Cases libres orthogonalement adjacentes au lanceur, pour y poser des
     * sbires. Passe par la grille — donc par le mobilier, les figures et les
     * portes — au lieu de relire `carte.grille['cases']` à la main : la boucle
     * maison faisait apparaître un squelette sur une table, et sur un héros à
     * terre.
     *
     * @return list<array{x: int, y: int}>
     */
    private function casesLibresAdjacentes(Quete $quete, InstanceMonstre $lanceur): array
    {
        $grille = $this->grilleQuete($quete, exceptInstanceId: $lanceur->id);
        $libres = [];

        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $x = (int) $lanceur->position_x + $dx;
            $y = (int) $lanceur->position_y + $dy;

            if ($grille->estTraversable($x, $y)) {
                $libres[] = ['x' => $x, 'y' => $y];
            }
        }

        return $libres;
    }

    /**
     * Chemin (BFS) entre deux positions de héros sur la grille de la quête.
     *
     * @return list<array{x: int, y: int}>|null
     */
    private function cheminVersHeros(Grille $grille, int $dx, int $dy, int $ax, int $ay): ?array
    {
        // On cherche le chemin vers une case adjacente à la cible.
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$ddx, $ddy]) {
            $cx = $ax + $ddx;
            $cy = $ay + $ddy;
            $chemin = $grille->chemin($dx, $dy, $cx, $cy);

            if ($chemin !== null) {
                return $chemin;
            }
        }

        return null;
    }

    /**
     * Allié adjacent OU, à défaut, allié le plus proche pour le Commandement.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $allies
     * @return array{0: Personnage, 1: EtatPersonnageQuete, 2: bool}|null [personnage, etat, adjacent]
     */
    private function allieAdjacentOuPlusProche(
        Groupe $groupe,
        Quete $quete,
        Personnage $personnage,
        EtatPersonnageQuete $etat,
        Collection $allies,
    ): ?array {
        if ($allies->isEmpty()) {
            return null;
        }

        // Cherche un allié adjacent.
        foreach ($allies as $allieEtat) {
            if (abs((int) $etat->position_x - (int) $allieEtat->position_x)
                + abs((int) $etat->position_y - (int) $allieEtat->position_y) === 1) {
                return [$allieEtat->personnage, $allieEtat, true];
            }
        }

        // Allié le plus proche (distance de Manhattan).
        $plusProche = $allies
            ->sortBy(fn (EtatPersonnageQuete $a) => abs((int) $etat->position_x - (int) $a->position_x)
                + abs((int) $etat->position_y - (int) $a->position_y)
            )
            ->first();

        return $plusProche !== null ? [$plusProche->personnage, $plusProche, false] : null;
    }

    /**
     * Grille tactique de la quête. Simple délégation à `FabriqueGrille::pour()`,
     * la source de vérité UNIQUE de l'occupation et de l'opacité. Ce moteur
     * tenait sa propre boucle : elle ignorait le MOBILIER (doc 17) — un boss
     * chargeait à travers une bibliothèque et la Fuite le téléportait dans une
     * table — et comptait les héros TOMBÉS comme des obstacles, alors qu'on
     * les enjambe (C4).
     */
    private function grilleQuete(
        Quete $quete,
        ?int $exceptPersonnageId = null,
        ?int $exceptInstanceId = null,
    ): Grille {
        return FabriqueGrille::pour($quete, $exceptPersonnageId, $exceptInstanceId);
    }

    /**
     * Journal générique si aucune cible pertinente (sort non lancé, raison log).
     *
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadGenericJournal(Groupe $groupe, SortDread $sort, array $acteur): array
    {
        $payload = [
            'type' => 'sort_dread_annule',
            'sort' => $sort->nom,
            'raison' => 'aucune_cible',
        ];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }
}
