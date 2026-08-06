<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\DureeEffet;
use App\Engine\MotsClesSort;
use App\Models\Competence;
use App\Models\Condition;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Objet;
use App\Models\Sort;
use App\Partie\MoteurPortes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moteur des sorts des héros (doc 02) — résolu en code, jamais par l'IA.
 *
 * Connaissance par ÉLÉMENTS (doc 02 §2-3) : connaître un élément = connaître
 * ses 3 sorts (pivot personnage_sorts, disponible = épuisé/dispo). À la
 * création (parité HeroQuest de base) : Magicien 3 éléments (9 sorts), Elfe
 * 1 élément (3 sorts) — voir NB_ELEMENTS_DEPART. Éléments SUPPLÉMENTAIRES via
 * l'arbre : nœuds « Première magie » / « Second élément » de l'Elfe, « Écoles »
 * (répétable) du Magicien — tous via la mécanique `emplacement_element` du
 * CompetenceSeeder.
 *
 * Récupération « une fois par quête » (S5) : DemarreurQuete remet tout
 * disponible via reinitialiserQuete ; « Concentration » (S6, nœud Magicien)
 * récupère UN sort épuisé en sacrifiant le tour, une fois par quête — l'usage
 * est marqué en cache (clé groupe+personnage), purgé au démarrage suivant.
 *
 * BUFFS DES SORTS UTILITAIRES : ils vivent en `personnage_conditions`
 * (condition du catalogue + pivot source `sort:{Nom}`). Les valeurs chiffrées
 * (bonus de dés, multiplicateur…) ne sont jamais recopiées : elles sont relues
 * dans l'effet JSON du sort pointé par la source, aux résolutions d'attaque /
 * défense / déplacement (ResolveurTour).
 *
 * Leur EXPIRATION est pilotée par le mot-clé `effet.duree` (App\Engine\DureeEffet,
 * cf. reference/19_mots_cles_effets.md) — plus par un compteur de tours ni par
 * des appels câblés sur la clé d'effet :
 *  - Courage (bonus_des_attaque)       : `prochaine_attaque` ;
 *  - Peau de Pierre (bonus_des_defense): `fin_du_combat` — plus aucun monstre
 *    ENGAGÉ (actif ET révélé), et non plus « fin de quête » comme au MVP ;
 *  - Voile de Brume (condition Caché)  : `prochain_tour` — couvre la phase des
 *    monstres, ce qui est tout l'intérêt d'une protection ;
 *  - Vent Véloce (deplacement_multiplie): `ce_tour`.
 *
 * CONDITIONS DES MONSTRES : il n'existe pas de pivot conditions pour les
 * instances de monstres (et pas de nouvelle migration) — elles vivent dans
 * le JSON `instances_monstres.habillage.conditions` (choix MVP documenté) :
 *  - `endormi` (Sommeil)         : le monstre ne joue pas tant qu'il n'est
 *    pas attaqué — une attaque le réveille ;
 *  - `saute_tour` (Tempête) : passe entièrement son prochain tour — ni
 *    déplacement ni attaque —, consommé à cette activation-là.
 */
final class MoteurSorts
{
    /** Les 4 éléments du MVP (doc 02 §7). */
    public const ELEMENTS = ['feu', 'eau', 'terre', 'air'];

    /**
     * Nombre d'éléments choisis à la CRÉATION selon la classe (parité HeroQuest
     * de base — doc 02 §2) : Magicien 3, Elfe 1 ; toute autre classe 0. Les
     * éléments au-delà s'acquièrent via l'arbre (`emplacement_element`).
     */
    public const NB_ELEMENTS_DEPART = ['magicien' => 3, 'elfe' => 1];

    /** Éléments de départ par défaut par classe quand le client n'en choisit aucun. */
    public const ELEMENTS_DEPART_DEFAUT = [
        'magicien' => ['feu', 'eau', 'terre'],
        'elfe' => ['eau'],
    ];

    /** Élément par défaut d'un nœud `emplacement_element` (contrat). */
    public const ELEMENT_DEFAUT = 'eau';

    /** Classes lanceuses de sorts (parchemins en réussite auto, doc 02 §6). */
    public const LANCEURS = ['magicien', 'elfe'];

    /** Mécanique des nœuds d'arbre qui débloquent un élément (CompetenceSeeder). */
    public const MECANIQUE_ELEMENT = 'emplacement_element';

    /** Nom exact du nœud magicien de récupération (CompetenceSeeder). */
    public const NOEUD_CONCENTRATION = 'Concentration';

    /** Préfixe des sources de conditions posées par un sort. */
    public const PREFIXE_SOURCE = 'sort:';

    /** Préfixe des sources de conditions posées par une POTION (buff bu). */
    public const PREFIXE_SOURCE_POTION = 'potion:';

    /** Condition générique des buffs chiffrés sans condition dédiée (catalogue). */
    public const CONDITION_BUFF_DEFAUT = 'Renforcé';

    /** Clés des conditions de monstre (habillage.conditions). */
    public const MONSTRE_ENDORMI = 'endormi';

    /**
     * Tempête : le monstre PASSE SON PROCHAIN TOUR (ni déplacement, ni attaque).
     *
     * Remplace l'ancien `empeche_attaque`, qui ne bloquait que l'attaque et
     * laissait le monstre avancer librement. Le texte officiel est sans
     * ambiguïté — « un monstre choisi passe son prochain tour » (Kellar's Keep
     * p. 15, reference/18_extensions.md §3).
     */
    public const MONSTRE_SAUTE_TOUR = 'saute_tour';

    /**
     * Dés de dégâts de repli si l'effet JSON du catalogue n'en donne pas
     * (départ playtest doc 02 §7) — le seeder fait toujours foi.
     */
    public const DES_DEGATS_DEFAUT = [
        'Boule de Feu' => 2,
        'Trait de Feu' => 1,
        'Génie' => 4,
    ];

    // ------------------------------------------------------------------
    // Acquisition par éléments
    // ------------------------------------------------------------------

    /** Nombre d'éléments choisis à la création par cette classe (0 = non-lanceur). */
    public static function nbElementsDepart(string $classe): int
    {
        return self::NB_ELEMENTS_DEPART[$classe] ?? 0;
    }

    /**
     * Éléments de départ à attacher pour cette classe : le choix du client
     * s'il est fourni, sinon le défaut catalogue ; liste vide pour un
     * non-lanceur (Barbare / Nain).
     *
     * @param  list<string>|null  $choix
     * @return list<string>
     */
    public static function elementsDepart(string $classe, ?array $choix = null): array
    {
        if (self::nbElementsDepart($classe) === 0) {
            return [];
        }

        return $choix ?? self::ELEMENTS_DEPART_DEFAUT[$classe] ?? [];
    }

    /**
     * Attache les 3 sorts d'un élément au héros (disponibles d'office).
     *
     * @return Collection<int, Sort> sorts attachés
     */
    public function attacherElement(Personnage $personnage, string $element): Collection
    {
        $sorts = Sort::query()->where('element', $element)->orderBy('id')->get();

        foreach ($sorts as $sort) {
            $personnage->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => true]]);
        }

        return $sorts;
    }

    /**
     * @return list<string> éléments dont le héros connaît les sorts
     */
    public function elementsConnus(Personnage $personnage): array
    {
        return $personnage->sorts()->pluck('element')->unique()->values()->all();
    }

    // ------------------------------------------------------------------
    // Récupération par quête (S5/S6)
    // ------------------------------------------------------------------

    /**
     * Démarrage de quête : tous les sorts redeviennent disponibles, les
     * buffs de sorts encore portés sont purgés (Peau de Pierre « fin de
     * quête » incluse) et l'usage de Concentration est réarmé.
     */
    public function reinitialiserQuete(Groupe $groupe, Personnage $personnage): void
    {
        DB::table('personnage_sorts')
            ->where('personnage_id', $personnage->id)
            ->update(['disponible' => true]);

        DB::table('personnage_conditions')
            ->where('personnage_id', $personnage->id)
            ->where('source', 'like', self::PREFIXE_SOURCE.'%')
            ->delete();

        Cache::forget(self::cleConcentration($groupe->id, $personnage->id));
    }

    /** Clé du marqueur « Concentration déjà utilisée cette quête ». */
    public static function cleConcentration(int $groupeId, int $personnageId): string
    {
        return "partie:sorts:concentration:{$groupeId}:{$personnageId}";
    }

    /** Magicien possédant le nœud Concentration, pas encore utilisé cette quête. */
    public function concentrationDisponible(Groupe $groupe, Personnage $personnage): bool
    {
        return $personnage->classe === 'magicien'
            && $personnage->competences()->where('nom', self::NOEUD_CONCENTRATION)->exists()
            && ! (bool) Cache::get(self::cleConcentration($groupe->id, $personnage->id), false);
    }

    public function marquerConcentrationUtilisee(Groupe $groupe, Personnage $personnage): void
    {
        Cache::forever(self::cleConcentration($groupe->id, $personnage->id), true);
    }

    // ------------------------------------------------------------------
    // Options de menu (MenuMoteur — exécutables telles quelles)
    // ------------------------------------------------------------------

    /**
     * Options de sorts d'un héros en quête : une option par sort DISPONIBLE
     * (cibles légales jointes), « Utiliser un parchemin » par parchemin au
     * sac, « Se concentrer » si le nœud le permet et qu'un sort est épuisé.
     *
     * @return list<array<string, mixed>>
     */
    public function options(Groupe $groupe, Quete $quete, Personnage $personnage): array
    {
        $options = [];
        $ciblesMonstres = $this->ciblesMonstres($quete);
        $ciblesHeros = $this->ciblesHeros($quete);

        // Ligne de vue (doc 03 §36) : les sorts offensifs ne peuvent viser qu'une
        // figure VISIBLE — une figure interposée (allié comme ennemi) coupe la
        // vue. Plateau occupé partagé avec le déplacement (FabriqueGrille).
        $etat = $quete->etatsPersonnages()->where('personnage_id', $personnage->id)->first();
        $grille = FabriqueGrille::pour($quete);
        $lanceur = ($etat !== null && $etat->position_x !== null)
            ? ['x' => (int) $etat->position_x, 'y' => (int) $etat->position_y]
            : null;

        foreach ($personnage->sorts()->wherePivot('disponible', true)->orderBy('sorts.id')->get() as $sort) {
            $options[] = $this->optionSort(
                "sort_{$sort->id}",
                "Lancer {$sort->nom}",
                'sort',
                ['sort_id' => $sort->id],
                $sort,
                $ciblesMonstres,
                $ciblesHeros,
                $lanceur,
                $grille,
            );

            // Sorts à DEUX modes (Génie : « ouvre une porte au choix OU attaque
            // avec 5 dés » — Kellar's Keep p. 15). Le second mode devient une
            // option distincte par porte connue plutôt qu'un paramètre : il
            // réutilise ainsi tout le rendu des options `ouvrir_porte`, sans
            // nouvelle feuille de sélection côté manette.
            foreach ($this->optionsPorteAuChoix($quete, $sort) as $option) {
                $options[] = $option;
            }
        }

        // Parchemins au sac (ObjetSeeder : effet.sort_id pointe le sort) —
        // utilisables par TOUS, jet de Mind pour les non-lanceurs (S1).
        foreach ($personnage->inventaire()->with('objet')->orderBy('id')->get() as $ligne) {
            $sort = Sort::find(data_get($ligne->objet?->effet, 'sort_id'));

            if ($sort === null) {
                continue;
            }

            $options[] = $this->optionSort(
                "parchemin_{$ligne->id}",
                "Utiliser un parchemin : {$sort->nom}",
                'parchemin',
                ['inventaire_id' => $ligne->id, 'sort_id' => $sort->id],
                $sort,
                $ciblesMonstres,
                $ciblesHeros,
                $lanceur,
                $grille,
            );
        }

        // « Se concentrer » (S6) : magicien + nœud + ≥1 sort épuisé + pas
        // encore utilisée cette quête.
        if ($this->concentrationDisponible($groupe, $personnage)) {
            $epuises = $personnage->sorts()->wherePivot('disponible', false)->orderBy('sorts.id')->get();

            if ($epuises->isNotEmpty()) {
                $options[] = [
                    'id' => 'se_concentrer',
                    'libelle' => 'Se concentrer — sacrifier le tour pour récupérer un sort épuisé',
                    'type' => 'concentration',
                    'parametres' => [
                        'sorts_epuises' => $epuises
                            ->map(fn (Sort $s) => ['sort_id' => $s->id, 'nom' => $s->nom])
                            ->values()
                            ->all(),
                    ],
                ];
            }
        }

        return $options;
    }

    /**
     * Cibles légales d'un sort (doc 02 §5, S3) : degats/mental → monstres
     * actifs ET héros (tir ami possible), RESTREINTS à la ligne de vue du
     * lanceur (une figure interposée coupe la vue, doc 03 §36) ; utilitaire
     * ciblé → héros de la quête ; cible `soi` (Traverser la Pierre) → pas de
     * liste, le lanceur. Les positions internes (x/y/emprise) servent au filtre
     * de LdV puis sont retirées : la liste rendue reste {type, id, nom}.
     *
     * @param  list<array<string, mixed>>  $monstres
     * @param  list<array<string, mixed>>  $heros
     * @param  array{x: int, y: int}|null  $lanceur
     * @return list<array{type: string, id: int, nom: string}>|null
     */
    public function ciblesLegales(Sort $sort, array $monstres, array $heros, ?array $lanceur = null, ?Grille $grille = null): ?array
    {
        $cible = (string) data_get($sort->effet, 'cible', MotsClesSort::CIBLE_SOI);

        // `soi` (Traverser la Pierre) : le lanceur, donc aucune liste à choisir.
        if (! in_array($sort->type, ['degats', 'mental'], true)
            && $cible !== MotsClesSort::CIBLE_HEROS) {
            return null;
        }

        $cibles = in_array($sort->type, ['degats', 'mental'], true)
            ? [...$monstres, ...$heros]   // tir ami délibéré (S3)
            : $heros;                      // bénéfique : les héros, LANCEUR COMPRIS

        // LIGNE DE VUE, pour TOUT sort — pas seulement les offensifs.
        // « Nécessaire pour lancer un sort ou observer une cible » (LR p. 14,
        // reference/16_armurerie.md §6.4). Le filtre n'était appliqué qu'aux
        // sorts de dégâts et mentaux : on soignait donc un compagnon à l'autre
        // bout du donjon, à travers les murs, jusque dans une salle jamais
        // explorée. Le lanceur se voit toujours lui-même, il reste donc
        // ciblable — « may be cast on any one hero, including yourself ».
        if ($lanceur !== null && $grille !== null) {
            $cibles = $this->filtrerLigneDeVue($lanceur['x'], $lanceur['y'], $grille, $cibles);
        }

        return $this->nettoyerCibles($cibles);
    }

    /**
     * Ne garde que les cibles dont AU MOINS une case (emprise incluse) est
     * visible depuis le lanceur, figures interposées bloquantes.
     *
     * @param  list<array<string, mixed>>  $cibles
     * @return list<array<string, mixed>>
     */
    private function filtrerLigneDeVue(int $cx, int $cy, Grille $grille, array $cibles): array
    {
        return array_values(array_filter($cibles, function (array $c) use ($cx, $cy, $grille) {
            $tx = (int) ($c['x'] ?? -1);
            $ty = (int) ($c['y'] ?? -1);

            if ($tx < 0 || $ty < 0) {
                return true; // position inconnue : ne pas masquer par excès de prudence
            }

            return $grille->ligneDeVueEmprise($cx, $cy, $tx, $ty, (int) ($c['l'] ?? 1), (int) ($c['h'] ?? 1), figuresBloquent: true);
        }));
    }

    /**
     * Réduit les cibles à la forme du contrat {type, id, nom} (retire x/y/emprise).
     *
     * @param  list<array<string, mixed>>  $cibles
     * @return list<array{type: string, id: int, nom: string}>
     */
    private function nettoyerCibles(array $cibles): array
    {
        return array_map(
            fn (array $c) => ['type' => $c['type'], 'id' => (int) $c['id'], 'nom' => $c['nom']],
            $cibles,
        );
    }

    // ------------------------------------------------------------------
    // Buffs des héros (personnage_conditions, source « sort:{Nom} »)
    // ------------------------------------------------------------------

    /**
     * Pose le buff d'un sort utilitaire sur un héros : condition du
     * catalogue (condition_appliquee, sinon « Renforcé ») + source
     * `sort:{Nom}` + durée en tours selon l'effet.
     */
    public function appliquerBuff(Personnage $cible, Sort $sort): Condition
    {
        $condition = $this->condition((string) data_get($sort->effet, 'condition_appliquee', self::CONDITION_BUFF_DEFAUT));

        $cible->conditions()->attach($condition->id, [
            'duree' => $this->dureeBuff($sort),
            'source' => self::PREFIXE_SOURCE.$sort->nom,
        ]);

        return $condition;
    }

    /**
     * Pose une condition du CATALOGUE sur un héros (sorts mentaux subis en
     * tir ami : Endormi, Étourdi…) avec sa durée par défaut — sauf résistance
     * nommée (Sang robuste du Nain vs Empoisonné, `Competence::resisteA`).
     */
    public function appliquerConditionCatalogue(Personnage $cible, string $nom, Sort $sort): Condition
    {
        $condition = $this->condition($nom);

        if (! Competence::resisteA($cible, $nom)) {
            $cible->conditions()->attach($condition->id, [
                'duree' => (int) $condition->duree_defaut,
                'source' => self::PREFIXE_SOURCE.$sort->nom,
            ]);
        }

        return $condition;
    }

    /**
     * Pose le buff d'une POTION (source `potion:{Nom}`) : la condition affichée
     * vient de l'objet (condition_appliquee, sinon « Renforcé ») et le bonus
     * chiffré (ex. bonus_des_attaque) est relu sur l'effet de l'objet. Consommé
     * comme un buff de sort (consommerBuffs, à la prochaine attaque).
     */
    public function appliquerBuffPotion(Personnage $cible, Objet $objet): Condition
    {
        $condition = $this->condition((string) data_get($objet->effet, 'condition_appliquee', self::CONDITION_BUFF_DEFAUT));

        // `duree` fait autorité (DureeEffet) : un ENTIER pose un décompte de
        // tours, un MOT-CLÉ laisse le pivot à 0 et confie l'expiration au
        // déclencheur correspondant. On lisait auparavant `duree_tours`, clé
        // qu'aucun objet ne porte — d'où des buffs de potion éternels.
        $cible->conditions()->attach($condition->id, [
            'duree' => DureeEffet::tours(data_get($objet->effet, 'duree')),
            'source' => self::PREFIXE_SOURCE_POTION.$objet->nom,
        ]);

        return $condition;
    }

    /**
     * Somme des bonus de dés (`bonus_des_attaque` / `bonus_des_defense`)
     * portés par les buffs de sorts du héros — relus dans l'effet JSON du
     * sort source, jamais recopiés.
     */
    public function bonusDes(Personnage $personnage, string $cle): int
    {
        $total = 0;

        foreach ($this->buffsSorts($personnage) as $condition) {
            $total += (int) ($this->effetSortSource((string) $condition->pivot->source)[$cle] ?? 0);
        }

        return $total;
    }

    /** Multiplicateur de déplacement (Vent Véloce) — 1 sans buff. */
    public function multiplicateurDeplacement(Personnage $personnage): int
    {
        $multiplicateur = 1;

        foreach ($this->buffsSorts($personnage) as $condition) {
            $multiplicateur = max(
                $multiplicateur,
                (int) ($this->effetSortSource((string) $condition->pivot->source)['deplacement_multiplie'] ?? 1),
            );
        }

        return $multiplicateur;
    }

    /**
     * Consomme les buffs de sorts portant la clé d'effet donnée (Vent Véloce au
     * déplacement : le multiplicateur est comptabilisé une fois pour le tour).
     *
     * ⚠ Ne PAS étendre ce chemin : consommer un buff sur sa clé d'EFFET
     * confond ce qu'il fait et quand il s'arrête. C'est ce qui rendait
     * impossible « +2 en défense jusqu'à la prochaine défense », et qui faisait
     * disparaître la Potion de rage (« un combat ») dès la première attaque.
     * Pour toute nouvelle expiration, déclare une `duree` et sers-toi de
     * `expirerBuffs()`.
     */
    public function consommerBuffs(Personnage $personnage, string $cle): void
    {
        foreach ($this->buffsSorts($personnage) as $condition) {
            $source = (string) $condition->pivot->source;

            if (array_key_exists($cle, $this->effetSortSource($source))) {
                $this->retirerBuff($personnage, (int) $condition->id, $source);
            }
        }
    }

    /**
     * Retire les buffs dont la source déclare la `duree` donnée (vocabulaire
     * `App\Engine\DureeEffet`, cf. reference/19_mots_cles_effets.md).
     *
     * C'est l'autorité : la durée est relue sur l'effet du SORT ou de l'OBJET
     * source, jamais recopiée sur le pivot — un catalogue corrigé s'applique
     * donc aux buffs déjà posés.
     */
    public function expirerBuffs(Personnage $personnage, string $declencheur): void
    {
        foreach ($this->buffsSorts($personnage) as $condition) {
            $source = (string) $condition->pivot->source;

            if (($this->effetSortSource($source)['duree'] ?? null) === $declencheur) {
                $this->retirerBuff($personnage, (int) $condition->id, $source);
            }
        }
    }

    /**
     * Même chose pour TOUS les héros d'une quête : `fin_du_combat` n'est pas un
     * événement personnel, il tombe quand le dernier monstre actif disparaît.
     */
    public function expirerBuffsQuete(Quete $quete, string $declencheur): void
    {
        foreach ($quete->etatsPersonnages()->with('personnage')->get() as $etat) {
            if ($etat->personnage !== null) {
                $this->expirerBuffs($etat->personnage, $declencheur);
            }
        }
    }

    private function retirerBuff(Personnage $personnage, int $conditionId, string $source): void
    {
        DB::table('personnage_conditions')
            ->where('personnage_id', $personnage->id)
            ->where('condition_id', $conditionId)
            ->where('source', $source)
            ->delete();
    }

    /**
     * Le héros traverse-t-il la roche ce tour-ci (Traverser la Pierre) ?
     *
     * Relu sur l'effet du sort SOURCE, comme tous les buffs chiffrés — jamais
     * recopié sur le pivot. Le buff porte `duree: ce_tour` : il tombe quand le
     * héros termine son tour.
     */
    public function traverseRoche(Personnage $personnage): bool
    {
        foreach ($this->buffsSorts($personnage) as $condition) {
            if (! empty($this->effetSortSource((string) $condition->pivot->source)['franchit_mur'])) {
                return true;
            }
        }

        return false;
    }

    /** Héros inattaquable (condition « Caché » du catalogue — Voile de Brume). */
    public function estInattaquable(Personnage $personnage): bool
    {
        return $personnage->conditions()->get()
            ->contains(fn (Condition $c) => (bool) data_get($c->effet, 'inattaquable', false));
    }

    /** Réveil d'un héros endormi : être attaqué retire la condition (doc 02 §7). */
    public function reveillerHeros(Personnage $personnage): void
    {
        DB::table('personnage_conditions')
            ->where('personnage_id', $personnage->id)
            ->whereIn('condition_id', Condition::where('nom', 'Endormi')->pluck('id'))
            ->delete();
    }

    /**
     * Fin de tour (après la phase des monstres) : les conditions à durée
     * POSITIVE des héros de la quête perdent 1 tour ; celles qui expirent
     * sont retirées. duree 0 = « jusqu'à une condition de fin », jamais
     * décrémentée (Courage consommé à l'attaque, Tombé relevé…).
     */
    public function decrementerDurees(Quete $quete): void
    {
        $ids = $quete->etatsPersonnages()->pluck('personnage_id');

        $expirees = DB::table('personnage_conditions')
            ->whereIn('personnage_id', $ids)
            ->where('duree', 1)
            ->pluck('id');

        DB::table('personnage_conditions')
            ->whereIn('personnage_id', $ids)
            ->where('duree', '>', 0)
            ->decrement('duree');

        DB::table('personnage_conditions')->whereIn('id', $expirees)->delete();
    }

    // ------------------------------------------------------------------
    // Conditions des monstres (habillage.conditions — pas de pivot dédié)
    // ------------------------------------------------------------------

    public function poserConditionMonstre(InstanceMonstre $instance, string $cle): void
    {
        $habillage = $instance->habillage ?? [];
        $habillage['conditions'][$cle] = true;
        $instance->update(['habillage' => $habillage]);
    }

    public function monstreA(InstanceMonstre $instance, string $cle): bool
    {
        return (bool) data_get($instance->habillage, "conditions.{$cle}", false);
    }

    public function retirerConditionMonstre(InstanceMonstre $instance, string $cle): void
    {
        if (! $this->monstreA($instance, $cle)) {
            return;
        }

        $habillage = $instance->habillage;
        unset($habillage['conditions'][$cle]);
        $instance->update(['habillage' => $habillage]);
    }

    // ------------------------------------------------------------------
    // Internes
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $parametres
     * @param  list<array<string, mixed>>  $ciblesMonstres
     * @param  list<array<string, mixed>>  $ciblesHeros
     * @return array<string, mixed>
     */
    /**
     * Second mode d'un sort à deux options : « ouvre une porte AU CHOIX ».
     *
     * Une option par porte encore fermée d'une salle DÉCOUVERTE — le magicien
     * ne choisit pas une porte qu'il n'a jamais vue, et on évite d'inonder le
     * menu avec tout le donjon. Contrairement à `ouvrir_porte` du MenuMoteur,
     * aucune adjacence n'est requise : c'est tout l'intérêt du sort, ouvrir à
     * distance une porte que des figures bloquent.
     *
     * @return list<array<string, mixed>>
     */
    private function optionsPorteAuChoix(Quete $quete, Sort $sort): array
    {
        if (! (bool) data_get($sort->effet, 'ouvre_porte', false) || $quete->carte === null) {
            return [];
        }

        $decouvertes = $quete->sallesDecouvertes();
        $options = [];

        foreach ((array) data_get($quete->carte->grille, 'portes', []) as $porte) {
            // Ni déjà ouverte, ni secrète non révélée (on ne choisit pas ce
            // qu'on ignore), et donnant sur une salle explorée.
            if (($porte['etat'] ?? null) !== MoteurPortes::ETAT_FERMEE) {
                continue;
            }

            $arete = (array) data_get($quete->carte->grille, 'aretes.'.($porte['jonction'] ?? -1), []);
            $salles = [(int) ($arete['a'] ?? -1), (int) ($arete['b'] ?? -1)];

            if (array_intersect($salles, $decouvertes) === []) {
                continue;
            }

            $cote = (string) ($porte['cote'] ?? 'e');
            $options[] = [
                'id' => "sort_{$sort->id}_porte_{$porte['x']}_{$porte['y']}_{$cote}",
                'libelle' => "Lancer {$sort->nom} — ouvrir une porte à distance",
                'type' => 'sort',
                'parametres' => [
                    'sort_id' => $sort->id,
                    'mode' => 'ouvre_porte',
                    'porte' => ['x' => (int) $porte['x'], 'y' => (int) $porte['y'], 'cote' => $cote],
                ],
            ];
        }

        return $options;
    }

    private function optionSort(
        string $id,
        string $libelle,
        string $type,
        array $parametres,
        Sort $sort,
        array $ciblesMonstres,
        array $ciblesHeros,
        ?array $lanceur = null,
        ?Grille $grille = null,
    ): array {
        $cibles = $this->ciblesLegales($sort, $ciblesMonstres, $ciblesHeros, $lanceur, $grille);

        if ($cibles !== null) {
            $parametres['cibles'] = $cibles;
        }

        return ['id' => $id, 'libelle' => $libelle, 'type' => $type, 'parametres' => $parametres];
    }

    /**
     * Monstres ciblables (actifs ET révélés — un dormant n'est pas visible),
     * position + emprise jointes pour le filtre de ligne de vue.
     *
     * @return list<array{type: string, id: int, nom: string, x: int, y: int, l: int, h: int}>
     */
    private function ciblesMonstres(Quete $quete): array
    {
        return $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true)
            ->with('monstre')
            ->orderBy('id')
            ->get()
            ->map(function (InstanceMonstre $i) {
                $e = $i->monstre->emprise();

                return [
                    'type' => 'monstre',
                    'id' => $i->id,
                    'nom' => $i->habillage['nom'] ?? $i->monstre->nom_base,
                    'x' => (int) $i->position_x,
                    'y' => (int) $i->position_y,
                    'l' => (int) $e['l'],
                    'h' => (int) $e['h'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{type: string, id: int, nom: string, x: int, y: int}>
     */
    private function ciblesHeros(Quete $quete): array
    {
        return $quete->etatsPersonnages()
            ->with('personnage')
            ->orderBy('personnage_id')
            ->get()
            ->map(fn ($etat) => [
                'type' => 'heros',
                'id' => (int) $etat->personnage_id,
                'nom' => $etat->personnage->nom,
                'x' => (int) $etat->position_x,
                'y' => (int) $etat->position_y,
            ])
            ->values()
            ->all();
    }

    /**
     * Durée (en tours) du buff d'un sort utilitaire — voir le bloc de doc
     * de classe pour la justification de chaque valeur.
     */
    private function dureeBuff(Sort $sort): int
    {
        $effet = $sort->effet ?? [];

        return match (true) {
            isset($effet['bonus_des_attaque']) => 0,        // consommé à la prochaine attaque
            isset($effet['bonus_des_defense']) => 0,        // fin de quête (MVP), purgé au démarrage suivant
            isset($effet['deplacement_multiplie']) => 2,    // déplacement du tour suivant, consommé à l'usage
            default => 1,                                   // Caché : jusqu'au prochain tour du héros
        };
    }

    /**
     * Buffs de sorts du héros : ses conditions dont la source commence par
     * `sort:` (une ligne de pivot par sort, condition éventuellement dupliquée).
     *
     * @return Collection<int, Condition>
     */
    private function buffsSorts(Personnage $personnage): Collection
    {
        return $personnage->conditions()->get()
            ->filter(fn (Condition $c) => str_starts_with((string) $c->pivot->source, self::PREFIXE_SOURCE)
                || str_starts_with((string) $c->pivot->source, self::PREFIXE_SOURCE_POTION))
            ->values();
    }

    /**
     * Effet JSON du sort pointé par une source `sort:{Nom}` (catalogue).
     *
     * @return array<string, mixed>
     */
    private function effetSortSource(string $source): array
    {
        // Buff de POTION : l'effet chiffré est relu sur l'objet consommable.
        if (str_starts_with($source, self::PREFIXE_SOURCE_POTION)) {
            $nom = substr($source, strlen(self::PREFIXE_SOURCE_POTION));

            return Objet::query()->where('nom', $nom)->first()?->effet ?? [];
        }

        $nom = substr($source, strlen(self::PREFIXE_SOURCE));

        return Sort::query()->where('nom', $nom)->first()?->effet ?? [];
    }

    private function condition(string $nom): Condition
    {
        return Condition::query()->where('nom', $nom)->first()
            ?? throw ValidationException::withMessages([
                'option_id' => "Condition « {$nom} » absente du catalogue — seeder les conditions.",
            ]);
    }
}
