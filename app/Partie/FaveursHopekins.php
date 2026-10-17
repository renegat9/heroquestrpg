<?php

declare(strict_types=1);

namespace App\Partie;

use App\Engine\Des\LanceurDes;
use App\Models\EtatPersonnageQuete;
use App\Models\Groupe;
use App\Models\GroupeMercenaire;
use App\Models\InstanceMonstre;
use App\Models\Objet;
use App\Models\Personnage;
use App\Models\PersonnageFaveur;
use App\Models\PersonnageVisiteHopekins;
use App\Models\Quete;
use App\Models\Sort;
use App\Support\Journal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Faveurs de Hopekins Rest (livret G1504 p. 22-23, Wizards of Morcar) —
 * chantier 1c, décision de René du 2026-10-06 : « récompense de quête
 * séparée, hors arbre de talents — une faveur offerte au groupe à la fin de
 * CERTAINES quêtes, comme le livret après la quête 2 ».
 *
 * Les NEUF lieux du livret, portés (René, 2026-10-15) : les CINQ compétences
 * transcrites mot pour mot (`reference/18_extensions.md` §Wizards of Morcar —
 * cartes TRANSCRITES, §7 : Deadeye, Weapon Expert, Healing Hands, Hold the Line,
 * Peacekeeper) et les QUATRE faveurs mécaniques du lieu, sourcées en toutes lettres :
 *  - *Mercenary Guild* — « Recruit a Mercenary without paying their cost » ;
 *  - *Dewdrop Inn* — « Raise your hero's max Body Points by 2 » ;
 *  - *Witch's Hut* — « Gain 1 Potion of Healing. » (« Can be gained multiple times. ») ;
 *  - *Arcane Library* — « you may take an additional set of spells that you are eligible
 *    to cast. This boon is only for spellcasting heroes. »
 *
 * RÈGLE DE CHOIX — AU HUB, par chaque joueur, pour SON héros (René, 2026-10-16 : « Choisissons la
 * faveur au hub si les extensions qui les utilisent sont utilisées »). Le livret (G1504 p. 21 et 23) :
 * « At the start of each quest, a hero may select one location to visit and receive its Boon of
 * Heroism (if available). » Le TIRAGE au hasard de fin de quête (décisions du 2026-10-06 et du
 * 2026-10-15) est RETIRÉ. Une campagne qui ne joue pas Wizards of Morcar n'en reçoit AUCUNE, et rien
 * n'est affiché ({@see self::campagneAvecMorcar()}, le seul prédicat). Une campagne qui le joue : le
 * hub qui suit une quête achevée ouvre un TOUR, où chaque héros actif choisit UN lieu, ou aucun
 * ({@see self::choisirLieu()}). Le choix est EN BASE (`personnage_visites_hopekins`), la liste est
 * publiée par le serveur (`lieuxPour()`), et le tour se ferme au départ de la quête suivante.
 *
 * DURÉE — suivre le livret (René, 2026-10-16). « At the start of each quest, a hero may select one
 * location to visit and receive its Boon of Heroism (if available). A hero may only benefit from
 * one boon at a time. » Et, pour le Mercenary Guild : « This Mercenary returns to the guild if you
 * ever choose another boon. » D'où : une faveur est TENUE jusqu'à ce que ce héros en reçoive une
 * AUTRE. Recevoir une faveur RELÂCHE celles qu'il tient ({@see self::relacherFaveursTenues()}) : elles
 * perdent leur effet dans la même opération (le mercenaire retourne à la guilde, le +2 PV tombe, le
 * répertoire part, la spécialité d'arme se perd), et l'annonce le dit. Le livret ne donne PAS une
 * durée « une quête » : ce n'est pas dans le texte, et la phrase du mercenaire n'aurait alors aucun sens.
 * Une faveur HÉRITÉE (acquise avant ce changement, un héros pouvant en cumuler plusieurs) reste en
 * vigueur jusqu'à la prochaine réception, qui les relâche TOUTES : un héros ne tient qu'une faveur à la
 * fois dès qu'il en reçoit une. Repli écrit, sans migration de données.
 * « Fallen Wardens may always return with new heroes » : un héros ne meurt jamais dans l'application
 * (« tombé, jamais mort », `docs/regles/vocabulaires-effets.md`). Un héros retiré du groupe (`actif` à
 * faux) sort déjà de toutes les listes de choix et de toutes les disponibilités : sa faveur ne bloque plus rien.
 *
 * Ce que « disponible » veut dire, lieu par lieu ({@see self::lieuxPour()}) :
 *  - les CINQ compétences portent « (1 available) » : indisponibles tant qu'un héros actif du groupe la
 *    tient — la carte revient au tas quand son porteur la relâche ;
 *  - *Mercenary Guild*, *Dewdrop Inn*, *Arcane Library* : une par HÉROS, jamais deux fois la même pour
 *    un même héros (« another boon ») ; la bibliothèque ne va qu'à un lanceur à qui il reste un
 *    répertoire à prendre ;
 *  - *Witch's Hut* est INSTANTANÉE et cumulable : elle remet une potion dans le sac, ne se TIENT pas,
 *    donc ne relâche rien (`INSTANTANEES`). Lecture nommée : « gain » ≠ « boon tenu ».
 * Le lieu n'est donc jamais « épuisé » tant que le catalogue a la potion.
 *
 * Trois choix restent au joueur, tous AU HUB, par le porteur : le LIEU lui-même (ce tour-ci, `choisirLieu()`),
 * puis, si le lieu est Weapon Expert ou Arcane Library, son sous-choix — le TYPE D'ARME (« Select a type
 * of weapon […] when you acquire this Boon », René 2026-10-15 — voir `choisirTypeArme()`) ou le RÉPERTOIRE
 * (voir `choisirRepertoire()`).
 */
final class FaveursHopekins
{
    public const DEADEYE = 'deadeye';

    public const WEAPON_EXPERT = 'weapon_expert';

    public const HEALING_HANDS = 'healing_hands';

    public const HOLD_THE_LINE = 'hold_the_line';

    public const PEACEKEEPER = 'peacekeeper';

    /** « Wandering Monsters' Mercenary Guild » — « Recruit a Mercenary without paying their cost. » */
    public const MERCENARY_GUILD = 'mercenary_guild';

    /** « Dewdrop Inn » — « Raise your hero's max Body Points by 2. » */
    public const DEWDROP_INN = 'dewdrop_inn';

    /** « Witch's Hut » — « Gain 1 Potion of Healing. » (cumulable) */
    public const WITCHS_HUT = 'witchs_hut';

    /** « The Arcane Library » — « an additional set of spells that you are eligible to cast ». */
    public const ARCANE_LIBRARY = 'arcane_library';

    /** « 25 gold coins per monster defeated at the end of that quest » (carte Peacekeeper). */
    public const OR_PAR_MONSTRE = 25;

    /** « Raise your hero's max Body Points by 2 » (Dewdrop Inn). */
    public const BONUS_BODY_MAX = 2;

    /**
     * La « Potion of Healing » du livret = la carte officielle du même nom (500 po,
     * « roll 1 red die and restore that number of Body Points ») — `config/cartes.php`,
     * section potions : `Potion of Healing` → `Potion de guérison`. Un test épingle ce lien.
     */
    public const POTION_DE_LA_SORCIERE = 'Potion de guérison';

    /**
     * La boîte de bestiaire qui OUVRE Hopekins Rest au choix (livret G1504, Wizards of Morcar). Seule
     * boîte à en porter : `reference/18_extensions.md` §7bis et le texte des livrets (`Boon`, `Hopekins`)
     * ne se trouvent que dans G1504.
     */
    public const BOITE_MORCAR = 'wizards_of_morcar';

    /** Les CINQ compétences de héros — celles du livret qui portent « (1 available) ». */
    public const COMPETENCES = [
        self::DEADEYE,
        self::WEAPON_EXPERT,
        self::HEALING_HANDS,
        self::HOLD_THE_LINE,
        self::PEACEKEEPER,
    ];

    /** Vocabulaire fermé — seule source, testée dans les deux sens. */
    public const TOUTES = [
        self::DEADEYE,
        self::WEAPON_EXPERT,
        self::HEALING_HANDS,
        self::HOLD_THE_LINE,
        self::PEACEKEEPER,
        self::MERCENARY_GUILD,
        self::DEWDROP_INN,
        self::WITCHS_HUT,
        self::ARCANE_LIBRARY,
    ];

    /**
     * Faveurs qui s'ACCOMPLISSENT sur le coup et n'écrivent aucune ligne de `personnage_faveurs` :
     * leur état durable est ailleurs (la potion, dans le sac). Une ligne n'aurait aucun lecteur —
     * et, unique sur `(personnage_id, cle)`, empêcherait justement le « cumulable » de la carte.
     */
    public const INSTANTANEES = [self::WITCHS_HUT];

    public const LIBELLES = [
        self::DEADEYE => 'Deadeye',
        self::WEAPON_EXPERT => 'Weapon Expert',
        self::HEALING_HANDS => 'Healing Hands',
        self::HOLD_THE_LINE => 'Hold the Line',
        self::PEACEKEEPER => 'Peacekeeper',
        self::MERCENARY_GUILD => 'Mercenary Guild',
        self::DEWDROP_INN => 'Dewdrop Inn',
        self::WITCHS_HUT => 'Witch\'s Hut',
        self::ARCANE_LIBRARY => 'Arcane Library',
    ];

    /**
     * Phrase de DURÉE commune aux faveurs TENUES (livret G1504 p. 22-23 : « A hero may only benefit
     * from one boon at a time » ; relâchée par `relacherFaveursTenues()` à la réception d'une autre).
     */
    public const TENUE = 'Tenue jusqu\'à ce que vous receviez une autre faveur.';

    /**
     * Effet de chaque faveur EN CLAIR, pour la fiche du héros et l'annonce au
     * hub (« nom + effet », décision de portage 2026-10-08). Traduit de la carte
     * (`reference/18_extensions.md` §7), mais chaque phrase décrit ce que le
     * MOTEUR fait, pas ce que la carte promet : Hold the Line ne lance qu'un dé de
     * combat. Vocabulaire fermé : même clés que `TOUTES`, testé.
     */
    public const EFFETS = [
        self::DEADEYE => 'Les figures (héros et monstres) ne bloquent pas votre ligne de vue pour attaquer ou lancer un sort. ' . self::TENUE,
        self::WEAPON_EXPERT => '+1 dé d\'attaque avec le type d\'arme que vous choisissez en recevant la faveur (au hub, entre deux quêtes). ' . self::TENUE,
        self::HEALING_HANDS => 'Si un héros adjacent tombe à 0 PV, il peut boire une de vos potions de soin au lieu de la sienne. ' . self::TENUE,
        self::HOLD_THE_LINE => 'Quand un monstre s\'éloigne des 8 cases qui vous entourent, un crâne au dé de combat lui inflige 1 PV de Body. ' . self::TENUE,
        self::PEACEKEEPER => '25 po à la bourse commune pour chaque monstre que vous réduisez à 0 PV. ' . self::TENUE,
        self::MERCENARY_GUILD => 'Vous engagez gratuitement le mercenaire de votre choix (au hub, panneau de recrutement) : son prix d\'engagement n\'est pas payé, son entretien de 10 po par quête reste dû. Mort ou parti, un autre vous est offert. ' . self::TENUE . ' Le mercenaire retourne alors à la guilde.',
        self::DEWDROP_INN => '+2 PV de Body maximum, tant que vous tenez cette faveur. ' . self::TENUE,
        self::WITCHS_HUT => 'Une Potion de guérison est remise à ce héros, tout de suite (elle ne se tient pas). La faveur peut se répéter.',
        self::ARCANE_LIBRARY => 'Un répertoire de sorts supplémentaire, que vous choisissez au hub, parmi ceux que vous pouvez lancer (réservé aux lanceurs de sorts). ' . self::TENUE . ' Le répertoire part avec elle.',
    ];

    public function __construct(
        private readonly LanceurDes $des,
        private readonly MoteurDegats $degats,
    ) {}

    public function possede(Personnage $personnage, string $cle): bool
    {
        return $this->faveur($personnage, $cle) !== null;
    }

    public function faveur(Personnage $personnage, string $cle): ?PersonnageFaveur
    {
        // Chargée une fois par héros (plusieurs lecteurs par tour la
        // relisent — attaque, menu, sort) : `loaded()` évite une requête par
        // appel sans jamais risquer une faveur périmée (elle n'est JAMAIS
        // réécrite une fois acquise, hors le `parametre` encore vide de
        // Weapon Expert, que `choisirTypeArme()` renseigne puis fait relire).
        if (! $personnage->relationLoaded('faveurs')) {
            $personnage->load('faveurs');
        }

        return $personnage->faveurs->firstWhere('cle', $cle);
    }

    /** Effet en clair d'une faveur (repli sur la clé brute : jamais de trou silencieux). */
    public static function effet(string $cle): string
    {
        return self::EFFETS[$cle] ?? $cle;
    }

    /**
     * POINT DE PASSAGE UNIQUE de la forme publiée d'une faveur (fiche du héros
     * en quête via `EtatGroupe.entites[].faveurs`, fiche du héros au hub via
     * `/moi`) : la liste n'est écrite nulle part ailleurs. L'effet est relu ici,
     * au moment de publier, et non figé dans le journal : un texte corrigé
     * s'affiche aussitôt sur toutes les faveurs déjà acquises.
     *
     * *Weapon Expert* porte en plus sa DÉCISION, jamais ses ingrédients :
     *  - `specialite` : le type d'arme choisi, sous son nom d'armurerie (`null` tant
     *    qu'aucun n'est choisi) ;
     *  - `choix` : la LISTE BLANCHE du choix à faire (`[{objet_id, nom, des_attaque}]`),
     *    `null` quand il n'y a rien à choisir. `$choixOuvert` dit si le choix est
     *    permis ICI (au hub : `PUT …/faveurs/weapon-expert` le refuse ailleurs) — la
     *    manette n'a donc ni à connaître la phase ni à relire le catalogue d'armes.
     *
     * *Arcane Library* publie de même `repertoire` (l'élément pris) et `choix`
     * (`[{element, sorts: [nom…]}]`) ; *Mercenary Guild* publie `recrutement_offert` (bool).
     *
     * @return list<array<string, mixed>>
     */
    public static function publier(Personnage $personnage, bool $choixOuvert = false): array
    {
        $personnage->loadMissing('faveurs');

        return $personnage->faveurs
            ->map(function (PersonnageFaveur $f) use ($personnage, $choixOuvert) {
                $entree = [
                    'cle' => $f->cle,
                    'libelle' => self::LIBELLES[$f->cle] ?? $f->cle,
                    'effet' => self::effet($f->cle),
                ];

                if ($f->cle === self::WEAPON_EXPERT) {
                    $specialite = self::typeChoisi($f);
                    $entree['specialite'] = $specialite;
                    $entree['choix'] = null;

                    if ($f->parametre === null && $choixOuvert) {
                        $options = self::typesArmeChoisissables($personnage);
                        $entree['choix'] = $options->isEmpty() ? null : $options->map(fn (Objet $o) => [
                            'objet_id' => (int) $o->id,
                            'nom' => (string) $o->nom,
                            'des_attaque' => (int) ($o->effet['des_attaque'] ?? 0),
                        ])->values()->all();
                    }
                }

                // *Arcane Library* : même patron que Weapon Expert — le répertoire pris (`null`
                // tant qu'aucun) et, au hub tant qu'il reste à choisir, la LISTE BLANCHE.
                if ($f->cle === self::ARCANE_LIBRARY) {
                    $entree['repertoire'] = $f->parametre;
                    $entree['choix'] = null;

                    if ($f->parametre === null && $choixOuvert) {
                        $options = self::repertoiresChoisissables($personnage);
                        $entree['choix'] = $options === [] ? null : array_map(fn (string $element) => [
                            'element' => $element,
                            'sorts' => Sort::query()->where('element', $element)->orderBy('id')->pluck('nom')->all(),
                        ], $options);
                    }
                }

                // *Mercenary Guild* : la DÉCISION (« ai-je un recrutement gratuit à prendre ? »),
                // jamais ses ingrédients — la fiche ne relit ni le recrutement ni le groupe.
                if ($f->cle === self::MERCENARY_GUILD) {
                    $entree['recrutement_offert'] = self::mercenaireGratuitDisponible($personnage, (int) $personnage->groupe_actif_id);
                }

                return $entree;
            })
            ->values()
            ->all();
    }

    // ---- DEADEYE -----------------------------------------------------
    //
    // « Monster and heroes do not block your line of sight when attacking
    // or casting spells. » Lève le blocage de ligne de vue PAR LES FIGURES
    // (jamais les murs/portes/meubles, intouchés) pour CE héros précisément
    // — chaque appelant lit déjà `figuresBloquent: true` en dur à l'un des
    // 5 points de passage de l'attaque/du sort d'un héros (menu de tir,
    // menu de sort, `frapper()`, `verifierLigneDeVueSort()`) ; cette méthode
    // est l'UNIQUE endroit qui décide s'il faut ou non lire `true`.

    /** `figuresBloquent` à passer à `Grille::ligneDeVue()`/`ligneDeVueEmprise()` pour CE héros. */
    public function figuresBloquentPour(?Personnage $personnage): bool
    {
        return $personnage === null || ! $this->possede($personnage, self::DEADEYE);
    }

    // ---- WEAPON EXPERT -------------------------------------------------
    //
    // « Select a type of weapon […] when you acquire this Boon. When
    // attacking with a weapon of that type, roll 1 additional Attack
    // dice. » (livret G1504 p. 22-23.)
    //
    // DÉCISION DE RENÉ (2026-10-15) : le JOUEUR choisit le type d'arme AU MOMENT où
    // il obtient la faveur. Ce moment est la fin d'une quête réussie
    // (le hub qui suit une quête réussie : le LIEU se choisit là aussi, `choisirLieu()`,
    // René 2026-10-16), donc le choix du type se fait AU HUB, entre deux quêtes :
    //   - la faveur naît avec `parametre = null` ;
    //   - `/moi` publie la liste blanche (`publier()`, clé `choix`) à son porteur, et
    //     `PUT /groupes/{id}/faveurs/weapon-expert` ({@see self::choisirTypeArme()}) la
    //     revalide, écrit `parametre` EN BASE et le fige : on ne change pas de
    //     spécialité, la carte dit « when you acquire » ;
    //   - `POST /pret` refuse à CE héros de se déclarer prêt tant qu'il n'a pas
    //     tranché (même patron que le panier de marché non confirmé : on ne bloque
    //     que celui dont le choix manque, et il suffit qu'il le fasse).
    //
    // LA LISTE vient du catalogue, jamais d'une liste recopiée : les armes de
    // l'ARMURERIE (ni artefact `unique`, ni copie en os) que ce héros PEUT manier
    // (`Equipement::estAccessible()` — offrir à un magicien une hache de bataille
    // serait lui vendre un bonus qu'il ne touchera jamais).
    //
    // « UN TYPE » = `Equipement::typeDArme()` : le Fléau des Orques est une épée
    // courte, la hache de bataille en os une hache de bataille. `parametre` garde
    // l'id de l'arme d'armurerie choisie (la même forme que l'ancien lien paresseux).
    //
    // REPLI — une faveur dont le type n'est pas choisi reste JOUABLE : faveurs
    // acquises avant ce changement, ou dont le joueur n'a rien choisi (la table lance
    // la quête sans passer par `/pret`). Le PREMIER type d'arme dont ce héros frappe
    // devient alors sa spécialité (l'ancien comportement, conservé tel quel) — jamais
    // « aucun bonus », qui aurait rendu muette une faveur déjà gagnée. Une faveur
    // dont l'ancien code a déjà lié une arme PRÉCISE garde ce lien, élargi à tout son
    // type (une épée courte liée vaut désormais pour toutes les épées courtes).

    /**
     * Armes d'armurerie que ce héros peut choisir comme type — la LISTE BLANCHE du
     * choix (revalidée par `choisirTypeArme()`), dans l'ordre du prix.
     *
     * @return Collection<int, Objet>
     */
    public static function typesArmeChoisissables(Personnage $personnage): Collection
    {
        $equipement = app(Equipement::class);

        return Objet::query()
            ->where('categorie', 'arme')
            ->where('rarete', '!=', 'unique') // les artefacts ont chacun leur type : `est_une`.
            ->whereNull('os_de') // la copie en os est de SON type, elle n'en est pas un.
            ->orderBy('prix_base')
            ->orderBy('id')
            ->get()
            ->filter(fn (Objet $o) => $equipement->estAccessible($personnage, $o))
            ->values();
    }

    /** Ce héros a-t-il un type d'arme à choisir MAINTENANT ? (faveur reçue, type vierge, au moins un choix possible) */
    public static function choixEnAttente(Personnage $personnage): bool
    {
        $personnage->loadMissing('faveurs');
        $faveur = $personnage->faveurs->firstWhere('cle', self::WEAPON_EXPERT);

        return $faveur !== null
            && $faveur->parametre === null
            && self::typesArmeChoisissables($personnage)->isNotEmpty();
    }

    /**
     * Motif pour lequel CE héros ne peut pas encore se déclarer prêt — la visite de son tour de hub
     * (un lieu, ou renoncer), puis un choix de faveur qui se fait une fois, « when you acquire this
     * Boon » — ou `null`. Appelé par `POST /pret` : on ne bloque que le porteur, et UN geste lève le refus.
     */
    public static function refusDeDepart(Groupe $groupe, Personnage $personnage): ?string
    {
        if (self::visiteOuverte($groupe, $personnage) !== null) {
            return "« {$personnage->nom} » peut visiter un lieu de Hopekins Rest avant de partir : choisissez-le (fiche du héros), ou n'en visitez aucun.";
        }

        if (self::choixEnAttente($personnage)) {
            return "« {$personnage->nom} » a reçu la faveur Weapon Expert : choisissez son type d'arme (fiche du héros) avant de partir.";
        }

        if (self::repertoireEnAttente($personnage)) {
            return "« {$personnage->nom} » a reçu la faveur Arcane Library : choisissez son répertoire de sorts supplémentaire (fiche du héros) avant de partir.";
        }

        return null;
    }

    /**
     * Le type d'arme lié à une faveur Weapon Expert, sous son nom d'armurerie —
     * `null` si rien n'est lié (ou si l'arme liée a quitté le catalogue).
     */
    public static function typeChoisi(PersonnageFaveur $faveur): ?string
    {
        if ($faveur->cle !== self::WEAPON_EXPERT || $faveur->parametre === null) {
            return null;
        }

        return app(Equipement::class)->typeDArme(Objet::find((int) $faveur->parametre));
    }

    /**
     * Le joueur choisit le type d'arme de sa faveur. Revalide TOUT côté serveur : le
     * héros porte la faveur, son type est encore vierge, et `$objetId` figure dans la
     * liste blanche publiée (`typesArmeChoisissables()`).
     *
     * @return array{personnage_id: int, objet_id: int, specialite: string}
     */
    public function choisirTypeArme(Groupe $groupe, Personnage $personnage, int $objetId): array
    {
        $faveur = $this->faveur($personnage, self::WEAPON_EXPERT);

        if ($faveur === null) {
            throw ValidationException::withMessages([
                'personnage_id' => "« {$personnage->nom} » n'a pas la faveur Weapon Expert.",
            ]);
        }

        if ($faveur->parametre !== null) {
            throw ValidationException::withMessages([
                'objet_id' => 'Le type d\'arme de cette faveur est déjà choisi : le choix se fait une fois, en la recevant.',
            ]);
        }

        $arme = self::typesArmeChoisissables($personnage)->firstWhere('id', $objetId);

        if ($arme === null) {
            throw ValidationException::withMessages([
                'objet_id' => 'Ce n\'est pas un type d\'arme que ce héros peut choisir.',
            ]);
        }

        $faveur->update(['parametre' => (string) $arme->id]);
        $personnage->unsetRelation('faveurs');

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'faveur_arme_choisie',
            'personnage_id' => (int) $personnage->id,
            'personnage' => $personnage->nom,
            'faveur' => self::WEAPON_EXPERT,
            'arme' => $arme->nom,
        ], ['nom' => $personnage->nom]);

        return [
            'personnage_id' => (int) $personnage->id,
            'objet_id' => (int) $arme->id,
            'specialite' => (string) $arme->nom,
        ];
    }

    /**
     * +1 dé d'attaque si `$arme` est du type choisi ; si le type n'est PAS choisi, le
     * premier type d'arme dont le héros frappe le devient (le repli décrit plus haut).
     * `0` si le héros n'a pas la faveur, ou frappe à mains nues (`$arme` `null` — rien
     * à lier, rien à bonifier).
     */
    public function bonusArmeExperte(Personnage $personnage, ?Objet $arme): int
    {
        $faveur = $this->faveur($personnage, self::WEAPON_EXPERT);

        if ($faveur === null || $arme === null) {
            return 0;
        }

        if ($faveur->parametre === null) {
            $faveur->update(['parametre' => (string) $arme->id]);

            return 1; // la toute première frappe liée profite déjà du bonus.
        }

        $type = self::typeChoisi($faveur);

        return $type !== null && $type === app(Equipement::class)->typeDArme($arme) ? 1 : 0;
    }

    // ---- ARCANE LIBRARY ---------------------------------------------------
    //
    // « Mentor expands your knowledge of arcane mysteries. After heroes have selected spells,
    // you may take an additional set of spells that you are eligible to cast. This boon is only
    // for spellcasting heroes. » (livret G1504 p. 23.)
    //
    // Un « set » est un RÉPERTOIRE au sens du projet (`sorts.element` : une école, un répertoire
    // de classe, un répertoire optionnel de Morcar) — trois sorts, attachés en bloc par
    // `MoteurSorts::attacherElement()`, le même geste que le choix de départ et le rechoix au
    // hub. « Eligible to cast » est lu par `MoteurSorts::repertoiresSupplementaires()` avec les
    // règles des lanceurs, jamais une liste recopiée ici. Comme Weapon Expert : le JOUEUR choisit,
    // au hub, en recevant la faveur ; la liste blanche est publiée (`publier()`, clé `choix`),
    // `PUT …/faveurs/arcane-library` la revalide, écrit `parametre` EN BASE (l'élément pris) et le
    // fige, et `POST /pret` refuse au porteur de partir sans avoir choisi.
    //
    // Le répertoire pris est ensuite un répertoire comme un autre : « Spellcasters may change their
    // spells between quests » (p. 11) vaut pour lui aussi (`MoteurSorts::remplacerElement()`), la
    // faveur n'en garde que la trace — l'ensemble reste, lui, plus gros d'un répertoire.

    /**
     * Répertoires que ce héros peut prendre en plus — la LISTE BLANCHE du choix
     * (revalidée par `choisirRepertoire()`).
     *
     * @return list<string>
     */
    public static function repertoiresChoisissables(Personnage $personnage): array
    {
        return app(MoteurSorts::class)->repertoiresSupplementaires($personnage);
    }

    /** Ce héros a-t-il un répertoire à choisir MAINTENANT ? (faveur reçue, choix vierge, au moins un répertoire à prendre) */
    public static function repertoireEnAttente(Personnage $personnage): bool
    {
        $personnage->loadMissing('faveurs');
        $faveur = $personnage->faveurs->firstWhere('cle', self::ARCANE_LIBRARY);

        return $faveur !== null
            && $faveur->parametre === null
            && self::repertoiresChoisissables($personnage) !== [];
    }

    /**
     * Le joueur choisit le répertoire supplémentaire de sa faveur. Revalide TOUT côté serveur :
     * le héros porte la faveur, son choix est encore vierge, et `$element` figure dans la liste
     * blanche publiée.
     *
     * @return array{personnage_id: int, element: string, sorts: list<string>}
     */
    public function choisirRepertoire(Groupe $groupe, Personnage $personnage, string $element): array
    {
        $faveur = $this->faveur($personnage, self::ARCANE_LIBRARY);

        if ($faveur === null) {
            throw ValidationException::withMessages([
                'personnage_id' => "« {$personnage->nom} » n'a pas la faveur Arcane Library.",
            ]);
        }

        if ($faveur->parametre !== null) {
            throw ValidationException::withMessages([
                'element' => 'Le répertoire de cette faveur est déjà choisi : le choix se fait une fois, en la recevant.',
            ]);
        }

        if (! in_array($element, self::repertoiresChoisissables($personnage), true)) {
            throw ValidationException::withMessages([
                'element' => 'Ce n\'est pas un répertoire que ce héros peut prendre en plus de ses sorts.',
            ]);
        }

        $sorts = DB::transaction(function () use ($faveur, $personnage, $element) {
            $sorts = app(MoteurSorts::class)->attacherElement($personnage, $element);
            $faveur->update(['parametre' => $element]);

            return $sorts;
        });

        $personnage->unsetRelation('faveurs');
        $personnage->unsetRelation('sorts');

        Journal::ajouter($groupe, 'systeme', [
            'action' => 'faveur_repertoire_choisi',
            'personnage_id' => (int) $personnage->id,
            'personnage' => $personnage->nom,
            'faveur' => self::ARCANE_LIBRARY,
            'element' => $element,
            'sorts' => $sorts->pluck('nom')->all(),
        ], ['nom' => $personnage->nom]);

        return [
            'personnage_id' => (int) $personnage->id,
            'element' => $element,
            'sorts' => $sorts->pluck('nom')->all(),
        ];
    }

    // ---- MERCENARY GUILD --------------------------------------------------
    //
    // « Ragnar and the veterans of the guild share stories of their past victories. Ragnar's
    // spirits seem lifted. Recruit a Mercenary without paying their cost. This Mercenary returns
    // to the guild if you ever choose another boon. » (livret G1504 p. 23.)
    //
    // Passe par le recrutement EXISTANT : même route (`POST …/mercenaires`), mêmes garde-fous
    // (hub, Gardien, quatre par héros, animal unique — `RecrutementHub`), même allié ordinaire
    // joué par son maître. Seul le PRIX change : `RecrutementHub::prixPour()` lit
    // `mercenaireGratuitDisponible()` et publie 0 au lieu du catalogue.
    //
    // DURÉE — la faveur est un DROIT à un mercenaire offert « en poste » : `parametre` retient
    // l'id du `groupe_mercenaires` engagé par ce droit. Tant qu'il est vivant et au service de ce
    // héros, le droit est consommé ; mort (purgé en fin de quête) ou parti faute d'entretien, la
    // ligne a disparu et le droit se rouvre. C'est ce que fait le livret en demandant de choisir
    // la guilde à CHAQUE quête (un nouveau mercenaire offert chaque fois qu'il en manque un) ;
    // tenue jusqu'à la réception d'une AUTRE faveur (DURÉE, docblock de classe) : ce que le livret
    // dit de « Returns to the guild if you ever choose another boon » se lit désormais ainsi : la
    // réception d'une autre faveur renvoie le mercenaire à la guilde (`relacherFaveursTenues()`).
    //
    // « Without paying their cost » : le coût d'ENGAGEMENT (« Cost: 75 gold coins » sur la
    // carte). L'ENTRETIEN de 10 po par quête est un autre paiement (p. 8-9, « as long as they are
    // paid 10 gold coins at the end of each quest ») que la carte ne retire pas : l'allié offert
    // par la guilde est donc un suivant ORDINAIRE (`groupe_mercenaires.offert` reste `false` — ce
    // drapeau est celui du compagnon animal d'Against the Ogre Horde, « at no cost » pour tout
    // son séjour). Si René veut l'en exempter, c'est une ligne dans `reglerEntretien()`.
    //
    // « A Mercenary » : un mercenaire, pas un compagnon animal — les animaux sont des « animal
    // allies » d'une autre boîte, avec leur propre règle de gratuité.

    /** Cet allié, recruté au titre de la guilde, est-il toujours au service de CE héros ? */
    public static function mercenaireOffertEnPoste(PersonnageFaveur $faveur, int $groupeId): bool
    {
        if ($faveur->parametre === null) {
            return false;
        }

        return GroupeMercenaire::query()
            ->whereKey((int) $faveur->parametre)
            ->where('groupe_id', $groupeId)
            ->where('recruteur_personnage_id', $faveur->personnage_id)
            ->where('etat', 'actif')
            ->exists();
    }

    /** Ce héros a-t-il, DANS CE GROUPE, un recrutement gratuit à prendre ? */
    public static function mercenaireGratuitDisponible(Personnage $personnage, int $groupeId): bool
    {
        if ($groupeId <= 0) {
            return false;
        }

        $personnage->loadMissing('faveurs');
        $faveur = $personnage->faveurs->firstWhere('cle', self::MERCENARY_GUILD);

        return $faveur !== null && ! self::mercenaireOffertEnPoste($faveur, $groupeId);
    }

    /**
     * Le droit est CONSOMMÉ par ce recrutement : la faveur retient l'allié engagé. À appeler dans
     * la transaction du recrutement, après la création de la ligne.
     */
    public static function lierMercenaireOffert(Personnage $recruteur, GroupeMercenaire $recrue): void
    {
        PersonnageFaveur::query()
            ->where('personnage_id', $recruteur->id)
            ->where('cle', self::MERCENARY_GUILD)
            ->update(['parametre' => (string) $recrue->id]);

        $recruteur->unsetRelation('faveurs');
    }

    // ---- DEWDROP INN ------------------------------------------------------
    //
    // « Friendship, food, merriment, and songs of heroic glory fortify your resolve. Raise your
    // hero's max Body Points by 2. » Une SECONDE source de plafond à côté des talismans : elle
    // passe par `Equipement::apresChangementDeJauges()` / `bonusJauges()`, le point de passage
    // des PV maximum hors montée de niveau — jamais un `increment` à côté. Les PV courants suivent
    // (« adds … to the totals »), et le sac, dont la capacité est PV max ÷ 2, gagne sa place
    // d'office : c'est dérivé, pas ajouté. Tenue (DURÉE, docblock de classe) : tant qu'elle est la faveur
    // du héros, le +2 tient ; relâchée, le plafond redescend et les PV courants s'écrêtent
    // (`apresChangementDeJauges()`). La ligne n'est pas décorative : c'est elle que `bonusJauges()` relit.

    /** Plafond de Body que cette faveur donne à CE héros (0 sans elle). Requête fraîche : jamais la relation en cache. */
    public static function bonusBodyMax(Personnage $personnage): int
    {
        return PersonnageFaveur::query()
            ->where('personnage_id', $personnage->id)
            ->where('cle', self::DEWDROP_INN)
            ->exists() ? self::BONUS_BODY_MAX : 0;
    }

    // ---- WITCH'S HUT ------------------------------------------------------
    //
    // « Gain 1 Potion of Healing. » (« Can be gained multiple times. ») La Potion de guérison du
    // catalogue (`POTION_DE_LA_SORCIERE`) entre dans le sac par le rangement de tous les butins
    // (`RangementObjet::ranger()` : un consommable s'empile, hors capacité du sac). INSTANTANÉE :
    // aucune ligne de faveur — la potion EST l'état durable, et c'est elle que boivent le héros,
    // son voisin (Healing Hands comprise) et le menu.

    /** La potion remise par le Witch's Hut, ou `null` si le catalogue ne l'a pas. */
    private static function potionDeLaSorciere(): ?Objet
    {
        return Objet::query()->where('nom', self::POTION_DE_LA_SORCIERE)->first();
    }

    // ---- HEALING HANDS ---------------------------------------------------
    //
    // « If a hero adjacent to you is reduced to 0 Body points, you may allow
    // them to use one of your available healing potions instead of their
    // own. » Réutilise EXACTEMENT le patron « Passing Items » déjà construit
    // (`MoteurPotions::boire($porteur, $ligne, [], $cible)` — le porteur
    // perd l'exemplaire, la cible encaisse le soin) : cette classe ne fait
    // que DÉSIGNER les aidants éligibles, `MoteurReactions` compose l'offre
    // et la résolution (même lecteur `soinsDisponibles()` que le soin sur
    // soi, juste appelé une fois par aidant en plus du tombé).

    /**
     * Héros DEBOUT, adjacents (Manhattan = 1) au tombé, porteurs de la
     * faveur — candidats à lui prêter une potion. Le tombé lui-même est
     * exclu par construction (`personnage_id !=`).
     *
     * @return Collection<int, array{personnage: Personnage, etat: EtatPersonnageQuete}>
     */
    public function aidantsPotionAdjacents(Quete $quete, EtatPersonnageQuete $etatTombe): Collection
    {
        if ($etatTombe->position_x === null) {
            return collect();
        }

        return $quete->etatsPersonnages()
            ->where('personnage_id', '!=', $etatTombe->personnage_id)
            ->where('tombe', false)
            ->whereNotNull('position_x')
            ->with('personnage')
            ->get()
            ->filter(fn (EtatPersonnageQuete $e) => abs((int) $e->position_x - (int) $etatTombe->position_x)
                    + abs((int) $e->position_y - (int) $etatTombe->position_y) === 1)
            ->filter(fn (EtatPersonnageQuete $e) => $e->personnage !== null
                && $this->possede($e->personnage, self::HEALING_HANDS))
            ->map(fn (EtatPersonnageQuete $e) => ['personnage' => $e->personnage, 'etat' => $e])
            ->values();
    }

    /**
     * Revalide un aidant désigné par une offre déjà déposée (la situation a
     * pu changer entre la proposition et la réponse — même garde que pour
     * toute autre réaction). `null` si l'aidant n'est plus adjacent, est
     * tombé entre-temps, ou a perdu la faveur (ce qui n'arrive jamais
     * aujourd'hui, mais le lecteur reste honnête).
     */
    public function validerAidantPotion(Quete $quete, EtatPersonnageQuete $etatTombe, int $aidantPersonnageId): ?Personnage
    {
        return $this->aidantsPotionAdjacents($quete, $etatTombe)
            ->first(fn (array $a) => $a['personnage']->id === $aidantPersonnageId)['personnage'] ?? null;
    }

    // ---- HOLD THE LINE ---------------------------------------------------
    //
    // « Each time a monster on Zargon's turn moves away from the 8 spaces
    // immediately surrounding you, roll a combat die. If you roll a skull,
    // inflict 1 Body Point of damage on the retreating monster. » La SEULE
    // exception nommée à C3 (« aucune attaque d'opportunité », doc 03,
    // Grille.php) — explicitement une carte, jamais la règle générale.
    // Dégât FIXE, non résistable (aucune défense mentionnée par la carte) :
    // même motif que « Death Bolt » (sort de Sorcier du Dread) plutôt que
    // `Combat::resoudreAttaque()`.

    /**
     * Un pas de monstre vient de s'achever ($avant → position courante de
     * `$instance`) : chaque héros porteur qui l'avait à portée de 8 cases
     * avant et plus après tente sa frappe. 0..N payloads (plusieurs héros
     * porteurs peuvent réagir au MÊME pas).
     *
     * @param  array{x: int|null, y: int|null}  $avant
     * @return list<array<string, mixed>>
     */
    public function tenterHoldTheLine(Groupe $groupe, Quete $quete, InstanceMonstre $instance, array $avant): array
    {
        if ($avant['x'] === null || $instance->position_x === null || $instance->etat !== 'actif') {
            return [];
        }

        $adjacente = fn (int $hx, int $hy, int $mx, int $my): bool => max(abs($hx - $mx), abs($hy - $my)) === 1;

        $porteurs = $quete->etatsPersonnages()
            ->where('tombe', false)
            ->whereNotNull('position_x')
            ->with('personnage')
            ->get()
            ->filter(fn (EtatPersonnageQuete $e) => $e->personnage !== null
                && $this->possede($e->personnage, self::HOLD_THE_LINE))
            ->filter(fn (EtatPersonnageQuete $e) => $adjacente((int) $e->position_x, (int) $e->position_y, $avant['x'], $avant['y'])
                && ! $adjacente((int) $e->position_x, (int) $e->position_y, (int) $instance->position_x, (int) $instance->position_y));

        $actions = [];

        foreach ($porteurs as $etat) {
            $face = $this->des->desCombat(1)[0];
            $touche = $face->estCrane();

            $payload = [
                'type' => 'faveur_hold_the_line',
                'action' => 'hold_the_line',
                'personnage' => $etat->personnage->nom,
                'monstre' => $instance->nomAffiche(),
                'face' => $face->value,
                'touche' => $touche,
                'degats' => 0,
            ];

            if ($touche) {
                // `$etat->personnage` comme AUTEUR : ce porteur frappe de sa
                // propre carte (« you may... inflict... damage »), au même
                // titre qu'un coup d'arme — s'il tient AUSSI Peacekeeper, le
                // monstre qu'il achève ainsi le crédite, par le même point
                // de passage que tout le reste.
                $resultatMort = $this->degats->infligerAMonstre(
                    $instance, 1, MoteurDegats::SOURCE_FAVEUR_HOLD_THE_LINE,
                    ['faveur' => self::HOLD_THE_LINE, 'personnage' => $etat->personnage->nom],
                    $etat->personnage,
                );
                $payload['degats'] = 1;
                $payload['pv_body_apres'] = $resultatMort['pv_body'];
                $payload['vaincu'] = $resultatMort['vaincu'];
            }

            Journal::ajouter($groupe, 'combat', $payload, ['nom' => $etat->personnage->nom]);
            $actions[] = $payload;
        }

        return $actions;
    }

    // ---- PEACEKEEPER ------------------------------------------------------
    //
    // « Keep track of the number of monsters you reduce to 0 Body Points for
    // each quest. For your service, the Realm rewards you with 25 gold
    // coins per monster defeated the end of that quest. » La carte paie « at
    // the end of that quest » et le code fait de même — plus rien à arbitrer :
    // chaque mise à mort est COMPTÉE sur la ligne du héros
    // (`etat_personnages_quete.monstres_vaincus`, durable, remise à zéro à
    // chaque quête : `compterPeacekeeper()`), et l'or se verse à la fin d'une
    // quête GAGNÉE (`reglerPeacekeeper()`, appelé par
    // `ResolveurTour::terminerQuete()` seulement). Une quête perdue ne verse
    // rien ; une reprise (`POST /reprise`) restaure le snapshot `debut_quete`,
    // compteur compris : jamais compté deux fois. L'écart d'interprétation du
    // 2026-10-08 (payer À LA MISE À MORT, sans compteur) est clos : aligné sur la
    // carte, `docs/regles/exploration-et-fouille.md`.
    //
    // CÂBLÉ (2026-10-08) au seul point de passage de la mort d'un monstre,
    // `MoteurDegats::infligerAMonstre()` — plus aux deux branchements
    // d'origine (2026-10-06 : arme au contact/à distance, sort à cible
    // unique) qui ne couvraient que 2 des 12 chemins de dégâts et laissaient
    // la flèche de Vindication, l'eau bénite, le Toucher du Brasier et les
    // sorts de zone muets. Cette méthode reste le lecteur du vocabulaire
    // (qui porte la faveur, combien), mais n'est plus appelée QUE depuis
    // `infligerAMonstre()` (résolu paresseusement par le conteneur pour
    // casser le cycle de construction — voir son docblock) — jamais
    // directement par un appelant de `ResolveurTour`/`MoteurDread` : ce
    // serait recréer le défaut que ce chantier corrige.
    //
    // ⚠ Pas crédité quand `$personnage` est `null` (allié, piège, sort du
    // Dread, monstre sur monstre — voir `infligerAMonstre()`) : la carte dit
    // « you », jamais un auxiliaire ni un mécanisme impersonnel.

    /**
     * PEACEKEEPER, au moment de la mise à mort : si `$vaincu` et que `$personnage`
     * porte la faveur, ce monstre est COMPTÉ pour sa quête (`monstres_vaincus` de la
     * ligne du héros, durable et remis à zéro à chaque quête) et l'annonce part au
     * fil. RIEN n'est versé ici : la carte dit « at the end of that quest », l'or
     * se verse à la fin d'une quête RÉUSSIE ({@see self::reglerPeacekeeper()}).
     *
     * `null` si rien n'est compté (monstre pas achevé, pas de faveur, pas de héros).
     *
     * @return array<string, mixed>|null
     */
    public function compterPeacekeeper(
        Groupe $groupe,
        InstanceMonstre $instance,
        bool $vaincu,
        ?Personnage $personnage,
    ): ?array {
        if (! $vaincu || $personnage === null || ! $this->possede($personnage, self::PEACEKEEPER)) {
            return null;
        }

        $etat = EtatPersonnageQuete::where('quete_id', $instance->quete_id)
            ->where('personnage_id', $personnage->id)
            ->first();

        if ($etat === null) {
            return null;
        }

        $etat->increment('monstres_vaincus');

        $payload = [
            'type' => 'faveur_peacekeeper',
            'action' => 'peacekeeper',
            'personnage' => $personnage->nom,
            'monstre' => $instance->nomAffiche(),
            'vaincus_quete' => (int) $etat->monstres_vaincus,
            'or_en_attente' => self::OR_PAR_MONSTRE,
        ];

        // Journal `combat` (et non `systeme`) : c'est le fil de combat qui rend
        // la ligne, relue à la reconnexion ; un `systeme` n'est lu par aucun
        // écran de jeu. Le tampon la porte aussi dans le résultat de l'action,
        // pour le fil en direct (`.combat.journal`).
        Journal::ajouter($groupe, 'combat', $payload, ['nom' => $personnage->nom]);
        app(TamponFaveurs::class)->ajouter($payload);

        return $payload;
    }

    /**
     * PEACEKEEPER, à la fin d'une quête RÉUSSIE : 25 po par monstre que CE héros a
     * réduit à 0 PV pendant CETTE quête, versés à la bourse commune. Appelé par
     * `ResolveurTour::terminerQuete()` uniquement — jamais par `echouerQuete()` :
     * un TPK ne touche pas la bourse. `null` si aucun héros n'a rien à percevoir.
     *
     * @return array<string, mixed>|null
     */
    public function reglerPeacekeeper(Groupe $groupe, Quete $quete): ?array
    {
        $versements = [];

        EtatPersonnageQuete::where('quete_id', $quete->id)
            ->where('monstres_vaincus', '>', 0)
            ->with('personnage')
            ->orderBy('id')
            ->get()
            ->each(function (EtatPersonnageQuete $etat) use (&$versements): void {
                $personnage = $etat->personnage;

                if ($personnage === null || ! $this->possede($personnage, self::PEACEKEEPER)) {
                    return;
                }

                $versements[] = [
                    'personnage_id' => (int) $personnage->id,
                    'nom' => (string) $personnage->nom,
                    'monstres' => (int) $etat->monstres_vaincus,
                    'or' => (int) $etat->monstres_vaincus * self::OR_PAR_MONSTRE,
                ];
            });

        if ($versements === []) {
            return null;
        }

        $total = array_sum(array_column($versements, 'or'));
        $groupe->increment('or', $total);

        // `quete_id` : l'annonce du hub ne vaut que pour la DERNIÈRE quête achevée
        // (`EtatGroupe::annonceDeQuete()`), comme l'entretien et la faveur.
        $payload = [
            'type' => 'peacekeeper_quete',
            'action' => 'peacekeeper_quete',
            'quete_id' => (int) $quete->id,
            'or_total' => $total,
            'versements' => $versements,
        ];

        Journal::ajouter($groupe, 'systeme', $payload);

        return $payload;
    }

    // ---- CHOIX AU HUB (Wizards of Morcar, René 2026-10-16) ------------------
    //
    // Le livret (G1504 p. 21 et 23) : « At the start of each quest, a hero may select one location to
    // visit and receive its Boon of Heroism (if available). A hero may only benefit from one boon at a
    // time. » Chaque joueur choisit pour SON héros, à chaque tour de hub ; « may » : renoncer est permis.
    //
    // UN TOUR (`tourDeHub()`) = le hub qui suit la quête ACHEVÉE la plus récente, une fois Gardien, dans
    // une campagne Morcar, tant que ce tour n'est pas tranché par la migration (repli, voir sa docblock).
    // Le tour se ferme au départ de la quête suivante. Un héros décide UNE fois par tour, EN BASE
    // (`personnage_visites_hopekins`) : un lieu, ou aucun.
    //
    // LA LISTE BLANCHE est publiée (`lieuxPour()` : tous les lieux, `disponible` et `motif`), et
    // `PUT …/faveurs/lieu` (`choisirLieu()`) la revalide : une route ne prend jamais un lieu que le hub
    // n'offre pas. Un lieu choisi REMPLACE la faveur tenue (DURÉE, docblock de classe) : `remettre()`
    // relâche et pose dans la même opération, et l'annonce le dit.

    /**
     * La campagne joue-t-elle Wizards of Morcar ? LE SEUL prédicat du choix au hub : le bestiaire du
     * groupe (automatique ou boîtes cochées), lu par `BestiaireGroupe`, point de passage unique.
     */
    public static function campagneAvecMorcar(Groupe $groupe): bool
    {
        return BestiaireGroupe::duGroupe($groupe)->contient(self::BOITE_MORCAR);
    }

    /**
     * La quête achevée après laquelle le hub OUVRE un tour de visite, ou `null`. Ouvert seulement au hub,
     * une fois Gardien, dans une campagne Morcar, et si cette quête n'est pas déjà tranchée par la
     * migration (`groupes.hopekins_quete_tranchee_id`, repli des campagnes en cours).
     *
     * JAMAIS après la VICTOIRE FINALE (`Groupe::victoireFinaleAcquise()`, mesuré 2026-10-16) : ni fenêtre de
     * clôture ouverte, ni arc épuisé après `DELETE /cloture`. Une campagne qui se clôt n'a rien à visiter, et
     * ce choix s'affichait sans effet. Ce prédicat est lu aussi par `choisirLieu()` (la route refuse) et par
     * la publication (`/moi`, `/etat`), qui passent toutes par cette méthode.
     */
    public static function tourDeHub(Groupe $groupe): ?Quete
    {
        if ($groupe->phase !== 'hub'
            || $groupe->victoireFinaleAcquise()
            || ! $groupe->estGardien()
            || ! self::campagneAvecMorcar($groupe)) {
            return null;
        }

        $quete = $groupe->quetes()->where('etat', 'terminee')->orderByDesc('id')->first();

        if ($quete === null || (int) $groupe->hopekins_quete_tranchee_id === (int) $quete->id) {
            return null;
        }

        return $quete;
    }

    /**
     * LA liste du choix de CE héros au tour : les neuf lieux, dans l'ordre de `TOUTES`, chacun avec
     * `disponible` et, quand il ne l'est pas, le `motif` que le hub affiche. POINT DE PASSAGE UNIQUE de
     * l'éligibilité — la règle de chaque carte tient ici et nulle part ailleurs :
     *  - les CINQ compétences « (1 available) » : indisponibles dès qu'un héros ACTIF du groupe les tient ;
     *  - *Mercenary Guild*, *Dewdrop Inn*, *Arcane Library* : une fois par héros (« Vous la tenez déjà. ») ;
     *  - *Arcane Library* : seulement à un lanceur à qui il reste un répertoire à prendre ;
     *  - *Witch's Hut* : toujours, tant que la potion est au catalogue (cumulable, ne se tient pas).
     *
     * @return list<array{cle: string, libelle: string, effet: string, disponible: bool, motif: ?string}>
     */
    public static function lieuxPour(Groupe $groupe, Personnage $personnage): array
    {
        $heros = $groupe->personnages()->wherePivot('actif', true)->get(['personnages.id', 'personnages.nom']);
        $noms = $heros->pluck('nom', 'id');

        /** @var Collection<string, Collection<int, int>> $porteurs clé => ids des héros ACTIFS qui la tiennent */
        $porteurs = PersonnageFaveur::query()
            ->whereIn('personnage_id', $heros->pluck('id'))
            ->get(['personnage_id', 'cle'])
            ->groupBy('cle')
            ->map(fn (Collection $lignes) => $lignes->pluck('personnage_id')->map(fn ($id) => (int) $id));

        $potion = self::potionDeLaSorciere() !== null;
        $lanceur = self::repertoiresChoisissables($personnage) !== [];
        $lieux = [];

        foreach (self::TOUTES as $cle) {
            $tenues = $porteurs->get($cle, collect());
            $moi = $tenues->contains((int) $personnage->id);

            [$disponible, $motif] = match (true) {
                $cle === self::WITCHS_HUT => $potion
                    ? [true, null]
                    : [false, 'La Potion de guérison n\'est pas au catalogue.'],
                in_array($cle, self::COMPETENCES, true) && $tenues->isNotEmpty() => $moi
                    ? [false, 'Vous la tenez déjà.']
                    : [false, 'Tenue par '.($noms[$tenues->first()] ?? 'un autre héros').'.'],
                $moi => [false, 'Vous la tenez déjà.'],
                $cle === self::ARCANE_LIBRARY && ! $lanceur => [false, 'Réservée aux lanceurs de sorts, à qui il reste un répertoire à prendre.'],
                default => [true, null],
            };

            $lieux[] = [
                'cle' => $cle,
                'libelle' => self::LIBELLES[$cle],
                'effet' => self::EFFETS[$cle],
                'disponible' => $disponible,
                'motif' => $motif,
            ];
        }

        return $lieux;
    }

    /** Ce héros a-t-il DÉJÀ décidé à ce tour (un lieu, ou aucun) ? */
    public static function visiteDecidee(Personnage $personnage, Quete $tour): bool
    {
        return PersonnageVisiteHopekins::query()
            ->where('personnage_id', $personnage->id)
            ->where('quete_id', $tour->id)
            ->exists();
    }

    /**
     * La visite que CE héros doit faire à ce hub — `['quete_id' => …, 'lieux' => […]]` — ou `null` : pas de
     * tour ouvert, héros inactif, décision déjà prise, ou aucun lieu ouvert pour lui. Publiée par `/moi`
     * (`joueur.personnages[].visite_hopekins`) et lue par `POST /pret` (`refusDeDepart()`).
     *
     * @return array{quete_id: int, lieux: list<array<string, mixed>>}|null
     */
    public static function visiteOuverte(?Groupe $groupe, Personnage $personnage): ?array
    {
        if ($groupe === null) {
            return null;
        }

        $tour = self::tourDeHub($groupe);

        if ($tour === null) {
            return null;
        }

        $actif = $groupe->personnages()->wherePivot('actif', true)->where('personnages.id', $personnage->id)->exists();

        if (! $actif || self::visiteDecidee($personnage, $tour)) {
            return null;
        }

        $lieux = self::lieuxPour($groupe, $personnage);

        if (! collect($lieux)->contains('disponible', true)) {
            return null;
        }

        return ['quete_id' => (int) $tour->id, 'lieux' => $lieux];
    }

    /**
     * Le héros VISITE un lieu de Hopekins Rest à ce tour (`$lieu`, une clé de `TOUTES`), ou RENONCE (`null`,
     * « may »). Revalide tout côté serveur : tour ouvert, décision encore vierge, lieu dans la liste blanche
     * (`lieuxPour()`). Le contrôleur a déjà vérifié que le héros est actif et qu'il est à ce joueur.
     *
     * Un lieu REMPLACE la faveur tenue : `remettre()` relâche celles qu'il tient et pose la nouvelle dans la
     * même opération. Écrit EN BASE (`personnage_visites_hopekins`, `personnage_faveurs`) ; journal `systeme`
     * `visite_hopekins` `{quete_id, personnage_id, personnage, lieu, detail}`, où `detail` dit ce qui a pris
     * fin et ce que la faveur fait (`null` pour une compétence sans relâche).
     *
     * @return array{personnage_id: int, lieu: ?string, detail: ?string}
     */
    public function choisirLieu(Groupe $groupe, Personnage $personnage, ?string $lieu): array
    {
        $tour = self::tourDeHub($groupe);

        if ($tour === null) {
            throw ValidationException::withMessages([
                'phase' => $groupe->victoireFinaleAcquise()
                    ? 'La campagne est gagnée : Hopekins Rest ne reçoit plus de visite.'
                    : 'Aucune visite de Hopekins Rest n\'est ouverte pour ce groupe entre ces quêtes.',
            ]);
        }

        if ($lieu !== null && ! in_array($lieu, self::TOUTES, true)) {
            throw ValidationException::withMessages(['lieu' => 'Ce n\'est pas un lieu de Hopekins Rest.']);
        }

        return DB::transaction(function () use ($groupe, $personnage, $tour, $lieu) {
            // Deux manettes ne posent pas leur choix dans la même seconde : le verrou de groupe sérialise les
            // écritures ; la contrainte unique (héros, quête) reste le dernier rempart.
            Groupe::query()->whereKey($groupe->id)->lockForUpdate()->first();

            if (self::visiteDecidee($personnage, $tour)) {
                throw ValidationException::withMessages([
                    'lieu' => 'Ce héros a déjà choisi son lieu pour cette quête.',
                ]);
            }

            if ($lieu === null) {
                $detail = "{$personnage->nom} ne visite aucun lieu.";
            } else {
                $ouvert = collect(self::lieuxPour($groupe, $personnage))->firstWhere('cle', $lieu);

                if ($ouvert === null || ! $ouvert['disponible']) {
                    throw ValidationException::withMessages([
                        'lieu' => $ouvert['motif'] ?? 'Ce lieu n\'est pas ouvert à ce héros.',
                    ]);
                }

                $detail = $this->remettre($personnage, $lieu);
            }

            PersonnageVisiteHopekins::query()->create([
                'personnage_id' => $personnage->id,
                'quete_id' => $tour->id,
                'lieu' => $lieu,
            ]);

            Journal::ajouter($groupe, 'systeme', [
                'action' => 'visite_hopekins',
                'quete_id' => (int) $tour->id,
                'personnage_id' => (int) $personnage->id,
                'personnage' => $personnage->nom,
                'lieu' => $lieu,
                'detail' => $detail,
            ], ['nom' => $personnage->nom]);

            return [
                'personnage_id' => (int) $personnage->id,
                'lieu' => $lieu,
                'detail' => $detail,
            ];
        });
    }

    /**
     * Remet la faveur à son porteur et rend le détail annoncé. Chaque cas écrit son ÉTAT DURABLE
     * en base (une ligne, un plafond, un objet du sac) — jamais en cache.
     *
     * Une faveur TENUE remplace celles que ce héros tient (« one boon at a time », DURÉE du docblock
     * de classe) : la fin de chacune est écrite dans la MÊME opération que la nouvelle, et le détail
     * le dit en tête (« Albrecht perd Deadeye. ») — le hub ne re-dérive rien.
     */
    private function remettre(Personnage $recu, string $cle): ?string
    {
        if ($cle === self::WITCHS_HUT) {
            $potion = self::potionDeLaSorciere();
            RangementObjet::ranger($potion, (int) $recu->id, 1);

            return "{$recu->nom} reçoit une {$potion->nom}.";
        }

        $relache = ['cles' => [], 'phrases' => []];

        // Le plafond passe TOUJOURS par le point de passage des jauges : relâcher un +2 PV (Dewdrop
        // Inn) doit reprendre ces PV-là comme poser un nouveau les donne. Sans écart, rien ne change.
        app(Equipement::class)->apresChangementDeJauges($recu, function () use ($recu, $cle, &$relache): void {
            $relache = $this->relacherFaveursTenues($recu);
            PersonnageFaveur::create(['personnage_id' => $recu->id, 'cle' => $cle]);
        });

        $recu->unsetRelation('faveurs');

        $phrases = [];

        if ($relache['cles'] !== []) {
            $libelles = array_map(fn (string $c) => self::LIBELLES[$c] ?? $c, $relache['cles']);
            $dernier = array_pop($libelles);
            $phrases[] = "{$recu->nom} perd " . ($libelles === [] ? $dernier : implode(', ', $libelles) . ' et ' . $dernier) . '.';
            array_push($phrases, ...$relache['phrases']);

            if ($cle !== self::DEWDROP_INN && in_array(self::DEWDROP_INN, $relache['cles'], true)) {
                $phrases[] = "{$recu->nom} revient à {$recu->pv_body_max} PV de Body maximum.";
            }
        }

        $phrases[] = match ($cle) {
            self::DEWDROP_INN => "{$recu->nom} passe à {$recu->pv_body_max} PV de Body maximum.",
            self::MERCENARY_GUILD => "{$recu->nom} peut engager gratuitement le mercenaire de son choix (recrutement, au hub).",
            self::ARCANE_LIBRARY => "{$recu->nom} choisira un répertoire de sorts supplémentaire au hub (fiche du héros).",
            default => null,
        };

        $phrases = array_values(array_filter($phrases));

        return $phrases === [] ? null : implode(' ', $phrases);
    }

    /**
     * Relâche TOUTES les faveurs tenues par ce héros à la fermeture de SA campagne — appelé par
     * `ClotureCampagne::purger()`, point de passage unique des issues (victoire, échec, abandon, arrêt
     * d'urgence). Décision de René (2026-10-16) : « Les faveurs de Hopekins ne survivent pas à la
     * campagne ; aucune récompense, car on ne suit pas l'histoire réelle. » Même chemin que la relâche
     * d'un lieu (`remettre()`) : les effets se défont, le plafond de PV se réconcilie (+2 de *Dewdrop
     * Inn*), puis les lignes partent — aucune ne reste. Le héros revient au roster sans faveur.
     *
     * La potion de *Witch's Hut* n'est PAS une faveur tenue (instantanée) : elle reste au sac, comme
     * un objet trouvé. Rien ne se pose : c'est une relâche pure.
     */
    public function relacherCampagne(Personnage $personnage): void
    {
        app(Equipement::class)->apresChangementDeJauges($personnage, function () use ($personnage): void {
            $this->relacherFaveursTenues($personnage);
        });

        $personnage->unsetRelation('faveurs');
    }

    /**
     * Relâche les faveurs TENUES par ce héros — « a hero may only benefit from one boon at a time »
     * (livret G1504 p. 22-23) : chacune prend fin, sa ligne disparaît, et son effet avec elle. Ce qui
     * dépend d'un AUTRE état est défait ici même : le mercenaire offert retourne à la guilde (sa ligne
     * de `groupe_mercenaires` part, comme un allié qu'on ne paie plus), et le répertoire de la
     * bibliothèque sort du livre de sorts. Le +2 PV n'a rien à défaire ici : `remettre()` réconcilie
     * le plafond après le changement, par le point de passage des jauges.
     *
     * Les faveurs INSTANTANÉES n'ont pas de ligne et ne sont jamais « tenues ».
     *
     * @return array{cles: list<string>, phrases: list<string>} les clés relâchées, dans l'ordre de
     *                                                       réception, et ce qui a été défait en plus
     */
    private function relacherFaveursTenues(Personnage $personnage): array
    {
        $tenues = PersonnageFaveur::query()
            ->where('personnage_id', $personnage->id)
            ->whereNotIn('cle', self::INSTANTANEES)
            ->orderBy('id')
            ->get();

        if ($tenues->isEmpty()) {
            return ['cles' => [], 'phrases' => []];
        }

        $phrases = [];

        foreach ($tenues as $faveur) {
            // Gardé par le RECRUTEUR : jamais l'allié d'un autre héros du groupe.
            if ($faveur->cle === self::MERCENARY_GUILD && $faveur->parametre !== null) {
                $renvoye = GroupeMercenaire::query()
                    ->whereKey((int) $faveur->parametre)
                    ->where('recruteur_personnage_id', $personnage->id)
                    ->delete();

                if ($renvoye > 0) {
                    $phrases[] = 'Son mercenaire offert retourne à la guilde.';
                }
            }

            if ($faveur->cle === self::ARCANE_LIBRARY && $faveur->parametre !== null) {
                $sorts = $personnage->sorts()->where('element', $faveur->parametre)->pluck('sorts.id');

                if ($sorts->isNotEmpty()) {
                    $personnage->sorts()->detach($sorts->all());
                    $phrases[] = 'Il perd son répertoire de sorts supplémentaire.';
                }
            }
        }

        PersonnageFaveur::query()->whereIn('id', $tenues->pluck('id')->all())->delete();
        $personnage->unsetRelation('sorts');

        return [
            'cles' => $tenues->pluck('cle')->values()->all(),
            'phrases' => $phrases,
        ];
    }

    // ---- ENTRETIEN DES MERCENAIRES -----------------------------------------
    //
    // Décision de René (2026-10-06) : « Entretien PARTOUT, 10 po/mercenaire/
    // quête, pour TOUS les groupes. Non payé, le mercenaire part. » Réglé au
    // hub, à la fin d'une quête RÉUSSIE — JAMAIS à un TPK (`echouerQuete()`),
    // qui peut encore être annulé par une reprise (`POST /reprise` recharge
    // le snapshot ; charger un entretien sur un échec qui va être défait
    // facturerait deux fois le même tour si le groupe reprend). Les captifs
    // scénarisés (`mercenaire.captif`) ne sont jamais recrutés contre de
    // l'or : ils restent HORS de cette économie.

    /**
     * Prélève 10 po par mercenaire RECRUTÉ (non captif) encore `actif`, dans
     * l'ordre d'embauche (le plus ANCIEN payé en premier si la bourse ne
     * suffit pas pour tous) ; ceux non payés QUITTENT le groupe (lignes
     * supprimées — ils se réengagent plein tarif, comme n'importe quel
     * nouveau recrutement). `null` si aucun mercenaire recruté n'est
     * actuellement actif (rien à régler, rien à annoncer).
     *
     * @return array<string, mixed>|null
     */
    public function reglerEntretien(Groupe $groupe, int $queteId): ?array
    {
        $mercenaires = GroupeMercenaire::where('groupe_id', $groupe->id)
            ->where('etat', 'actif')
            // Le compagnon animal OFFERT (Against the Ogre Horde p. 8-9, « at no cost ») ne
            // coûte jamais d'entretien : il n'a pas été engagé, il accompagne le groupe
            // toute la campagne (`CompagnonOffert`). Sans cette exemption, un groupe à
            // sec le perdrait à la première quête.
            ->where('offert', false)
            ->whereHas('mercenaire', fn ($q) => $q->where('captif', false))
            ->with('mercenaire')
            ->orderBy('id')
            ->get();

        if ($mercenaires->isEmpty()) {
            return null;
        }

        $cout = 10;
        $disponible = (int) $groupe->or;
        $payables = intdiv($disponible, $cout);

        $payes = $mercenaires->take($payables);
        $partis = $mercenaires->slice($payables);

        $totalPaye = $payes->count() * $cout;

        if ($totalPaye > 0) {
            $groupe->decrement('or', $totalPaye);
        }

        if ($partis->isNotEmpty()) {
            GroupeMercenaire::whereIn('id', $partis->pluck('id'))->delete();
        }

        // `quete_id` : même garde que la faveur (`EtatGroupe::annonceDeQuete()`).
        $payload = [
            'type' => 'mercenaire_entretien',
            'action' => 'mercenaire_entretien',
            'quete_id' => $queteId,
            'cout_par_mercenaire' => $cout,
            'cout_total' => $totalPaye,
            'or_restant' => (int) $groupe->fresh()->or,
            'payes' => $payes->map(fn (GroupeMercenaire $m) => [
                'id' => $m->id, 'nom' => $m->mercenaire->nom,
            ])->values()->all(),
            'partis' => $partis->map(fn (GroupeMercenaire $m) => [
                'id' => $m->id, 'nom' => $m->mercenaire->nom,
            ])->values()->all(),
        ];

        Journal::ajouter($groupe, 'systeme', $payload);

        return $payload;
    }
}
