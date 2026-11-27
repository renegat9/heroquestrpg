<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Groupe extends Model
{
    protected $table = 'groupes';

    protected $fillable = [
        'identifiant',
        'nom',
        'theme',
        'longueur',
        'nb_quetes_total',
        'plan_campagne',
        'ton',
        'or',
        // Compteur de pitié du passage secret (2026-08-27) : 50 % de base,
        // +10 par carte sans passage, retour à 50 dès qu'on en pose un.
        'chance_passage_secret',
        // Boîte du bestiaire, FIGÉE pour toute la campagne (2026-09-06,
        // phase 6a) — écrite une seule fois par DemarreurQuete::demarrer(),
        // jamais recalculée ensuite. `null` tant qu'aucune quête n'a démarré
        // (ou pour une campagne antérieure à cette colonne) : lire via
        // DemarreurQuete::themeBestiaireDuGroupe(), qui retombe alors sur le
        // calcul historique plutôt que de traiter `null` comme une erreur.
        'theme_bestiaire',
        // Boîtes qui FILTRENT le bestiaire (2026-09-28) : cochées à la création
        // (liste vide = jeu de base seul), ou, depuis le 2026-11-05, choisies
        // en automatique (tirage, puis le MJ IA selon le thème) ; `null` = une
        // campagne d'avant, en rotation historique. Lire via
        // App\Partie\BestiaireGroupe::duGroupe(), jamais brut.
        'boites_bestiaire',
        // QUI a choisi `boites_bestiaire` : `mj`, `tirage`, ou `null` (les joueurs).
        'origine_boites',
        'etat',
        'phase',
        // Issue DURABLE de la proposition du compagnon animal offert (Against the
        // Ogre Horde p. 8-9) : `null` (pas tranchée), `'pris'` ou `'refuse'`. Lire via
        // App\Partie\CompagnonOffert, qui dit aussi quand la proposition EXISTE.
        'compagnon_offert',
        'quete_courante_id',
        // MODE ARÈNE (lot A2, 2026-11-09) : `campagne` ou `arene` — vocabulaire fermé `self::MODES`.
        'mode',
        // MODE ARÈNE (lot A5) : ids `monstres` du Defender composé au salon — lus par `GroupesArene` seulement.
        'arene_defender',
        // MODE ARÈNE, lot A9a : la variante (`coop` | `monstres`) et le salon Monster vs Monster.
        'arene_variante',
        'arene_mvm',
        // MODE CLASSIQUE (2026-11-14) : la campagne construite que joue le groupe, et le rang de sa prochaine quête.
        'campagne_construite_id',
        'rang_quete',
        // MODE CLASSIQUE, lot H1 (2026-11-16) : `story` ou `heroic` — vocabulaire fermé `self::MODES_MORT`.
        'mode_mort',
        // Lot K5h : drapeaux de CAMPAGNE `{nom: true}`, posés par une note (`drapeau` + `campagne: true`), lus par `Quete::aDrapeau()`.
        'drapeaux_campagne',
        // Lot K6 (*Dread Moon*) : les jetons de réputation du groupe — `Classique\Reputation` seul.
        'jetons_reputation',
    ];

    /**
     * Un groupe est une CAMPAGNE ou un COMBAT D'ARÈNE (mode arène, lot A2, `docs/plan-mode-arene.md` §3). Un seul
     * prédicat, `estArene()` ; une seule garde, `exigerCampagne()`, posée au point d'entrée de chaque étape propre à la
     * campagne et recensée dans `App\Partie\Arene\EtapesCampagne`.
     */
    public const MODE_CAMPAGNE = 'campagne';

    public const MODE_ARENE = 'arene';

    /** Variantes d'un combat d'arène (lot A9a) : héros contre Zargon, ou deux équipes de monstres. */
    public const VARIANTE_COOP = 'coop';

    public const VARIANTE_MONSTRES = 'monstres';

    /**
     * MODE CLASSIQUE (`docs/plan-mode-classique.md`) : le groupe joue les quêtes FIXES d'une campagne construite, sans
     * aucun travail d'IA, et n'est jamais dissous. Un seul prédicat, `estClassique()` ; la même garde que l'arène.
     */
    public const MODE_CLASSIQUE = 'classique';

    public const MODES = [self::MODE_CAMPAGNE, self::MODE_ARENE, self::MODE_CLASSIQUE];

    /**
     * MODE DE MORT (lot H1, *Jungles of Delthrak* F9907 p. 8-9 ; plan classique Q2) — `story` : un héros à 0 Body est
     * hors de combat, jamais mort, et se relève d'un point de Body quand aucun monstre n'est actif (la politique de
     * tous les groupes, `ResolveurTour::relevementsStory()`) ; `heroic` : hors de combat, il prend un jeton crâne au
     * début de son tour suivant, et MEURT s'il commence un tour avec. Choisi à la création d'un groupe classique.
     */
    public const MORT_STORY = 'story';

    public const MORT_HEROIC = 'heroic';

    /**
     * `standard` (lot K6, René 2026-11-19 — *Jungles of Delthrak* F9907 p. 8 : « hero death proceeds as written in the
     * HeroQuest Game System rulebook ») : à 0 Body le héros MEURT, sauf s'il se sauve lui-même aussitôt — sa potion ou
     * son sort de soin (la réaction « Rester debout ») ; un compagnon ne peut plus rien pour lui (livret de règles G0978,
     * « Dead Heroes »). Point de passage : `MortHeroic::mortsStandard()`.
     */
    public const MORT_STANDARD = 'standard';

    public const MODES_MORT = [self::MORT_STORY, self::MORT_HEROIC, self::MORT_STANDARD];

    /** Ce groupe joue-t-il en Standard (mort à 0 Body, sauf soin de soi-même) ? */
    public function estStandard(): bool
    {
        return $this->mode_mort === self::MORT_STANDARD;
    }

    /** Ce groupe joue-t-il en Heroic (la mort est possible, et définitive) ? */
    /**
     * LE SOCLE DU DÉPLACEMENT (décision Q1 du mode classique, codée le 2026-11-17) : mode RPG, la base de la classe et UN
     * d6 (écart voulu de René) ; mode CLASSIQUE, DEUX dés rouges sans base, comme au plateau. Point de passage unique,
     * lu par les trois calculs du jet (`MenuMoteur`, sa relance, le repli de `ResolveurTour`).
     *
     * @return array{base: int, des: int}
     */
    public function socleDeDeplacement(Personnage $personnage): array
    {
        // Lot E2 (*Spirit Queen's Torment* q. 8 : « heroes can breathe water and each roll 1 additional movement die ») : les dés
        // en plus d'une quête construite (`regles.des_deplacement_bonus`).
        return $this->estClassique()
            ? ['base' => 0, 'des' => 2 + (int) data_get($this->queteCourante?->documentConstruit(), 'regles.des_deplacement_bonus', 0)]
            : ['base' => (int) $personnage->deplacement_base, 'des' => 1];
    }

    public function estHeroic(): bool
    {
        return $this->mode_mort === self::MORT_HEROIC;
    }

    protected function casts(): array
    {
        return [
            'plan_campagne' => 'array',
            'drapeaux_campagne' => 'array',
            'jetons_reputation' => 'integer',
            'ton' => 'array',
            'boites_bestiaire' => 'array',
            'arene_defender' => 'array',
            'arene_mvm' => 'array',
        ];
    }

    /** Monster vs Monster (lot A9a) : un combat d'arène sans héros, deux camps de monstres. */
    public function estMonstresContreMonstres(): bool
    {
        return $this->estArene() && $this->arene_variante === self::VARIANTE_MONSTRES;
    }

    public function estArene(): bool
    {
        return $this->mode === self::MODE_ARENE;
    }

    /**
     * Les RÈGLES DE COMBAT du tournoi de World's End s'appliquent-elles (activation alternée, trophées, mort « hors jeu »,
     * combattant seul) ? Un groupe d'arène, ou un groupe classique dont la quête courante est un tournoi
     * (`Quete::estTournoi()`, *Against the Ogre Horde* q. 1-3, `docs/plan-tournoi-classique.md`). Distinct de
     * `estArene()`, qui dit le CYCLE DE VIE du groupe d'arène (doublures, palmarès, hall) et rien d'autre.
     */
    public function estEnTournoi(): bool
    {
        return $this->estArene() || ($this->estClassique() && (bool) $this->queteCourante?->estTournoi());
    }

    public function estClassique(): bool
    {
        return $this->mode === self::MODE_CLASSIQUE;
    }

    /** La campagne construite que joue un groupe classique (`null` pour tout autre groupe). */
    public function campagneConstruite(): BelongsTo
    {
        return $this->belongsTo(CampagneConstruite::class, 'campagne_construite_id');
    }

    /**
     * LA garde des étapes de campagne : un groupe d'arène qui en atteint une lève une erreur NOMMÉE, jamais un passage
     * en silence — sinon la première étape de campagne ajoutée plus tard s'appliquerait à l'arène sans que personne ne
     * le voie (plan §3). `$etape` est une clé de `EtapesCampagne::ETAPES`, et chaque appel est recensé là, testé dans
     * les deux sens (`tests/Feature/Arene/EtapesCampagneTest.php`).
     *
     * Relue en base : une instance chargée avant la colonne, ou construite à la main, ne passe pas sur un `mode` absent.
     */
    public function exigerCampagne(string $etape): void
    {
        $mode = $this->exists
            ? (\Illuminate\Support\Facades\DB::table('groupes')->where('id', $this->id)->value('mode') ?? $this->mode)
            : $this->mode;

        if ($mode === self::MODE_ARENE) {
            throw \App\Partie\Arene\RefusArene::etapeDeCampagne($this, $etape);
        }

        // MODE CLASSIQUE : chaque étape dit au registre si elle lui est OUVERTE (`null`) ou FERMÉE (sa raison). Une étape
        // qui n'a rien déclaré est fermée : celle qu'on ajoutera plus tard ne s'appliquera pas en silence.
        // ⚠ `array_key_exists`, pas `??` : une étape OUVERTE vaut `null`, que `??` prendrait pour une étape absente.
        if ($mode === self::MODE_CLASSIQUE) {
            $reponses = \App\Partie\Arene\EtapesCampagne::CLASSIQUE;

            if (! array_key_exists($etape, $reponses) || $reponses[$etape] !== null) {
                throw \App\Partie\Classique\RefusClassique::etapeFermee($this, $etape);
            }
        }
    }

    /**
     * Cette étape de campagne est-elle OUVERTE à ce groupe ? La vue booléenne de {@see self::exigerCampagne()}, par elle :
     * une seule règle, lue par les routes (qui lèvent) et par les écrans (à qui `EtatGroupe` publie la décision plutôt
     * que de leur laisser tester le mode).
     */
    public function etapeOuverte(string $etape): bool
    {
        try {
            $this->exigerCampagne($etape);
        } catch (\App\Partie\Arene\RefusArene|\App\Partie\Classique\RefusClassique) {
            return false;
        }

        return true;
    }

    /** Composition du groupe (+ initiative). */
    public function personnages(): BelongsToMany
    {
        return $this->belongsToMany(Personnage::class, 'groupe_personnages', 'groupe_id', 'personnage_id')
            ->withPivot(['ordre_initiative', 'actif', 'pret_arene', 'allie_arene_id', 'pilote_joueur_id']);
    }

    /**
     * MANETTE À PLUSIEURS HÉROS (lot M1, `docs/plan-mode-classique.md` §5) — le joueur qui JOUE ce héros dans ce groupe.
     *
     * Le PILOTE n'est pas le propriétaire : `personnages.joueur_id` dit à qui le héros appartient,
     * `groupe_personnages.pilote_joueur_id` dit qui le joue ici, et `null` veut dire « son propriétaire ». Point de
     * passage UNIQUE avec {@see self::herosPilotesPar()} : menu, choix, réactions et canal privé passent par eux, jamais
     * par `joueur_id` relu sur place.
     */
    public function piloteDe(Personnage $personnage): int
    {
        $pilote = \Illuminate\Support\Facades\DB::table('groupe_personnages')
            ->where('groupe_id', $this->id)
            ->where('personnage_id', $personnage->id)
            ->value('pilote_joueur_id');

        return self::piloteResolu($pilote === null ? null : (int) $pilote, $personnage);
    }

    /**
     * Le pilote d'un héros chargé AVEC son pivot (`self::personnages()` lit `pilote_joueur_id`) — sans requête par
     * héros, pour un payload qui en sérialise plusieurs. Même règle que {@see self::piloteDe()}, par la même fonction.
     */
    public static function piloteDuPivot(Personnage $personnage): int
    {
        $pilote = $personnage->pivot?->pilote_joueur_id;

        return self::piloteResolu($pilote === null ? null : (int) $pilote, $personnage);
    }

    /** LA règle, écrite une fois : une colonne vide désigne le propriétaire. */
    private static function piloteResolu(?int $pilote, Personnage $personnage): int
    {
        return $pilote ?? (int) $personnage->joueur_id;
    }

    /**
     * Les héros ACTIFS de ce groupe que ce joueur pilote : ceux qu'on lui a confiés, et les siens tant qu'ils ne sont
     * confiés à personne. Le pendant en requête de {@see self::piloteDe()} — même règle, tenue au même endroit.
     */
    public function herosPilotesPar(int $joueurId): BelongsToMany
    {
        return $this->personnages()
            ->wherePivot('actif', true)
            ->where(function ($q) use ($joueurId) {
                $q->where('groupe_personnages.pilote_joueur_id', $joueurId)
                    ->orWhere(function ($q) use ($joueurId) {
                        $q->whereNull('groupe_personnages.pilote_joueur_id')
                            ->where('personnages.joueur_id', $joueurId);
                    });
            });
    }

    /** Personnages dont c'est le groupe actif. */
    public function personnagesActifs(): HasMany
    {
        return $this->hasMany(Personnage::class, 'groupe_actif_id');
    }

    public function quetes(): HasMany
    {
        return $this->hasMany(Quete::class, 'groupe_id');
    }

    public function queteCourante(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_courante_id');
    }

    /** Journal rejouable. */
    public function evenements(): HasMany
    {
        return $this->hasMany(Evenement::class, 'groupe_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class, 'groupe_id');
    }

    /**
     * Alliés recrutés (mercenaires + compagnons). Depuis le chantier 1c
     * (Wizards of Morcar, René 2026-10-06) les mercenaires RECRUTÉS (pas les
     * captifs scénarisés) PERSISTENT d'une quête à l'autre contre un
     * entretien — voir App\Partie\FaveursHopekins::reglerEntretien() et
     * ResolveurTour::terminerQuete()/echouerQuete(). Seuls les captifs
     * (`mercenaire.captif`) et les morts restent consommés en fin de quête.
     */
    public function mercenaires(): HasMany
    {
        return $this->hasMany(GroupeMercenaire::class, 'groupe_id');
    }

    /**
     * Statut de Gardien (« Warden », livret G1504 p. 8-9, Wizards of Morcar) —
     * débloqué pour TOUT le groupe dès que **2 quêtes sont achevées**
     * (`etat: 'terminee'`) : « Once a hero has become a Warden (after
     * completing Quest 2) ». Calculé en DIRECT sur `quetes` (jamais une
     * colonne ni un cache — ce décompte est déjà une lecture DB déterministe,
     * pas un état à dupliquer) : débloque le recrutement de mercenaires
     * (`MercenaireController::recruter()`) et l'entretien devient dû au
     * premier hub qui suit.
     *
     * ⚠ Décision de René (2026-10-06) : **l'entretien de 10 po/mercenaire/
     * quête s'applique à TOUS les groupes**, pas seulement au thème
     * `wizards_of_morcar` — mais le statut de Gardien (et donc la PORTE
     * d'entrée au recrutement) est lui aussi générique, puisque le modèle
     * économique remplace l'ancien (payant une fois, consommé en fin de
     * quête) PARTOUT. Une campagne déjà en cours garde ses mercenaires déjà
     * recrutés quel que soit ce compteur — jamais retirés rétroactivement.
     */
    public function estGardien(): bool
    {
        return $this->quetes()->where('etat', 'terminee')->count() >= 2;
    }

    /**
     * VICTOIRE FINALE acquise : le boss final de l'arc est vaincu (`type_jalon` = `boss_final`, `etat` =
     * `terminee`). La victoire du Haut Autel passe par la même fin de quête (`ResolveurTour::terminerQuete()`).
     *
     * L'ARC EST ÉPUISÉ : plus aucune quête ne démarre (`DemarreurQuete::refusDeDepart()`, lu par `POST /pret` et
     * par `demarrer()`, donc aussi par la table), et plus aucun tour de Hopekins Rest ne s'ouvre
     * (`FaveursHopekins::tourDeHub()`). Le drapeau ne se remet pas à zéro quand on annule la fenêtre de victoire
     * (`DELETE /cloture`) : la clôture reste la seule issue, et `POST /cloture` la rouvre.
     *
     * Calculé en DIRECT sur `quetes` (même patron que `estGardien()`) : rien à remettre à jour, rien en cache.
     * ⚠ Le SEUL prédicat de cette règle : `ClotureCampagne::ouvrir()` lit le même (issue `victoire`).
     */
    public function victoireFinaleAcquise(): bool
    {
        return $this->quetes()->where('type_jalon', 'boss_final')->where('etat', 'terminee')->exists();
    }
}
