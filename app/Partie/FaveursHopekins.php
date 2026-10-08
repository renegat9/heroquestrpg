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
 * un héros actif lui aussi tiré au hasard. Ni choix ni file d'attente : zéro
 * UI nouvelle, une décision engine-autoritaire annoncée (journal + payload),
 * conforme à « le serveur publie la décision ». Épuisé (les 5 déjà
 * distribuées) : rien n'est tiré ce tour-ci, silencieusement — pas un défaut
 * à annoncer, au même titre qu'un butin qui ne tombe pas.
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
     * MOTEUR fait, pas ce que la carte promet : Weapon Expert lie son type à la
     * première arme maniée (aucun écran de choix), Hold the Line ne lance qu'un
     * dé de combat. Vocabulaire fermé : même clés que `TOUTES`, testé.
     */
    public const EFFETS = [
        self::DEADEYE => 'Les figures (héros et monstres) ne bloquent pas votre ligne de vue pour attaquer ou lancer un sort.',
        self::WEAPON_EXPERT => '+1 dé d\'attaque avec l\'arme de votre spécialité : le premier type d\'arme dont vous frappez après l\'avoir reçue.',
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
        // Weapon Expert, lui-même rechargé explicitement par son propre
        // lecteur).
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
     * @return list<array{cle: string, libelle: string, effet: string}>
     */
    public static function publier(Personnage $personnage): array
    {
        $personnage->loadMissing('faveurs');

        return $personnage->faveurs
            ->map(fn (PersonnageFaveur $f) => [
                'cle' => $f->cle,
                'libelle' => self::LIBELLES[$f->cle] ?? $f->cle,
                'effet' => self::effet($f->cle),
            ])
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
    // dice. » Le tirage de la faveur ne passe par aucune UI de choix
    // (voir le docblock de classe) : le TYPE choisi est donc lié
    // PARESSEUSEMENT, à la première arme avec laquelle ce héros frappe
    // après l'avoir reçue — « la première arme que vous maniez devient
    // votre spécialité », une lecture fidèle à l'esprit de la carte sans
    // inventer un écran de sélection.

    /**
     * +1 dé d'attaque si `$arme` est le type choisi ; lie paresseusement le
     * choix au premier appel si la faveur est détenue mais encore vierge.
     * `0` si le héros n'a pas la faveur, ou frappe à mains nues (`$arme`
     * `null` — rien à lier, rien à bonifier).
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

        return (int) $faveur->parametre === $arme->id ? 1 : 0;
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
    // coins per monster defeated the end of that quest. » Décision
    // d'interprétation (2026-10-08, à arbitrer par René) : la carte paie « at
    // the end of that quest » ; on paie à la mise à mort, sans compteur par
    // quête à stocker. Identique quand la quête est GAGNÉE : les trois seules
    // dépenses de la bourse (recrutement, forge, marché) sont refusées hors
    // hub, donc l'or gagné en quête n'est jamais dépensé avant la fin. Deux
    // écarts seulement : (a) un TPK sans reprise garde l'or des mises à mort
    // faites avant la chute ; (b) l'or tombe au fil du combat. Une reprise
    // (`POST /reprise`) restaure le snapshot `debut_quete`, bourse comprise :
    // jamais compté deux fois. Un compteur par quête (colonne sur
    // `etat_personnages_quete`, payé à la victoire) lèverait l'écart (a) — hors
    // de ce chantier, à demander.
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
