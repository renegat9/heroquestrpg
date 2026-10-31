<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monstre extends Model
{
    protected $table = 'monstres';

    protected $fillable = [
        'nom_base',
        'deplacement',
        'attaque',
        'portee',
        'attaque_distance',
        'defense',
        'pv_body',
        'pv_mind',
        'tier',
        // Boîte d'origine (doc 18) : `null` = aucune, donc compatible avec TOUS
        // les thèmes — c'est le cas de nos propres blocs de stats.
        'boite',
        // AUTRES boîtes où la MÊME fiche est imprimée (liste de `boite`), en plus de `boite`
        // qui reste la boîte d'origine. `null` = aucune. Une figurine commune à deux thèmes
        // (Archer elfe / Guerrier elfe : Mage of the Mirror ET Rise of the Dread Moon p. 36)
        // se déclare ici, sur UNE ligne — jamais dupliquée, jamais `boite: null` (qui la
        // ferait entrer dans TOUS les thèmes, jeu de base compris). Lu à un seul endroit :
        // `boites()`.
        'boites_partagees',
        'cout',
        'grande_taille',
        'capacites',
        'sorts_dread',
        'archetype_lanceur',
        // Lien DÉCLARÉ vers le monstre standard dont ce monstre est la
        // variante à distance (Q6, Against the Ogre Horde p. 8) — nom_base de
        // la cible, jamais null + une convention de nommage. `null` = ce
        // monstre n'est pas une variante.
        'variante_distance_de',
        // MONSTRE À PHASES (chantier 2026-10-04, Ogre Horde p. 6) : nom_base de
        // la phase SUIVANTE, même patron que `variante_distance_de`. `null` =
        // dernière phase (ou monstre ordinaire) — c'est ce qui dit au point de
        // passage unique qu'il doit vraiment mourir plutôt qu'adopter une
        // nouvelle statistique.
        'phase_suivante',
    ];

    protected function casts(): array
    {
        return [
            'capacites' => 'array',
            'sorts_dread' => 'array',
            'grande_taille' => 'array',
            'boites_partagees' => 'array',
        ];
    }

    /**
     * TOUTES les boîtes où ce monstre est imprimé : la boîte d'origine, puis les boîtes
     * partagées (`boites_partagees`). Liste VIDE = aucune boîte (`boite: null`, nos propres
     * blocs de stats), donc compatible avec tous les thèmes. C'est ce que lisent
     * `BestiaireGroupe::autorise()` / `contient()` — le point de passage unique de « ce
     * bestiaire peut-il le tirer ? ».
     *
     * @return list<string>
     */
    public function boites(): array
    {
        if ($this->boite === null) {
            return [];
        }

        return array_values(array_unique([(string) $this->boite, ...array_map('strval', (array) ($this->boites_partagees ?? []))]));
    }

    /** Attaque à distance (ligne de vue) plutôt qu'au contact. */
    public function aDistance(): bool
    {
        return $this->portee === 'distance';
    }

    /** Le monstre porte-t-il la capacité *Ambush* (Dreadshifter) ? */
    public function aCapaciteEmbuscade(): bool
    {
        $capacites = (array) ($this->capacites ?? []);

        return in_array('embuscade', $capacites, true) || array_key_exists('embuscade', $capacites);
    }

    /**
     * Un monstre de MÊLÉE qui tire AUSSI à distance, au choix (`tir_au_choix`, Gruulob
     * dans ses deux formes). Distinct de `aDistance()` : l'archer recule pour tirer,
     * celui-ci tire sur place et frappe au contact. Lu par `ResolveurTour::jouerMonstre()`.
     */
    public function aTirAuChoix(): bool
    {
        $capacites = (array) ($this->capacites ?? []);

        return in_array('tir_au_choix', $capacites, true) || array_key_exists('tir_au_choix', $capacites);
    }

    /**
     * Figure IMMOBILE : déplacement 0 au catalogue (Guardian Effigy, Dread Skull, Bloomrot
     * Scion). UNE seule définition — c'est elle que lisent la phase des monstres (aucun
     * chemin à calculer), le placement (`AssembleurCarte::casesPourFiguresFixes()` : en salle
     * seulement, jamais en couloir, jamais au détriment du plancher de cases jouables) et
     * l'achat des rencontres. Jamais un drapeau séparé : une fiche à Move 0 qui ne le serait
     * pas se contredirait elle-même.
     */
    public function estImmobile(): bool
    {
        return (int) $this->deplacement === 0;
    }

    /**
     * TOURELLE (`tourelle`) : immobile ET fait feu à chaque tour du MJ sur un héros en ligne
     * de vue — au contact comme à distance, aux mêmes dés. Lu par
     * `ResolveurTour::jouerMonstreEveille()`. Voir `App\Engine\MotsClesAdversaires`.
     */
    public function estTourelle(): bool
    {
        $capacites = (array) ($this->capacites ?? []);

        return in_array('tourelle', $capacites, true) || array_key_exists('tourelle', $capacites);
    }

    /**
     * RÉSERVÉ À SON THÈME (`reserve_a_son_theme`, chantier EXT-CRYPTE, 2026-10-23) : ne se tire JAMAIS hors de sa boîte,
     * même quand le pool de la rencontre n'a aucun autre candidat du thème. Lu par
     * `DemarreurQuete::acheterMonstres()`. Voir `App\Engine\MotsClesAdversaires`.
     */
    public function reserveASonTheme(): bool
    {
        $capacites = (array) ($this->capacites ?? []);

        return in_array('reserve_a_son_theme', $capacites, true) || array_key_exists('reserve_a_son_theme', $capacites);
    }

    /**
     * IMMUNITÉ TOTALE aux effets de sort (`immunite_sorts`) — Guardian Effigy : « immune to
     * all spell effects ». Lu aux points de passage des sorts qui atteignent un monstre :
     * le menu des sorts de héros (`MoteurSorts::ciblesLegales()`), les sorts de zone et de
     * rayon (`ResolveurTour`), les sorts de Dread qui frappent ou soignent des monstres
     * (`MoteurDread`).
     */
    public function immuniseAuxSorts(): bool
    {
        $capacites = (array) ($this->capacites ?? []);

        return in_array('immunite_sorts', $capacites, true) || array_key_exists('immunite_sorts', $capacites);
    }

    /**
     * Les conditions de monstre qu'un SORT ne peut pas poser sur cette créature
     * (`immunite_conditions`) — Bloomrot Scion : « immune to spells that would cause it to
     * sleep or miss its turn ». Liste de clés `MoteurSorts::CONDITIONS_MONSTRE`.
     *
     * @return list<string>
     */
    public function conditionsImmunisees(): array
    {
        $liste = ((array) ($this->capacites ?? []))['immunite_conditions'] ?? [];

        return is_array($liste) ? array_values(array_map('strval', $liste)) : [];
    }

    /**
     * L'ÉCHELLE des immunités aux sorts, lue d'un seul tenant par `MoteurSorts::sortRefuseSur()` (menu ET
     * résolution) : `totale` (`immunite_sorts`, Effigie), `sauf` (`immunite_sorts_sauf`, les ÉLÉMENTS qui passent —
     * Kessandria : `['feu']`), `elements` (`immunite_sorts_elements`, les ÉLÉMENTS qui ne l'atteignent pas — Balur :
     * `['feu']`, l'inverse de `sauf`) et `sources` (`vulnerable_uniquement_a`, les armes et sorts de dégâts qui blessent
     * — Seigneur Sorcier). `null` = pas de restriction de cette forme.
     *
     * @return array{totale: bool, sauf: ?list<string>, elements: ?list<string>, sources: ?list<string>}
     */
    public function immunitesSorts(): array
    {
        $capacites = (array) ($this->capacites ?? []);

        return [
            'totale' => $this->immuniseAuxSorts(),
            'sauf' => $this->listeDeCapacite($capacites, 'immunite_sorts_sauf'),
            'elements' => $this->listeDeCapacite($capacites, 'immunite_sorts_elements'),
            'sources' => $this->listeDeCapacite($capacites, 'vulnerable_uniquement_a'),
        ];
    }

    /**
     * INVULNÉRABLE ET BRISE L'ARME (`invulnerable_casse_arme`) : la liste des armes EXEMPTÉES de casse. `null` =
     * la créature n'est pas de cette famille. Lue par `ResolveurTour::frapper()`, seul point de passage des coups.
     *
     * @return ?list<string>
     */
    public function casseArmeExemptees(): ?array
    {
        $capacites = (array) ($this->capacites ?? []);

        if (! array_key_exists('invulnerable_casse_arme', $capacites)) {
            return null;
        }

        return $this->listeDeCapacite($capacites, 'invulnerable_casse_arme') ?? [];
    }

    /**
     * Une capacité PARAMÉTRÉE par une liste de noms : `['cle' => ['a', 'b']]`. Absente, ou présente sans liste
     * exploitable, elle ne restreint rien (`null`).
     *
     * @param  array<array-key, mixed>  $capacites
     * @return ?list<string>
     */
    private function listeDeCapacite(array $capacites, string $cle): ?array
    {
        if (! array_key_exists($cle, $capacites) || ! is_array($capacites[$cle])) {
            return null;
        }

        return array_values(array_map('strval', $capacites[$cle]));
    }

    /** Emprise en cases : [largeur, hauteur]. Par défaut 1×1. */
    public function emprise(): array
    {
        $t = $this->grande_taille;

        return [
            'l' => (int) ($t['l'] ?? 1),
            'h' => (int) ($t['h'] ?? 1),
        ];
    }

    public function grandeTaille(): bool
    {
        $e = $this->emprise();

        return $e['l'] > 1 || $e['h'] > 1;
    }

    public function instances(): HasMany
    {
        return $this->hasMany(InstanceMonstre::class, 'monstre_id');
    }

    /** Mind 0 → immunité aux effets mentaux (morts-vivants). */
    public function immuniseMental(): bool
    {
        return $this->pv_mind === 0;
    }

    /** Ce monstre est-il une variante À DISTANCE d'un monstre standard (Q6) ? */
    public function estVarianteDistance(): bool
    {
        return $this->variante_distance_de !== null;
    }

    /**
     * Ce bloc de stats a-t-il une phase SUIVANTE (chantier 2026-10-04) ? Une
     * chaîne de phases (Gruzbella, Spawn of the Pit, Gretzl) se termine par une
     * ligne qui rend `false` ici — c'est elle qui meurt pour de vrai.
     */
    public function aPhaseSuivante(): bool
    {
        return $this->phase_suivante !== null;
    }

    /**
     * Le bloc de stats de la phase suivante, ou `null` si la colonne est vide
     * OU si elle nomme une ligne absente du catalogue (donnée de seeder
     * incohérente — mieux vaut une mort normale qu'une exception en pleine
     * résolution de combat).
     */
    public function monstrePhaseSuivante(): ?self
    {
        return $this->phase_suivante === null
            ? null
            : self::where('nom_base', $this->phase_suivante)->first();
    }

    /**
     * Les noms de bloc qui sont la FORME SUIVANTE d'une autre ligne (la cible de
     * `phase_suivante`) : une forme n'est jamais une ENTRÉE de rencontre.
     *
     * ⚠ Sans cette exclusion, la rotation du boss final tirait les formes elles-
     * mêmes : chaque forme porte le même `archetype_lanceur` que sa première phase
     * (c'est lui qui garde le répertoire de sorts à travers le changement), donc
     * Demonspider et Demonape étaient des candidats `boss` du thème Jungles au
     * même titre que Gretzl — un groupe pouvait affronter le troisième Gretzl sans
     * ses deux premières phases. La rencontre ne nomme que la première phase ; les
     * autres ne s'atteignent qu'en jeu, par `phase_suivante`.
     *
     * @return list<string>
     */
    public static function nomsDeFormeSuivante(): array
    {
        return self::query()
            ->whereNotNull('phase_suivante')
            ->distinct()
            ->pluck('phase_suivante')
            ->values()
            ->all();
    }
}
