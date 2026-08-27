<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\FaceDeCombat;
use App\Engine\DureeEffet;
use App\Engine\MotsClesSort;
use App\Engine\RegainEffet;
use App\Engine\TypeDegat;
use App\Models\Competence;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
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
 * le JSON `instances_monstres.habillage.conditions`, valeur `true`
 * (« sans durée ») OU un ENTIER de tours restants (2026-08-24) :
 *  - `endormi` (Sommeil)         : le monstre ne joue pas tant qu'il n'est
 *    pas attaqué — une attaque le réveille ;
 *  - `saute_tour` (Tempête) et `enfume` (Bombe fumigène) : passent tout le
 *    prochain tour du monstre, consommés à cette activation-là ;
 *  - `terrifie` (Terreur), `ralenti` (Ralentissement), `paralyse` (Flamme
 *    hypnotique) : posées avec une DURÉE (`dureeConditionMonstre()`),
 *    décomptée par `decrementerDureesMonstres()` — jusqu'au 2026-08-24 elles
 *    ne portaient que `true` et ne retombaient donc JAMAIS : un monstre
 *    paralysé ne rejouait plus de toute la quête.
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
    // Barde, Druide et Warlock rejoignent la liste le 2026-08-12, une fois
    // leurs sorts SEMÉS et leurs lecteurs écrits — pas avant : les déclarer
    // lanceurs sans le moindre sort aurait été un mensonge que `/moi` et le
    // menu auraient relayé jusqu'à la manette.
    public const LANCEURS = ['magicien', 'elfe', 'barde', 'druide', 'warlock'];

    /**
     * Classes dont les sorts sont FIXES : leur carte en donne trois, acquis
     * d'emblée, sans aucun choix d'école (2026-08-12).
     *
     * ⚠ Le mécanisme d'attache existe, les SORTS pas encore : les leurs
     * emploient neuf clés d'effet (`exclut_soi`, `zone`, `regain`, `reaction`,
     * `des_attaque_cible`…) dont `SortsFonctionnelsTest` exige à juste titre un
     * lecteur. Semer les sorts avant leurs lecteurs aurait produit neuf clés
     * décoratives d'un coup.
     *
     * La valeur est le `sorts.element` qui sert de nom de RÉPERTOIRE. Ce n'est
     * donc pas une école élémentaire — la colonne est réutilisée plutôt que
     * d'ajouter une table pour trois lignes.
     */
    public const REPERTOIRES_CLASSE = [
        'barde' => 'barde',
        'druide' => 'druide',
        'warlock' => 'warlock',
    ];

    /**
     * Répertoire ELFIQUE (Mage of the Mirror) : l'Elfe choisit à la création
     * soit une école élémentaire, soit 3 sorts pris ici (décision de René,
     * 2026-08-11). Fermé, et il ne se mélange pas aux quatre éléments.
     */
    public const REPERTOIRE_ELFIQUE = 'elfique';

    /**
     * Combien de sorts elfiques l'Elfe emporte s'il prend cette voie : TROIS,
     * comme les 3 sorts d'une école — les deux voies pèsent pareil, seule la
     * liberté du choix change (8 sorts au catalogue elfique, contre un lot de
     * 3 imposé par l'école).
     */
    public const NB_SORTS_ELFIQUES_DEPART = 3;

    /** La classe qui a le droit de piocher dans le répertoire elfique. */
    public const CLASSE_ELFIQUE = 'elfe';

    /** Mécanique des nœuds d'arbre qui débloquent un élément (CompetenceSeeder). */
    public const MECANIQUE_ELEMENT = 'emplacement_element';

    /** Nom exact du nœud magicien de récupération (CompetenceSeeder). */
    public const MECANIQUE_CONCENTRATION = 'recuperer_sort_epuise';

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
     * Conditions de sort posables sur un MONSTRE par la clé générique
     * `effet.condition_monstre` (2026-08-12).
     *
     * Jusqu'ici chaque condition de monstre était câblée en dur dans
     * `ResolveurTour::sortMental()` — un `if` par nom de sort. Deux cartes de
     * plus en demandaient deux autres ; la clé rend la liste ouverte, et les
     * dés effectifs sont lus par `InstanceMonstre::attaqueEffective()` /
     * `defenseEffective()`.
     */
    public const MONSTRE_TERRIFIE = 'terrifie';

    public const MONSTRE_RALENTI = 'ralenti';

    /** Flamme hypnotique : la créature ne bouge, n'attaque ni ne défend plus. */
    public const MONSTRE_PARALYSE = 'paralyse';

    /**
     * Bombe fumigène : la créature est noyée dans la fumée, donc elle cesse
     * d'occuper sa case — « all heroes move unseen through the monster's space »
     * (carte © 2023). `FabriqueGrille::pour()` la retire de `$occupees`, ce qui
     * lève d'un seul geste le blocage du mouvement ET celui de la ligne de vue.
     *
     * Se consomme au tour suivant du monstre, comme `MONSTRE_SAUTE_TOUR` : la
     * carte dit « until that monster's next turn ».
     */
    public const MONSTRE_ENFUME = 'enfume';

    /** @var list<string> */
    public const CONDITIONS_MONSTRE = [
        self::MONSTRE_ENDORMI,
        self::MONSTRE_SAUTE_TOUR,
        self::MONSTRE_TERRIFIE,
        self::MONSTRE_RALENTI,
        self::MONSTRE_PARALYSE,
        self::MONSTRE_ENFUME,
    ];

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
     * Attache le répertoire FIXE d'une classe (Barde, Druide, Warlock), s'il en
     * a un. Sans effet pour les autres — y compris l'Elfe et le Magicien, dont
     * les sorts se CHOISISSENT.
     */
    public function attacherRepertoireClasse(Personnage $personnage, string $classe): void
    {
        $repertoire = self::REPERTOIRES_CLASSE[$classe] ?? null;

        if ($repertoire !== null) {
            $this->attacherElement($personnage, $repertoire);
        }
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

    /**
     * L'Elfe a-t-il pris la VOIE ELFIQUE plutôt qu'une école ?
     *
     * Déduit de ses sorts, sans colonne dédiée : porter un sort `elfique`, c'est
     * avoir choisi cette voie. Une donnée de plus sur `personnages` aurait pu
     * mentir dès la première divergence — celle-ci est le fait lui-même.
     */
    public function aRepertoireElfique(Personnage $personnage): bool
    {
        return $personnage->sorts()->where('element', self::REPERTOIRE_ELFIQUE)->exists();
    }

    /**
     * Fixe les sorts elfiques du héros : les précédents partent, les choisis
     * arrivent DISPONIBLES.
     *
     * Sert à la création et au RECHOIX au hub (décision de René : les 3 sorts
     * elfiques se rechoisissent entre deux quêtes, à la différence d'une école
     * qui est définitive). ⚠ Ne touche qu'aux sorts `elfique` : un Elfe qui a
     * acheté une école par l'arbre garde ses éléments intacts.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Sort> sorts attachés
     */
    public function fixerSortsElfiques(Personnage $personnage, array $ids): Collection
    {
        $sorts = Sort::query()
            ->where('element', self::REPERTOIRE_ELFIQUE)
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        if ($sorts->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'sorts' => 'Ces sorts ne font pas tous partie du répertoire elfique.',
            ]);
        }

        $anciens = $personnage->sorts()->where('element', self::REPERTOIRE_ELFIQUE)->pluck('sorts.id');
        $personnage->sorts()->detach($anciens->all());

        foreach ($sorts as $sort) {
            $personnage->sorts()->syncWithoutDetaching([$sort->id => ['disponible' => true]]);
        }

        return $sorts;
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

    /**
     * Une CONDITION portée interdit-elle tout déplacement (`Envenimé`,
     * `Immobilisé`) ?
     *
     * La clé `deplacement_interdit` existait au catalogue depuis la création de
     * la table sans le moindre lecteur : un héros « immobilisé » marchait
     * normalement. Lue depuis le 2026-08-10.
     */
    public function deplacementInterdit(Personnage $personnage): bool
    {
        return $personnage->conditions()
            ->get()
            ->contains(fn (Condition $c) => (bool) ($c->effet['deplacement_interdit'] ?? false));
    }

    /**
     * Rompt l'Évanescence : la condition tombe et le buff avec elle.
     *
     * Appelée sur un jet de déplacement trop élevé (MenuMoteur). Sans effet si
     * le héros n'est pas évanescent — c'est le cas ordinaire.
     */
    public function rompreEvanescence(Personnage $personnage): void
    {
        $condition = Condition::where('nom', 'Évanescent')->first();

        if ($condition === null) {
            return;
        }

        DB::table('personnage_conditions')
            ->where('personnage_id', $personnage->id)
            ->where('condition_id', $condition->id)
            ->delete();
    }

    /**
     * Une CONDITION portée interdit-elle toute ACTION — attaquer, fouiller,
     * désamorcer, lancer un sort ?
     *
     * Jumelle de `deplacementInterdit()`, et les deux se combinent différemment
     * selon la carte : *Évanescence* interdit l'action mais laisse marcher et
     * ouvrir des portes, *Paralysie* interdit les deux.
     */
    public function actionInterdite(Personnage $personnage): bool
    {
        return $personnage->conditions()
            ->get()
            ->contains(fn (Condition $c) => (bool) ($c->effet['action_interdite'] ?? false));
    }

    /**
     * Dés de défense EFFECTIFS d'un héros — le seul calcul qui fasse foi.
     *
     * Sept endroits reproduisaient `des_defense + bonusDes(...)` à la main :
     * attaque de monstre, frappe de zone, charge, sorts Dread, commandement…
     * Chaque nouvelle règle de défense devait donc être recopiée sept fois, ou
     * ne valoir que par endroits. Elle vit ici désormais.
     *
     * Trois couches, dans cet ordre :
     *  1. **Paralysé** met tout à ZÉRO — « unable to move, attack, or defend ».
     *  2. La valeur du héros plus ses buffs de sort.
     *  3. **Léger sur ses pieds** (Barde) : +1 dé « when you are wearing no
     *     "metal" armor and carrying no shield ». Un bonus conditionnel, pas
     *     une interdiction — libre à lui de s'alourdir et d'y renoncer.
     */
    public function desDefenseHeros(Personnage $personnage): int
    {
        if ($this->defenseNulle($personnage)) {
            return 0;
        }

        $des = (int) $personnage->des_defense + $this->bonusDes($personnage, 'bonus_des_defense');

        $leger = app(CapacitesInnees::class)->noeud($personnage, 'bonus_des_defense_sans_metal');

        if ($leger !== null && ! app(Equipement::class)->porteMetalOuBouclier($personnage)) {
            $des += (int) ($leger->effet['valeur'] ?? 1);
        }

        return max(0, $des);
    }

    /**
     * Une CONDITION portée annule-t-elle la défense ?
     *
     * « Paralyzed for 3 turns — unable to move, attack, OR DEFEND » (Flamme
     * hypnotique). Le héros lance alors zéro dé : ce n'est pas un malus, c'est
     * une suppression.
     */
    public function defenseNulle(Personnage $personnage): bool
    {
        return $personnage->conditions()
            ->get()
            ->contains(fn (Condition $c) => (bool) ($c->effet['defense_nulle'] ?? false));
    }

    /**
     * Une pièce portée absorbe-t-elle intégralement un dégât de cette NATURE ?
     *
     * Consomme une charge et rend `true` — l'appelant n'applique alors aucun
     * dégât. « Prevents the wearer from being affected by the next two Fire
     * spells… the ring turns to ash after the second » (Anneau de Feu) : c'est
     * une immunité, pas une réduction, et elle s'épuise.
     *
     * UN SEUL lecteur pour les deux chemins qui blessent un héros — le tir ami
     * d'un sort de héros et le sort d'un Dread. Deux implémentations auraient
     * fini par diverger, et un anneau qui protège d'un feu mais pas de l'autre
     * serait pire que pas d'anneau du tout.
     */
    public function absorbeDegat(Personnage $personnage, ?string $typeDegat): bool
    {
        if (! TypeDegat::estConnu($typeDegat)) {
            return false;
        }

        // `resistance_degats_type` (Chair impie du warlock) : un TALENT annule
        // la même nature de dégâts qu'un anneau, et sans charge — il est lu en
        // premier, car un talent permanent ne doit jamais consommer une pièce
        // qui, elle, s'use.
        $talent = app(Talents::class)->noeud($personnage, 'resistance_degats_type');

        if ($talent !== null && ($talent->effet['type_degat'] ?? null) === $typeDegat) {
            return true;
        }

        $charges = app(MoteurCharges::class);

        $piece = $personnage->inventaire()
            ->whereIn('emplacement', Equipement::SLOTS)
            ->with('objet')
            ->get()
            ->first(fn ($ligne) => ($ligne->objet?->effet['immunite_degat'] ?? null) === $typeDegat
                && $charges->disponible($ligne));

        return $piece !== null && $charges->consommer($piece);
    }

    /**
     * Rend TOUS les sorts épuisés du héros, et dit combien l'ont été.
     *
     * Le nœud *Concentration* n'en récupère qu'un, au prix du tour ; ce
     * mouvement-là est celui du Parchemin de Sorts et de la Baguette de
     * Galimatias — la différence d'échelle EST la valeur de ces cartes.
     */
    public function restaurerTousLesSorts(Personnage $personnage): int
    {
        return $this->restaurerSorts($personnage);
    }

    /**
     * Rend des sorts épuisés, et dit combien l'ont été.
     *
     * `$nombre = null` les rend TOUS (Parchemin de Sorts, Baguette de
     * Galimatias). Un entier borne la restauration, ce qu'exigent deux cartes
     * officielles : Potion de magie (« recover up to 3 spells you have cast
     * during this quest ») et Potion de rappel (un seul, Elfe).
     *
     * `$sortIds` porte le CHOIX du joueur — la carte du rappel dit « Choose
     * wisely which spell to recall! », et un tirage automatique lui retirerait
     * la seule décision qu'elle contient. Les identifiants inconnus ou déjà
     * disponibles sont ignorés ; ce qui manque est complété par les premiers
     * sorts épuisés, parce qu'une potion qui ne ferait rien faute de paramètre
     * serait pire qu'un choix arbitraire.
     *
     * @param  list<int>  $sortIds
     */
    public function restaurerSorts(Personnage $personnage, ?int $nombre = null, array $sortIds = []): int
    {
        $epuises = DB::table('personnage_sorts')
            ->where('personnage_id', $personnage->id)
            ->where('disponible', false);

        if ($nombre === null) {
            return $epuises->update(['disponible' => true]);
        }

        if ($nombre <= 0) {
            return 0;
        }

        $disponibles = $epuises->orderBy('sort_id')->pluck('sort_id')->all();

        // Le choix d'abord, dans l'ordre demandé, puis le remplissage.
        $choisis = array_values(array_intersect($sortIds, $disponibles));
        $retenus = array_slice([...$choisis, ...array_diff($disponibles, $choisis)], 0, $nombre);

        if ($retenus === []) {
            return 0;
        }

        return DB::table('personnage_sorts')
            ->where('personnage_id', $personnage->id)
            ->whereIn('sort_id', $retenus)
            ->update(['disponible' => true]);
    }

    /** Clé du marqueur « Concentration déjà utilisée cette quête ». */
    public static function cleConcentration(int $groupeId, int $personnageId): string
    {
        return "partie:sorts:concentration:{$groupeId}:{$personnageId}";
    }

    /** Magicien possédant le nœud Concentration, pas encore utilisé cette quête. */
    public function concentrationDisponible(Groupe $groupe, Personnage $personnage): bool
    {
        // ⚠ La garde `classe === 'magicien'` est tombée le 2026-08-23 : c'est la
        // possession du nœud qui fait foi, et *Rappel* (barde) comme *Communion*
        // (druide) portent la même mécanique depuis le 2026-08-12 sans qu'aucun
        // des deux n'ait jamais rien récupéré.
        return app(Talents::class)->a($personnage, self::MECANIQUE_CONCENTRATION)
            && ! (bool) Cache::get(self::cleConcentration($groupe->id, $personnage->id), false);
    }

    /**
     * `sacrifice_pv_pour_sort` (Prix du pacte, warlock) : le héros paie 1 PV de
     * Body et rend UN sort épuisé relançable.
     *
     * ⚠ Le paiement passe par `MoteurDegats::SOURCE_SACRIFICE`, la source déjà
     * créée pour la *Furie* du Berserker et volontairement absente des sources
     * réactives : annuler d'une réaction le prix qu'on vient de payer rendrait
     * le talent gratuit.
     *
     * ⚠ Refusé à 1 PV de Body : un talent d'appoint ne doit pas pouvoir tuer
     * son porteur. Le menu ne le propose alors pas, et le résolveur le refuse.
     */
    public function sacrifierPourUnSort(Personnage $personnage, Sort $sort): bool
    {
        if ((int) $personnage->pv_body <= 1
            || ! app(Talents::class)->a($personnage, 'sacrifice_pv_pour_sort')) {
            return false;
        }

        app(MoteurDegats::class)->infligerAHeros($personnage, 1, MoteurDegats::SOURCE_SACRIFICE, [
            'talent' => 'sacrifice_pv_pour_sort',
        ]);

        $personnage->sorts()->updateExistingPivot($sort->id, ['disponible' => true]);

        return true;
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
            // Le libellé DIT la zone : sans cible à choisir, c'est la seule
            // chose qui prévienne le joueur qu'il va toucher ses alliés.
            $libelle = data_get($sort->effet, 'zone') !== null
                ? "Lancer {$sort->nom} — toute la salle, alliés compris"
                : "Lancer {$sort->nom}";

            $options[] = $this->optionSort(
                "sort_{$sort->id}",
                $libelle,
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
            foreach ($this->optionsPorteAuChoix($quete, $sort, $lanceur) as $option) {
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

        // `sacrifice_pv_pour_sort` (Prix du pacte, warlock) : même famille que
        // « Se concentrer », mais le prix est du SANG plutôt qu'un tour — d'où
        // une option distincte et non un paramètre de la première.
        if (app(Talents::class)->a($personnage, 'sacrifice_pv_pour_sort')
            && (int) $personnage->pv_body > 1) {
            $epuises = $personnage->sorts()->wherePivot('disponible', false)->orderBy('sorts.id')->get();

            if ($epuises->isNotEmpty()) {
                $options[] = [
                    'id' => 'sacrifier_pour_sort',
                    'libelle' => 'Payer le pacte — 1 PV de Body pour récupérer un sort épuisé',
                    'type' => 'sacrifice_sort',
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

        // ZONE : il n'y a RIEN à choisir — le sort balaie la salle du lanceur,
        // et `ResolveurTour::sortMental()` route vers `sortDeZone()` AVANT même
        // de lire une cible. Offrir une liste ici faisait pire que rien
        // (constaté en partie réelle le 2026-08-13) : le joueur visait un
        // gobelin, son choix était silencieusement ignoré, et la Flamme
        // hypnotique paralysait DEUX de ses alliés pendant 3 tours. Le tir ami
        // est assumé (doc 02 §5, S3) ; faire semblant de viser ne l'est pas.
        if (data_get($sort->effet, 'zone') !== null) {
            return null;
        }

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

        // « may be cast on any one hero, EXCLUDING YOURSELF » (Conte inspirant
        // du Barde). L'inverse de la règle par défaut, et il faut le dire : ce
        // sort revient quand un ALLIÉ pare, alors se l'accorder à soi-même en
        // ferait un bonus quasi permanent.
        if (data_get($sort->effet, 'exclut_soi') && $lanceur !== null && isset($lanceur['personnage_id'])) {
            $cibles = array_values(array_filter(
                $cibles,
                fn ($c) => ($c['personnage_id'] ?? null) !== $lanceur['personnage_id'],
            ));
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

        // `duree` fait autorité, comme pour les potions (DureeEffet) : un
        // ENTIER pose un compteur de tours, un MOT-CLÉ laisse le pivot à 0 et
        // confie l'expiration au déclencheur.
        //
        // On devinait auparavant la durée d'après la CLÉ D'EFFET du sort
        // (`dureeBuff()` : bonus_des_attaque → 0, deplacement_multiplie → 2,
        // défaut → 1) — un second système de durée, parallèle au vocabulaire et
        // câblé sur exactement ce que DureeEffet devait cesser de confondre.
        // Les deux tombaient d'accord par chance sur les sorts actuels ; le
        // premier sort dont le mot-clé aurait contredit la devinette aurait
        // divergé en silence. Repéré en partie réelle (2026-08-06) : Traverser
        // la Pierre portait `ce_tour` ET un compteur de 1 tour.
        $cible->conditions()->attach($condition->id, [
            'duree' => DureeEffet::tours(data_get($sort->effet, 'duree')),
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
    public function bonusDes(Personnage $personnage, string $cle, ?string $contexte = null): int
    {
        $total = 0;

        foreach ($this->buffsSorts($personnage) as $condition) {
            $effet = $this->effetSortSource((string) $condition->pivot->source);

            // Bonus CONDITIONNEL : « 1 extra Attack dice when attacking a
            // monster that you are ADJACENT TO » (Métamorphose du Druide). Le
            // dé de défense du même sort, lui, est inconditionnel — d'où une
            // condition portée par la clé d'attaque seule, et non par le sort.
            $requis = $effet['condition_bonus_attaque'] ?? null;

            if ($cle === 'bonus_des_attaque' && $requis !== null && $requis !== $contexte) {
                continue;
            }

            $total += (int) ($effet[$cle] ?? 0);
        }

        return $total;
    }

    /**
     * Un buff actif du héros porte-t-il ce DRAPEAU ?
     *
     * Pour les clés booléennes qui ne se cumulent pas — `ignore_pieges_fosse`
     * (Forme démoniaque du Warlock : « the warlock ignores pit traps »), là où
     * `bonusDes()` additionne des dés.
     */
    public function aBuff(Personnage $personnage, string $cle): bool
    {
        foreach ($this->buffsSorts($personnage) as $condition) {
            if (! empty($this->effetSortSource((string) $condition->pivot->source)[$cle])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un monstre actif et révélé est-il dans la LIGNE DE VUE de ce héros ?
     *
     * Prédicat partagé : le Moine y lit sa récupération de styles (« If there
     * are no monsters in your line of sight at the start of your turn »), les
     * potions de rage guerrière et de peau de givre y lisent leur fin (« As
     * soon as there are no monsters in the Barbarian's line of sight »).
     *
     * ⚠ C'est la vue du HÉROS, pas l'état du donjon : une bête vivante derrière
     * un mur ne compte pas. À ne pas confondre avec `combatTermine()`, qui
     * raisonne au niveau de la quête entière.
     */
    public function monstreEnVue(Quete $quete, EtatPersonnageQuete $etat): bool
    {
        if ($etat->position_x === null) {
            return false;
        }

        $grille = FabriqueGrille::pour($quete);

        return $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->where('revele', true)
            ->get()
            ->contains(fn (InstanceMonstre $i) => $i->position_x !== null
                && $grille->ligneDeVue(
                    (int) $etat->position_x, (int) $etat->position_y,
                    (int) $i->position_x, (int) $i->position_y,
                ));
    }

    /**
     * DÉBUT DE TOUR — fait vivre et mourir les buffs adossés à la vue.
     *
     * Deux gestes, et il faut les deux. Plus de monstre en vue : les buffs de
     * durée `plus_de_monstre_en_vue` expirent (la peau de givre retombe). Un
     * monstre en vue et une rage guerrière encore vivante : on RÉARME
     * `etat.attaque_supplementaire`, que la fin du tour précédent a consommé —
     * sans quoi la potion ne donnerait sa seconde attaque qu'une seule fois,
     * alors que la carte dit « 2 attacks per turn as long as there are
     * monsters in sight ».
     *
     * ⚠ Même garde d'idempotence que le crochet du Moine : dès que le héros a
     * entamé son tour, on ne touche plus à rien. Sans elle, le réarmement
     * repasserait après chaque action et offrirait une troisième attaque.
     */
    public function rythmerBuffsDeVue(Quete $quete, EtatPersonnageQuete $etat): void
    {
        $personnage = $etat->personnage;

        if ($personnage === null || $etat->a_joue || $etat->a_agi || $etat->a_deplace) {
            return;
        }

        if (! $this->monstreEnVue($quete, $etat)) {
            $this->expirerBuffs($personnage, DureeEffet::PLUS_DE_MONSTRE_EN_VUE);

            return;
        }

        foreach ($this->buffsSorts($personnage) as $condition) {
            $effet = $this->effetSortSource((string) $condition->pivot->source);

            if (($effet['duree'] ?? null) === DureeEffet::PLUS_DE_MONSTRE_EN_VUE
                && ! empty($effet['attaque_supplementaire'])
                && ! $etat->attaque_supplementaire) {
                $etat->update(['attaque_supplementaire' => true]);

                return;
            }
        }
    }

    /** Multiplicateur de déplacement (Vent Véloce, Potion de vitesse) — 1 sans buff. */
    public function multiplicateurDeplacement(Personnage $personnage): int
    {
        return $this->multiplicateurDeBuff($personnage, 'deplacement_multiplie');
    }

    /**
     * Multiplicateur de DÉGÂTS d'une attaque — Potion de force glaciale :
     * « their next attack causes twice as many Body Points of damage as are
     * rolled » (carte © 2022, Barbare seul). 1 sans buff.
     */
    public function multiplicateurDegats(Personnage $personnage): int
    {
        return $this->multiplicateurDeBuff($personnage, 'multiplicateur_degats');
    }

    /**
     * Le plus fort multiplicateur porté par les buffs vivants — jamais la
     * somme : deux effets qui doublent ne quadruplent pas, ils doublent.
     */
    private function multiplicateurDeBuff(Personnage $personnage, string $cle): int
    {
        $multiplicateur = 1;

        foreach ($this->buffsSorts($personnage) as $condition) {
            $multiplicateur = max(
                $multiplicateur,
                (int) ($this->effetSortSource((string) $condition->pivot->source)[$cle] ?? 1),
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
     * Rend relançables les sorts ÉPUISÉS dont l'effet déclare ce `regain`
     * (vocabulaire `App\Engine\RegainEffet`).
     *
     * Troisième axe de la vie d'un effet, à ne pas confondre avec les deux
     * autres : `duree` dit quand le BUFF s'arrête, `disponible` si le SORT est
     * relançable, et `regain` à quel événement il le redevient. Les cartes
     * officielles l'expriment sans cesse — « Regain this spell when you reduce
     * a monster's Body Points to zero » — et aucune donnée ne savait le dire :
     * `disponible` ne se rechargeait qu'au changement de quête, ou par deux
     * nœuds d'arbre codés en dur.
     *
     * Ne touche QUE les sorts épuisés : un sort disponible n'a rien à regagner,
     * et l'événement ne doit pas être consommé pour rien.
     *
     * @return int  nombre de sorts rendus (0 = l'événement n'intéressait personne)
     */
    public function regagnerSorts(Personnage $personnage, string $evenement): int
    {
        $rendus = 0;

        // `regain_sort` (Chant runique de l'elfe, Appel de la forêt du druide) :
        // le TALENT porte l'événement, là où jusqu'ici seul le SORT pouvait le
        // déclarer. Il rend UN sort — le premier épuisé — et non tous : rendre
        // le grimoire entier à chaque monstre abattu supprimerait l'économie de
        // sorts au lieu de l'assouplir.
        $talent = app(Talents::class)->noeud($personnage, 'regain_sort');
        $parLeTalent = $talent !== null && ($talent->effet['regain'] ?? null) === $evenement;

        foreach ($personnage->sorts()->wherePivot('disponible', false)->get() as $sort) {
            $parCeSort = ($sort->effet['regain'] ?? null) === $evenement;

            if (! $parCeSort && ! ($parLeTalent && $rendus === 0)) {
                continue;
            }

            DB::table('personnage_sorts')
                ->where('personnage_id', $personnage->id)
                ->where('sort_id', $sort->id)
                ->update(['disponible' => true]);

            $rendus++;
        }

        return $rendus;
    }

    /**
     * Parade d'un héros : les AUTRES héros qui le voient regagnent leurs sorts
     * `allie_deux_boucliers_blancs` (*Inspiring Tale* du Barde).
     *
     * ⚠ Deux subtilités portées par la carte, et toutes deux mécaniques :
     * « **any hero you can see, excluding yourself** » — le lanceur ne se
     * recharge donc pas sur sa propre parade (il serait quasi permanent à
     * 4 dés de défense), et il doit AVOIR VUE sur le défenseur. On compte les
     * boucliers **blancs** parce que c'est la face qui pare pour un héros ;
     * un bouclier noir dans sa volée ne vaut rien et ne compte pas.
     *
     * @param  list<string>  $facesDefense  faces brutes du jet de défense
     */
    public function regainSurParade(Quete $quete, Personnage $defenseur, array $facesDefense): void
    {
        $blancs = count(array_filter($facesDefense, fn ($f) => $f === FaceDeCombat::BouclierBlanc->value));

        if ($blancs < 2) {
            return;
        }

        $grille = FabriqueGrille::pour($quete);
        $etatDefenseur = $quete->etatsPersonnages()->where('personnage_id', $defenseur->id)->first();

        if ($etatDefenseur?->position_x === null) {
            return;
        }

        foreach ($quete->etatsPersonnages()->with('personnage')->get() as $etat) {
            if ($etat->personnage === null
                || $etat->personnage_id === $defenseur->id   // « excluding yourself »
                || $etat->position_x === null) {
                continue;
            }

            $voit = $grille->ligneDeVue(
                (int) $etat->position_x, (int) $etat->position_y,
                (int) $etatDefenseur->position_x, (int) $etatDefenseur->position_y,
            );

            if ($voit) {
                $this->regagnerSorts($etat->personnage, RegainEffet::ALLIE_DEUX_BOUCLIERS_BLANCS);
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

    /**
     * Pose une condition de monstre, avec sa durée en TOURS.
     *
     * `$duree = null` (défaut) stocke `true` — « sans durée », le comportement
     * HISTORIQUE d'avant le 2026-08-24 : Endormi (fin = attaque subie) et
     * saute_tour/enfume (auto-consommés au tour même du monstre, dans
     * `ResolveurTour::jouerMonstre()`) n'ont jamais eu besoin d'un compteur, et
     * les données déjà en base restent lisibles telles quelles — TOUS les
     * lecteurs testent `! empty(...)` ou `(bool) data_get(...)`, où `true` et
     * un entier > 0 valent également vrai. Rien à migrer.
     *
     * Un entier pose au contraire un COMPTE À REBOURS, décrémenté par
     * `decrementerDureesMonstres()` — c'est ce qui manquait à `terrifie`,
     * `ralenti` et `paralyse` : posées vraies pour toujours, jamais retirées.
     */
    public function poserConditionMonstre(InstanceMonstre $instance, string $cle, ?int $duree = null): void
    {
        $habillage = $instance->habillage ?? [];
        $habillage['conditions'][$cle] = $duree ?? true;
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

    /**
     * Durée (en tours) à poser pour la condition qu'un SORT nomme sur un
     * monstre, ou `null` (sans compteur) faute de source exploitable.
     *
     * Autorité : `conditions.duree_defaut` du catalogue, relu via le nom que
     * le sort déclare dans `effet.condition_appliquee` — le MÊME nom que
     * celui posé côté héros en tir ami (Ralenti, Paralysé…), pour qu'une
     * condition dure pareil qu'elle touche un monstre ou un héros.
     *
     * ⚠ `duree_defaut = 0` (« pas de compteur, expiration par déclencheur »,
     * cf. reference/19_mots_cles_effets.md) n'est PAS exploitable : on
     * retombe alors sur `effet.duree` du SORT lui-même s'il porte un ENTIER
     * (un mot-clé `DureeEffet` n'est pas un compte de tours), et en dernier
     * recours sur `null`.
     *
     * Cas réel rencontré en écrivant cette méthode : Terreur pose
     * `condition_appliquee: Apeuré`, dont le catalogue donne `duree_defaut: 0`
     * (fin = `jet_mind_reussi`, un déclencheur que ni le moteur monstre NI le
     * moteur héros ne câblent — `conditions.effet.fin` reste descriptif et
     * non lu, cf. doc-block de `decrementerDurees()`) ; Terreur ne déclare pas
     * non plus de `effet.duree` entier. `terrifie` reste donc SANS compteur
     * pour un monstre — exactement comme `Apeuré` pour un héros. Ce n'est pas
     * un trou laissé par ce correctif : c'est la même dette, côté monstre
     * comme côté héros, faute d'un déclencheur câblé quelque part.
     */
    public function dureeConditionMonstre(Sort $sort): ?int
    {
        $nomCondition = data_get($sort->effet, 'condition_appliquee');

        if (! is_string($nomCondition)) {
            return null;
        }

        $dureeCatalogue = (int) (Condition::query()->where('nom', $nomCondition)->value('duree_defaut') ?? 0);

        if ($dureeCatalogue > 0) {
            return $dureeCatalogue;
        }

        $dureeSort = data_get($sort->effet, 'duree');

        return is_int($dureeSort) ? $dureeSort : null;
    }

    /**
     * Pendant MONSTRES de `decrementerDurees()` (héros) : décrémente les
     * conditions à durée ENTIÈRE de `habillage.conditions`, pour toutes les
     * instances actives de la quête, et retire celles qui tombent à zéro.
     *
     * Les valeurs `true` (sans durée — Endormi, saute_tour, enfume, et
     * `terrifie` faute de source exploitable, voir `dureeConditionMonstre()`)
     * ne sont PAS touchées : décrémenter un booléen n'a aucun sens, et leur
     * expiration vient d'un déclencheur (attaque, tour du monstre propre) ou
     * jamais.
     *
     * ⚠ Deux stockages, deux méthodes, appelées CÔTE À CÔTE dans
     * `ResolveurTour::ouvrirNouveauTour()` : le pivot `personnage_conditions`
     * des héros porte une colonne `duree` dédiée que `decrementerDurees()`
     * sait interroger en SQL ; `habillage.conditions` des monstres est un
     * JSON sans colonne, qu'il faut charger, muter et réécrire instance par
     * instance. Un unique décompte ne pouvait pas parcourir les deux formes.
     */
    public function decrementerDureesMonstres(Quete $quete): void
    {
        foreach ($quete->instancesMonstres()->where('etat', 'actif')->get() as $instance) {
            $conditions = (array) data_get($instance->habillage, 'conditions', []);

            if ($conditions === []) {
                continue;
            }

            $modifie = false;

            foreach ($conditions as $cle => $valeur) {
                if ($valeur === true) {
                    continue; // sans durée : rien à décompter
                }

                $modifie = true;
                $restant = (int) $valeur - 1;

                if ($restant > 0) {
                    $conditions[$cle] = $restant;
                } else {
                    unset($conditions[$cle]); // durée écoulée : la condition tombe
                }
            }

            if ($modifie) {
                $habillage = $instance->habillage;
                $habillage['conditions'] = $conditions;
                $instance->update(['habillage' => $habillage]);
            }
        }
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
    private function optionsPorteAuChoix(Quete $quete, Sort $sort, ?array $lanceur = null): array
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
                // Repère DIRECTIONNEL depuis le lanceur : six libellés
                // rigoureusement identiques ne se distinguaient que par leur
                // index, ce qui revenait à choisir au hasard (constaté en partie
                // réelle, 2026-08-06).
                'libelle' => "Lancer {$sort->nom} — ouvrir la porte {$this->reperePorte($lanceur, $porte)}",
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

    /**
     * Repère d'une porte VU DU LANCEUR : « au nord-est, à 7 cases ».
     *
     * Le joueur ne voit ni coordonnées ni numéros de salle — une direction et
     * une distance sont les seules informations qu'il puisse rapporter à ce
     * qu'il a sous les yeux.
     *
     * @param  array{x: int, y: int}|null  $lanceur
     * @param  array<string, mixed>  $porte
     */
    private function reperePorte(?array $lanceur, array $porte): string
    {
        if ($lanceur === null) {
            return 'à distance';
        }

        $dx = (int) $porte['x'] - $lanceur['x'];
        $dy = (int) $porte['y'] - $lanceur['y'];
        $distance = abs($dx) + abs($dy);

        if ($distance === 0) {
            return 'sous tes pieds';
        }

        // Une composante négligeable devant l'autre (moins d'un tiers) ne mérite
        // pas d'être nommée : « au nord » se lit mieux que « au nord-nord-est ».
        $vertical = abs($dy) * 3 >= abs($dx) ? ($dy < 0 ? 'nord' : 'sud') : '';
        $horizontal = abs($dx) * 3 >= abs($dy) ? ($dx < 0 ? 'ouest' : 'est') : '';
        $direction = trim($vertical.($vertical && $horizontal ? '-' : '').$horizontal);

        return "au {$direction}, à {$distance} cases";
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
                    'nom' => $i->nomAffiche(),
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
