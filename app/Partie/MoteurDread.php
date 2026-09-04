<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Des\LanceurDes;
use App\Engine\MotsClesSort;
use App\Engine\MotsClesSortDread as Mot;
use App\Engine\SortMental;
use App\Engine\TypeDegat;
use App\Engine\TypeFigurine;
use App\Models\Competence;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Inventaire;
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
    /**
     * Usages de sorts de Dread par rencontre — valeurs playtest.
     *
     * ⚠ `USAGES_BASE` est né avec les cartes officielles (2026-09-04) : les
     * extensions donnent la magie à des créatures ORDINAIRES — le Dread
     * Cultist, le Specter et le Blightweaver sont de tier `base` et lancent
     * chacun leurs sorts « 1 fois par quête » (doc 18). Sans usage, leur
     * répertoire n'aurait jamais rien produit.
     *
     * Divergence assumée pour le Specter, dont la carte dit « à volonté » :
     * un spectre par salle canaliserait alors à chaque tour de monstre.
     */
    public const USAGES_BASE = 1;

    public const USAGES_SOUS_BOSS = 2;

    public const USAGES_BOSS = 3;

    /** Seuil du d6 BRUT en dessous duquel un dé de résistance ne protège pas. */
    public const SEUIL_DE_ROUGE = 5;

    /** Bonus de dés de défense de la capacité Résistance magique. */
    public const BONUS_RESISTANCE_MAGIQUE = 2;

    /** Condition posée par une créature venimeuse (Jungles of Delthrak). */
    public const CONDITION_ENVENIME = 'Envenimé';

    /** Nombre de sbires invoqués par sort/capacité d'invocation. */
    public const NB_SBIRES_INVOQUES = 2;

    /** Seuil de PV (fraction du max) déclenchant la Fuite. */
    public const SEUIL_FUITE = 0.25;

    /** Seule destination de Fuite que le moteur sache atteindre (voir `sortDreadFuite()`). */
    public const FUITE_CASE_ELOIGNEE = 'case_la_plus_eloignee';

    /**
     * Ordre des paliers de `sorts_dread.palier` : un palier est un tier
     * MINIMUM. Un palier inconnu vaut 0 — fail open, comme partout ailleurs
     * dans le moteur : une donnée de référence manquante ne doit jamais
     * durcir le jeu en silence.
     */
    private const RANG_PALIER = ['base' => 0, 'sous_boss' => 1, 'boss' => 2];

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

        // ⚠ Un monstre de tier `base` reçoit un usage S'IL A UN RÉPERTOIRE, et
        // seulement dans ce cas : c'est la condition qui garde la porte fermée
        // pour les 25 créatures ordinaires qui ne lancent rien, tout en
        // l'ouvrant au Cultiste du Dread, au Spectre et au Tisseur putride, à
        // qui la doc 18 donne expressément des sorts. Tester le tier seul,
        // comme avant, les rendait muets ; ne tester que le répertoire aurait
        // donné trois usages à un gobelin que l'IA aurait décoré d'un sort.
        $usages = match ($tier) {
            'boss' => self::USAGES_BOSS,
            'sous_boss' => self::USAGES_SOUS_BOSS,
            default => $this->repertoireSorts($instance->monstre) === [] ? 0 : self::USAGES_BASE,
        };

        if ($usages === 0) {
            return;
        }

        $instance->update([
            'usages_dread' => $usages,
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
    /**
     * Correspondance des conditions de HÉROS que posent les sorts de contrôle
     * de Dread vers les conditions de MONSTRE, pour le *Bâton Ancien*.
     *
     * ⚠ `Commandé` n'y figure pas, et pas par oubli : aucune condition de
     * monstre ne dit « attaque les tiens », et en inventer une aurait produit
     * la clé décorative que le projet traque. Le reflet du Commandement est donc
     * JOUÉ sur-le-champ par `ResolveurTour::subirRefletDeSort()`.
     *
     * @var array<string, string>
     */
    public const REFLET_CONDITIONS = [
        'Apeuré' => MoteurSorts::MONSTRE_TERRIFIE,
        'Endormi' => MoteurSorts::MONSTRE_ENDORMI,
    ];

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
            $chemin = $this->cheminVersCaseAdjacente(
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
     * PLAFOND de dés d'attaque imposé par une condition (`des_attaque_max`).
     *
     * ⚠ Un plafond, plus un malus, depuis le passage aux cartes officielles :
     * *Fear* dit « may ONLY USE 1 Attack die », pas « −1 dé ». Le malus ne
     * coûtait qu'un dé au barbare qui en lance cinq, là où la carte le ramène
     * au dé unique de tout le monde — et la règle était DÉJÀ écrite ainsi côté
     * monstres (`InstanceMonstre::apresConditions()` fait `min($des, 1)` sur
     * `terrifie`). Les deux bords de la table disent enfin la même chose.
     *
     * Le plus BAS des plafonds l'emporte, jamais leur somme : deux conditions
     * qui disent « au plus 1 » ne disent pas « zéro ». *Esprit brisé* (Choc
     * Mental) plafonne à 0 — « This hero cannot move or attack ».
     *
     * Rend `null` quand aucune condition ne plafonne : c'est ce qui distingue
     * « pas de plafond » d'un plafond à zéro.
     */
    public function plafondDesAttaque(Personnage $personnage): ?int
    {
        $plafond = null;

        foreach ($personnage->conditions()->get() as $condition) {
            $max = data_get($condition->effet, 'des_attaque_max');

            if ($max !== null) {
                $plafond = $plafond === null ? (int) $max : min($plafond, (int) $max);
            }
        }

        return $plafond;
    }

    /**
     * Dés d'attaque SUPPLÉMENTAIRES qu'un monstre gagne contre ce héros
     * (`bonus_des_attaque_ennemie`).
     *
     * *Feux de l'Effroi* (carte *Dreadlights*) : « All monsters roll one
     * additional Attack die when attacking the affected hero. » C'est la seule
     * condition du catalogue dont l'effet profite à quelqu'un d'autre que son
     * porteur — d'où un lecteur à part, appelé du côté de l'assaillant.
     */
    public function bonusAttaqueContre(Personnage $personnage): int
    {
        $bonus = 0;

        foreach ($personnage->conditions()->get() as $condition) {
            $bonus += (int) data_get($condition->effet, 'bonus_des_attaque_ennemie', 0);
        }

        return $bonus;
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
        $sortsDisponibles = $this->sortsDisponibles($instance, $quete)
            ->filter(fn (SortDread $s) => $this->sortUtilisable($s, $quete, $instance, $cibles, $enVue))
            ->values();

        if ($sortsDisponibles->isEmpty()) {
            return null;
        }

        // Le meilleur SCORE l'emporte, à égalité le premier du répertoire —
        // l'ordre déclaré dans `config/archetypes_lanceurs.php` reste donc
        // lisible comme une préférence, sans être une règle rigide.
        return $sortsDisponibles
            ->sortByDesc(fn (SortDread $s) => $this->scoreSort($s, $quete, $instance, $cibles, $enVue))
            ->first();
    }

    /**
     * Le sort peut-il être lancé MAINTENANT ? Filtre dur, sans note : ce qui
     * échoue ici ne sera jamais choisi.
     *
     * ⚠ C'est le pendant de la leçon des talents (« lire par MÉCANIQUE, jamais
     * par NOM ») appliquée à la magie du MJ. `choisirSort()` reconnaissait ses
     * sept sorts par leur `nom` — « Tempête de feu », « Trait de Chaos »,
     * « Sommeil »… Avec 22 sorts au catalogue, une cascade de noms aurait fait
     * exactement ce qu'elle a fait pour les nœuds d'arbre : quinze sorts
     * seedés, jamais choisis, sans une erreur nulle part.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles  tous les héros debout
     * @param  Collection<int, EtatPersonnageQuete>  $enVue  ceux qu'il VOIT
     */
    private function sortUtilisable(
        SortDread $sort,
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
        Collection $enVue,
    ): bool {
        // « Not used in corridors » (Firestorm, Ice Storm) : la zone est une
        // SALLE, et un couloir n'en est pas une.
        if ((bool) data_get($sort->effet, 'hors_couloir', false) && $this->salleDuLanceur($quete, $instance) === null) {
            return false;
        }

        return match ($sort->type) {
            Mot::TYPE_DEGATS => $this->ciblesDuSort($sort, $quete, $instance, $cibles, $enVue)->isNotEmpty()
                || $this->monstresDuSort($sort, $quete, $instance)->isNotEmpty(),
            Mot::TYPE_CONTROLE => $this->ciblesDuSort($sort, $quete, $instance, $cibles, $enVue)->isNotEmpty(),
            // Un renfort ne s'appelle que si le lanceur est presque seul, et
            // jamais au contact : un boss encerclé qui siffle des sbires au
            // lieu de frapper est un cadeau, pas une menace (même arbitrage que
            // la capacité `invocation` et que `pondre()`).
            // ⚠ La RÉANIMATION échappe aux deux garde-fous de l'invocation, et
            // c'est une décision, pas un oubli. « Reanimate all defeated
            // skeletons, zombies, or mummies IN THE SAME ROOM » : il faut donc
            // que le groupe ait déjà tué là — c'est-à-dire, presque toujours,
            // qu'il soit au contact. Refuser le sort au contact et le réserver
            // au lanceur presque seul le rendrait quasi injouable, alors que sa
            // condition d'existence (des cadavres) est déjà la plus exigeante
            // du paquet. Le verrou 1×/rencontre, lui, tient : relever ses morts
            // à chaque tour rendrait toute salle impossible à nettoyer.
            Mot::TYPE_INVOCATION => ! $instance->invocation_dread_utilisee
                && (data_get($sort->effet, 'reanime') !== null
                    ? $this->mortsVivantsARelever($quete, $instance, $sort)->isNotEmpty()
                    : ! $this->auContact($instance, $cibles) && $this->assezSeulPourInvoquer($quete, $instance)),
            // Un soin ne se lance que s'il rend quelque chose : sans blessé, le
            // sort brûlerait un usage pour rien — le défaut que la Tempête de
            // feu avait avant que `ciblesDansZone()` ne devienne le point de
            // passage du choix comme de la résolution.
            Mot::TYPE_SOIN => $this->cibleSoin($sort, $quete, $instance) !== null,
            Mot::TYPE_FUITE => ! $instance->fuite_dread_utilisee
                && (int) $instance->pv_body < max(1, (int) ($instance->pvBodyMax() * self::SEUIL_FUITE)),
            // Rien à ronger, pas de sort : un héros sans métal en main ni sur
            // la tête n'offre aucune prise, et brûler un usage pour un journal
            // vide est le défaut que ce filtre existe pour empêcher.
            Mot::TYPE_DESTRUCTION => $this->ciblesDuSort($sort, $quete, $instance, $cibles, $enVue)
                ->contains(fn (EtatPersonnageQuete $e) => $this->cibleDeRouille($sort, $e->personnage) !== null),
            default => false,
        };
    }

    /**
     * Note d'un sort utilisable — c'est la seule chose qui décide de l'ordre.
     *
     * L'échelle traduit l'ancienne cascade de priorités sans nommer un seul
     * sort : une zone qui prend plusieurs héros passe devant tout, puis les
     * dégâts, puis le contrôle, puis les renforts, puis le soin, et la fuite en
     * dernier — c'est une sortie, pas une attaque.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  Collection<int, EtatPersonnageQuete>  $enVue
     */
    private function scoreSort(
        SortDread $sort,
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
        Collection $enVue,
    ): int {
        $atteints = $this->ciblesDuSort($sort, $quete, $instance, $cibles, $enVue)->count();

        return match ($sort->type) {
            // +40 par héros au-delà du premier : c'est ce qui fait qu'une Nuée
            // d'Effroi sur trois héros passe devant une Boule de Flammes sur un.
            Mot::TYPE_DEGATS => 100 + 40 * max(0, $atteints - 1),
            Mot::TYPE_CONTROLE => 80 + 40 * max(0, $atteints - 1),
            Mot::TYPE_INVOCATION => 60,
            Mot::TYPE_SOIN => 40,
            // Entre les dégâts et le contrôle : détruire une arme ne fait pas
            // tomber un héros ce tour-ci, mais l'ampute pour tout le reste de
            // la campagne — c'est la seule chose de ce paquet qui ne se répare
            // pas.
            Mot::TYPE_DESTRUCTION => 90,
            Mot::TYPE_FUITE => 10,
            default => 0,
        };
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
     * Index de la salle du lanceur, ou `null` s'il est en couloir.
     * Passe par `App\Partie\Salles`, seul point de passage du projet pour
     * « quelle salle contient cette case ? ».
     */
    private function salleDuLanceur(Quete $quete, InstanceMonstre $instance): ?int
    {
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);

        return Salles::indexDe($salles, (int) $instance->position_x, (int) $instance->position_y);
    }

    /**
     * Les cases que balaie un sort — SEUL point de passage, lu par le choix du
     * sort ET par sa résolution.
     *
     * C'est la leçon de la Tempête de feu, qui était choisie sur le nombre de
     * héros DEBOUT DANS LA QUÊTE alors qu'elle ne brûlait que cinq cases :
     * l'usage partait, le journal annonçait une tempête, personne n'était
     * touché. Deux lectures de la même zone finissent toujours par diverger.
     *
     * Rend une liste vide pour un sort à cible unique (`zone` absente) : la
     * zone n'est pas « toute la carte », elle n'existe simplement pas.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @return list<array{x: int, y: int}>
     */
    private function casesDeZone(
        SortDread $sort,
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
    ): array {
        $zone = (string) data_get($sort->effet, 'zone', Mot::ZONE_CIBLE);
        $cx = (int) $instance->position_x;
        $cy = (int) $instance->position_y;

        return match ($zone) {
            // « adjacent to the spellcaster (though NOT DIAGONALLY adjacent) » :
            // les quatre orthogonales, la parenthèse de la carte de *Chill*
            // étant précisément ce qui la distingue d'un contact ordinaire.
            Mot::ZONE_CONTACT => [
                ['x' => $cx + 1, 'y' => $cy], ['x' => $cx - 1, 'y' => $cy],
                ['x' => $cx, 'y' => $cy + 1], ['x' => $cx, 'y' => $cy - 1],
            ],
            Mot::ZONE_SALLE => $this->casesDeLaSalle($quete, $instance),
            // « in the same room OR CORRIDOR » : un couloir n'a pas d'index de
            // salle, d'où un mot distinct — sans lui, la Nuée d'Effroi ne
            // fonctionnerait nulle part hors des salles, ce que sa carte
            // autorise expressément.
            Mot::ZONE_SALLE_OU_COULOIR => $this->salleDuLanceur($quete, $instance) === null
                ? $this->casesDuCouloir($quete, $instance)
                : $this->casesDeLaSalle($quete, $instance),
            Mot::ZONE_CARRE_2X2 => $this->meilleurCarre($quete, $instance, $cibles),
            Mot::ZONE_RAYON => $this->meilleurRayon($quete, $instance, $cibles),
            default => [],
        };
    }

    /**
     * Toutes les cases de la salle du lanceur.
     *
     * @return list<array{x: int, y: int}>
     */
    private function casesDeLaSalle(Quete $quete, InstanceMonstre $instance): array
    {
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $index = $this->salleDuLanceur($quete, $instance);

        if ($index === null || ! isset($salles[$index])) {
            return [];
        }

        $salle = $salles[$index];
        $cases = [];

        for ($y = (int) $salle['y']; $y < (int) $salle['y'] + (int) $salle['hauteur']; $y++) {
            for ($x = (int) $salle['x']; $x < (int) $salle['x'] + (int) $salle['largeur']; $x++) {
                $cases[] = ['x' => $x, 'y' => $y];
            }
        }

        return $cases;
    }

    /**
     * Le COULOIR du lanceur : la coulée de cases hors salle qui communiquent
     * avec la sienne, portes closes exclues.
     *
     * ⚠ Un couloir n'a pas d'identifiant dans la grille — seules les salles
     * sont des rectangles nommés. « Le même couloir » se calcule donc, par
     * propagation sur les cases sans index de salle. S'arrêter à une porte
     * close est ce qui empêche la Nuée d'Effroi de traverser tout l'étage par
     * les corridors.
     *
     * @return list<array{x: int, y: int}>
     */
    private function casesDuCouloir(Quete $quete, InstanceMonstre $instance): array
    {
        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $grille = $this->grilleQuete($quete);

        $depart = ['x' => (int) $instance->position_x, 'y' => (int) $instance->position_y];
        $vues = [$depart['x'].':'.$depart['y'] => true];
        $file = [$depart];
        $cases = [$depart];

        while ($file !== []) {
            $courante = array_shift($file);

            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nx = $courante['x'] + $dx;
                $ny = $courante['y'] + $dy;
                $cle = $nx.':'.$ny;

                if (isset($vues[$cle])
                    || $grille->estRoche($nx, $ny)
                    || $grille->porteBloqueEntre($courante['x'], $courante['y'], $nx, $ny)
                    || Salles::indexDe($salles, $nx, $ny) !== null) {
                    continue;
                }

                $vues[$cle] = true;
                $file[] = ['x' => $nx, 'y' => $ny];
                $cases[] = ['x' => $nx, 'y' => $ny];
            }
        }

        return $cases;
    }

    /**
     * Le carré 2×2 qui couvre le PLUS de héros (*Ice Storm*, « an area 2 squares
     * wide by 2 squares long »).
     *
     * La carte laisse Zargon poser la zone où il veut : on la pose donc au
     * mieux, dans la salle du lanceur — le sort étant interdit en couloir, il
     * n'y a pas d'autre terrain à considérer. Aucun héros dans la salle rend
     * une liste vide, et le sort n'est alors pas retenu par `sortUtilisable()`.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @return list<array{x: int, y: int}>
     */
    private function meilleurCarre(Quete $quete, InstanceMonstre $instance, Collection $cibles): array
    {
        $salle = $this->casesDeLaSalle($quete, $instance);

        if ($salle === []) {
            return [];
        }

        $meilleur = [];
        $meilleurCompte = -1;

        foreach ($salle as $coin) {
            $carre = [
                ['x' => $coin['x'], 'y' => $coin['y']],
                ['x' => $coin['x'] + 1, 'y' => $coin['y']],
                ['x' => $coin['x'], 'y' => $coin['y'] + 1],
                ['x' => $coin['x'] + 1, 'y' => $coin['y'] + 1],
            ];

            $compte = $this->herosSurCases($cibles, $carre)->count();

            if ($compte > $meilleurCompte) {
                $meilleurCompte = $compte;
                $meilleur = $carre;
            }
        }

        return $meilleurCompte > 0 ? $meilleur : [];
    }

    /**
     * La direction de rayon qui touche le plus de héros (*Lightning Bolt*).
     *
     * Réutilise `App\Partie\Rayon`, écrit pour le parchemin d'*Éclair* : les
     * deux cartes portent la même phrase — « straight or diagonal […] until it
     * meets a wall or closed door » — donc la même ligne, et il n'y a aucune
     * raison de l'écrire une troisième fois.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @return list<array{x: int, y: int}>
     */
    private function meilleurRayon(Quete $quete, InstanceMonstre $instance, Collection $cibles): array
    {
        $grille = $this->grilleQuete($quete);
        $x = (int) $instance->position_x;
        $y = (int) $instance->position_y;

        $meilleur = [];
        $meilleurCompte = 0;

        foreach (array_keys(Rayon::DIRECTIONS) as $direction) {
            $ligne = Rayon::cases($grille, $x, $y, (string) $direction);
            $compte = $this->herosSurCases($cibles, $ligne)->count();

            if ($compte > $meilleurCompte) {
                $meilleurCompte = $compte;
                $meilleur = $ligne;
            }
        }

        return $meilleur;
    }

    /**
     * Les héros qui se tiennent sur l'une de ces cases.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  list<array{x: int, y: int}>  $cases
     * @return \Illuminate\Support\Collection<int, EtatPersonnageQuete>
     */
    private function herosSurCases(Collection $cibles, array $cases): \Illuminate\Support\Collection
    {
        $index = [];

        foreach ($cases as $case) {
            $index[$case['x'].':'.$case['y']] = true;
        }

        return $cibles
            ->filter(fn (EtatPersonnageQuete $c) => $c->position_x !== null
                && isset($index[(int) $c->position_x.':'.(int) $c->position_y]))
            ->values();
    }

    /**
     * Les héros qu'un sort atteindra — SEUL point de passage du choix ET de la
     * résolution, pour les zones comme pour les cibles uniques.
     *
     * ⚠ Une zone se lit sur `$cibles` (tous les héros debout) et non sur
     * `$enVue` : la carte de *Firestorm* dit « all heroes in the same room »,
     * pas « tous ceux que le lanceur voit ». Un feu qui remplit la pièce
     * n'épargne pas celui qui se tient derrière une armoire. Un sort à cible
     * unique, lui, exige la vue — comme tous les sorts du jeu depuis que la
     * règle vaut des deux côtés de la table.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  Collection<int, EtatPersonnageQuete>  $enVue
     * @return \Illuminate\Support\Collection<int, EtatPersonnageQuete>
     */
    private function ciblesDuSort(
        SortDread $sort,
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
        Collection $enVue,
    ): \Illuminate\Support\Collection {
        $zone = (string) data_get($sort->effet, 'zone', Mot::ZONE_CIBLE);

        if (in_array($zone, Mot::ZONES_MULTIPLES, true) || $zone === Mot::ZONE_CONTACT) {
            $atteints = $this->herosSurCases($cibles, $this->casesDeZone($sort, $quete, $instance, $cibles));

            // Un sort de contrôle ne vise pas ceux qui portent déjà sa
            // condition : la Nuée d'Effroi relancée sur une salle entièrement
            // paralysée brûlerait un usage pour rien.
            if ($sort->type === Mot::TYPE_CONTROLE) {
                $condition = (string) data_get($sort->effet, 'condition_appliquee', 'Étourdi');
                $atteints = $atteints
                    ->reject(fn (EtatPersonnageQuete $e) => $this->herosSousCondition($e->personnage, $condition))
                    ->values();
            }

            return $atteints;
        }

        $cible = $sort->type === Mot::TYPE_CONTROLE
            ? $this->cibleControle($sort, $enVue)
            : $this->cibleOffensive($instance, $enVue);

        return $cible === null ? collect() : collect([$cible]);
    }

    /**
     * Les MONSTRES qu'un sort de zone attrape au passage (`touche_monstres`).
     *
     * Trois cartes le disent expressément — *Firestorm*, *Lightning Bolt*,
     * *Ice Storm* frappent « all heroes OR MONSTERS » —, et *Firestorm* ajoute
     * « the spellcaster is unaffected », d'où `epargne_lanceur`. C'est le seul
     * tir ami du côté du MJ, et il est aussi délibéré que le nôtre (doc 02 §5).
     *
     * @return \Illuminate\Support\Collection<int, InstanceMonstre>
     */
    private function monstresDuSort(SortDread $sort, Quete $quete, InstanceMonstre $instance): \Illuminate\Support\Collection
    {
        if (! (bool) data_get($sort->effet, 'touche_monstres', false)) {
            return collect();
        }

        $cases = $this->casesDeZone($sort, $quete, $instance, $quete->etatsPersonnages()->get());

        if ($cases === []) {
            return collect();
        }

        $index = [];

        foreach ($cases as $case) {
            $index[$case['x'].':'.$case['y']] = true;
        }

        $epargneLanceur = (bool) data_get($sort->effet, 'epargne_lanceur', false);

        return $quete->instancesMonstres()->where('etat', 'actif')->get()
            ->filter(fn (InstanceMonstre $m) => $m->position_x !== null
                && isset($index[(int) $m->position_x.':'.(int) $m->position_y])
                && ! ($epargneLanceur && $m->id === $instance->id))
            ->values();
    }

    /**
     * Cible d'un sort de contrôle À CIBLE UNIQUE : le héros au Mind le plus
     * FAIBLE qui ne porte pas déjà la condition posée par ce sort.
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
     * Cible d'un sort de dégâts à cible unique : le héros le PLUS PROCHE,
     * départagé par les PV les plus bas.
     *
     * ⚠ UNE seule closure, qui rend un couple. `sortBy([$f, $g])` prend chaque
     * callable pour un COMPARATEUR appelé `$f($a, $b)`, pas pour un extracteur
     * de clé : une closure à un paramètre y rend alors la distance de `$a`
     * comme résultat de comparaison, et l'ordre part en vrille sans la moindre
     * erreur. Constaté en partie réelle le 2026-09-02 — le boss visait le héros
     * le PLUS LOIN.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     */
    private function cibleOffensive(InstanceMonstre $instance, Collection $cibles): ?EtatPersonnageQuete
    {
        return $cibles
            ->sortBy(fn (EtatPersonnageQuete $e) => [
                $this->distance($instance, $e),
                (int) $e->personnage->pv_body,
            ])
            ->first();
    }

    /**
     * Cible d'un sort de SOIN : le monstre le plus blessé — le lanceur compris,
     * « to the spellcaster or any one monster ».
     *
     * ⚠ `null` quand personne n'a perdu un seul PV : un soin qui ne rend rien
     * dépenserait un usage pour un journal vide.
     */
    private function cibleSoin(SortDread $sort, Quete $quete, InstanceMonstre $instance): ?InstanceMonstre
    {
        $candidats = $quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get();

        // « any monster WITHIN THE SPELLCASTER'S LINE OF SIGHT » (Restore
        // Dread). *Soothe* ne le dit pas — sa cible est donc libre.
        if ((bool) data_get($sort->effet, 'ligne_de_vue', false) && $instance->position_x !== null) {
            $grille = $this->grilleQuete($quete, exceptInstanceId: $instance->id);
            $candidats = $candidats->filter(fn (InstanceMonstre $m) => $m->id === $instance->id
                || ($m->position_x !== null && $grille->ligneDeVue(
                    (int) $instance->position_x, (int) $instance->position_y,
                    (int) $m->position_x, (int) $m->position_y, figuresBloquent: true,
                )));
        }

        return $candidats
            ->filter(fn (InstanceMonstre $m) => (int) $m->pv_body < (int) $m->pvBodyMax())
            ->sortBy(fn (InstanceMonstre $m) => (int) $m->pv_body)
            ->first();
    }

    /**
     * Les morts-vivants VAINCUS que la *Réanimation* peut relever : « all
     * defeated skeletons, zombies, or mummies in the same room as the
     * spellcaster ».
     *
     * ⚠ Le nom lu est `monstres.nom_base`, le nom du CATALOGUE — jamais celui
     * que l'IA a posé dessus. Même précaution que l'eau bénite et que les deux
     * clés « contre » des artefacts : un « Noyé de Gorrim » reste un zombie.
     *
     * @return \Illuminate\Support\Collection<int, InstanceMonstre>
     */
    private function mortsVivantsARelever(Quete $quete, InstanceMonstre $instance, SortDread $sort): \Illuminate\Support\Collection
    {
        $noms = (array) data_get($sort->effet, 'reanime', []);

        if ($noms === []) {
            return collect();
        }

        $salles = (array) ($quete->carte?->grille['salles'] ?? []);
        $salle = $this->salleDuLanceur($quete, $instance);

        return $quete->instancesMonstres()->where('etat', '!=', 'actif')->with('monstre')->get()
            ->filter(function (InstanceMonstre $m) use ($noms, $salles, $salle) {
                if (! in_array($m->monstre?->nom_base, $noms, true) || $m->position_x === null) {
                    return false;
                }

                return Salles::indexDe($salles, (int) $m->position_x, (int) $m->position_y) === $salle;
            })
            ->values();
    }

    /**
     * Un héros est-il au contact ? MÊME notion qu'ailleurs dans le moteur —
     * Manhattan = 1, orthogonal strict.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     */
    private function auContact(InstanceMonstre $instance, Collection $cibles): bool
    {
        return $cibles->contains(fn (EtatPersonnageQuete $c) => $this->distance($instance, $c) === 1);
    }

    /** Distance de Manhattan entre une créature et un héros. */
    private function distance(InstanceMonstre $instance, EtatPersonnageQuete $cible): int
    {
        return abs((int) $instance->position_x - (int) $cible->position_x)
            + abs((int) $instance->position_y - (int) $cible->position_y);
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

        // ⚠ L'ORDRE est celui du RÉPERTOIRE, pas celui des id en base. Le
        // `whereIn` rendait les sorts dans l'ordre du catalogue, si bien que la
        // préférence déclarée dans `config/archetypes_lanceurs.php` ne
        // gouvernait rien — et deux sorts à égalité de score (deux frappes sur
        // un seul héros, par exemple) se départageaient sur un ordre
        // d'insertion que personne ne lit.
        $rang = array_flip($noms);

        return SortDread::whereIn('nom', $noms)->get()
            ->filter(fn (SortDread $s) => (self::RANG_PALIER[$s->palier] ?? 0) <= $rangLanceur)
            ->keyBy('nom')
            ->sortBy(fn (SortDread $s) => $rang[$s->nom] ?? PHP_INT_MAX)
            ->values();
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
     * @param  Collection<int, EtatPersonnageQuete>  $enVue
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
        // ⚠ Dégâts et contrôle visent `$enVue` pour une cible unique et
        // `$cibles` pour une zone (`ciblesDuSort()` tranche) ; la Fuite raisonne
        // sur `$cibles` : se téléporter loin des seuls héros VISIBLES
        // reviendrait à sauter dans les bras de celui qu'un mur masquait.
        return match ($sort->type) {
            Mot::TYPE_DEGATS => $this->sortDreadDegats($groupe, $quete, $instance, $sort, $cibles, $enVue, $acteur),
            Mot::TYPE_CONTROLE => $this->sortDreadControle($groupe, $quete, $instance, $sort, $cibles, $enVue, $acteur),
            Mot::TYPE_INVOCATION => data_get($sort->effet, 'reanime') !== null
                ? $this->sortDreadReanimation($groupe, $quete, $instance, $sort, $acteur)
                : $this->sortDreadInvocation($groupe, $quete, $instance, $sort, $acteur),
            Mot::TYPE_SOIN => $this->sortDreadSoin($groupe, $quete, $instance, $sort, $acteur),
            Mot::TYPE_FUITE => $this->sortDreadFuite($groupe, $quete, $instance, $sort, $cibles, $acteur),
            Mot::TYPE_DESTRUCTION => $this->sortDreadDestruction($groupe, $quete, $instance, $sort, $cibles, $enVue, $acteur),
            default => $this->sortDreadGenericJournal($groupe, $sort, $acteur),
        };
    }

    // ------------------------------------------------------------------
    // Internals — le calcul des dégâts, commun aux six sorts offensifs
    // ------------------------------------------------------------------

    /**
     * DÉGÂTS D'UN SORT DE DREAD SUR UNE CIBLE — le seul calcul qui fasse foi.
     *
     * Quatre résolutions coexistent, une par carte, et il faut qu'elles vivent
     * au même endroit : la précédente version lançait des dés de combat contre
     * une défense, point, et les six cartes de dégâts en décrivent quatre.
     *
     *  - `des_rouges` : montant FIXE que la cible réduit à coups de d6 BRUTS,
     *    chaque 5-6 annulant 1 point (*Ball of Flame*, *Firestorm*). ⚠ Le seuil
     *    se lit sur le d6 brut, jamais sur une face de combat : les nôtres
     *    fusionnent 4-5 en bouclier blanc et écraseraient la moitié de la règle.
     *    Elle REMPLACE la défense, d'où `defense_applicable: false`.
     *  - `paliers_d6` : un d6, une table de seuils (*Channel Dread*).
     *  - `des_combat_crane` : un dé de combat, seul un crâne fait mal
     *    (*Creeping Grasp*).
     *  - défaut : `des_degats` dés de combat contre la défense du héros, la
     *    résolution historique.
     *
     * ⚠ La **Résistance magique** d'un boss n'a rien à faire ici : elle protège
     * un MONSTRE des sorts d'un héros, jamais un héros des sorts du MJ.
     *
     * @return array{degats: int, detail: array<string, mixed>}
     */
    private function degatsInfliges(
        SortDread $sort,
        Quete $quete,
        InstanceMonstre $instance,
        ?Personnage $personnage,
        int $pvCible = 0,
    ): array {
        $resistance = (string) data_get($sort->effet, 'resistance', '');

        if ($resistance === Mot::RESISTANCE_DES_ROUGES) {
            $degats = (int) data_get($sort->effet, 'degats_fixes', 0);
            $nb = (int) data_get($sort->effet, 'des_resistance', 0);
            $des = [];

            for ($i = 0; $i < $nb; $i++) {
                $de = $this->des->d6();
                $des[] = $de;

                if ($de >= self::SEUIL_DE_ROUGE) {
                    $degats--;
                }
            }

            return ['degats' => max(0, $degats), 'detail' => ['des_rouges' => $des, 'degats_bruts' => (int) data_get($sort->effet, 'degats_fixes', 0)]];
        }

        if ($resistance === Mot::RESISTANCE_PALIERS_D6) {
            $de = $this->des->d6();
            $bonus = $this->bonusLanceursAdjacents($sort, $quete, $instance);
            $total = $de + $bonus;

            return [
                'degats' => $this->degatsSelonPaliers($total, (array) data_get($sort->effet, 'paliers', [])),
                'detail' => ['de' => $de, 'lanceurs_adjacents' => $bonus, 'total' => $total],
            ];
        }

        if ($resistance === Mot::RESISTANCE_DES_COMBAT_CRANE) {
            $volee = (new Combat($this->des))->resoudreAttaque(
                desAttaque: 1,
                desDefense: 0,
                typeDefenseur: TypeFigurine::Heros,
                pvBodyDefenseur: $pvCible,
            );

            return [
                'degats' => $volee->touches > 0 ? (int) data_get($sort->effet, 'degats_fixes', 1) : 0,
                'detail' => ['crane' => $volee->touches > 0] + $volee->pourJournal(),
            ];
        }

        // Montant fixe sans jet du tout (*Chill*, *Lightning Bolt*) : pas de
        // `resistance`, pas de dés d'attaque — la carte donne un nombre.
        if (data_get($sort->effet, 'degats_fixes') !== null) {
            return ['degats' => (int) data_get($sort->effet, 'degats_fixes'), 'detail' => ['degats_fixes' => true]];
        }

        // ⚠ La défense n'existe que pour un héros. Un monstre pris dans la
        // zone n'en a pas ici : les trois cartes qui frappent « heroes OR
        // monsters » posent toutes `defense_applicable: false`, et inventer
        // une parade pour la créature reviendrait à écrire une règle que la
        // carte ne porte pas.
        $defense = $personnage !== null && (bool) data_get($sort->effet, 'defense_applicable', true)
            ? $this->sorts->desDefenseHeros($personnage)
            : 0;

        $volee = (new Combat($this->des))->resoudreAttaque(
            desAttaque: (int) data_get($sort->effet, 'des_degats', 2),
            desDefense: $defense,
            typeDefenseur: $personnage !== null ? TypeFigurine::Heros : TypeFigurine::Monstre,
            pvBodyDefenseur: $personnage !== null ? (int) $personnage->pv_body : $pvCible,
        );

        return [
            'degats' => $volee->degats,
            'detail' => ['touches' => $volee->touches, 'boucliers' => $volee->boucliers] + $volee->pourJournal(),
        ];
    }

    /**
     * Dégâts lus dans une table de paliers, sur un d6 (*Channel Dread* :
     * « On 1, 2, or 3 = resists. On 4 or 5 = 1 Body Point. On 6+ = 2 »).
     *
     * La table est ordonnée par seuil croissant et le PREMIER seuil atteint
     * gagne ; au-delà du dernier, c'est le dernier qui s'applique — c'est le
     * « 6+ » de la carte, sans lequel le bonus des lanceurs adjacents
     * n'aurait aucun effet.
     *
     * @param  array<array-key, int|string>  $paliers
     */
    private function degatsSelonPaliers(int $total, array $paliers): int
    {
        $degats = 0;

        foreach ($paliers as $seuil => $valeur) {
            $degats = (int) $valeur;

            if ($total <= (int) $seuil) {
                return $degats;
            }
        }

        return $degats;
    }

    /**
     * « For each monster ADJACENT TO THE CASTER THAT CAN CAST THIS SPELL, add
     * 1 point to the die total » (*Channel Dread*).
     *
     * ⚠ « qui sait lancer CE sort », pas « n'importe quel monstre » : c'est ce
     * qui fait de la carte une récompense pour avoir groupé ses lanceurs, et
     * non un bonus de mêlée. Le répertoire est relu par `repertoireSorts()`,
     * donc un archétype nommé compte comme une liste brute.
     */
    private function bonusLanceursAdjacents(SortDread $sort, Quete $quete, InstanceMonstre $instance): int
    {
        if (! (bool) data_get($sort->effet, 'bonus_lanceurs_adjacents', false) || $instance->position_x === null) {
            return 0;
        }

        return $quete->instancesMonstres()->where('etat', 'actif')->with('monstre')->get()
            ->filter(fn (InstanceMonstre $m) => $m->id !== $instance->id
                && $m->position_x !== null
                && abs((int) $m->position_x - (int) $instance->position_x)
                    + abs((int) $m->position_y - (int) $instance->position_y) === 1
                && in_array($sort->nom, $this->repertoireSorts($m->monstre), true))
            ->count();
    }

    // ------------------------------------------------------------------
    // Internals — résolution par famille
    // ------------------------------------------------------------------

    /**
     * Sorts de dégâts — cible unique ou zone, la seule différence étant le
     * nombre de victimes que `ciblesDuSort()` rend.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  Collection<int, EtatPersonnageQuete>  $enVue
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadDegats(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        Collection $cibles,
        Collection $enVue,
        array $acteur,
    ): array {
        $victimes = $this->ciblesDuSort($sort, $quete, $instance, $cibles, $enVue);
        $monstres = $this->monstresDuSort($sort, $quete, $instance);

        if ($victimes->isEmpty() && $monstres->isEmpty()) {
            return $this->sortDreadGenericJournal($groupe, $sort, $acteur);
        }

        $typeDegat = data_get($sort->effet, 'type_degat');
        $resultats = [];

        foreach ($victimes as $cible) {
            $personnage = $cible->personnage;

            // Anneau de Feu : la carte vise « Fire OR CHAOS FIRE spells » — les
            // sorts de Dread comptent donc autant que ceux des héros. Même
            // lecteur des deux côtés, pour qu'un anneau ne protège pas d'un feu
            // sur deux. Il absorbe pour SA victime seulement : une tempête qui
            // balaie la salle ne s'éteint pas parce qu'un héros la pare.
            if ($this->sorts->absorbeDegat($personnage, $typeDegat)) {
                $resultats[] = [
                    'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
                    'absorbe' => true,
                    'degats' => 0,
                ];

                continue;
            }

            $calcul = $this->degatsInfliges($sort, $quete, $instance, $personnage, (int) $personnage->pv_body);

            $subis = $this->degats->infligerAHeros(
                $personnage, $calcul['degats'], MoteurDegats::SOURCE_SORT_DREAD,
                // `lanceur_id` et `des_degats` : le *Bâton Ancien* renvoie CE
                // sort-là à CE lanceur-là. Sans les deux, la réaction ne saurait
                // ni qui viser ni avec quelle force.
                [
                    'sort' => $sort->nom,
                    'lanceur_id' => (int) $instance->id,
                    'des_degats' => (int) data_get($sort->effet, 'des_degats', $calcul['degats']),
                ],
            );
            $this->sorts->reveillerHeros($personnage);

            if ((int) $personnage->pv_body === 0 && $subis > 0) {
                $cible->update(['tombe' => true]);
            }

            $resultats[] = [
                'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
                'degats' => $subis,
                'pv_body_apres' => (int) $personnage->pv_body,
                'cible_tombee' => (int) $personnage->pv_body === 0 && $subis > 0,
                ...$calcul['detail'],
            ];
        }

        $collateraux = [];

        foreach ($monstres as $monstre) {
            // « All victims immediately roll 2 red dice » : la créature prise
            // dans la zone résiste comme un héros, avec les mêmes dés — c'est
            // pour cela que le calcul ne demande pas de personnage.
            $calcul = $this->degatsInfliges($sort, $quete, $instance, null, (int) $monstre->pv_body);

            $collateraux[] = $this->blesserMonstre($monstre, (int) $calcul['degats'], $typeDegat) + [
                'monstre' => $monstre->nomAffiche(),
            ] + $calcul['detail'];
        }

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'resultats' => $resultats,
        ];

        // La zone n'est publiée que si le sort en a une : un sort à cible unique
        // qui annoncerait `cases_affectees: []` laisserait croire à une frappe
        // ratée.
        $cases = $this->casesDeZone($sort, $quete, $instance, $cibles);

        if ($cases !== []) {
            $payload['cases_affectees'] = $cases;
        }

        if ($collateraux !== []) {
            $payload['monstres_touches'] = $collateraux;
        }

        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    /**
     * Un sort de zone blesse aussi les MONSTRES qui s'y tiennent.
     *
     * ⚠ Passe par `InstanceMonstre` directement et non par `MoteurDegats`, qui
     * n'existe que pour les héros (c'est lui qui ouvre les réactions et lit les
     * réductions de talent — un monstre n'a ni l'un ni l'autre). Le feu marque
     * la créature comme brûlée, exactement comme un sort de héros : le troll
     * cesse de régénérer, quelle que soit la main qui a allumé la flamme.
     *
     * @return array<string, mixed>
     */
    private function blesserMonstre(InstanceMonstre $monstre, int $degats, ?string $typeDegat): array
    {
        if ($typeDegat === TypeDegat::FEU && ! $monstre->brule) {
            $monstre->update(['brule' => true]);
        }

        $avant = (int) $monstre->pv_body;
        $apres = max(0, $avant - max(0, $degats));

        $monstre->update(['pv_body' => $apres] + ($apres === 0 ? ['etat' => 'vaincu'] : []));

        return [
            'degats' => $avant - $apres,
            'pv_body_apres' => $apres,
            'vaincu' => $apres === 0,
        ];
    }

    /**
     * Sorts de contrôle — une cible ou toute une salle.
     *
     * ⚠ La résistance a changé de nature avec les cartes officielles. Trois
     * mots coexistent désormais et ne veulent pas dire la même chose :
     *  - `jet_mind` décide AU LANCER (aucune carte du paquet ne le fait ;
     *    conservé pour toute donnée héritée) ;
     *  - `aucune` ne laisse aucune chance (*Tempest*) ;
     *  - `rupture_6_par_mind` et `rupture_5_6_un_de` laissent le sort PRENDRE,
     *    et c'est sa poursuite qui est contestée, tout de suite puis à chaque
     *    tour de la victime (`MoteurSorts::tenterRuptureHeros()`).
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  Collection<int, EtatPersonnageQuete>  $enVue
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadControle(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        Collection $cibles,
        Collection $enVue,
        array $acteur,
    ): array {
        $conditionNom = (string) data_get($sort->effet, 'condition_appliquee', 'Étourdi');
        $resistance = (string) data_get($sort->effet, 'resistance', MotsClesSort::RESISTANCE_JET_MIND);
        $victimes = $this->ciblesDuSort($sort, $quete, $instance, $cibles, $enVue);

        if ($victimes->isEmpty()) {
            return $this->sortDreadGenericJournal($groupe, $sort, $acteur);
        }

        $resultats = [];

        foreach ($victimes as $cible) {
            $personnage = $cible->personnage;
            $ligne = ['cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom]];

            // *Creeping Grasp* : « They must roll 1 combat die. If they roll a
            // skull, they suffer 1 Body Point AND are restrained. » Le dégât et
            // l'entrave tiennent au même dé — un seul jet, pas deux.
            if ($resistance === Mot::RESISTANCE_DES_COMBAT_CRANE) {
                $calcul = $this->degatsInfliges($sort, $quete, $instance, $personnage, (int) $personnage->pv_body);
                $ligne += $calcul['detail'];

                if ($calcul['degats'] <= 0 && empty($calcul['detail']['crane'])) {
                    $ligne['effet_applique'] = false;
                    $resultats[] = $ligne;

                    continue;
                }

                $subis = $this->degats->infligerAHeros(
                    $personnage, $calcul['degats'], MoteurDegats::SOURCE_SORT_DREAD,
                    ['sort' => $sort->nom, 'lanceur_id' => (int) $instance->id, 'des_degats' => 1],
                );
                $ligne['degats'] = $subis;
                $ligne['pv_body_apres'] = (int) $personnage->pv_body;

                if ((int) $personnage->pv_body === 0 && $subis > 0) {
                    $cible->update(['tombe' => true]);
                }
            }

            // Résistance au LANCER : seul `jet_mind` en accorde une. Les
            // ruptures, elles, laissent passer le sort.
            if ($resistance === MotsClesSort::RESISTANCE_JET_MIND) {
                $mindHeros = $this->sorts->desResistanceMentale($personnage);
                $jet = (new SortMental($this->des))->resoudre($mindHeros);

                $ligne += [
                    'mind_cible' => $mindHeros,
                    'issue' => $jet->issue->value,
                    'succes' => $jet->succes,
                    'faces' => array_map(fn ($f) => $f->value, $jet->faces),
                ];

                if (! $jet->effetApplique()) {
                    $ligne['effet_applique'] = false;
                    $resultats[] = $ligne;

                    continue;
                }
            }

            // `annuler_effet_magique` (Contresort du magicien et du warlock,
            // Verbe ancien du druide) : une SECONDE chance, jet de Mind
            // indépendant, qui annule l'effet magique avant qu'il ne soit posé.
            if ($this->talents->a($personnage, 'annuler_effet_magique')) {
                $mindHeros = $this->sorts->desResistanceMentale($personnage);
                $jetContresort = (new SortMental($this->des))->resoudre($mindHeros);
                $ligne['contresort'] = [
                    'reussi' => ! $jetContresort->effetApplique(),
                    'faces' => array_map(fn ($f) => $f->value, $jetContresort->faces),
                ];

                if ($ligne['contresort']['reussi']) {
                    $ligne['effet_applique'] = false;
                    $resultats[] = $ligne;

                    continue;
                }
            }

            // Une condition dont la sortie est une RUPTURE ne porte pas de
            // compteur : elle dure jusqu'au 6, point. Les autres prennent la
            // durée déclarée par le sort, sinon celle du catalogue.
            $this->poserConditionHeros(
                $personnage,
                $conditionNom,
                in_array($resistance, Mot::RESISTANCES_RUPTURE, true)
                    ? 0
                    : data_get($sort->effet, 'duree_tours'),
                'sort_dread:'.$sort->nom,
            );

            $ligne['effet_applique'] = true;
            $ligne['condition'] = $conditionNom;

            // « The spell can be broken IMMEDIATELY or on a future turn » : la
            // victime tente sa chance sur-le-champ, avant même de subir un tour
            // sous l'emprise. Sans ce premier jet, les quatre cartes de rupture
            // coûteraient toutes un tour plein, ce qu'aucune ne dit.
            if (in_array($resistance, Mot::RESISTANCES_RUPTURE, true)) {
                $rupture = $this->sorts->tenterRuptureHeros($personnage, $conditionNom);
                $ligne['rupture_immediate'] = $rupture;

                if ($rupture['rompu']) {
                    $ligne['effet_applique'] = false;
                }
            }

            // *Bâton Ancien* : un sort de contrôle ne blesse personne, donc
            // n'atteint jamais `MoteurDegats` — et c'est là que toutes les
            // autres réactions naissent. Celle-ci s'ouvre ICI, comme le *Défi du
            // chevalier* le fait pour un errant qui surgit.
            if ($ligne['effet_applique']) {
                $ligne['reflet_propose'] = app(MoteurReactions::class)->proposerRefletControle($personnage, [
                    'sort' => $sort->nom,
                    'lanceur_id' => (int) $instance->id,
                    'condition' => $conditionNom,
                ]);
            }

            $resultats[] = $ligne;
        }

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'condition' => $conditionNom,
            'resultats' => $resultats,
        ];

        $cases = $this->casesDeZone($sort, $quete, $instance, $cibles);

        if ($cases !== []) {
            $payload['cases_affectees'] = $cases;
        }

        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * Invocation : la composition du renfort est TIRÉE SUR UN d6.
     *
     * ⚠ Notre version invoquait deux squelettes, point — les quatre cartes
     * d'invocation donnent chacune une table (« on 1-2 = 4 skeletons ; on 3-4 =
     * 3 skeletons, 2 zombies ; on 5-6 = 2 zombies, 2 mummies »). C'est la table
     * qui sépare un renfort d'une bascule de combat, et c'est elle qui rend le
     * sort digne d'un palier boss.
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
        $de = $this->des->d6();
        $composition = $this->compositionInvoquee($sort, $de);

        $invoques = $this->invoquerSbires($quete, $instance, $composition);

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'de' => $de,
            'invoques' => $invoques,
        ];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * La ligne de `table_d6` que ce jet désigne, sous forme `nom => nombre`.
     *
     * Les seuils sont CROISSANTS et le premier atteint gagne ; un jet au-delà
     * du dernier retombe sur lui — un dé pipé ne doit jamais rendre un renfort
     * vide.
     *
     * @return array<string, int>
     */
    private function compositionInvoquee(SortDread $sort, int $de): array
    {
        $table = (array) data_get($sort->effet, 'table_d6', []);
        $composition = [];

        foreach ($table as $ligne) {
            $composition = (array) data_get($ligne, 'invoque', []);

            if ($de <= (int) data_get($ligne, 'jusqu_a', 6)) {
                break;
            }
        }

        // Repli : un sort d'invocation sans table (donnée héritée) invoque les
        // morts-vivants de base, le comportement d'avant les cartes.
        return $composition === [] ? ['Squelette' => self::NB_SBIRES_INVOQUES] : $composition;
    }

    /**
     * *Réanimation* : « reanimate ALL DEFEATED skeletons, zombies, or mummies
     * IN THE SAME ROOM as the spellcaster. These monsters rise from the floor,
     * with all lost Body Points restored, and attack the heroes again. »
     *
     * ⚠ Le seul sort du paquet qui rende une victoire réversible, et il ne
     * demandait rien de neuf : nos instances vaincues restent en base
     * (`etat != actif`) avec leur position. Il n'y avait qu'à les relever.
     *
     * ⚠ Elle consomme le verrou d'invocation, comme les quatre autres : un
     * nécromancien qui relèverait ses morts à chaque tour rendrait toute salle
     * impossible à nettoyer.
     *
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadReanimation(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        array $acteur,
    ): array {
        $instance->update(['invocation_dread_utilisee' => true]);

        $releves = [];

        foreach ($this->mortsVivantsARelever($quete, $instance, $sort) as $mort) {
            $mort->update([
                'etat' => 'actif',
                'pv_body' => (int) $mort->pvBodyMax(),
            ]);

            $releves[] = [
                'instance_id' => $mort->id,
                'monstre' => $mort->nomAffiche(),
                'x' => (int) $mort->position_x,
                'y' => (int) $mort->position_y,
            ];
        }

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'releves' => $releves,
        ];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * *Rouille* (carte *Rust*) : « causes any one metal sword or helmet to
     * become so thin, brittle, and useless that IT CAN NEVER BE USED AGAIN.
     * Not effective against artifacts. »
     *
     * ⚠ Le seul sort du paquet dont l'effet SURVIT À LA QUÊTE — la pièce quitte
     * l'inventaire pour de bon (arbitrage de René, 2026-09-04 : « c'est correct
     * qu'un joueur puisse perdre un objet »). Il n'y a donc ni condition, ni
     * rupture, ni durée : rien à annuler plus tard.
     *
     * ⚠ Le recalcul des dés passe par `Equipement::recalculerCombat()`, le même
     * point de passage que l'arme lancée : `des_attaque` et `des_defense` sont
     * des COLONNES tenues à jour à l'équipement, pas un calcul à la volée.
     * Supprimer la ligne sans recalculer laisserait le héros frapper avec une
     * épée qu'il n'a plus.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     * @param  Collection<int, EtatPersonnageQuete>  $enVue
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadDestruction(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        Collection $cibles,
        Collection $enVue,
        array $acteur,
    ): array {
        // La victime est choisie sur ce qu'il y a À PRENDRE : parmi les héros en
        // vue, celui dont la meilleure pièce rouillable vaut le plus cher. Un
        // ciblage « le plus proche » aurait rongé la dague du voisin en laissant
        // l'armure de plates à trois cases.
        $victime = null;
        $piece = null;

        foreach ($this->ciblesDuSort($sort, $quete, $instance, $cibles, $enVue) as $candidat) {
            $trouvee = $this->cibleDeRouille($sort, $candidat->personnage);

            if ($trouvee !== null
                && ($piece === null || (int) $trouvee->objet?->prix_base > (int) $piece->objet?->prix_base)) {
                $victime = $candidat;
                $piece = $trouvee;
            }
        }

        if ($victime === null || $piece === null) {
            return $this->sortDreadGenericJournal($groupe, $sort, $acteur);
        }

        $personnage = $victime->personnage;
        $nomPiece = (string) $piece->objet?->nom;
        $emplacement = (string) $piece->emplacement;

        $piece->delete();
        app(Equipement::class)->recalculerCombat($personnage->refresh());

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'resultats' => [[
                'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
                'objet_detruit' => $nomPiece,
                'emplacement' => $emplacement,
                'des_attaque_apres' => (int) $personnage->des_attaque,
                'des_defense_apres' => (int) $personnage->des_defense,
            ]],
        ];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
    }

    /**
     * La pièce que la *Rouille* peut ronger chez ce héros, ou `null`.
     *
     * Trois conditions, toutes DÉCLARÉES dans `effet.detruit` plutôt que câblées
     * ici — c'est ce qui permettra à une seconde carte de destruction de viser
     * autre chose sans toucher au moteur :
     *  - la matière (`metallique`) : le Bâton, la Baguette et l'Arbalète sont de
     *    bois, ils sont donc immunisés sans qu'aucune exception ne soit écrite ;
     *  - les emplacements (mains et casque) : « sword or helmet » ;
     *  - `epargne_artefacts` : « NOT EFFECTIVE AGAINST ARTIFACTS », mot pour mot.
     *
     * ⚠ Elle ne regarde que l'équipement PORTÉ, jamais le sac : Zargon désigne
     * une pièce qu'il voit, et rouiller une épée de rechange au fond d'un havresac
     * ne se lit pas sur le plateau.
     *
     * La plus CHÈRE d'abord — le sort fait mal, autant qu'il le fasse là où ça
     * se sent.
     */
    private function cibleDeRouille(SortDread $sort, ?Personnage $personnage): ?Inventaire
    {
        if ($personnage === null) {
            return null;
        }

        $regle = (array) data_get($sort->effet, 'detruit', []);
        $emplacements = (array) ($regle['emplacements'] ?? []);

        if ($emplacements === []) {
            return null;
        }

        return $personnage->inventaire()
            ->whereIn('emplacement', $emplacements)
            ->with('objet')
            ->get()
            ->filter(function (Inventaire $ligne) use ($regle) {
                $objet = $ligne->objet;

                if ($objet === null) {
                    return false;
                }

                if (! empty($regle['metallique']) && ! (bool) $objet->metallique) {
                    return false;
                }

                return empty($regle['epargne_artefacts']) || $objet->rarete !== 'unique';
            })
            ->sortByDesc(fn (Inventaire $ligne) => (int) $ligne->objet?->prix_base)
            ->first();
    }

    /**
     * *Soothe* / *Restore Dread* : « restores up to N lost Body Points to the
     * spellcaster or any one monster ».
     *
     * ⚠ « up to » : le soin est PLAFONNÉ par ce que la créature a perdu, jamais
     * par ses PV maximum — un monstre à 1 PV sur 3 récupère 2 points, pas 6.
     * C'est le même plafond que les soins des héros.
     *
     * @param  array<string, mixed>  $acteur
     * @return array<string, mixed>
     */
    private function sortDreadSoin(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        SortDread $sort,
        array $acteur,
    ): array {
        $cible = $this->cibleSoin($sort, $quete, $instance);

        if ($cible === null) {
            return $this->sortDreadGenericJournal($groupe, $sort, $acteur);
        }

        $max = (int) $cible->pvBodyMax();
        $rendu = min((int) data_get($sort->effet, 'soin', 0), $max - (int) $cible->pv_body);
        $cible->update(['pv_body' => (int) $cible->pv_body + $rendu]);

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'cible' => ['instance_id' => $cible->id, 'nom' => $cible->nomAffiche()],
            'soin' => $rendu,
            'pv_body_apres' => (int) $cible->pv_body,
            'sur_soi' => $cible->id === $instance->id,
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
        $composition = $creature === null
            ? ['Squelette' => self::NB_SBIRES_INVOQUES]
            : [(string) $creature => self::NB_SBIRES_INVOQUES];

        $invoques = $this->invoquerSbires($quete, $instance, $composition);

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

        // ⚠ `teleportation` DIT notre divergence plutôt que de la taire. La
        // carte (*Escape*) envoie le lanceur « to a secret destination known
        // only to Zargon […] marked on the quest map » : nos donjons sont
        // procéduraux et ne portent aucun refuge marqué. La seule lecture
        // possible ici est la case libre la plus éloignée des héros, et c'est
        // ce que la donnée déclare — un mot inconnu ne téléporte nulle part
        // plutôt que d'inventer un ailleurs.
        $caseCible = data_get($sort->effet, 'teleportation') === self::FUITE_CASE_ELOIGNEE
            ? $this->caseLaPlusEloignee($quete, $instance, $cibles)
            : null;

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
     * Fait apparaître une composition de sbires sur les cases libres adjacentes
     * au lanceur.
     *
     * ⚠ La composition est un `nom => nombre` — pas une liste de noms plus un
     * compte. Les quatre cartes d'invocation mélangent les espèces (« 3
     * skeletons, 2 zombies »), ce que l'ancienne signature ne pouvait pas dire :
     * elle prenait la PREMIÈRE créature trouvée et la répétait.
     *
     * ⚠ Et la recherche ne filtre plus sur `tier = base` : le *Loup géant* est
     * un sous-boss, et *Summon Wolves* en appelle jusqu'à trois. Le filtre
     * aurait fait tomber le sort dans son repli et invoqué des gobelins.
     *
     * Les nouveaux venus sont réarmés (`reinitialiserUsagesInstance`) : un
     * spectre invoqué sait canaliser l'Effroi, comme celui qui l'a appelé.
     *
     * @param  array<string, int>  $composition
     * @return list<array{monstre: string, x: int, y: int}>
     */
    private function invoquerSbires(
        Quete $quete,
        InstanceMonstre $lanceur,
        array $composition,
    ): array {
        $lanceur->update(['invocation_dread_utilisee' => true]);

        $casesLibres = $this->casesLibresAdjacentes($quete, $lanceur);
        $invoques = [];
        $i = 0;

        foreach ($composition as $nom => $nombre) {
            $catalogue = Monstre::query()->where('nom_base', $nom)->orderBy('id')->first();

            // Repli : n'importe quel monstre de base plutôt que rien — une
            // donnée de référence manquante ne doit pas annuler le sort en
            // silence.
            $catalogue ??= Monstre::query()->where('tier', 'base')->orderBy('id')->first();

            if ($catalogue === null) {
                continue;
            }

            for ($n = 0; $n < (int) $nombre && isset($casesLibres[$i]); $n++, $i++) {
                $case = $casesLibres[$i];
                $sbire = InstanceMonstre::create([
                    'quete_id' => $quete->id,
                    'monstre_id' => $catalogue->id,
                    'pv_body' => $catalogue->pv_body,
                    'pv_mind' => $catalogue->pv_mind,
                    'position_x' => $case['x'],
                    'position_y' => $case['y'],
                    'etat' => 'actif',
                ]);
                $this->reinitialiserUsagesInstance($sbire->setRelation('monstre', $catalogue), $quete);

                $invoques[] = ['monstre' => $catalogue->nom_base, 'x' => $case['x'], 'y' => $case['y']];
            }
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
        ?int $duree,
        string $source,
    ): void {
        $condition = Condition::where('nom', $nomCondition)->first();

        if ($condition === null || Competence::resisteA($personnage, $nomCondition)) {
            return; // condition inconnue, ou résistance nommée (Sang robuste vs Empoisonné)
        }

        // ⚠ `null` = « prends la durée du catalogue » ; un ENTIER, zéro compris,
        // est une consigne explicite. La signature était `int` avec « 0 = valeur
        // par défaut », ce qui rendait « sans compteur » INEXPRIMABLE — or c'est
        // exactement ce que réclame une condition dont la sortie est une
        // rupture. *Paralysé* porte `duree_defaut: 3` pour la Flamme hypnotique
        // des héros ; la *Nuée d'Effroi*, elle, ne finit que sur un 6, et un
        // compteur de trois tours l'aurait levée dans le dos de la carte.
        $duree ??= (int) $condition->duree_defaut;

        $personnage->conditions()->attach($condition->id, [
            'duree' => $duree,
            'source' => $source,
        ]);
    }

    /** Retire toutes les lignes d'une condition par son nom. */
    public function retirerConditionHeros(Personnage $personnage, string $nomCondition): void
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
     * Chemin (BFS) d'une case vers une case ADJACENTE à une autre — de quoi
     * venir au contact sans occuper la case visée.
     *
     * ⚠ Publique et nommée sur ce qu'elle fait depuis le 2026-09-04 : la
     * *Baguette d'Os* fait marcher des squelettes vers un autre monstre, ce que
     * `cheminVersHeros` prétendait ne pas savoir faire alors que le calcul est
     * le même. Une seconde copie aurait dérivé.
     *
     * @return list<array{x: int, y: int}>|null
     */
    public function cheminVersCaseAdjacente(Grille $grille, int $dx, int $dy, int $ax, int $ay): ?array
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
