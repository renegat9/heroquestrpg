<?php

declare(strict_types=1);

namespace App\Partie;

use App\Models\Condition;
use App\Models\Groupe;
use App\Models\InstanceMonstre;
use App\Models\Personnage;
use App\Models\Quete;
use App\Models\Objet;
use App\Models\Sort;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moteur des sorts des héros (doc 02) — résolu en code, jamais par l'IA.
 *
 * Connaissance par ÉLÉMENTS (doc 02 §2-3) : connaître un élément = connaître
 * ses 3 sorts (pivot personnage_sorts, disponible = épuisé/dispo). Magicien :
 * 2 éléments à la création ; Elfe : nœuds « Première magie » / « Second
 * élément » ; nœuds « Écoles » du Magicien — tous via la mécanique
 * `emplacement_element` du CompetenceSeeder.
 *
 * Récupération « une fois par quête » (S5) : DemarreurQuete remet tout
 * disponible via reinitialiserQuete ; « Concentration » (S6, nœud Magicien)
 * récupère UN sort épuisé en sacrifiant le tour, une fois par quête — l'usage
 * est marqué en cache (clé groupe+personnage), purgé au démarrage suivant.
 *
 * BUFFS DES SORTS UTILITAIRES : ils vivent en `personnage_conditions`
 * (condition du catalogue + pivot source `sort:{Nom}` + duree en tours).
 * Les valeurs chiffrées (bonus de dés, multiplicateur…) ne sont jamais
 * recopiées : elles sont relues dans l'effet JSON du sort pointé par la
 * source, aux résolutions d'attaque / défense / déplacement (ResolveurTour).
 *  - Courage (bonus_des_attaque)      : duree 0, consommé à la PROCHAINE attaque ;
 *  - Peau de Pierre (bonus_des_defense): duree 0, jusqu'à la fin de la quête
 *    (MVP — le doc dit « fin du combat », notion sans état dédié ici ;
 *    purgé par reinitialiserQuete au démarrage suivant) ;
 *  - Voile de Brume (condition Caché) : duree 1 — couvre la phase des
 *    monstres du tour courant, expire au décompte de fin de tour ;
 *  - Vent Véloce (deplacement_multiplie): duree 2 — actif au déplacement du
 *    tour SUIVANT (lancer le sort consomme l'action du tour), consommé à
 *    l'usage.
 *
 * CONDITIONS DES MONSTRES : il n'existe pas de pivot conditions pour les
 * instances de monstres (et pas de nouvelle migration) — elles vivent dans
 * le JSON `instances_monstres.habillage.conditions` (choix MVP documenté) :
 *  - `endormi` (Sommeil)         : le monstre ne joue pas tant qu'il n'est
 *    pas attaqué — une attaque le réveille ;
 *  - `empeche_attaque` (Tempête) : n'attaque pas à son prochain tour,
 *    consommé à ce tour-là.
 */
final class MoteurSorts
{
    /** Les 4 éléments du MVP (doc 02 §7). */
    public const ELEMENTS = ['feu', 'eau', 'terre', 'air'];

    /** Éléments de départ du Magicien quand le client n'en choisit pas. */
    public const ELEMENTS_DEFAUT_MAGICIEN = ['feu', 'eau'];

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

    public const MONSTRE_EMPECHE_ATTAQUE = 'empeche_attaque';

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

        foreach ($personnage->sorts()->wherePivot('disponible', true)->orderBy('sorts.id')->get() as $sort) {
            $options[] = $this->optionSort(
                "sort_{$sort->id}",
                "Lancer {$sort->nom}",
                'sort',
                ['sort_id' => $sort->id],
                $sort,
                $ciblesMonstres,
                $ciblesHeros,
            );
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
     * actifs ET héros (tir ami possible) ; utilitaire ciblé → héros de la
     * quête ; cible `soi` (Traverser la Pierre) → pas de liste, le lanceur.
     *
     * @param  list<array<string, mixed>>  $monstres
     * @param  list<array<string, mixed>>  $heros
     * @return list<array<string, mixed>>|null
     */
    public function ciblesLegales(Sort $sort, array $monstres, array $heros): ?array
    {
        if (in_array($sort->type, ['degats', 'mental'], true)) {
            return [...$monstres, ...$heros];
        }

        $cible = (string) data_get($sort->effet, 'cible', 'soi');

        return str_contains($cible, 'heros') ? $heros : null;
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
     * tir ami : Endormi, Étourdi…) avec sa durée par défaut.
     */
    public function appliquerConditionCatalogue(Personnage $cible, string $nom, Sort $sort): Condition
    {
        $condition = $this->condition($nom);

        $cible->conditions()->attach($condition->id, [
            'duree' => (int) $condition->duree_defaut,
            'source' => self::PREFIXE_SOURCE.$sort->nom,
        ]);

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

        $cible->conditions()->attach($condition->id, [
            'duree' => (int) data_get($objet->effet, 'duree_tours', 0),
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
     * Consomme les buffs de sorts portant la clé d'effet donnée (Courage à
     * la prochaine attaque, Vent Véloce au déplacement).
     */
    public function consommerBuffs(Personnage $personnage, string $cle): void
    {
        foreach ($this->buffsSorts($personnage) as $condition) {
            $source = (string) $condition->pivot->source;

            if (array_key_exists($cle, $this->effetSortSource($source))) {
                DB::table('personnage_conditions')
                    ->where('personnage_id', $personnage->id)
                    ->where('condition_id', $condition->id)
                    ->where('source', $source)
                    ->delete();
            }
        }
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
    private function optionSort(
        string $id,
        string $libelle,
        string $type,
        array $parametres,
        Sort $sort,
        array $ciblesMonstres,
        array $ciblesHeros,
    ): array {
        $cibles = $this->ciblesLegales($sort, $ciblesMonstres, $ciblesHeros);

        if ($cibles !== null) {
            $parametres['cibles'] = $cibles;
        }

        return ['id' => $id, 'libelle' => $libelle, 'type' => $type, 'parametres' => $parametres];
    }

    /**
     * @return list<array{type: string, id: int, nom: string}>
     */
    private function ciblesMonstres(Quete $quete): array
    {
        return $quete->instancesMonstres()
            ->where('etat', 'actif')
            ->with('monstre')
            ->orderBy('id')
            ->get()
            ->map(fn (InstanceMonstre $i) => [
                'type' => 'monstre',
                'id' => $i->id,
                'nom' => $i->habillage['nom'] ?? $i->monstre->nom_base,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{type: string, id: int, nom: string}>
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
