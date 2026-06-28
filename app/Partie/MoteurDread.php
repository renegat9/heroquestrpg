<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Combat;
use App\Engine\Des\LanceurDes;
use App\Engine\SortMental;
use App\Engine\TypeFigurine;
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
use Illuminate\Support\Facades\Cache;

/**
 * Moteur de la magie du Chaos et des capacités de boss (doc 09 §4, contrat
 * « Sorts de Dread & capacités des boss »).
 *
 * USAGES : un lanceur (tier sous_boss ou boss) dispose d'un nombre limité
 * d'usages par rencontre — départ playtest :
 *   - sous_boss : USAGES_SOUS_BOSS = 2
 *   - boss      : USAGES_BOSS      = 3
 * Les usages vivent en cache (clé par instance+quête), réarmés à chaque
 * démarrage de quête par DemarreurQuete::reinitialiserUsagesDread().
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
 *   - invocation     : même mécanique que le sort, sbires de base ;
 *   - frappe_de_zone : attaque touche TOUS les héros adjacents (un jet/cible) ;
 *   - regeneration   : +1 PV Body au début de son tour (plafonné au max du catalogue) ;
 *   - resistance_magique : +2 dés de défense quand un héros lui lance un sort de
 *     dégâts — branchement dans MoteurSorts::bonusDefenseResistanceMagique() ;
 *   - charge         : si hors contact mais joignable ce tour : déplacement
 *     puis attaque à +1 dé.
 *
 * DÉCISION DE SORT (jouerMonstreDread) — priorités :
 *   1. Tempête de feu / Trait de Chaos si héros à portée de vue (score ≥ 2 héros
 *      touchés ou héros visible) ;
 *   2. Sommeil / Frayeur / Commandement sur le héros au Mind le plus FAIBLE non
 *      déjà sous cette condition ;
 *   3. Invocation si ≤ 1 autre monstre actif ;
 *   4. Fuite si pv_body < 25 % du max.
 */
final class MoteurDread
{
    /** Usages de sorts de Dread par rencontre — valeurs playtest. */
    public const USAGES_SOUS_BOSS = 2;

    public const USAGES_BOSS = 3;

    /** Bonus de dés de défense de la capacité Résistance magique. */
    public const BONUS_RESISTANCE_MAGIQUE = 2;

    /** Nombre de sbires invoqués par sort/capacité d'invocation. */
    public const NB_SBIRES_INVOQUES = 2;

    /** Seuil de PV (fraction du max) déclenchant la Fuite. */
    public const SEUIL_FUITE = 0.25;

    /** Clé de cache d'un usage d'invocation unique par instance+quête. */
    public static function cleInvocation(int $instanceId, int $queteId): string
    {
        return "dread:invocation:{$instanceId}:{$queteId}";
    }

    /** Clé de cache d'un usage de Fuite unique par instance+quête. */
    public static function cleFuite(int $instanceId, int $queteId): string
    {
        return "dread:fuite:{$instanceId}:{$queteId}";
    }

    /** Clé des usages restants par instance+quête. */
    public static function cleUsages(int $instanceId, int $queteId): string
    {
        return "dread:usages:{$instanceId}:{$queteId}";
    }

    public function __construct(
        private readonly LanceurDes $des,
        private readonly MoteurSorts $sorts,
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

        $max = $tier === 'boss' ? self::USAGES_BOSS : self::USAGES_SOUS_BOSS;
        Cache::forever(self::cleUsages($instance->id, $quete->id), $max);
        Cache::forget(self::cleInvocation($instance->id, $quete->id));
        Cache::forget(self::cleFuite($instance->id, $quete->id));
    }

    /** Usages restants pour cette instance (0 si jamais initialisé). */
    public function usagesRestants(InstanceMonstre $instance, Quete $quete): int
    {
        return (int) Cache::get(self::cleUsages($instance->id, $quete->id), 0);
    }

    /** Consomme un usage (ne descend pas en dessous de 0). */
    private function consommerUsage(InstanceMonstre $instance, Quete $quete): void
    {
        $restants = max(0, $this->usagesRestants($instance, $quete) - 1);
        Cache::forever(self::cleUsages($instance->id, $quete->id), $restants);
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
        $nomMonstre = $instance->habillage['nom'] ?? $instance->monstre->nom_base;
        $acteur = ['type' => 'monstre', 'id' => $instance->id, 'nom' => $nomMonstre];

        // Collecte les actions Dread jouées ce tour (régénération + action principale).
        $actions = [];

        // 1. Régénération : +1 PV Body au DÉBUT du tour (avant toute action).
        if ($this->aCapacite($instance, 'regeneration')) {
            $regenPayload = $this->appliquerRegeneration($groupe, $instance, $acteur);

            if ($regenPayload !== null) {
                $actions[] = $regenPayload;
            }
        }

        // 2. Sort de Dread si usages restants et répertoire non vide (archétype
        //    nommé inclus — 3.8 : le répertoire peut venir de l'archétype même si
        //    le champ brut sorts_dread est vide).
        if ($this->repertoireSorts($instance->monstre) !== [] && $this->usagesRestants($instance, $quete) > 0) {
            $sortChoisi = $this->choisirSort($groupe, $quete, $instance, $cibles);

            if ($sortChoisi !== null) {
                $this->consommerUsage($instance, $quete);
                $actions[] = $this->lancerSortDread($groupe, $quete, $instance, $sortChoisi, $cibles, $acteur);

                return $this->fusionnerActions($actions);
            }
        }

        // 3. Capacité Charge si hors contact mais joignable.
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
            desDefense: (int) $ciblePersonnage->des_defense + $this->sorts->bonusDes($ciblePersonnage, 'bonus_des_defense'),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $ciblePersonnage->pv_body,
        );

        $ciblePersonnage->update(['pv_body' => $resultat->pvBodyApres]);
        $this->sorts->reveillerHeros($ciblePersonnage); // être attaqué réveille

        if ($resultat->cibleTombee) {
            $cibleEtat->update(['tombe' => true]);
        }

        $payload = [
            'type' => 'commandement_attaque',
            'personnage' => $personnage->nom,
            'cible' => ['personnage_id' => $ciblePersonnage->id, 'nom' => $ciblePersonnage->nom],
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $resultat->degats,
            'pv_body_apres' => $resultat->pvBodyApres,
            'cible_tombee' => $resultat->cibleTombee,
            'faces_attaque' => array_map(fn ($f) => $f->value, $resultat->facesAttaque),
            'faces_defense' => array_map(fn ($f) => $f->value, $resultat->facesDefense),
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

    public function aCapacite(InstanceMonstre $instance, string $capacite): bool
    {
        return in_array($capacite, (array) ($instance->monstre->capacites ?? []), true);
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
        $nomMonstre = $instance->habillage['nom'] ?? $instance->monstre->nom_base;
        $adjacents = $cibles->filter(function (EtatPersonnageQuete $c) use ($instance) {
            return abs((int) $instance->position_x - (int) $c->position_x)
                + abs((int) $instance->position_y - (int) $c->position_y) === 1;
        });

        $resultats = [];

        foreach ($adjacents as $cible) {
            $personnage = $cible->personnage;
            $resultat = (new Combat($this->des))->resoudreAttaque(
                desAttaque: (int) $instance->monstre->attaque,
                desDefense: (int) $personnage->des_defense + $this->sorts->bonusDes($personnage, 'bonus_des_defense'),
                typeDefenseur: TypeFigurine::Heros,
                pvBodyDefenseur: (int) $personnage->pv_body,
            );

            $personnage->update(['pv_body' => $resultat->pvBodyApres]);
            $this->sorts->reveillerHeros($personnage);

            if ($resultat->cibleTombee) {
                $cible->update(['tombe' => true]);
            }

            $resultats[] = [
                'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
                'touches' => $resultat->touches,
                'boucliers' => $resultat->boucliers,
                'degats' => $resultat->degats,
                'pv_body_apres' => $resultat->pvBodyApres,
                'cible_tombee' => $resultat->cibleTombee,
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
     * documentées.
     *
     * @param  Collection<int, EtatPersonnageQuete>  $cibles
     */
    private function choisirSort(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
    ): ?SortDread {
        $sortsDisponibles = $this->sortsDisponibles($instance, $quete);

        if ($sortsDisponibles->isEmpty() || $cibles->isEmpty()) {
            return null;
        }

        // Priorité 1 : Tempête de feu si plusieurs héros visibles, Trait de Chaos sinon.
        $tempete = $sortsDisponibles->firstWhere('nom', 'Tempête de feu');

        if ($tempete !== null && $cibles->count() >= 2) {
            return $tempete;
        }

        $trait = $sortsDisponibles->firstWhere('nom', 'Trait de Chaos');

        if ($trait !== null && $cibles->isNotEmpty()) {
            return $trait;
        }

        // Priorité 1b : Tempête de feu même sur 1 héros.
        if ($tempete !== null && $cibles->isNotEmpty()) {
            return $tempete;
        }

        // Priorité 2 : Sommeil / Frayeur / Commandement sur le héros au Mind le plus faible.
        $cibleMindFaible = $cibles
            ->sortBy(fn (EtatPersonnageQuete $e) => (int) $e->personnage->attribut_mind)
            ->first();

        if ($cibleMindFaible !== null) {
            foreach (['Sommeil', 'Frayeur', 'Commandement'] as $nomSort) {
                $sort = $sortsDisponibles->firstWhere('nom', $nomSort);

                if ($sort === null) {
                    continue;
                }

                $conditionNom = data_get($sort->effet, 'condition_appliquee');

                // Ne pas relancer le sort si le héros est déjà sous cette condition.
                if ($conditionNom !== null && $this->herosSousCondition($cibleMindFaible->personnage, $conditionNom)) {
                    continue;
                }

                return $sort;
            }
        }

        // Priorité 3 : Invocation si ≤ 1 autre monstre actif.
        $invocation = $sortsDisponibles->firstWhere('nom', 'Invocation de morts-vivants');

        if ($invocation !== null) {
            $autresMonstres = $quete->instancesMonstres()
                ->where('etat', 'actif')
                ->whereKeyNot($instance->id)
                ->count();

            if ($autresMonstres <= 1
                && ! Cache::has(self::cleInvocation($instance->id, $quete->id))) {
                return $invocation;
            }
        }

        // Priorité 4 : Fuite si PV < 25 % du max.
        $fuite = $sortsDisponibles->firstWhere('nom', 'Fuite');

        if ($fuite !== null
            && (int) $instance->pv_body < max(1, (int) $instance->monstre->pv_body * self::SEUIL_FUITE)
            && ! Cache::has(self::cleFuite($instance->id, $quete->id))) {
            return $fuite;
        }

        return null;
    }

    /**
     * Sorts de Dread disponibles pour cette instance : ceux listés dans
     * monstres.sorts_dread matchés dans le catalogue SortDread.
     *
     * @return \Illuminate\Support\Collection<int, SortDread>
     */
    private function sortsDisponibles(InstanceMonstre $instance, Quete $quete): \Illuminate\Support\Collection
    {
        $noms = $this->repertoireSorts($instance->monstre);

        if (empty($noms)) {
            return collect();
        }

        return SortDread::whereIn('nom', $noms)->get()->keyBy('nom')->values();
    }

    /**
     * Répertoire de sorts de Dread d'un monstre (3.8) : si un archétype lanceur
     * nommé est défini ET connu de config/archetypes_lanceurs.php, on prend son
     * répertoire COMPLET ; sinon la liste per-monstre `sorts_dread` du catalogue.
     *
     * @return list<string>
     */
    private function repertoireSorts(\App\Models\Monstre $monstre): array
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
        array $acteur,
    ): array {
        return match ($sort->type) {
            'degats' => $this->sortDreadDegats($groupe, $quete, $instance, $sort, $cibles, $acteur),
            'controle' => $this->sortDreadControle($groupe, $instance, $sort, $cibles, $acteur),
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

        // Trait de Chaos : 1 héros ciblé (le plus proche / le plus faible — on prend le premier de la liste).
        $cible = $cibles->first();

        if ($cible === null) {
            return $this->sortDreadGenericJournal($groupe, $sort, $acteur);
        }

        $personnage = $cible->personnage;
        $resultat = (new Combat($this->des))->resoudreAttaque(
            desAttaque: $desDegats,
            desDefense: (int) $personnage->des_defense + $this->sorts->bonusDes($personnage, 'bonus_des_defense'),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $personnage->pv_body,
        );

        $personnage->update(['pv_body' => $resultat->pvBodyApres]);
        $this->sorts->reveillerHeros($personnage);

        if ($resultat->cibleTombee) {
            $cible->update(['tombe' => true]);
        }

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
            'des_degats' => $desDegats,
            'touches' => $resultat->touches,
            'boucliers' => $resultat->boucliers,
            'degats' => $resultat->degats,
            'pv_body_apres' => $resultat->pvBodyApres,
            'cible_tombee' => $resultat->cibleTombee,
            'faces_attaque' => array_map(fn ($f) => $f->value, $resultat->facesAttaque),
            'faces_defense' => array_map(fn ($f) => $f->value, $resultat->facesDefense),
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
        // Cases affectées : la case du lanceur + 4 orthogonales.
        $cx = (int) $instance->position_x;
        $cy = (int) $instance->position_y;
        $casesAffectees = [
            ['x' => $cx, 'y' => $cy],
            ['x' => $cx + 1, 'y' => $cy],
            ['x' => $cx - 1, 'y' => $cy],
            ['x' => $cx, 'y' => $cy + 1],
            ['x' => $cx, 'y' => $cy - 1],
        ];

        $resultats = [];

        foreach ($cibles as $cible) {
            $cx2 = (int) $cible->position_x;
            $cy2 = (int) $cible->position_y;

            $touche = false;
            foreach ($casesAffectees as $case) {
                if ($case['x'] === $cx2 && $case['y'] === $cy2) {
                    $touche = true;
                    break;
                }
            }

            if (! $touche) {
                continue;
            }

            $personnage = $cible->personnage;
            $resultat = (new Combat($this->des))->resoudreAttaque(
                desAttaque: $desDegats,
                desDefense: (int) $personnage->des_defense + $this->sorts->bonusDes($personnage, 'bonus_des_defense'),
                typeDefenseur: TypeFigurine::Heros,
                pvBodyDefenseur: (int) $personnage->pv_body,
            );

            $personnage->update(['pv_body' => $resultat->pvBodyApres]);
            $this->sorts->reveillerHeros($personnage);

            if ($resultat->cibleTombee) {
                $cible->update(['tombe' => true]);
            }

            $resultats[] = [
                'cible' => ['personnage_id' => $personnage->id, 'nom' => $personnage->nom],
                'des_degats' => $desDegats,
                'touches' => $resultat->touches,
                'boucliers' => $resultat->boucliers,
                'degats' => $resultat->degats,
                'pv_body_apres' => $resultat->pvBodyApres,
                'cible_tombee' => $resultat->cibleTombee,
                'faces_attaque' => array_map(fn ($f) => $f->value, $resultat->facesAttaque),
                'faces_defense' => array_map(fn ($f) => $f->value, $resultat->facesDefense),
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

        // Ciblage : héros au Mind le plus faible non déjà sous cette condition.
        $cible = $cibles
            ->filter(fn (EtatPersonnageQuete $e) => ! $this->herosSousCondition($e->personnage, $conditionNom))
            ->sortBy(fn (EtatPersonnageQuete $e) => (int) $e->personnage->attribut_mind)
            ->first();

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
            $duree = (int) data_get($sort->effet, 'duree_tours', 0);
            $this->poserConditionHeros($personnage, $conditionNom, $duree, 'sort_dread:'.$sort->nom);
            $payload['condition'] = $conditionNom;
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
        // Marqueur 1×/rencontre.
        Cache::forever(self::cleInvocation($instance->id, $quete->id), true);

        $invoques = $this->invoquerSbires($groupe, $quete, $instance, $sort);

        $payload = [
            'type' => 'sort_dread',
            'sort' => $sort->nom,
            'invoques' => $invoques,
        ];
        Journal::ajouter($groupe, 'action', $payload, $acteur);

        return $payload;
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
        Cache::forever(self::cleFuite($instance->id, $quete->id), true);

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
     * @return array<string, mixed>|null  null si charge non applicable
     */
    private function tentativeCharge(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $instance,
        Collection $cibles,
        array $acteur,
    ): ?array {
        $nomMonstre = $instance->habillage['nom'] ?? $instance->monstre->nom_base;
        $grille = $this->grilleQuete($quete, exceptInstanceId: $instance->id);

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
            desDefense: (int) $personnage->des_defense + $this->sorts->bonusDes($personnage, 'bonus_des_defense'),
            typeDefenseur: TypeFigurine::Heros,
            pvBodyDefenseur: (int) $personnage->pv_body,
        );

        $personnage->update(['pv_body' => $resultat->pvBodyApres]);
        $this->sorts->reveillerHeros($personnage);

        if ($resultat->cibleTombee) {
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
            'degats' => $resultat->degats,
            'pv_body_apres' => $resultat->pvBodyApres,
            'cible_tombee' => $resultat->cibleTombee,
            'faces_attaque' => array_map(fn ($f) => $f->value, $resultat->facesAttaque),
            'faces_defense' => array_map(fn ($f) => $f->value, $resultat->facesDefense),
        ];
        Journal::ajouter($groupe, 'combat', $payload, $acteur);

        return $payload;
    }

    // ------------------------------------------------------------------
    // Internals — invocation de sbires
    // ------------------------------------------------------------------

    /**
     * Invoque des sbires sur les cases libres adjacentes au lanceur.
     *
     * @return list<array{monstre: string, x: int, y: int}>
     */
    private function invoquerSbires(
        Groupe $groupe,
        Quete $quete,
        InstanceMonstre $lanceur,
        SortDread $sort,
    ): array {
        $nomsInvocables = (array) data_get($sort->effet, 'invoque', ['Squelette']);
        $nombre = (int) data_get($sort->effet, 'nombre', self::NB_SBIRES_INVOQUES);

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

        if ($condition === null) {
            return; // condition inconnue — silencieux, le catalogue fait foi
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

        \Illuminate\Support\Facades\DB::table('personnage_conditions')
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
        $maxPv = (int) $instance->monstre->pv_body;
        $avant = (int) $instance->pv_body;

        if ($avant >= $maxPv) {
            return null; // déjà au max
        }

        $apres = min($maxPv, $avant + 1);
        $instance->update(['pv_body' => $apres]);

        $payload = [
            'type' => 'regeneration',
            'monstre' => $instance->habillage['nom'] ?? $instance->monstre->nom_base,
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
     * Cases libres (sol/porte, inoccupées) orthogonalement adjacentes au lanceur.
     *
     * @return list<array{x: int, y: int}>
     */
    private function casesLibresAdjacentes(Quete $quete, InstanceMonstre $lanceur): array
    {
        $cases = $quete->carte?->grille['cases'] ?? [];
        $occupees = [];

        foreach ($quete->etatsPersonnages()->get() as $etat) {
            $occupees["{$etat->position_x},{$etat->position_y}"] = true;
        }
        foreach ($quete->instancesMonstres()->where('etat', 'actif')->get() as $m) {
            $occupees["{$m->position_x},{$m->position_y}"] = true;
        }

        $libres = [];
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $x = (int) $lanceur->position_x + $dx;
            $y = (int) $lanceur->position_y + $dy;
            $type = $cases[$y][$x] ?? 'm';

            if (($type === 's' || $type === 'p') && ! isset($occupees["{$x},{$y}"])) {
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
            ->sortBy(fn (EtatPersonnageQuete $a) =>
                abs((int) $etat->position_x - (int) $a->position_x)
                + abs((int) $etat->position_y - (int) $a->position_y)
            )
            ->first();

        return $plusProche !== null ? [$plusProche->personnage, $plusProche, false] : null;
    }

    /**
     * Grille de la quête avec les figurines occupées (utile pour pathfinding).
     */
    private function grilleQuete(
        Quete $quete,
        ?int $exceptPersonnageId = null,
        ?int $exceptInstanceId = null,
    ): Grille {
        $carte = $quete->carte;

        if ($carte === null) {
            throw new \RuntimeException('Quête sans carte — impossible de calculer le pathfinding.');
        }

        $grille = Grille::depuisCarte($carte);
        $occupees = [];

        foreach ($quete->etatsPersonnages()->get() as $etatP) {
            if ($etatP->personnage_id !== $exceptPersonnageId && $etatP->position_x !== null) {
                $occupees[] = ['x' => (int) $etatP->position_x, 'y' => (int) $etatP->position_y];
            }
        }

        foreach ($quete->instancesMonstres()->where('etat', 'actif')->get() as $m) {
            if ($m->id !== $exceptInstanceId && $m->position_x !== null) {
                $occupees[] = ['x' => (int) $m->position_x, 'y' => (int) $m->position_y];
            }
        }

        $grille->occuper($occupees);

        return $grille;
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
