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
    ];

    /**
     * Un groupe est une CAMPAGNE ou un COMBAT D'ARÈNE (mode arène, lot A2, `docs/plan-mode-arene.md` §3). Un seul
     * prédicat, `estArene()` ; une seule garde, `exigerCampagne()`, posée au point d'entrée de chaque étape propre à la
     * campagne et recensée dans `App\Partie\Arene\EtapesCampagne`.
     */
    public const MODE_CAMPAGNE = 'campagne';

    public const MODE_ARENE = 'arene';

    public const MODES = [self::MODE_CAMPAGNE, self::MODE_ARENE];

    protected function casts(): array
    {
        return [
            'plan_campagne' => 'array',
            'ton' => 'array',
            'boites_bestiaire' => 'array',
            'arene_defender' => 'array',
        ];
    }

    public function estArene(): bool
    {
        return $this->mode === self::MODE_ARENE;
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
    }

    /** Composition du groupe (+ initiative). */
    public function personnages(): BelongsToMany
    {
        return $this->belongsToMany(Personnage::class, 'groupe_personnages', 'groupe_id', 'personnage_id')
            ->withPivot(['ordre_initiative', 'actif', 'pret_arene', 'allie_arene_id']);
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
