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
use App\Models\Quete;
use App\Support\Journal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Faveurs de Hopekins Rest (livret G1504 p. 22-23, Wizards of Morcar) —
 * chantier 1c, décision de René du 2026-10-06 : « récompense de quête
 * séparée, hors arbre de talents — une faveur offerte au groupe à la fin de
 * CERTAINES quêtes, comme le livret après la quête 2 ».
 *
 * Les CINQ compétences transcrites mot pour mot
 * (`reference/18_extensions.md` §Wizards of Morcar — cartes TRANSCRITES,
 * §7) — les quatre autres faveurs du lieu (mercenaire gratuit, +2 PV Body,
 * +1 Potion, répertoire de sorts supplémentaire) sont HORS CHANTIER (pas
 * nommées par la décision du 2026-10-06, déjà couvertes par d'autres dons du
 * catalogue) et restent ⚠ non portées.
 *
 * RÈGLE D'ATTRIBUTION (décision d'interprétation de ce chantier, le livret
 * ne tranchant qu'un « un héros visite un lieu, hors ligne ») : à la fin
 * d'une quête RÉUSSIE (`ResolveurTour::terminerQuete()`, jamais un échec —
 * aligné sur la montée de niveau par jalon), UNE FOIS Gardien
 * (`Groupe::estGardien()`), le groupe reçoit UNE faveur TIRÉE AU HASARD
 * parmi les 5 non encore détenues par aucun héros actif du groupe, remise à
 * un héros actif lui aussi tiré au hasard. Ni choix de la faveur ni file
 * d'attente : une décision engine-autoritaire annoncée (journal + payload),
 * conforme à « le serveur publie la décision ». Épuisé (les 5 déjà
 * distribuées) : rien n'est tiré ce tour-ci, silencieusement — pas un défaut
 * à annoncer, au même titre qu'un butin qui ne tombe pas.
 *
 * UN SEUL choix reste au joueur (René, 2026-10-15) : le TYPE D'ARME de Weapon
 * Expert (« Select a type of weapon […] when you acquire this Boon »). La faveur
 * est tirée et remise au hasard, mais c'est le joueur du héros qui choisit sa
 * spécialité, au hub, dès qu'il l'a reçue — voir `choisirTypeArme()`.
 */
final class FaveursHopekins
{
    public const DEADEYE = 'deadeye';

    public const WEAPON_EXPERT = 'weapon_expert';

    public const HEALING_HANDS = 'healing_hands';

    public const HOLD_THE_LINE = 'hold_the_line';

    public const PEACEKEEPER = 'peacekeeper';

    /** « 25 gold coins per monster defeated at the end of that quest » (carte Peacekeeper). */
    public const OR_PAR_MONSTRE = 25;

    /** Vocabulaire fermé — seule source, testée dans les deux sens. */
    public const TOUTES = [
        self::DEADEYE,
        self::WEAPON_EXPERT,
        self::HEALING_HANDS,
        self::HOLD_THE_LINE,
        self::PEACEKEEPER,
    ];

    public const LIBELLES = [
        self::DEADEYE => 'Deadeye',
        self::WEAPON_EXPERT => 'Weapon Expert',
        self::HEALING_HANDS => 'Healing Hands',
        self::HOLD_THE_LINE => 'Hold the Line',
        self::PEACEKEEPER => 'Peacekeeper',
    ];

    /**
     * Effet de chaque faveur EN CLAIR, pour la fiche du héros et l'annonce au
     * hub (« nom + effet », décision de portage 2026-10-08). Traduit de la carte
     * (`reference/18_extensions.md` §7), mais chaque phrase décrit ce que le
     * MOTEUR fait, pas ce que la carte promet : Hold the Line ne lance qu'un dé de
     * combat. Vocabulaire fermé : même clés que `TOUTES`, testé.
     */
    public const EFFETS = [
        self::DEADEYE => 'Les figures (héros et monstres) ne bloquent pas votre ligne de vue pour attaquer ou lancer un sort.',
        self::WEAPON_EXPERT => '+1 dé d\'attaque avec le type d\'arme que vous choisissez en recevant la faveur (au hub, entre deux quêtes).',
        self::HEALING_HANDS => 'Si un héros adjacent tombe à 0 PV, il peut boire une de vos potions de soin au lieu de la sienne.',
        self::HOLD_THE_LINE => 'Quand un monstre s\'éloigne des 8 cases qui vous entourent, un crâne au dé de combat lui inflige 1 PV de Body.',
        self::PEACEKEEPER => '25 po à la bourse commune pour chaque monstre que vous réduisez à 0 PV.',
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
    // (`attribuerFaveurDeFinDeQuete()` — la faveur et son porteur restent TIRÉS au
    // hasard, ça n'a pas changé), donc le choix se fait AU HUB, entre deux quêtes :
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

    // ---- ATTRIBUTION DE FIN DE QUÊTE --------------------------------------

    /**
     * Tirage de fin de quête RÉUSSIE (voir le docblock de classe pour la
     * règle). `null` si pas encore Gardien, pas de héros actif, ou les 5
     * faveurs sont déjà toutes distribuées dans ce groupe.
     *
     * @return array<string, mixed>|null
     */
    public function attribuerFaveurDeFinDeQuete(Groupe $groupe, int $queteId): ?array
    {
        if (! $groupe->estGardien()) {
            return null;
        }

        $heros = $groupe->personnages()->wherePivot('actif', true)->get();

        if ($heros->isEmpty()) {
            return null;
        }

        $dejaDetenues = PersonnageFaveur::whereIn('personnage_id', $heros->pluck('id'))
            ->pluck('cle')
            ->unique()
            ->all();

        $pool = array_values(array_diff(self::TOUTES, $dejaDetenues));

        if ($pool === []) {
            return null; // les 9 lieux de Hopekins Rest : les 5 portés ici sont épuisés.
        }

        $cle = $pool[array_rand($pool)];
        /** @var Personnage $recu */
        $recu = $heros->random();

        PersonnageFaveur::create(['personnage_id' => $recu->id, 'cle' => $cle]);

        // `quete_id` : l'annonce du hub ne vaut que pour la DERNIÈRE quête
        // achevée (voir `EtatGroupe::annonceDeQuete()`) — sans lui, une quête
        // suivante sans attribution relirait encore celle-ci.
        $payload = [
            'type' => 'faveur_hopekins',
            'action' => 'faveur_hopekins',
            'quete_id' => $queteId,
            'faveur' => $cle,
            'faveur_libelle' => self::LIBELLES[$cle],
            'personnage_id' => $recu->id,
            'personnage' => $recu->nom,
        ];

        Journal::ajouter($groupe, 'systeme', $payload, ['nom' => $recu->nom]);

        return $payload;
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
