<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\FaceDeCombat;
use App\Engine\Des\LanceurDes;
use App\Engine\DureeEffet;
use App\Engine\MotsClesEquipement;
use App\Engine\MotsClesSort;
use App\Engine\MotsClesSortDread;
use App\Engine\RegainEffet;
use App\Engine\TypeDegat;
use App\Models\Competence;
use App\Models\Condition;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\Inventaire;
use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Sort;
use App\Models\SortDread;
use Illuminate\Support\Collection;
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
 * est compté dans `etat_personnage_quete.capacites_utilisees`, par
 * {@see Talents}, comme toute capacité « une fois par quête ».
 *
 * ⚠ Il vivait en `Cache::forever` jusqu'au 2026-09-02, ce que la règle
 * consolidée du projet interdit : un cache vidé rendait le nœud au magicien en
 * pleine quête, et le marqueur ÉTANT HORS DE L'ÉTAT DE QUÊTE, une `/reprise`
 * comme un « Recommencer la quête » rendaient une Concentration déjà dépensée.
 * Même défaut que les compteurs de Dread, corrigés le même jour. Aucune
 * colonne nouvelle : la fréquence `une_fois_par_quete` du nœud a déjà son
 * compteur, il n'était simplement pas utilisé ici.
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
 *  - Vent Véloce (deplacement_multiplie): `prochain_deplacement` (errata
 *    2021 B4 — `ce_tour` le perdait si le porteur ne bougeait pas).
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

    /**
     * Répertoires OPTIONNELS (Wizards of Morcar, livret p. 11, 2026-10-06) :
     * *Spells of Protection*, *Spells of Detection*, *Spells of Darkness* —
     * « These may replace existing sets of spells that a spellcaster can
     * draw on (but Elf and Wizard still have one and three sets of spells
     * respectively). Spellcasters may change their spells between quests. »
     *
     * Trois sorts chacun, comme toute autre école : AUCUN choix interne n'est
     * nécessaire (à l'inverse du répertoire elfique, qui pioche 3 parmi 8) —
     * `attacherElement()` suffit. Ce qui est neuf est le REMPLACEMENT d'un
     * élément CONNU par l'un de ceux-ci, ouvert aux CINQ classes de lanceurs
     * (pas seulement l'Elfe) : voir {@see self::remplacerElement()}.
     */
    public const REPERTOIRES_OPTIONNELS = ['protection', 'detection', 'tenebres'];

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

    /**
     * Enchaîné (*Chains of Darkness*, *Spells of Darkness*, 2026-10-06) :
     * « may not move or attack until the start of your next turn. They may
     * defend or cast spells. » Consommée au tour MÊME du monstre, sans
     * compteur — même famille que `saute_tour`, SAUF que `saute_tour` est
     * vérifiée AVANT toute tentative de sort de Dread dans `jouerMonstre()`
     * (elle bloquerait donc aussi « may … cast spells ») : `enchaine` est
     * donc testée APRÈS la tentative de sort du Sorcier, jamais à sa place.
     * La défense n'est PAS touchée par `apresConditions()` — « may defend »
     * est la valeur PAR DÉFAUT, rien à écrire pour la préserver.
     */
    public const MONSTRE_ENCHAINE = 'enchaine';

    /** @var list<string> */
    public const CONDITIONS_MONSTRE = [
        self::MONSTRE_ENDORMI,
        self::MONSTRE_SAUTE_TOUR,
        self::MONSTRE_TERRIFIE,
        self::MONSTRE_RALENTI,
        self::MONSTRE_PARALYSE,
        self::MONSTRE_ENFUME,
        self::MONSTRE_ENCHAINE,
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

    /**
     * REMPLACE un élément CONNU du héros par un répertoire OPTIONNEL
     * (`REPERTOIRES_OPTIONNELS`) — le mécanisme générique derrière
     * « Spellcasters may change their spells between quests » (livret p. 11).
     *
     * Réutilise exactement le patron de {@see self::fixerSortsElfiques()}
     * (détacher l'ancien, attacher le nouveau EN BLOC via
     * {@see self::attacherElement()}) plutôt que d'inventer un second système
     * de choix — la différence est que celui-ci vaut pour les CINQ classes de
     * lanceurs, et jamais seulement pour l'Elfe : un Magicien peut remplacer
     * UN de ses trois éléments, l'Elfe son unique voie (école OU elfique), et
     * Barde/Druide/Warlock leur répertoire de classe fixe — le texte ne fait
     * d'exception pour personne, et « Elf and Wizard still have one and
     * three sets » n'est qu'une CONSÉQUENCE du remplacement UN POUR UN, jamais
     * une règle à appliquer à part.
     *
     * ⚠ Ne vaut QUE dans un sens : vers un répertoire optionnel. Revenir
     * d'un répertoire optionnel à une école élémentaire reste hors de ce
     * point de passage (la création de personnage s'en occupe) — nommé ici
     * plutôt que deviné.
     *
     * @return Collection<int, Sort> sorts nouvellement attachés
     */
    public function remplacerElement(Personnage $personnage, string $ancienElement, string $nouveauRepertoire): Collection
    {
        if (! in_array($nouveauRepertoire, self::REPERTOIRES_OPTIONNELS, true)) {
            throw ValidationException::withMessages([
                'repertoire' => "« {$nouveauRepertoire} » n'est pas un répertoire optionnel (protection/detection/tenebres).",
            ]);
        }

        if (! in_array($personnage->classe, self::LANCEURS, true)) {
            throw ValidationException::withMessages([
                'personnage_id' => 'Ce héros ne lance aucun sort.',
            ]);
        }

        if (! in_array($ancienElement, $this->elementsConnus($personnage), true)) {
            throw ValidationException::withMessages([
                'element' => "Ce héros ne connaît aucun sort de « {$ancienElement} ».",
            ]);
        }

        // Un PARCHEMIN n'est pas un répertoire : le détacher effacerait un sort
        // qu'aucun répertoire ne porte (l'élément `parchemin` n'a pas d'école).
        if ($ancienElement === 'parchemin') {
            throw ValidationException::withMessages([
                'element' => 'Un parchemin n\'est pas un répertoire : il ne se remplace pas.',
            ]);
        }

        // Déjà connu : `attacherElement()` est idempotent (`syncWithoutDetaching`),
        // donc ce cas ne DUPLIQUERAIT rien — il PERDRAIT un répertoire en silence
        // (l'ancien détaché, le nouveau déjà là). Refus explicite.
        if (in_array($nouveauRepertoire, $this->elementsConnus($personnage), true)) {
            throw ValidationException::withMessages([
                'nouveau_repertoire' => "Ce héros connaît déjà le répertoire « {$nouveauRepertoire} ».",
            ]);
        }

        $anciens = $personnage->sorts()->where('element', $ancienElement)->pluck('sorts.id');
        $personnage->sorts()->detach($anciens->all());

        return $this->attacherElement($personnage, $nouveauRepertoire);
    }

    /**
     * La DÉCISION que la manette affiche entre deux quêtes, publiée par `/moi`
     * (le client ne re-dérive ni « qui peut remplacer quoi » ni « qui peut
     * prendre quoi ») :
     *  - `remplacables` : les éléments qu'un remplacement peut détacher — tout
     *    répertoire connu, à l'exception du PARCHEMIN (il n'est pas un
     *    répertoire) ;
     *  - `offerts` : les répertoires optionnels que ce héros ne connaît pas
     *    encore (un répertoire déjà connu ne peut pas être pris une seconde fois).
     *
     * Un non-lanceur n'a ni l'un ni l'autre : `remplacerElement()` le refuse.
     *
     * @return array{remplacables: list<string>, offerts: list<string>}
     */
    public function repertoiresChangeables(Personnage $personnage): array
    {
        if (! in_array($personnage->classe, self::LANCEURS, true)) {
            return ['remplacables' => [], 'offerts' => []];
        }

        $connus = $this->elementsConnus($personnage);

        return [
            'remplacables' => array_values(array_filter($connus, fn (string $e) => $e !== 'parchemin')),
            'offerts' => array_values(array_diff(self::REPERTOIRES_OPTIONNELS, $connus)),
        ];
    }

    // ------------------------------------------------------------------
    // Récupération par quête (S5/S6)
    // ------------------------------------------------------------------

    /**
     * Démarrage de quête : tous les sorts redeviennent disponibles et les
     * buffs de sorts encore portés sont purgés (Peau de Pierre « fin de
     * quête » incluse).
     *
     * L'usage de Concentration, lui, n'a plus rien à réarmer : il est compté
     * dans l'`etat_personnage_quete` de la quête, qui naît vide avec elle.
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
     * Une CONDITION portée interdit-elle de LANCER un sort (`sorts_interdits`) ?
     * *Blinding Sleet* (Storm Master) : « Characters in that room may not […]
     * cast spells until the start of Zargon's next turn. » Lue par
     * `MenuMoteur` (rien n'est offert) et par `ResolveurTour::resoudre()` (rien
     * n'est accepté) — jamais par un seul des deux.
     */
    public function sortsInterdits(Personnage $personnage): bool
    {
        return $personnage->conditions()->get()
            ->contains(fn (Condition $c) => (bool) data_get($c->effet, 'sorts_interdits', false));
    }

    /**
     * Une CONDITION portée interdit-elle les attaques À DISTANCE (`tir_interdit`) ?
     * *Blinding Sleet* : « may not […] make ranged attacks » — « can only attack
     * […] adjacent enemies ». Lue par `MenuMoteur::ciblesPourArme()` (aucune cible
     * lointaine offerte) et par `ResolveurTour::frapper()` (tir et arme lancée
     * refusés).
     */
    public function tirInterdit(Personnage $personnage): bool
    {
        return $personnage->conditions()->get()
            ->contains(fn (Condition $c) => (bool) data_get($c->effet, 'tir_interdit', false));
    }

    /**
     * Les conditions qu'une ACTION de « Détruire les entraves » peut lever : celles
     * qui interdisent le déplacement ET dont la sortie n'est ni un tour du MJ
     * (`debut_tour_mj`, le grésil) ni la destruction des liens eux-mêmes
     * (`liens_detruits`, *Strands of Binding* : des liens à 1 PV et 4 dés de
     * défense, qu'on ATTAQUE). POINT DE PASSAGE UNIQUE du menu et du résolveur.
     *
     * @return \Illuminate\Support\Collection<int, Condition>
     */
    public function entravesLiberables(Personnage $personnage): \Illuminate\Support\Collection
    {
        return $personnage->conditions()->get()
            ->filter(fn (Condition $c) => (bool) data_get($c->effet, 'deplacement_interdit', false)
                && ! in_array(data_get($c->effet, 'fin'), ['debut_tour_mj', 'liens_detruits'], true))
            ->values();
    }

    /**
     * Les LIENS qui retiennent ce héros (*Strands of Binding*), ou `null` : la
     * condition portant `liens_defense` (dés de défense des liens, 1 PV).
     */
    public function liensDe(Personnage $personnage): ?Condition
    {
        return $personnage->conditions()->get()
            ->first(fn (Condition $c) => data_get($c->effet, 'liens_defense') !== null);
    }

    /**
     * Fin de la phase des héros : les conditions « jusqu'au début du prochain tour
     * de Zargon » (`fin: debut_tour_mj`, le grésil aveuglant) tombent. Appelé en
     * TÊTE de `ResolveurTour::phaseMonstres()` — le seul « début de tour du MJ »
     * qu'un moteur par rounds possède. Une condition posée PENDANT cette phase
     * survit jusqu'à la suivante : c'est « until the start of Zargon's next turn ».
     *
     * @return list<array{personnage_id: int, condition: string}> ce qui a été levé (à annoncer)
     */
    public function leverConditionsDeDebutDeTourMJ(Quete $quete): array
    {
        $ids = Condition::query()->get()
            ->filter(fn (Condition $c) => data_get($c->effet, 'fin') === 'debut_tour_mj')
            ->pluck('nom', 'id');

        if ($ids->isEmpty()) {
            return [];
        }

        $persos = $quete->etatsPersonnages()->pluck('personnage_id');
        $lignes = DB::table('personnage_conditions')
            ->whereIn('personnage_id', $persos)
            ->whereIn('condition_id', $ids->keys())
            ->get(['id', 'personnage_id', 'condition_id']);

        DB::table('personnage_conditions')->whereIn('id', $lignes->pluck('id'))->delete();

        return $lignes->map(fn ($l) => [
            'personnage_id' => (int) $l->personnage_id,
            'condition' => (string) $ids[$l->condition_id],
        ])->all();
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
        return $this->desDefenseHerosDetail($personnage)['total'];
    }

    /**
     * Le MÊME calcul que {@see self::desDefenseHeros()}, avec en plus le détail
     * des modificateurs de TALENT qui l'ont composé — `docs/contrat-api.md`
     * §« Un talent qui s'active tout seul se VOIT » (groupe 3, `des.modificateurs`).
     *
     * ⚠ Une SEULE forme, calculée là où le bonus est appliqué : `desDefenseHeros()`
     * délègue ici plutôt que de dupliquer « Léger sur ses pieds » — les sept
     * appelants qui ne veulent que le total gardent leur signature inchangée,
     * et seul celui qui construit un payload de jet (`resoudreAttaqueMonstre()`)
     * lit le détail.
     *
     * @return array{total: int, modificateurs: list<array{source: string, valeur: int, sur: string}>}
     */
    public function desDefenseHerosDetail(Personnage $personnage): array
    {
        if ($this->defenseNulle($personnage)) {
            return ['total' => 0, 'modificateurs' => []];
        }

        $modificateurs = [];

        // ÉTAT DE CHOC (*Against the Ogre Horde* p. 9, René 2026-10-01) :
        // « can only roll [...] 2 Defend dice. Armor, weapons, and artifacts
        // do not increase the [...] Defend dice while a hero is at 0 Mind
        // Points. The creature's [...] Defend dice can be temporarily
        // increased by some spells and spell scrolls. » La BASE devient 2,
        // en écartant `des_defense` (classe + arme/bouclier + Forge +
        // artefact + talents passifs permanents qui l'alimentent) — mais le
        // buff de sort (`bonusDes`) continue de s'ajouter, exactement ce que
        // « some spells and spell scrolls » excepte. Lu ICI, au moment du
        // jet (`Personnage::estEnChoc()`), jamais sur la colonne : `pv_mind`
        // varie en quête sans jamais redéclencher `recalculerCombat()`.
        $enChoc = $personnage->estEnChoc();
        $des = ($enChoc ? 2 : (int) $personnage->des_defense) + $this->bonusDes($personnage, 'bonus_des_defense');

        // « Léger sur ses pieds » (Barde) est un talent PASSIF : la carte de
        // choc l'écarte au même titre que le reste de l'équipement/des talents.
        $leger = $enChoc ? null : app(CapacitesInnees::class)->noeud($personnage, 'bonus_des_defense_sans_metal');

        if ($leger !== null && ! app(Equipement::class)->porteMetalOuBouclier($personnage)) {
            $valeur = (int) ($leger->effet['valeur'] ?? 1);
            $des += $valeur;
            $modificateurs[] = ['source' => $leger->nom, 'valeur' => $valeur, 'sur' => 'defense'];
        }

        // 4. PLAFOND d'une condition (`des_defense_max`) — *Choc Mental*
        //    (carte *Mind Blast*) : « The hero defends with 1 combat die. »
        //    ⚠ Un plafond, et non une mise à zéro : c'est le seul mot qui
        //    sépare cette carte de *Cloud of Dread*, qui, elle, supprime la
        //    défense. Appliqué EN DERNIER, sinon un bonus posté après lui
        //    relèverait la valeur que la carte vient de brider.
        $plafond = $this->plafondDefense($personnage);

        if ($plafond !== null) {
            $des = min($des, $plafond);
        }

        return ['total' => max(0, $des), 'modificateurs' => $modificateurs];
    }

    /**
     * Le plus BAS des plafonds de défense posés par les conditions du héros,
     * ou `null` si aucune n'en pose.
     *
     * Jumeau de `MoteurDread::plafondDesAttaque()` : deux plafonds qui disent
     * « au plus 1 » ne disent pas « zéro », donc c'est le minimum qui gagne,
     * jamais la somme.
     */
    private function plafondDefense(Personnage $personnage): ?int
    {
        $plafond = null;

        foreach ($personnage->conditions()->get() as $condition) {
            $max = data_get($condition->effet, 'des_defense_max');

            if ($max !== null) {
                $plafond = $plafond === null ? (int) $max : min($plafond, (int) $max);
            }
        }

        return $plafond;
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
     * UN SEUL lecteur pour les TROIS chemins qui blessent un héros — le tir ami
     * d'un sort de héros, le sort d'un Dread, et le dégât de TERRAIN typé
     * (`ResolveurTour::saignerParTerrain()` / `saignerSurRiviere()`, Chambre
     * forte de glace et Rivière gelée). Deux implémentations auraient fini par
     * diverger, et un anneau qui protège d'un feu mais pas de l'autre serait
     * pire que pas d'anneau du tout.
     *
     * ⚠ N'EXIGE PAS de charges : `Anneau de Feu` en porte 2, mais `Anneau de
     * Chaleur` (Ring of Warmth) n'en porte AUCUNE sur sa carte — l'absence de
     * `charges` fait de son immunité une protection permanente tant qu'elle est
     * portée, le comportement par défaut de tout objet sans compteur
     * (`MoteurCharges::disponible()`/`consommer()` rendent alors `true` sans
     * rien décrémenter), pas un cas particulier codé ici.
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
            // Un talent qui s'active tout seul se VOIT (2026-09-25) : Chair
            // impie annulait le feu sans qu'un magicien lançant sa Boule de
            // Feu sache pourquoi elle n'avait fait aucun dégât.
            app(AnnoncesTalents::class)->annoncer($personnage, $talent, "annule les dégâts de {$typeDegat}");

            return true;
        }

        $charges = app(MoteurCharges::class);

        $piece = $personnage->inventaire()
            ->whereIn('emplacement', Equipement::SLOTS)
            ->with('objet')
            ->get()
            ->first(fn ($ligne) => ($ligne->objet?->effet['immunite_degat'] ?? null) === $typeDegat
                && $charges->disponible($ligne));

        if ($piece !== null) {
            return $charges->consommer($piece);
        }

        // POTION OF FIRE RESISTANCE (Wizards of Morcar, doc 18) — même clé
        // `immunite_degat`, portée par un BUFF de potion plutôt qu'une pièce
        // ÉQUIPÉE à charges : « completely unaffected by the next magical
        // fire attack, spell or trap ». Une potion n'a pas de compteur à
        // décrémenter — elle est consommée au moment où elle est bue — donc
        // ce qui l'épuise ici est la DÉTACHER dès qu'elle a protégé une fois,
        // même geste que les autres buffs ponctuels de ce fichier
        // (`ResolveurTour` détache aussi par `$condition->id`).
        foreach ($this->buffsSorts($personnage) as $condition) {
            $effet = $this->effetSortSource((string) $condition->pivot->source);

            if (($effet['immunite_degat'] ?? null) === $typeDegat) {
                $personnage->conditions()->detach($condition->id);

                return true;
            }
        }

        return false;
    }

    /**
     * POTION OF MAGIC RESISTANCE (Wizards of Morcar, doc 18) — « ignore the
     * effects of the next damaging spell cast on them ». À la différence
     * d'{@see self::absorbeDegat()} (qui exige une NATURE de dégât précise,
     * `type_degat`), cette potion annule n'importe quel sort à dégâts, qu'il
     * en porte une ou non (*Death Bolt* n'en a aucune). Même patron à usage
     * unique : la première condition qui porte la clé est détachée.
     *
     * ⚠ Scopé aux dégâts de BODY lancés par un sort de Dread
     * (`MoteurDread::sortDreadDegats()`) — le seul point sourcé par la carte ;
     * les dégâts de MIND (`infligerMindAHeros()`) restent hors périmètre,
     * nommé plutôt qu'oublié.
     */
    public function annuleProchainSortDegats(Personnage $personnage): bool
    {
        foreach ($this->buffsSorts($personnage) as $condition) {
            $effet = $this->effetSortSource((string) $condition->pivot->source);

            if (! empty($effet['annule_prochain_sort_degats'])) {
                $personnage->conditions()->detach($condition->id);

                return true;
            }
        }

        return false;
    }

    /**
     * Absorbe un dégât de MIND un POINT à la fois, sur un compteur de charges
     * — « Orbe Céleste / Sky Orb : absorbe 4 points de dégâts de Mind, un
     * jeton à la fois, puis se brise » (Mage of the Mirror).
     *
     * ⚠ PAS `absorbeDegat()` : celui-ci bloque une NATURE de dégât en entier
     * pour une charge, quel que soit le montant du coup ; l'Orbe Céleste
     * grignote un MONTANT, un jeton par point encaissé, et laisse passer le
     * reste dès que ses jetons sont épuisés — « 4 points, un jeton à la fois »
     * n'est ni une immunité totale ni un montant fixe. *Gel de l'Esprit* n'a de
     * toute façon aucun `type_degat` (la carte ne parle ni de feu ni de froid),
     * `absorbeDegat()` ne pourrait donc pas l'intercepter.
     *
     * Rend le montant à appliquer APRÈS absorption — l'appelant se contente
     * ensuite d'appeler `MoteurDegats::infligerMindAHeros()` avec ce reste,
     * jamais avec `$degats` d'origine.
     */
    public function absorbePartielDegatMind(Personnage $personnage, int $degats): int
    {
        if ($degats <= 0) {
            return max(0, $degats);
        }

        $charges = app(MoteurCharges::class);

        $piece = $personnage->inventaire()
            ->whereIn('emplacement', Equipement::SLOTS)
            ->with('objet')
            ->get()
            ->first(fn ($ligne) => (bool) ($ligne->objet?->effet['absorbe_degats_mind'] ?? false)
                && $charges->disponible($ligne));

        if ($piece === null) {
            return $degats;
        }

        $restantes = $charges->restantes($piece);
        // Toujours un entier ici : `ABSORBE_DEGATS_MIND` est TOUJOURS posée
        // avec `charges` (docblock du mot-clé) — un objet illimité n'a pas de
        // sens pour un jeton qui « se brise ». `?? $degats` ne sert donc que de
        // garde-fou si cette invariante venait à être rompue au catalogue.
        $absorbe = min($degats, $restantes ?? $degats);

        for ($i = 0; $i < $absorbe; $i++) {
            $charges->consommer($piece);
        }

        return $degats - $absorbe;
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

    /**
     * Un PARCHEMIN est DÉTRUIT à l'usage : une unité de sa ligne au sac, la ligne
     * elle-même à la dernière. Le SEUL point de passage de cette règle — la lecture
     * (`ResolveurTour::resoudreParchemin()`) comme la relance de *Vision du futur*
     * (`MoteurReactions::depenserSourceRelance()`) s'en servent (2026-10-08).
     */
    public function consommerParchemin(Inventaire $ligne): void
    {
        if ((int) $ligne->quantite > 1) {
            $ligne->decrement('quantite');

            return;
        }

        $ligne->delete();
    }

    /**
     * Héros possédant un nœud « Concentration », pas encore utilisé cette quête.
     *
     * ⚠ La garde `classe === 'magicien'` est tombée le 2026-08-23 : c'est la
     * possession du nœud qui fait foi, et *Rappel* (barde) comme *Communion*
     * (druide) portent la même mécanique depuis le 2026-08-12 sans qu'aucun
     * des deux n'ait jamais rien récupéré.
     *
     * ⚠ Le compteur est celui de `Talents` — les trois nœuds déclarent
     * `frequence: une_fois_par_quete`, donc la fenêtre est tenue par la même
     * mécanique que toutes les autres capacités par quête, dans une COLONNE.
     * Sans `$etat` (héros hors quête), il n'y a pas de fenêtre à ouvrir.
     */
    public function concentrationDisponible(Personnage $personnage, ?EtatPersonnageQuete $etat): bool
    {
        return app(Talents::class)->disponible($personnage, $etat, self::MECANIQUE_CONCENTRATION);
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

    public function marquerConcentrationUtilisee(Personnage $personnage, EtatPersonnageQuete $etat): void
    {
        app(Talents::class)->consommer($personnage, $etat, self::MECANIQUE_CONCENTRATION);
    }

    // ------------------------------------------------------------------
    // Options de menu (MenuMoteur — exécutables telles quelles)
    // ------------------------------------------------------------------

    /**
     * Options de sorts d'un héros en quête — TROIS options au plus, chacune
     * portant la liste de ses sous-choix (René, 2026-09-01).
     *
     * ⚠ Avant, une option PAR SORT : le menu d'un magicien niveau 1 en portait
     * neuf, sur quatorze au total, là où le doc de conception fixe « 2 à 5
     * options claires » (doc 13 §3.1). C'est la même leçon que le ciblage, un
     * cran plus haut : l'option ne doit pas ÊTRE le sort, elle doit PORTER la
     * liste des sorts. Le second pas se fait dans une feuille.
     *
     * ⚠ Les `cibles` restent PAR ENTRÉE, jamais au niveau de l'option :
     * `ciblesLegales()` rend trois listes différentes selon le sort — monstres
     * et héros pour `degats`/`mental`, héros seuls pour un soin, `null` pour un
     * sort sur soi — et la ligne de vue se filtre par sort. Une liste unique
     * serait fausse pour cinq des neuf sorts d'un magicien.
     *
     * ⚠ Chaque entrée porte une `cle` composite, et c'est elle que le client
     * renvoie : `sort_id` ne suffit pas à désigner une entrée, puisque le mode
     * « ouvre une porte » du Génie en produit une par porte. Même patron que les
     * `soins` des réactions (`MoteurReactions::soinsDisponibles()`).
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
            ? [
                'x' => (int) $etat->position_x, 'y' => (int) $etat->position_y,
                // FAVEUR « Deadeye » (Hopekins Rest, Wizards of Morcar) : les
                // figures ne bloquent plus la ligne de vue de CE lanceur —
                // lu par `filtrerLigneDeVue()`, plus bas.
                'figures_bloquent' => app(FaveursHopekins::class)->figuresBloquentPour($personnage),
                // CONTE INSPIRANT (« excluding yourself ») : lu par `ciblesLegales()`
                // pour retirer le lanceur de SA propre liste. Absente, la règle ne
                // retirait rien — le Barde se voyait proposer le sort sur lui-même
                // (2026-10-08).
                'personnage_id' => $personnage->id,
            ]
            : null;

        // ⚠ TOUT le répertoire, pas seulement le disponible : un sort épuisé
        // reste dans la liste, marqué `disponible: false`, pour que la feuille
        // le GRISE au lieu de le faire disparaître (René, 2026-09-01). Un sort
        // absent laisse croire qu'on l'a perdu ; un sort grisé dit ce qu'il en
        // est. Il n'entre évidemment pas dans la liste blanche du résolveur.
        $entrees = [];

        // UNLEARN (2026-10-08) : un sort oublié pour la quête est GRISÉ, comme un
        // sort épuisé — jamais une entrée lançable (« le menu ne propose jamais ce
        // que le résolveur refusera »). Lecteur unique : `OubliSorts`.
        $oubliesHeros = app(OubliSorts::class)->oublies($quete, OubliSorts::CIBLE_PERSONNAGE, $personnage->id, OubliSorts::SOURCE_SORT);

        foreach ($personnage->sorts()->orderBy('sorts.id')->get() as $sort) {
            $disponible = (bool) $sort->pivot->disponible && ! in_array($sort->nom, $oubliesHeros, true);

            // CLAIRVOYANCE (Spells of Detection, 2026-10-06) : « lay out the
            // contents of one room anywhere on the board ». Une entrée PAR SALLE
            // NON DÉCOUVERTE — le serveur décide seul ce qui est offert, et ne
            // laisse jamais voir le contenu d'une salle dans le libellé (une
            // salle vide ne se distingue pas d'une salle pleine ici).
            if ((bool) data_get($sort->effet, 'vision_salle', false)) {
                if ($disponible && $lanceur !== null) {
                    foreach ($this->entreesVisionSalle($quete, $sort, $lanceur) as $entree) {
                        $entrees[] = $entree;
                    }
                } elseif (! $disponible) {
                    $entrees[] = $this->entreeSort("sort:{$sort->id}", $sort->nom, $sort, false, [], [], $lanceur, $grille);
                }

                continue;
            }

            // FUTURE SIGHT (Spells of Detection, 2026-10-08) : « may be cast at any
            // time and does not take an action ». Il n'a donc AUCUNE entrée de
            // menu — le menu ne propose jamais ce que le résolveur refuserait, et
            // le résolveur de `resoudreSort()` n'a rien à en faire : le sort se
            // joue APRÈS un jet, par `MoteurReactions::proposerRelanceJet()`.
            // Sa disponibilité se lit au grimoire (`personnage_sorts.disponible`).
            if ((bool) data_get($sort->effet, 'relance_jet', false)) {
                continue;
            }

            // VOILE D'OMBRE (Cloak of Shadows — Spells of Darkness, 2026-10-08) :
            // l'emplacement est le choix — une entrée par emplacement LÉGAL
            // (`MoteurOmbre::emplacementsLegaux()`, le même appel que le résolveur),
            // jamais de base-entry sans emplacement. Patron du mur magique.
            if ((bool) data_get($sort->effet, 'pose_ombre', false)) {
                if ($disponible && $lanceur !== null) {
                    foreach ($this->entreesPoseOmbre($quete, $grille, $sort, $lanceur) as $entree) {
                        $entrees[] = $entree;
                    }
                } elseif (! $disponible) {
                    $entrees[] = $this->entreeSort("sort:{$sort->id}", $sort->nom, $sort, false, [], [], $lanceur, $grille);
                }

                continue;
            }

            // MUR MAGIQUE (Wall of Stone — Spells of Protection, 2026-10-06) :
            // poser un mur n'a pas de cible GÉNÉRIQUE à proposer — seulement
            // CELLE qu'on choisit. Une entrée PAR case libre orthogonalement
            // adjacente, jamais de base-entry « sans case » qui promettrait un
            // clic que le résolveur ne pourrait pas honorer (« le menu ne
            // propose jamais ce que le résolveur refusera »). Même patron que
            // `entreesPorteAuChoix()`/`entreesDeRayon()` : le second niveau de
            // choix EST la liste d'entrées, il n'y a pas de troisième niveau
            // `cibles` à ouvrir derrière.
            if ((bool) data_get($sort->effet, 'pose_mur_magique', false)) {
                if ($disponible && $lanceur !== null) {
                    foreach ($this->entreesPoseMurMagique($grille, $sort, $lanceur) as $entree) {
                        $entrees[] = $entree;
                    }
                } elseif (! $disponible) {
                    // Grisé, comme tout sort épuisé (René, 2026-09-01) :
                    // affiché pour information, jamais choisissable.
                    $entrees[] = $this->entreeSort("sort:{$sort->id}", $sort->nom, $sort, false, [], [], $lanceur, $grille);
                }

                continue;
            }

            // Le libellé DIT la zone : sans cible à choisir, c'est la seule
            // chose qui prévienne le joueur qu'il va toucher ses alliés.
            $entrees[] = $this->entreeSort(
                "sort:{$sort->id}",
                data_get($sort->effet, 'zone') !== null
                    ? "{$sort->nom} — toute la salle, alliés compris"
                    : $sort->nom,
                $sort,
                $disponible,
                $disponible ? $ciblesMonstres : [],
                $disponible ? $ciblesHeros : [],
                $lanceur,
                $grille,
            );

            // Sorts à DEUX modes (Génie : « ouvre une porte au choix OU attaque
            // avec 5 dés » — Kellar's Keep p. 28-29). Une entrée par porte connue :
            // c'est ce second mode qui gonflait le plus le menu.
            if ($disponible) {
                foreach ($this->entreesPorteAuChoix($quete, $sort, $lanceur) as $entree) {
                    $entrees[] = $entree;
                }
            }
        }

        // UN SORT À CIBLE SANS CIBLE LÉGALE N'A AUCUNE ENTRÉE (2026-10-08) — voir
        // `sansCiblesVides()`. Filtré ICI, avant le `if` qui décide d'émettre
        // l'option : une `lancer_sort` sans plus aucune entrée ne paraît pas.
        $entrees = self::sansCiblesVides($entrees);

        if ($entrees !== []) {
            $options[] = [
                'id' => 'lancer_sort',
                'libelle' => 'Lancer un sort',
                'type' => 'sort',
                'parametres' => ['sorts' => $entrees],
            ];
        }

        // Parchemins au sac (ObjetSeeder : effet.sort_id pointe le sort) —
        // utilisables par TOUS, jet de Mind pour les non-lanceurs (S1).
        // ⚠ Action SÉPARÉE des sorts (René, 2026-09-01) : les mélanger ferait
        // cohabiter deux économies contraires dans une même liste — un sort
        // s'épuise et revient à la quête suivante, un parchemin est DÉTRUIT.
        $parchemins = [];

        foreach ($personnage->inventaire()->with('objet')->orderBy('id')->get() as $ligne) {
            $sort = Sort::find(data_get($ligne->objet?->effet, 'sort_id'));

            if ($sort === null) {
                continue;
            }

            // VISION DU FUTUR : se joue APRÈS un jet, jamais en lisant une carte —
            // un parchemin que le menu offrirait serait un bouton que le résolveur
            // ne saurait pas honorer. Le parchemin (un par sort, `ObjetSeeder`) se
            // joue donc par la RÉACTION : `MoteurReactions::sourceVisionDuFutur()`
            // le lit au sac à chaque jet (2026-10-08).
            if ((bool) data_get($sort->effet, 'relance_jet', false)) {
                continue;
            }

            // VOILE D'OMBRE : l'emplacement est le choix, comme au sort connu —
            // une entrée par emplacement légal, chacune portant `inventaire_id`.
            // Même générateur que le sort connu : seule la racine de la `cle` change.
            if ((bool) data_get($sort->effet, 'pose_ombre', false)) {
                if ($lanceur !== null) {
                    foreach ($this->entreesPoseOmbre($quete, $grille, $sort, $lanceur, "parchemin:{$ligne->id}", ['inventaire_id' => $ligne->id]) as $entree) {
                        $parchemins[] = $entree;
                    }
                }

                continue;
            }

            // MUR MAGIQUE (2026-10-08) : le parchemin de Mur de Pierre offre les
            // MÊMES paires que le sort connu — un seul générateur de paires. Sans
            // cette branche, l'entrée générique ci-dessous n'a ni `mode` ni `cases`,
            // et le résolveur n'a aucune paire à poser.
            if ((bool) data_get($sort->effet, 'pose_mur_magique', false)) {
                if ($lanceur !== null) {
                    foreach ($this->entreesPoseMurMagique($grille, $sort, $lanceur, "parchemin:{$ligne->id}", ['inventaire_id' => $ligne->id]) as $entree) {
                        $parchemins[] = $entree;
                    }
                }

                continue;
            }

            // CLAIRVOYANCE (2026-10-08) : même raison — une entrée par salle non
            // découverte, avec `mode: vision_salle` et `salle`. Sans cela le
            // résolveur ne sait pas quelle salle montrer, et refuse.
            if ((bool) data_get($sort->effet, 'vision_salle', false)) {
                if ($lanceur !== null) {
                    foreach ($this->entreesVisionSalle($quete, $sort, $lanceur, "parchemin:{$ligne->id}", ['inventaire_id' => $ligne->id]) as $entree) {
                        $parchemins[] = $entree;
                    }
                }

                continue;
            }

            // ÉCLAIR : il ne vise pas une figure mais une DIRECTION, donc UNE
            // ENTRÉE PAR DIRECTION — la même forme que le rayon du Moine, un
            // cran plus bas dans le menu. Aucune grammaire neuve : le `cle`
            // reste la liste blanche que `entreeChoisie()` revérifie.
            if (! empty($sort->effet['rayon']) && $lanceur !== null) {
                foreach ($this->entreesDeRayon($quete, $ligne->id, $sort, $lanceur) as $entree) {
                    $parchemins[] = $entree;
                }

                continue;
            }

            $parchemins[] = $this->entreeSort(
                "parchemin:{$ligne->id}",
                $sort->nom,
                $sort,
                true,
                $ciblesMonstres,
                $ciblesHeros,
                $lanceur,
                $grille,
                ['inventaire_id' => $ligne->id],
            );
        }

        // Même règle que les sorts connus : un parchemin dont la cible manque
        // n'est pas offert non plus (même liste de cibles, même résolveur).
        $parchemins = self::sansCiblesVides($parchemins);

        if ($parchemins !== []) {
            $options[] = [
                'id' => 'lire_parchemin',
                'libelle' => 'Lire un parchemin',
                'type' => 'parchemin',
                'parametres' => ['parchemins' => $parchemins],
            ];
        }

        // « Se concentrer » (S6) : magicien + nœud + ≥1 sort épuisé + pas
        // encore utilisée cette quête.
        if ($this->concentrationDisponible($personnage, $etat)) {
            $epuises = $this->entreesEpuisees($personnage);

            if ($epuises !== []) {
                $options[] = [
                    'id' => 'se_concentrer',
                    'libelle' => 'Se concentrer — sacrifier le tour pour récupérer un sort épuisé',
                    'type' => 'concentration',
                    'parametres' => ['sorts' => $epuises],
                ];
            }
        }

        // `sacrifice_pv_pour_sort` (Prix du pacte, warlock) : même famille que
        // « Se concentrer », mais le prix est du SANG plutôt qu'un tour — d'où
        // une option distincte et non un paramètre de la première.
        if (app(Talents::class)->a($personnage, 'sacrifice_pv_pour_sort')
            && (int) $personnage->pv_body > 1) {
            $epuises = $this->entreesEpuisees($personnage);

            if ($epuises !== []) {
                $options[] = [
                    'id' => 'sacrifier_pour_sort',
                    'libelle' => 'Payer le pacte — 1 PV de Body pour récupérer un sort épuisé',
                    'type' => 'sacrifice_sort',
                    'parametres' => ['sorts' => $epuises],
                ];
            }
        }

        return $options;
    }

    /**
     * Sorts épuisés, au format d'entrée de liste.
     *
     * ⚠ La clé est `sorts`, pas `sorts_epuises` : le front lisait déjà
     * `parametres.sorts` alors que le moteur publiait `sorts_epuises`, si bien
     * que la liste du serveur n'était JAMAIS consommée — la feuille ne marchait
     * que par son repli sur `/moi`. Un seul nom pour les quatre listes.
     *
     * @return list<array<string, mixed>>
     */
    private function entreesEpuisees(Personnage $personnage): array
    {
        return $personnage->sorts()
            ->wherePivot('disponible', false)
            ->orderBy('sorts.id')
            ->get()
            ->map(fn (Sort $s) => [
                'cle' => "sort:{$s->id}",
                'sort_id' => $s->id,
                'nom' => $s->nom,
                'element' => $s->element,
                'sort_type' => $s->type,
                'disponible' => true, // choisissable : c'est CE sort qu'on récupère
            ])
            ->values()
            ->all();
    }

    /**
     * Cibles légales d'un sort, RESTREINTES à la ligne de vue du lanceur (une
     * figure interposée coupe la vue, doc 03 §36).
     *
     * ⚠ RÈGLE EN VIGUEUR depuis le 2026-10-09 (décision de René) : un sort à
     * CIBLE UNIQUE vise ce que dit sa carte. `monstre` → monstres seuls ;
     * `heros` → héros seuls, lanceur compris ; `soi` → aucune liste. Le tir ami
     * ne subsiste que pour les sorts de ZONE (`zone`, `rayon`), qui touchent
     * toutes les figures de leur surface et n'ont donc pas de liste à viser.
     * Avant cette date, tout sort de dégâts ou mental portait monstres ET héros
     * (doc 02 §5, S3 appliqué au sens large) : un magicien seul se voyait
     * proposer Boule de Feu sur lui-même.
     *
     * Utilitaire ciblé → héros de la quête ; cible `soi` (Traverser la Pierre)
     * → pas de liste, le lanceur. Les positions internes (x/y/emprise) servent
     * au filtre de LdV puis sont retirées : la liste rendue reste {type, id, nom}.
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

        $offensif = in_array($sort->type, ['degats', 'mental'], true);

        // `soi` (Traverser la Pierre) : le lanceur, donc aucune liste à choisir.
        if (! $offensif && ! in_array($cible, [MotsClesSort::CIBLE_HEROS, MotsClesSort::CIBLE_LANCEUR_DREAD], true)) {
            return null;
        }

        // UNLEARN (Spells of Protection, 2026-10-08) : un monstre LANCEUR DE DREAD
        // qui peut encore perdre un sort — seul un sort de ce genre est offert.
        // Un Sorcier épuisé (répertoire entièrement oublié) n'est plus une cible.
        if ($cible === MotsClesSort::CIBLE_LANCEUR_DREAD) {
            $cibles = $this->lanceursDreadOubliables($monstres);
        } elseif ($offensif) {
            // CIBLE UNIQUE, suivie telle que la carte la dit (2026-10-09). Un
            // `soi` offensif n'a aucune figure à viser ici : un rayon se choisit
            // par DIRECTION (`entreesDeRayon()`), et la zone n'arrive jamais ici
            // (elle a rendu `null` plus haut). Aucun repli sur « tout le monde ».
            $cibles = match ($cible) {
                MotsClesSort::CIBLE_MONSTRE, MotsClesSort::CIBLE_MONSTRES_ZONE => $monstres,
                MotsClesSort::CIBLE_HEROS => $heros,
                default => [],
            };
        } else {
            $cibles = $heros;   // bénéfique : les héros, LANCEUR COMPRIS
        }

        // LIGNE DE VUE, pour TOUT sort — pas seulement les offensifs.
        // « Nécessaire pour lancer un sort ou observer une cible » (LR p. 14,
        // reference/16_armurerie.md §6.4). Le filtre n'était appliqué qu'aux
        // sorts de dégâts et mentaux : on soignait donc un compagnon à l'autre
        // bout du donjon, à travers les murs, jusque dans une salle jamais
        // explorée. Le lanceur se voit toujours lui-même, il reste donc
        // ciblable — « may be cast on any one hero, including yourself ».
        if ($lanceur !== null && $grille !== null) {
            $cibles = $this->filtrerLigneDeVue(
                $lanceur['x'], $lanceur['y'], $grille, $cibles,
                (bool) ($lanceur['figures_bloquent'] ?? true),
            );
        }

        // « may be cast on any one hero, EXCLUDING YOURSELF » (Conte inspirant
        // du Barde). L'inverse de la règle par défaut, et il faut le dire : ce
        // sort revient quand un ALLIÉ pare, alors se l'accorder à soi-même en
        // ferait un bonus quasi permanent.
        // ⚠ Un candidat héros porte son identifiant de PERSONNAGE dans `id` (voir
        // `ciblesHeros()`) : c'est `id` qu'il faut comparer. Lire `personnage_id`,
        // une clé qu'aucun candidat ne porte, faisait de cette règle un no-op.
        if (data_get($sort->effet, 'exclut_soi') && $lanceur !== null && isset($lanceur['personnage_id'])) {
            $cibles = array_values(array_filter(
                $cibles,
                fn ($c) => ($c['type'] ?? null) !== 'heros' || (int) $c['id'] !== (int) $lanceur['personnage_id'],
            ));
        }

        // IMMUNITÉ AUX SORTS (Invisibilité — « immune to all spells »,
        // 2026-10-06) : une cible protégée disparaît de la liste, qu'elle
        // soit l'adversaire visé par un sort de dégâts OU le compagnon qu'on
        // voulait soigner — la carte ne distingue pas l'intention, « ALL
        // spells » n'épargne pas les sorts amis. Monstres non concernés :
        // seul un héros peut porter cette condition.
        $cibles = array_values(array_filter($cibles, function ($c) {
            if (($c['type'] ?? null) !== 'heros') {
                return true;
            }

            $personnage = Personnage::find($c['id'] ?? 0);

            return $personnage === null || ! $this->immuniteSorts($personnage);
        }));

        return $this->nettoyerCibles($cibles);
    }

    /**
     * Ne garde que les cibles dont AU MOINS une case (emprise incluse) est
     * visible depuis le lanceur, figures interposées bloquantes.
     *
     * @param  list<array<string, mixed>>  $cibles
     * @return list<array<string, mixed>>
     */
    private function filtrerLigneDeVue(int $cx, int $cy, Grille $grille, array $cibles, bool $figuresBloquent = true): array
    {
        return array_values(array_filter($cibles, function (array $c) use ($cx, $cy, $grille, $figuresBloquent) {
            $tx = (int) ($c['x'] ?? -1);
            $ty = (int) ($c['y'] ?? -1);

            if ($tx < 0 || $ty < 0) {
                return true; // position inconnue : ne pas masquer par excès de prudence
            }

            return $grille->ligneDeVueEmprise($cx, $cy, $tx, $ty, (int) ($c['l'] ?? 1), (int) ($c['h'] ?? 1), figuresBloquent: $figuresBloquent);
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
    public function appliquerBuffPotion(Personnage $cible, Objet $objet, ?int $inventaireId = null): Condition
    {
        $condition = $this->condition((string) data_get($objet->effet, 'condition_appliquee', self::CONDITION_BUFF_DEFAUT));

        // `duree` fait autorité (DureeEffet) : un ENTIER pose un décompte de
        // tours, un MOT-CLÉ laisse le pivot à 0 et confie l'expiration au
        // déclencheur correspondant. On lisait auparavant `duree_tours`, clé
        // qu'aucun objet ne porte — d'où des buffs de potion éternels.
        // ⚠ L'EXEMPLAIRE est collé à la source (`potion:{Nom}#{inventaire_id}`)
        // depuis le 2026-09-03. Certaines cartes ne valent qu'avec l'arme qui a
        // produit le buff — « when you attack WITH THE DAGGER » —, et un buff
        // posé sur le héros ne saurait pas le dire. Le suffixe est optionnel :
        // toutes les potions continuent de poser une source sans lui.
        $cible->conditions()->attach($condition->id, [
            'duree' => DureeEffet::tours(data_get($objet->effet, 'duree')),
            'source' => self::PREFIXE_SOURCE_POTION.$objet->nom
                .($inventaireId === null ? '' : '#'.$inventaireId),
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
    /**
     * VALEUR chiffrée d'une clé portée par un buff (0 si aucun buff ne la
     * porte). Le pendant chiffré de {@see self::aBuff()}, pour les clés qui
     * disent « combien » et pas seulement « oui » — `ignore_defense_monstre`
     * de la Lame Fantôme en est la première.
     *
     * ⚠ Un `max` et non une somme : deux sources ne se cumulent pas ici, la
     * plus forte l'emporte. Additionner des dés de défense ignorés donnerait
     * vite une défense négative.
     */
    /** Nom de l'objet porté par une source `potion:{Nom}` ou `potion:{Nom}#{id}`. */
    private static function nomDeSourceObjet(string $source): string
    {
        $reste = substr($source, strlen(self::PREFIXE_SOURCE_POTION));

        return explode('#', $reste, 2)[0];
    }

    /** Exemplaire d'inventaire collé à une source, ou `null` si elle n'en porte pas. */
    private static function exemplaireDeSource(string $source): ?int
    {
        $morceaux = explode('#', $source, 2);

        return isset($morceaux[1]) && ctype_digit($morceaux[1]) ? (int) $morceaux[1] : null;
    }

    /**
     * Comme {@see self::valeurBuff()}, mais un buff COLLÉ À UN EXEMPLAIRE ne
     * compte que si c'est bien cette arme qui frappe.
     *
     * La carte de la *Lame Fantôme* dit « when you attack WITH THE DAGGER » :
     * sans ce filtre, l'activer puis tirer à l'arc annulait quand même la
     * défense de la cible. Un buff SANS exemplaire (potion, sort) reste valable
     * quelle que soit l'arme — c'est le cas de tous les autres.
     */
    /**
     * Dés de RÉSISTANCE MENTALE d'un héros : son Mind, plus ce que l'équipement
     * porté y ajoute (`bonus_des_resistance_mentale`).
     *
     * ⚠ Point de passage unique, miroir de {@see self::desDefenseHeros()} pour
     * le corps. `MoteurDread` passait `attribut_mind` BRUT en deux endroits — le
     * jet de résistance et le contresort — et aucun équipement ne pouvait s'y
     * ajouter. Les *Écailles d'Elethorn* disent « when you attempt to resist a
     * Dread spell, roll an additional die » ; deux additions locales auraient
     * dérivé à la première divergence entre les deux jets.
     */
    public function desResistanceMentale(Personnage $personnage): int
    {
        return max(0, (int) $personnage->attribut_mind
            + app(Equipement::class)->valeurEffetPorte($personnage, 'bonus_des_resistance_mentale'));
    }

    public function valeurBuffDeLArme(Personnage $personnage, string $cle, ?int $ligneArmeId, int $vraiVaut = 1): int
    {
        $valeur = 0;

        foreach ($this->buffsSorts($personnage) as $condition) {
            $source = (string) $condition->pivot->source;
            $exemplaire = self::exemplaireDeSource($source);

            if ($exemplaire !== null && $exemplaire !== $ligneArmeId) {
                continue; // ce buff appartient à une autre arme
            }

            $brut = $this->effetSortSource($source)[$cle] ?? null;

            if ($brut !== null) {
                $valeur = max($valeur, $brut === true ? $vraiVaut : (int) $brut);
            }
        }

        return $valeur;
    }

    public function valeurBuff(Personnage $personnage, string $cle, int $vraiVaut = 1): int
    {
        $valeur = 0;

        foreach ($this->buffsSorts($personnage) as $condition) {
            $effet = $this->effetSortSource((string) $condition->pivot->source);
            $brut = $effet[$cle] ?? null;

            if ($brut === null) {
                continue;
            }

            // ⚠ `$vraiVaut` dit ce que signifie un `true` pour CETTE clé, et ce
            // n'est pas la même chose partout : « ne peut pas se défendre » sans
            // chiffre retire un dé (défaut 1), tandis que la Potion de bataille
            // — « 1 reroll of your Attack dice » — relance TOUTE la volée. Sans
            // ce paramètre, la potion serait tombée à un seul dé le jour où la
            // Longue épée de Fortune a eu besoin d'un nombre.
            $valeur = max($valeur, $brut === true ? $vraiVaut : (int) $brut);
        }

        return $valeur;
    }

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

            // Forge du Nain — Cruelle (relance) et Gardée (bouclier du
            // premier état) sont « une fois par COMBAT » (René, 2026-09-19 :
            // « si aucun monstre n'est présent dans les zones dévoilées alors
            // on n'est pas en combat, sinon on est en combat »). La fenêtre
            // se ferme donc ICI, au même instant et par le même prédicat que
            // la récupération des Styles Élémentaires du Moine juste
            // au-dessus — jamais un second point de réarmement.
            // ⚠ Gardée par un test d'égalité, comme `styles_epuises` à côté :
            // une écriture par tour pour un tableau déjà vide n'apporterait
            // rien.
            if ((array) $etat->capacites_combat !== []) {
                $etat->update(['capacites_combat' => []]);
            }

            return;
        }

        foreach ($this->buffsSorts($personnage) as $condition) {
            $effet = $this->effetSortSource((string) $condition->pivot->source);

            if (DureeEffet::correspond($effet['duree'] ?? null, DureeEffet::PLUS_DE_MONSTRE_EN_VUE)
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

            if (DureeEffet::correspond($this->effetSortSource($source)['duree'] ?? null, $declencheur)) {
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
     * @return int nombre de sorts rendus (0 = l'événement n'intéressait personne)
     */
    public function regagnerSorts(Personnage $personnage, string $evenement): int
    {
        $rendus = 0;

        // ⚠ Les talents `regain_sort` (Chant runique, Appel de la forêt) qui
        // passaient ici — un sort rendu à chaque monstre abattu, sur un
        // bouclier noir — sont devenus `garde_sort_qui_tue` le 2026-09-25
        // (René) : ils épargnent le sort qui tue, lu par
        // `ResolveurTour::preserverSort()`. Ce regain-ci ne sert plus qu'aux
        // SORTS qui déclarent leur propre `regain`.
        foreach ($personnage->sorts()->wherePivot('disponible', false)->get() as $sort) {
            if (($sort->effet['regain'] ?? null) !== $evenement) {
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

    /**
     * SOMMEIL — le monstre tente de rompre : **1 d6 brut par point de Mind**,
     * un seul **6** le réveille (carte officielle doc 16 §3bis, arbitrage de
     * René 2026-09-02 : « sur-le-champ, ou à chaque fois que son tour revient »).
     *
     * ⚠ Ce n'est PAS une résistance au lancer — le sort prend toujours. C'est sa
     * POURSUITE qui est contestée, et à deux moments : immédiatement après le
     * lancer, puis à chacun des tours du monstre.
     *
     * ⚠ Le d6 BRUT, pas une face de combat : le 6 est le bouclier noir, mais
     * dire « bouclier noir » ici mélangerait deux échelles pour rien — la carte
     * parle de dés rouges et d'un 6.
     *
     * ⚠ Mind 0 → aucun dé, donc aucune rupture possible. Le cas est
     * inatteignable (un Mind 0 est immunisé aux sorts mentaux, il ne peut pas
     * être endormi), mais il ne doit pas devenir une boucle infinie si la donnée
     * change : rendre `false` sans lancer est le comportement sûr.
     *
     * @return array{rompu: bool, faces: list<int>}
     */
    public function tenterRuptureSommeil(InstanceMonstre $instance): array
    {
        return $this->tenterRupture($instance, self::MONSTRE_ENDORMI);
    }

    /**
     * La MÊME rupture, pour n'importe quelle condition de monstre.
     *
     * Généralisée le 2026-09-03 pour le *Sceptre de Télékinésie*, dont la carte
     * reprend la phrase de Sommeil mot pour mot — « resisted immediately by the
     * monster rolling 1 red die for each of their Mind Points. If a 6 is rolled,
     * it resists ». Deux écritures de la même règle auraient dérivé au premier
     * ajustement ; `tenterRuptureSommeil()` n'est plus qu'un nom pour l'usage
     * historique.
     *
     * @return array{rompu: bool, faces: list<int>}
     */
    public function tenterRupture(InstanceMonstre $instance, string $condition): array
    {
        $faces = [];
        $rompu = false;

        // ⚠ Résolu au conteneur À L'APPEL, comme les autres services de ce
        // fichier : c'est ce qui laisse `desFiges()` remplacer le lanceur dans
        // les tests. Une instance capturée à la construction ignorerait le
        // re-binding et rendrait la mécanique intestable.
        $lanceur = app(LanceurDes::class);

        for ($i = 0, $mind = (int) $instance->pv_mind; $i < $mind; $i++) {
            $face = $lanceur->d6();
            $faces[] = $face;

            if ($face === 6) {
                $rompu = true;
            }
        }

        if ($rompu) {
            $this->retirerConditionMonstre($instance, $condition);
        }

        return ['rompu' => $rompu, 'faces' => $faces];
    }

    /**
     * RUPTURE D'UN SORT DE DREAD, côté HÉROS — le pendant exact de
     * {@see self::tenterRupture()}, qui ne parlait qu'aux monstres.
     *
     * Cinq cartes de Dread portent la même phrase, mot pour mot : « The spell
     * can be broken immediately or on a future turn by the hero rolling 1 red
     * die for each of their Mind Points. If a 6 is rolled, the spell is
     * broken. » (*Sleep*, *Command*, *Fear*, *Cloud of Dread*, *Mind Blast*).
     * Une sixième, *Dreadlights*, change les deux nombres — UN dé, seuil 5-6 —
     * et c'est pour cela que la règle du jet se relit sur le SORT plutôt que
     * d'être câblée ici : confondre les deux inverserait le sort, en libérant
     * vite un magicien (Mind 4) là où la carte ne parle pas du Mind du tout.
     *
     * ⚠ La règle est relue depuis la SOURCE de la condition
     * (`personnage_conditions.source` = `sort_dread:{Nom}`), jamais recopiée sur
     * le pivot — même principe que `expirerBuffs()`, qui relit la durée sur le
     * sort d'origine : corriger une valeur du catalogue doit atteindre les
     * conditions DÉJÀ posées en jeu.
     *
     * ⚠ Une condition posée par autre chose qu'un sort de Dread n'est pas
     * concernée : `Empoisonné` a un compteur, `Tombé` une relève. Rendre
     * `rompu: false` sans lancer un dé est le comportement sûr.
     *
     * @return array{rompu: bool, faces: list<int>, seuil: int}
     */
    public function tenterRuptureHeros(Personnage $personnage, string $nomCondition): array
    {
        $condition = Condition::where('nom', $nomCondition)->first();

        $ligne = $condition === null ? null : DB::table('personnage_conditions')
            ->where('personnage_id', $personnage->id)
            ->where('condition_id', $condition->id)
            ->orderByDesc('id')
            ->first();

        $sort = $ligne === null || ! str_starts_with((string) $ligne->source, 'sort_dread:')
            ? null
            : SortDread::where('nom', substr((string) $ligne->source, strlen('sort_dread:')))->first();

        $resistance = $sort === null ? null : data_get($sort->effet, 'resistance');

        if (! in_array($resistance, MotsClesSortDread::RESISTANCES_RUPTURE, true)) {
            return ['rompu' => false, 'faces' => [], 'seuil' => 0];
        }

        // *Dreadlights* : un seul dé, 5 ou 6. Les quatre autres : un dé par
        // point de Mind, et seul le 6 libère.
        //
        // ⚠ Les points de Mind ACTUELS — la jauge `pv_mind`, pas l'attribut
        // d'épreuve `attribut_mind` (errata 2021 C4, René 2026-10-01). Les
        // cartes disent « 1 red die for each of their MIND POINTS », *Mind
        // Blast* précise « currently have », et *Against the Ogre Horde* p. 10
        // le redit : « combat dice equal to their Mind Points ». Lire
        // l'attribut (3-4) était sans conséquence tant que personne ne perdait
        // de Mind ; depuis *Gel de l'Esprit*, un esprit entamé se libère moins
        // bien — et un héros en état de choc (0 Mind) ne se libère plus seul.
        // C'est la même lecture que le côté monstre (`tenterRupture()` lit
        // `instances_monstres.pv_mind`).
        $unDe = $resistance === MotsClesSortDread::RESISTANCE_RUPTURE_5_6;
        $nb = $unDe ? 1 : max(0, (int) $personnage->pv_mind);
        $seuil = $unDe ? 5 : 6;

        $lanceur = app(LanceurDes::class);
        $faces = [];
        $rompu = false;

        for ($i = 0; $i < $nb; $i++) {
            $face = $lanceur->d6();
            $faces[] = $face;

            if ($face >= $seuil) {
                $rompu = true;
            }
        }

        if ($rompu) {
            DB::table('personnage_conditions')->where('id', $ligne->id)->delete();
        }

        return ['rompu' => $rompu, 'faces' => $faces, 'seuil' => $seuil];
    }

    /**
     * Toutes les conditions du héros posées par un sort de Dread À RUPTURE, dans
     * l'ordre où elles ont été subies.
     *
     * Lue à l'ouverture du tour (`ResolveurTour::ouvrirNouveauTour()`), le seul
     * « début du tour » qu'un moteur par rounds possède.
     *
     * @return list<string>
     */
    public function conditionsARompre(Personnage $personnage): array
    {
        $noms = [];

        foreach ($personnage->conditions()->get() as $condition) {
            $source = (string) ($condition->pivot->source ?? '');

            if (! str_starts_with($source, 'sort_dread:')) {
                continue;
            }

            $sort = SortDread::where('nom', substr($source, strlen('sort_dread:')))->first();

            if ($sort !== null
                && in_array(data_get($sort->effet, 'resistance'), MotsClesSortDread::RESISTANCES_RUPTURE, true)) {
                $noms[] = (string) $condition->nom;
            }
        }

        return array_values(array_unique($noms));
    }

    /**
     * `perd_prochain_tour` (*Étourdi*) : le héros saute son tour.
     *
     * ⚠ La clé vivait au catalogue **sans le moindre lecteur** depuis la
     * création de la table — un héros étourdi jouait normalement. Elle n'avait
     * aucun producteur non plus jusqu'à la carte *Tempest* du paquet de Dread
     * (« That hero then misses their next turn »), qui la réveille des deux
     * bouts à la fois.
     *
     * Consommée à l'ouverture du round, comme `saute_tour` l'est au tour du
     * monstre : c'est un tour perdu, pas un état durable.
     */
    public function consommerTourPerdu(Personnage $personnage): bool
    {
        $perdu = false;

        foreach ($personnage->conditions()->get() as $condition) {
            if (! (bool) data_get($condition->effet, 'perd_prochain_tour', false)) {
                continue;
            }

            $perdu = true;

            // ⚠ On supprime par (héros, condition) et NON par `pivot->id` : la
            // relation ne déclare que `duree` et `source` dans son `withPivot`,
            // si bien que `pivot->id` est toujours nul — le DELETE ne touchait
            // rien, et l'Étourdi restait posé à vie tout en sautant chaque tour.
            DB::table('personnage_conditions')
                ->where('personnage_id', $personnage->id)
                ->where('condition_id', $condition->id)
                ->delete();
        }

        return $perdu;
    }

    /**
     * Le héros traverse-t-il les FIGURES ce tour-ci (Voile de Brume) ?
     *
     * Jumeau exact de {@see self::traverseRoche()} : relu sur l'effet du sort
     * SOURCE, jamais recopié sur le pivot. La carte dit « On the hero's next
     * move, they may move unseen through spaces that are occupied by monsters »
     * — c'est un MODE DE DÉPLACEMENT, la même famille que Traverser la Pierre,
     * et la même phrase que la *Mobilité de combat* du Rogue, dont le talent
     * porte déjà la mécanique `franchit_figures`.
     */
    public function franchitFigures(Personnage $personnage): bool
    {
        foreach ($this->buffsSorts($personnage) as $condition) {
            if (! empty($this->effetSortSource((string) $condition->pivot->source)['franchit_figures'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * MOBILITÉ DE COMBAT effective, talent OU buff confondus — la même
     * expression, désormais au SEUL endroit qui la calcule, que
     * `ResolveurTour::resoudreDeplacer()` appliquait déjà en dur
     * (`$this->capacites->a($personnage, 'franchit_figures') ||
     * $this->sorts->franchitFigures($personnage)`).
     *
     * ⚠ `EtatGroupe` et `MenuMoteur::peutSeDeplacer()` en ont besoin pour la
     * MÊME raison que `sort_bonus_disponible` : un client ne peut pas deviner
     * si CE héros porte le talent du Rogue ou le buff de Voile de Brume, donc
     * le serveur publie la DÉCISION plutôt que de la laisser se re-dériver —
     * un cinquième miroir aurait dérivé comme les quatre précédents
     * (`docs/regles/front-manette-et-table.md`). Sans elle, la manette
     * traitait tout monstre comme un mur MÊME pour un héros qui les
     * traverse : le talent existait côté moteur et restait injouable côté
     * écran (signalé en partie réelle, 2026-09-11).
     */
    public function mobiliteCombatDisponible(Personnage $personnage): bool
    {
        return app(CapacitesInnees::class)->a($personnage, 'franchit_figures')
            || $this->franchitFigures($personnage);
    }

    /**
     * Le héros se déplace-t-il SANS PAYER le terrain gênant (« hindering terrain »,
     * Jungles of Delthrak p. 4) ?
     *
     * Aujourd'hui : le talent `ignore_terrain_entravant` (Ronces complices).
     * Demain, les *Bracers of the Wild* (« you move unaffected through squares
     * containing furniture and hindering terrain », p. 50) s'ajoutent ICI, et
     * nulle part ailleurs : c'est le SEUL endroit qui répond à la question, relu
     * par `ResolveurTour::grilleDeplacement()` (le déplacement et l'aperçu de
     * trajet) et par `EtatGroupe` (qui publie la DÉCISION à la manette —
     * `entites[].ignore_terrain_entravant` —, jamais les ingrédients : un
     * miroir client qui re-déduirait « talent OU bracers » dériverait le jour
     * où une troisième source apparaîtrait).
     */
    public function terrainEntravantIgnore(Personnage $personnage): bool
    {
        // TROIS sources, UNE question : le talent des Ronces complices, une pièce
        // PORTÉE (Bracers of the Wild) et un buff de potion (Spiderstep Elixir —
        // fini au premier dégât subi, `duree: premier_degat_subi`).
        return app(Talents::class)->a($personnage, 'ignore_terrain_entravant')
            || app(Equipement::class)->effetPorte($personnage, MotsClesEquipement::IGNORE_TERRAIN_ENTRAVANT)
            || $this->aBuff($personnage, MotsClesEquipement::IGNORE_TERRAIN_ENTRAVANT);
    }

    /**
     * Le héros TRAVERSE-t-il le mobilier bloquant ? — *Bracers of the Wild*
     * (pièce portée) et *Spiderstep Elixir* (buff de potion) : « move unaffected
     * through squares containing furniture » (Jungles of Delthrak, p. 2 et 50).
     *
     * Jumeau de {@see self::terrainEntravantIgnore()} : MÊME structure, MÊME
     * raison d'être — le serveur décide, le menu (`peutSeDeplacer()`), le
     * résolveur (`grilleDeplacement()`) et la manette (`entites[].franchit_mobilier`)
     * lisent CETTE réponse. On traverse, on ne s'arrête pas sur le meuble.
     */
    public function mobilierFranchi(Personnage $personnage): bool
    {
        return app(Equipement::class)->effetPorte($personnage, MotsClesEquipement::FRANCHIT_MOBILIER)
            || $this->aBuff($personnage, MotsClesEquipement::FRANCHIT_MOBILIER);
    }

    /**
     * Héros inattaquable : condition « Évanescent »/« Caché » du catalogue, OU
     * sous un VOILE D'OMBRE (*Cloak of Shadows* : « heroes and monsters on the
     * tile may not … be attacked »). Lecteur unique de la phase des monstres
     * (`ResolveurTour::phaseMonstres()`) — le voile s'y lit donc au MÊME endroit
     * que l'invisibilité, sans second filtre.
     */
    public function estInattaquable(Personnage $personnage): bool
    {
        return $personnage->conditions()->get()
            ->contains(fn (Condition $c) => (bool) data_get($c->effet, 'inattaquable', false))
            || app(MoteurOmbre::class)->contientHeros($personnage);
    }

    /**
     * Héros qui NE PEUT PAS ATTAQUER (condition « Caché », posée par
     * *Invisibility* — *Spells of Protection*, 2026-10-06) : « While
     * invisible, you may not attack. » Distinct d'`actionInterdite()`
     * (Évanescent) : CELUI-CI bloque tout — fouille, désamorçage, sorts — là
     * où l'Invisibilité ne retire QUE l'attaque, et laisse le reste intact
     * (« you may … cast spells »). Lecteur unique : `ResolveurTour::frapper()`.
     */
    public function attaqueInterdite(Personnage $personnage): bool
    {
        return $this->raisonAttaqueInterdite($personnage) !== null;
    }

    /**
     * POURQUOI ce héros ne peut pas attaquer, ou `null` : la condition
     * « Caché » (*Invisibility* — « While invisible, you may not attack »), ou
     * un VOILE D'OMBRE sous ses pieds (*Cloak of Shadows* — « heroes and
     * monsters on the tile may not attack »). Les deux se lisent ICI, donc par
     * le menu (`MenuMoteur::generer()`), le résolveur (`resoudre()`) et la
     * frappe (`frapper()`) — un seul prédicat, trois portes. Le texte est celui
     * du refus : il dit la VRAIE cause, pas toujours l'invisibilité.
     */
    public function raisonAttaqueInterdite(Personnage $personnage): ?string
    {
        $interdisante = $personnage->conditions()->get()
            ->first(fn (Condition $c) => (bool) data_get($c->effet, 'attaque_interdite', false));

        if ($interdisante !== null) {
            // Le motif est celui que la condition DÉCLARE (`raison_attaque`) :
            // l'Invisibilité et les liens de *Strands of Binding* interdisent la
            // même chose pour des raisons que le joueur doit pouvoir lire.
            return str_replace('{nom}', (string) $personnage->nom, (string) data_get(
                $interdisante->effet, 'raison_attaque',
                "{nom} est invisible : impossible d'attaquer avant le début de son prochain tour.",
            ));
        }

        if (app(MoteurOmbre::class)->contientHeros($personnage)) {
            return "{$personnage->nom} se tient sous un voile d'ombre : nul n'y attaque, nul n'y est attaqué.";
        }

        return null;
    }

    /**
     * Héros IMMUNISÉ À TOUT SORT (condition « Caché », *Invisibility*) :
     * « You cannot be attacked and are immune to all spells. » La moitié
     * « cannot be attacked » est déjà couverte par `inattaquable`
     * (`estInattaquable()`, lu depuis longtemps par `phaseMonstres()`) ; celle-
     * ci couvre l'AUTRE moitié — un sort, ami ou ennemi, ne peut plus choisir
     * ce héros pour cible. Lecteur : `MoteurSorts::ciblesLegales()`.
     *
     * ⚠ Portée connue et nommée : seul le ciblage d'un SORT DE HÉROS est
     * filtré ici. Le ciblage des sorts de Dread (`MoteurDread`) n'est pas
     * recâblé par ce chantier — un Sorcier pourrait donc encore viser un
     * héros invisible avec un sort. Dette explicite, pas un oubli.
     */
    public function immuniteSorts(Personnage $personnage): bool
    {
        return $personnage->conditions()->get()
            ->contains(fn (Condition $c) => (bool) data_get($c->effet, 'immunite_sorts', false));
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
     * Second mode d'un sort : « ouvre une porte AU CHOIX ».
     *
     * Une ENTRÉE par porte encore fermée d'une salle DÉCOUVERTE — le magicien
     * ne choisit pas une porte qu'il n'a jamais vue, et on évite d'inonder le
     * menu avec tout le donjon. Contrairement à `ouvrir_porte` du MenuMoteur,
     * aucune adjacence n'est requise : c'est tout l'intérêt du sort, ouvrir à
     * distance une porte que des figures bloquent.
     *
     * @return list<array<string, mixed>>
     */
    private function entreesPorteAuChoix(Quete $quete, Sort $sort, ?array $lanceur = null): array
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
                'cle' => "sort:{$sort->id}:porte:{$porte['x']}:{$porte['y']}:{$cote}",
                'sort_id' => $sort->id,
                // Repère DIRECTIONNEL depuis le lanceur : six libellés
                // rigoureusement identiques ne se distinguaient que par leur
                // index, ce qui revenait à choisir au hasard (constaté en partie
                // réelle, 2026-08-06).
                'nom' => "{$sort->nom} — ouvrir la porte {$this->reperePorte($lanceur, $porte)}",
                'element' => $sort->element,
                'sort_type' => $sort->type,
                'disponible' => true,
                'mode' => 'ouvre_porte',
                'porte' => ['x' => (int) $porte['x'], 'y' => (int) $porte['y'], 'cote' => $cote],
            ];
        }

        return $options;
    }

    /**
     * Les Sorciers de Dread encore capables de lancer un sort, parmi les cibles
     * monstres de `ciblesLegales()` — la cible d'*Unlearn* (2026-10-08). Un monstre
     * sans répertoire, ou dont tout le répertoire est déjà oublié, n'est pas
     * lanceur pour cette quête.
     *
     * @param  list<array<string, mixed>>  $monstres
     * @return list<array<string, mixed>>
     */
    private function lanceursDreadOubliables(array $monstres): array
    {
        return array_values(array_filter($monstres, function (array $m) {
            $instance = InstanceMonstre::query()->with('monstre')->find((int) ($m['id'] ?? 0));

            return $instance !== null
                && $instance->quete !== null
                && app(MoteurDread::class)->sortsOubliables($instance, $instance->quete) !== [];
        }));
    }

    /**
     * Les sorts qu'un HÉROS peut encore faire oublier pour la quête (Unlearn,
     * mécanisme générique : la carte Dread *Unlearn* du High Mage, vague 2, s'y
     * branchera contre un héros) — ceux qu'il connaît, moins ceux déjà oubliés.
     *
     * @return list<string>
     */
    public function sortsOubliablesHeros(Personnage $personnage, Quete $quete): array
    {
        $oublies = app(OubliSorts::class)->oublies($quete, OubliSorts::CIBLE_PERSONNAGE, $personnage->id, OubliSorts::SOURCE_SORT);

        return $personnage->sorts()->pluck('sorts.nom')
            ->map(fn ($nom) => (string) $nom)
            ->diff($oublies)
            ->values()
            ->all();
    }

    /**
     * Une entrée PAR SALLE NON DÉCOUVERTE — Clairvoyance (Spells of Detection).
     *
     * « You may ask Zargon to lay out the contents of one room anywhere on the
     * board » : n'importe quelle salle de la carte, sans trajet ni ligne de vue,
     * mais une salle que le groupe n'a pas encore vue — montrer le contenu
     * d'une salle déjà découverte ne révèle rien. Le repère est celui des
     * portes (`reperePorte()`) appliqué à la MÉDIANE de la salle : « au
     * nord-est, à 7 cases ». Aucun contenu dans le libellé.
     *
     * `$racine` / `$extra` : la `cle` part de `sort:{id}` (le sort connu, défaut) ou
     * de `parchemin:{inventaire_id}` (le parchemin du sac, 2026-10-08), et `$extra`
     * porte `inventaire_id` — MÊME générateur, deux entrées de menu.
     *
     * @param  array{x: int, y: int}  $lanceur
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function entreesVisionSalle(Quete $quete, Sort $sort, array $lanceur, ?string $racine = null, array $extra = []): array
    {
        $racine ??= "sort:{$sort->id}";
        $decouvertes = $quete->sallesDecouvertes();
        $salles = (array) data_get($quete->carte?->grille, 'salles', []);
        $entrees = [];

        foreach ($salles as $index => $salle) {
            if (in_array((int) $index, $decouvertes, true)) {
                continue;
            }

            $mx = (int) ($salle['mediane_x'] ?? ((int) $salle['x'] + intdiv((int) $salle['largeur'], 2)));
            $my = (int) ($salle['mediane_y'] ?? ((int) $salle['y'] + intdiv((int) $salle['hauteur'], 2)));

            $entrees[] = [
                'cle' => "{$racine}:salle:{$index}",
                'sort_id' => $sort->id,
                'nom' => "{$sort->nom} — salle ".$this->reperePorte($lanceur, ['x' => $mx, 'y' => $my]),
                'element' => $sort->element,
                'sort_type' => $sort->type,
                'disponible' => true,
                'mode' => 'vision_salle',
                'salle' => (int) $index,
                ...$extra,
            ];
        }

        return $entrees;
    }

    /**
     * Une entrée PAR PAIRE de cases libres — Wall of Stone (Spells of
     * Protection). La carte dit « covers 2 squares not occupied by figures »,
     * et René a tranché le 2026-10-05 le retour aux DEUX cases (la décision
     * « une case » du 2026-10-04 avait été prise sur un mur posé sur une
     * arête, cf. `docs/plan-morcar-execution-2026-10-06.md`).
     *
     * Une paire est `[A, B]` : A orthogonalement adjacente au lanceur, B
     * orthogonalement adjacente à A, et ni B ni A ne portent de figure ou de
     * mobilier (`estTraversable()` exclut déjà les deux, et les embrasures de
     * porte non ouvertes). B ne peut jamais être la case du lanceur — il s'y
     * tient, donc `estTraversable()` le refuse déjà, mais la règle est écrite
     * en clair parce que c'est précisément le cas tordu. Deux paires ne
     * peuvent pas se confondre : deux voisines orthogonales du lanceur ne
     * sont jamais orthogonalement adjacentes entre elles, donc A est toujours
     * LA case du lanceur et jamais l'autre.
     *
     * ⚠ Aucune entrée si aucune paire n'est libre — une liste vide est le
     * signal correct, pas une erreur à masquer (cf. le rayon de l'Éclair).
     *
     * `$racine` / `$extra` : comme `entreesVisionSalle()` — le parchemin de Mur de
     * Pierre (2026-10-08) offre les MÊMES paires, `cle` en `parchemin:{id}`.
     *
     * @param  array{x: int, y: int}  $lanceur
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function entreesPoseMurMagique(Grille $grille, Sort $sort, array $lanceur, ?string $racine = null, array $extra = []): array
    {
        $racine ??= "sort:{$sort->id}";
        $directions = ['nord' => [0, -1], 'sud' => [0, 1], 'ouest' => [-1, 0], 'est' => [1, 0]];
        $entrees = [];

        foreach ($directions as $nomA => [$dxA, $dyA]) {
            $ax = $lanceur['x'] + $dxA;
            $ay = $lanceur['y'] + $dyA;

            if (! $grille->estTraversable($ax, $ay)) {
                continue;
            }

            foreach ($directions as $nomB => [$dxB, $dyB]) {
                $bx = $ax + $dxB;
                $by = $ay + $dyB;

                // Revenir sur le lanceur, ou sur une case déjà prise : pas une
                // seconde case, c'est un mur qui n'existe pas.
                if (($bx === $lanceur['x'] && $by === $lanceur['y'])
                    || ! $grille->estTraversable($bx, $by)) {
                    continue;
                }

                $entrees[] = [
                    'cle' => "{$racine}:mur:{$ax}:{$ay}:{$bx}:{$by}",
                    'sort_id' => $sort->id,
                    'nom' => "{$sort->nom} — au {$nomA}, puis au {$nomB}",
                    'element' => $sort->element,
                    'sort_type' => $sort->type,
                    'disponible' => true,
                    'mode' => 'pose_mur_magique',
                    'cases' => [['x' => $ax, 'y' => $ay], ['x' => $bx, 'y' => $by]],
                    ...$extra,
                ];
            }
        }

        return $entrees;
    }

    /**
     * Une entrée PAR emplacement légal du voile d'ombre (*Cloak of Shadows*).
     *
     * ⚠ Les emplacements viennent de `MoteurOmbre::emplacementsLegaux()`, que le
     * résolveur (`MoteurOmbre::poser()`) relit à l'identique : la liste du menu
     * EST la liste blanche. Le nom dit la taille, l'orientation et le repère
     * (direction + distance) — jamais de coordonnées.
     *
     * `$racine` / `$extra` : comme `entreesVisionSalle()`, pour le parchemin du voile.
     *
     * @param  array{x: int, y: int}  $lanceur
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function entreesPoseOmbre(Quete $quete, Grille $grille, Sort $sort, array $lanceur, ?string $racine = null, array $extra = []): array
    {
        $racine ??= "sort:{$sort->id}";
        $moteur = app(MoteurOmbre::class);
        $entrees = [];

        foreach ($moteur->emplacementsLegaux($quete, $grille, $lanceur) as $e) {
            $centre = ['x' => $e['x'] + intdiv($e['l'] - 1, 2), 'y' => $e['y'] + intdiv($e['h'] - 1, 2)];
            $repere = $this->repereDeZone($lanceur, $centre);

            $entrees[] = [
                'cle' => "{$racine}:ombre:{$e['x']}:{$e['y']}:{$e['l']}:{$e['h']}",
                'sort_id' => $sort->id,
                'nom' => "{$sort->nom} — {$e['l']}×{$e['h']}, {$repere}",
                'element' => $sort->element,
                'sort_type' => $sort->type,
                'disponible' => true,
                'mode' => 'pose_ombre',
                'cases' => MoteurOmbre::casesDuRectangle($e['x'], $e['y'], $e['l'], $e['h']),
                ...$extra,
            ];
        }

        return $entrees;
    }

    /**
     * Repère d'une ZONE (le centre d'un voile) vu du lanceur : « sur toi », « à
     * l'ouest, à 1 case », « au nord-est, à 4 cases ». Mêmes seuils de direction
     * que `reperePorte()`, la grammaire en plus (« à l'est », jamais « au est »).
     *
     * @param  array{x: int, y: int}  $lanceur
     * @param  array{x: int, y: int}  $centre
     */
    private function repereDeZone(array $lanceur, array $centre): string
    {
        $dx = $centre['x'] - $lanceur['x'];
        $dy = $centre['y'] - $lanceur['y'];
        $distance = abs($dx) + abs($dy);

        if ($distance === 0) {
            return 'sur toi';
        }

        $vertical = abs($dy) * 3 >= abs($dx) ? ($dy < 0 ? 'nord' : 'sud') : '';
        $horizontal = abs($dx) * 3 >= abs($dy) ? ($dx < 0 ? 'ouest' : 'est') : '';
        $direction = match (true) {
            $vertical !== '' && $horizontal !== '' => "au {$vertical}-{$horizontal}",
            $vertical !== '' => "au {$vertical}",
            $horizontal === 'ouest' => "à l'ouest",
            default => "à l'est",
        };

        return "{$direction}, à {$distance} case".($distance > 1 ? 's' : '');
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

    /**
     * Une entrée de la liste d'une option — sort connu ou parchemin.
     *
     * ⚠ `cibles` n'est joint QUE si le sort en a : son absence est le signal
     * qui dit à la manette de ne pas ouvrir de troisième niveau. Un sort sur soi
     * (`cible: 'soi'`) part donc directement, sans clic imposé pour une liste à
     * une seule entrée — c'est déjà la règle de `ciblesLegales()`, qui rend
     * `null` dans ce cas.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    /**
     * Une entrée par direction utile, pour un sort-rayon (*Éclair*).
     *
     * ⚠ Seules les directions qui touchent AU MOINS UN MONSTRE sont offertes :
     * un parchemin est DÉTRUIT à l'usage, et le foudroyer dans un couloir vide
     * serait un bouton pour perdre une carte. Même arbitrage que le rayon du
     * Moine, dont le Style du Feu ne s'ouvre qu'une fois par combat.
     *
     * ⚠ Les compagnons sur la ligne sont NOMMÉS dans le libellé. La carte dit
     * « all heroes or monsters that stand in its path » : le joueur doit voir
     * qui il va griller avant de choisir, sans quoi le tir ami serait un effet
     * automatique que rien n'annonce.
     *
     * @param  array{x: int, y: int}  $lanceur
     * @return list<array<string, mixed>>
     */
    private function entreesDeRayon(Quete $quete, int $inventaireId, Sort $sort, array $lanceur): array
    {
        $entrees = [];

        foreach (Rayon::cadran($quete, $lanceur['x'], $lanceur['y']) as $code => $vise) {
            if ($vise['monstres'] === 0) {
                continue;
            }

            [, , $libelle] = Rayon::DIRECTIONS[$code];
            $detail = "{$vise['monstres']} ennemi".($vise['monstres'] > 1 ? 's' : '');

            if ($vise['heros'] !== []) {
                $detail .= ' — touche aussi '.implode(', ', $vise['heros']);
            }

            $entrees[] = [
                'cle' => "parchemin:{$inventaireId}:{$code}",
                'sort_id' => $sort->id,
                'nom' => "{$sort->nom} {$libelle}",
                'element' => $sort->element,
                'sort_type' => $sort->type,
                'disponible' => true,
                'detail' => $detail,
                'inventaire_id' => $inventaireId,
                'direction' => $code,
                'tir_ami' => $vise['heros'],
            ];
        }

        return $entrees;
    }

    private function entreeSort(
        string $cle,
        string $nom,
        Sort $sort,
        bool $disponible,
        array $ciblesMonstres,
        array $ciblesHeros,
        ?array $lanceur = null,
        ?Grille $grille = null,
        array $extra = [],
    ): array {
        $entree = [
            'cle' => $cle,
            'sort_id' => $sort->id,
            'nom' => $nom,
            'element' => $sort->element,
            'sort_type' => $sort->type,
            'disponible' => $disponible,
            ...$extra,
        ];

        $cibles = $disponible
            ? $this->ciblesLegales($sort, $ciblesMonstres, $ciblesHeros, $lanceur, $grille)
            : null;

        if ($cibles !== null) {
            $entree['cibles'] = $cibles;
        }

        return $entree;
    }

    /**
     * Retire les entrées de sort ou de parchemin dont la liste `cibles` est VIDE.
     *
     * ⚠ Un sort à cible sans AUCUNE cible légale — *Désapprentissage* sans Sorcier
     * de Dread en vue, *Conte inspirant* d'un Barde seul — n'a pas à figurer du
     * tout : ni grisé comme un sort épuisé (il n'est pas épuisé, il attend une
     * cible), ni `cibles: []`, que la manette ouvrirait comme un niveau sans choix
     * avant que le résolveur ne refuse « Cible requise ». C'est la règle des sorts
     * à emplacement, qui n'ont pas d'entrée sans emplacement (Mur de Pierre, Voile
     * d'ombre, Clairvoyance). Un seul point de passage pour les sorts connus et
     * les parchemins, quel que soit le `cible`.
     *
     * Une entrée SANS clé `cibles` (sort sur soi, zone, mode à emplacement, sort
     * épuisé) n'est pas concernée : seule une liste présente et vide l'est.
     *
     * @param  list<array<string, mixed>>  $entrees
     * @return list<array<string, mixed>>
     */
    private static function sansCiblesVides(array $entrees): array
    {
        return array_values(array_filter(
            $entrees,
            static fn (array $entree) => ! array_key_exists('cibles', $entree) || $entree['cibles'] !== [],
        ));
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
            // ⚠ Le suffixe `#{inventaire_id}` est retiré avant la recherche : il
            // dit QUEL exemplaire a posé le buff, il ne fait pas partie du nom.
            $nom = self::nomDeSourceObjet($source);

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
