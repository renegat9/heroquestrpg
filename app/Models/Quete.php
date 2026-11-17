<?php

namespace App\Models;

use App\Partie\Fouille\DeckFouille;
use App\Partie\MoteurMobilier;
use App\Partie\Talents;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Quete extends Model
{
    protected $table = 'quetes';

    protected $fillable = [
        // MODE ARÈNE (lot T4) : tuiles trophées posées et réserves d'équipe — `App\Partie\Arene\TropheesArene`.
        'trophees_arene',
        'groupe_id',
        'gabarit_id',
        'titre',
        'position_arc',
        'type_jalon',
        'objectif_majeur',
        'branche_active',
        'salles_decouvertes',
        'tresors_fouilles',
        'recits',
        'deck_fouille',
        'salle_artefact',
        'salles_coffre',
        'coffres_ouverts',
        'artefact_objet_id',
        'etat',
        'or_initial',
        // Mission « secourir » (chantier 3b, 2026-10-04) : LE captif de cette
        // quête, posé à l'assemblage — état durable, jamais recalculé (deux
        // captifs sur la carte n'arriveraient jamais, mais la colonne dit
        // SANS AMBIGUÏTÉ lequel compte pour l'objectif).
        'captif_mercenaire_id',
        // Effets GLOBAUX de la quête (Gruulob, « All Goblins in this quest… »), figés
        // au démarrage par `EffetsGlobauxQuete::etablir()` — en colonne, jamais en cache.
        'effets_globaux',
        // MODE CLASSIQUE (2026-11-14) : la condition de fin écrite par l'auteur d'une quête construite —
        // `{type: "vaincre", libelle}`. Lue par `objectif()`, `objectifAccompli()` et `objectifLibelle()` seulement.
        'objectif_construit',
        // MODE CLASSIQUE (2026-11-14) : par où l'on entre et par où l'on sort — `{entree, sortie}`, chacun
        // `{type: escalier|porte, cases: [{x, y}], porte?: {x, y, cote}}`. Lu par `casesDeSortie()` seulement.
        'acces',
        // MODE CLASSIQUE (lot S1, 2026-11-16) : les héros ENGAGÉS, figés au départ — `null` = tout le groupe. Lu par
        // `participants()` / `participe()` seulement.
        'participants',
        // MODE HEROIC (lot H1b, 2026-11-16) : les affaires d'un mort restées au sol — `[{personnage_id, nom, x, y}]`. Lu
        // par `depouilles()` seulement.
        'depouilles',
        // MODE CLASSIQUE (lot K3, 2026-11-15) : le document de la quête construite, FIGÉ au départ ; ses drapeaux ; ses
        // notes « une fois » déjà tirées. Lus par `documentConstruit()`, `NotesDeQuete` et `MonstreErrant`.
        'document_construit',
        'drapeaux',
        'notes_jouees',
        // Lot K5a : les tours du maître du jeu commencés, lus par le déclencheur de note `tour_du_mj`.
        'tours_mj',
    ];

    protected function casts(): array
    {
        return [
            'objectif_majeur' => 'boolean',
            'branche_active' => 'array',
            // Avancement d'exploration — état de partie DURABLE (§2.16) : a
            // longtemps vécu en cache avec un TTL, ce qui figeait tout le
            // groupe quand la clé disparaissait (brouillard refermé sur des
            // zones déjà explorées → plus aucune case accessible).
            'salles_decouvertes' => 'array',
            'tresors_fouilles' => 'array',
            // Récits pré-générés (salles + temps forts) : l'IA fabrique la
            // quête, le moteur la joue sans elle (décision de René 2026-08-18).
            'recits' => 'array',
            // Deck de fouille : pioche ordonnée, index 0 = sommet.
            'deck_fouille' => 'array',
            // Salles à coffre : la plus profonde (artefact) + celles situées
            // derrière une porte secrète.
            'salles_coffre' => 'array',
            'coffres_ouverts' => 'array',
            'trophees_arene' => 'array',
            // Effets globaux figés au démarrage (`EffetsGlobauxQuete`). NULL = quête
            // ouverte avant la colonne : sa liste se lit sur sa roster.
            'effets_globaux' => 'array',
            'objectif_construit' => 'array',
            'acces' => 'array',
            'participants' => 'array',
            'depouilles' => 'array',
            'document_construit' => 'array',
            'drapeaux' => 'array',
            'notes_jouees' => 'array',
            'tours_mj' => 'integer',
        ];
    }

    /**
     * Les héros ENGAGÉS dans cette quête (lot S1) — `null` = tout le groupe : toute quête générée, toute quête construite
     * sans règle solo. Une quête SOLO (`regles.solo`) n'engage que le héros désigné ; les autres sont SPECTATEURS.
     *
     * @return list<int>|null
     */
    public function participants(): ?array
    {
        return $this->participants === null ? null : array_values(array_map('intval', (array) $this->participants));
    }

    /**
     * Ce héros est-il engagé dans cette quête ? Un héros SORTI l'est encore (il a quitté le donjon en personne) ; un
     * SPECTATEUR ne l'a jamais été. Point de passage unique : publication (`EtatGroupe`) et récompense le lisent.
     */
    public function participe(int $personnageId): bool
    {
        $participants = $this->participants();

        return $participants === null || in_array($personnageId, $participants, true);
    }

    /**
     * Les AFFAIRES d'un héros mort restées au sol (lot H1b, Heroic) : `[{personnage_id, nom, x, y}]`, à la case de sa
     * mort, jusqu'à ce qu'un héros de la même salle ou du même couloir les ramasse.
     *
     * @return list<array{personnage_id: int, nom: string, x: int, y: int}>
     */
    public function depouilles(): array
    {
        return array_values(array_map(static fn (array $d): array => [
            'personnage_id' => (int) $d['personnage_id'], 'nom' => (string) $d['nom'], 'x' => (int) $d['x'], 'y' => (int) $d['y'],
        ], (array) ($this->depouilles ?? [])));
    }

    /** Ajoute (ou retire, `$garder = false`) les affaires au sol d'un mort. État durable : en colonne. */
    public function poserDepouille(array $depouille, bool $garder = true): void
    {
        $liste = array_values(array_filter($this->depouilles(), static fn (array $d) => $d['personnage_id'] !== (int) $depouille['personnage_id']));
        if ($garder) {
            $liste[] = $depouille;
        }
        $this->update(['depouilles' => $liste === [] ? null : $liste]);
    }

    /** Cette quête vient-elle du CONSTRUCTEUR (mode classique) plutôt que du générateur ? */
    public function estConstruite(): bool
    {
        return $this->document_construit !== null;
    }

    /** Ce drapeau de quête a-t-il été posé (effet `drapeau` d'une note) ? */
    public function aDrapeau(string $nom): bool
    {
        return ! empty(((array) $this->drapeaux)[$nom]);
    }

    /** Pose un drapeau de quête — état durable, en colonne. Lu par une autre note, ou par la condition de fin. */
    public function poserDrapeau(string $nom): void
    {
        if ($nom !== '' && ! $this->aDrapeau($nom)) {
            $this->update(['drapeaux' => [...(array) $this->drapeaux, $nom => true]]);
        }
    }

    /**
     * Le document de la quête construite, tel qu'il était AU DÉPART — jamais relu dans la campagne, que l'auteur peut
     * corriger pendant la partie. `[]` pour une quête générée.
     *
     * @return array<string, mixed>
     */
    public function documentConstruit(): array
    {
        return (array) ($this->document_construit ?? []);
    }

    /**
     * Index des salles déjà découvertes. La salle de DÉPART l'est toujours :
     * elle est semée par DemarreurQuete et ne dépend d'aucune révélation.
     *
     * C'est la salle 0 — sauf pour une quête CONSTRUITE qui entre par une
     * porte de COULOIR (René, 2026-11-16) : aucune salle n'y est « de départ »
     * (`acces.salle_depart` = `null`, écrit par `CarteConstruite::produire()`),
     * et la salle 0 n'est alors que la première salle ouverte du plateau, à
     * découvrir comme les autres. Sans la clé (toute autre quête), c'est 0.
     *
     * @return list<int>
     */
    public function sallesDecouvertes(): array
    {
        $vues = array_map('intval', (array) ($this->salles_decouvertes ?? []));

        return array_values(array_unique($this->entreeEnCouloir() !== [] ? $vues : [0, ...$vues]));
    }

    /**
     * Les héros commencent-ils HORS DU PLATEAU, devant la porte d'entrée ? Oui pour une quête CONSTRUITE qui entre par
     * une PORTE (René, 2026-11-16 : « respectons la règle hors plateau » — *Kellar's Keep*, F4543 : « the heroes line
     * up outside the door »). Une ligne d'état sans case veut alors dire « dehors, devant la porte ».
     */
    public function entreeHorsPlateau(): bool
    {
        return data_get($this->acces, 'entree.type') === 'porte';
    }

    /**
     * La case DEVANT la porte d'entrée, sur laquelle un héros hors plateau entre ; `null` sans entrée par une porte.
     *
     * @return array{x: int, y: int}|null
     */
    public function caseDEntree(): ?array
    {
        if (! $this->entreeHorsPlateau()) {
            return null;
        }
        $case = data_get($this->acces, 'entree.cases.0');

        return is_array($case) ? ['x' => (int) $case['x'], 'y' => (int) $case['y']] : null;
    }

    /**
     * Les cases de l'ENTRÉE quand on entre par une porte de COULOIR (quête construite, René 2026-11-16 :
     * `acces.salle_depart` = `null`) ; `[]` partout ailleurs. Ce couloir tient lieu de salle de départ : il n'y en a
     * pas ({@see sallesDecouvertes()}), et le brouillard s'ouvre depuis ces cases (`EtatGroupe::appliquerBrouillard()`).
     *
     * @return list<array{x: int, y: int}>
     */
    public function entreeEnCouloir(): array
    {
        $acces = (array) ($this->acces ?? []);

        if (! array_key_exists('salle_depart', $acces) || $acces['salle_depart'] !== null) {
            return [];
        }

        return array_values((array) data_get($acces, 'entree.cases', []));
    }

    /**
     * Entrées de fouille, au format `"{salle}:{personnage}"` : chaque héros
     * fouille une fois par salle et tire sa propre carte, comme au plateau.
     *
     * @return list<string>
     */
    public function fouillesFaites(): array
    {
        return array_values(array_unique(array_map(
            fn ($e) => (string) $e,
            (array) ($this->tresors_fouilles ?? []),
        )));
    }

    /**
     * Ce héros a-t-il épuisé ses fouilles dans cette salle ?
     *
     * Une par héros et par salle — sauf `fouille_supplementaire` (Fouineur, de
     * l'explorateur), qui en accorde davantage. Les fouilles au-delà de la
     * première portent un suffixe `#n` : les entrées sont dédoublonnées, et
     * deux passages ne pouvaient donc pas s'inscrire sous la même clé.
     */
    public function aFouille(int $salle, int $personnageId): bool
    {
        $faites = $this->fouillesDe($salle, $personnageId);

        if ($faites === 0) {
            return false;
        }

        // Le talent n'est lu que si une fouille a DÉJÀ eu lieu : le chemin
        // ordinaire (première fouille) ne paie aucune requête supplémentaire,
        // et `MenuMoteur` appelle cette méthode pour chaque salle du donjon.
        $personnage = Personnage::find($personnageId);

        $autorisees = 1 + ($personnage === null
            ? 0
            : app(Talents::class)->valeur($personnage, 'fouille_supplementaire'));

        return $faites >= $autorisees;
    }

    /** Nombre de fouilles déjà inscrites pour ce couple salle/héros. */
    private function fouillesDe(int $salle, int $personnageId): int
    {
        $base = "{$salle}:{$personnageId}";

        return count(array_filter(
            $this->fouillesFaites(),
            fn (string $e) => $e === $base || str_starts_with($e, $base.'#'),
        ));
    }

    /**
     * Index des salles fouillées par AU MOINS un héros — ce qui suffit à
     * l'objectif « atteindre et récupérer » : le coffre du fond n'est ouvert
     * qu'une fois, quel que soit celui qui s'en charge.
     *
     * @return list<int>
     */
    public function tresorsFouilles(): array
    {
        return array_values(array_unique(array_map(
            fn (string $e) => (int) explode(':', $e)[0],
            $this->fouillesFaites(),
        )));
    }

    /** Marque une salle comme découverte. Idempotent. */
    public function marquerSalleDecouverte(int $salle): void
    {
        $vues = $this->sallesDecouvertes();

        if (in_array($salle, $vues, true)) {
            return;
        }

        $vues[] = $salle;
        $this->update(['salles_decouvertes' => array_values($vues)]);
    }

    /** Marque la fouille d'une salle PAR UN HÉROS. Idempotent. */
    public function marquerTresorFouille(int $salle, int $personnageId): void
    {
        if ($this->aFouille($salle, $personnageId)) {
            return;
        }

        $deja = $this->fouillesDe($salle, $personnageId);
        $faites = $this->fouillesFaites();
        $faites[] = $deja === 0 ? "{$salle}:{$personnageId}" : "{$salle}:{$personnageId}#".($deja + 1);
        $this->update(['tresors_fouilles' => array_values($faites)]);
    }

    /**
     * Récit pré-généré d'une SALLE, ou `null` tant que le pack n'a pas été
     * écrit — la quête démarre avant que le job de pré-génération n'ait rendu,
     * et le repli scripté prend alors le relais.
     *
     * DEUX textes, et c'est voulu (René, 2026-08-18) : `texte` est figé et sans
     * variable, ce qui permet d'en pré-enregistrer la voix du narrateur
     * (indexée par hash) ; `entree` nomme l'arrivant via `{heros}` et ne sert
     * que là où cet enregistrement n'existe pas. L'arbitrage est rendu par
     * `BibliothequeNarration::salle()`, sur un fait — le fichier audio est-il
     * là ? — et non sur un réglage.
     *
     * @return array{texte: string, entree?: string, ambiance?: string}|null
     */
    public function recitSalle(int $salle): ?array
    {
        $recit = data_get($this->recits, "salles.{$salle}");

        return is_array($recit) && is_string($recit['texte'] ?? null) && $recit['texte'] !== ''
            ? $recit
            : null;
    }

    /**
     * Variantes pré-générées d'un TEMPS FORT (`fouille_tresor`, `piege_declenche`…).
     * Liste vide = aucune, l'appelant retombe sur `config/narration.php`.
     *
     * @return list<string>
     */
    public function recitsTempsFort(string $cle): array
    {
        $variantes = data_get($this->recits, "temps_forts.{$cle}.variantes", []);

        return array_values(array_filter(
            (array) $variantes,
            fn ($v) => is_string($v) && trim($v) !== '',
        ));
    }

    /** Ambiance déclarée pour un temps fort pré-généré (null = celle du repli). */
    public function ambianceTempsFort(string $cle): ?string
    {
        $ambiance = data_get($this->recits, "temps_forts.{$cle}.ambiance");

        return is_string($ambiance) && $ambiance !== '' ? $ambiance : null;
    }

    /**
     * Cartes de fouille encore dans la pioche, sommet en tête.
     *
     * @return list<array<string, mixed>>
     */
    public function deckFouille(): array
    {
        return array_values(array_filter((array) ($this->deck_fouille ?? []), 'is_array'));
    }

    /**
     * Pioche la carte du dessus et applique SON sort (`DeckFouille::sortDe()`) :
     * repartie SOUS le paquet si elle dit de la remettre (monstre errant, Hazard!),
     * retirée du paquet pour le reste de la quête sinon (or, gemme, bijoux, potion).
     * `null` si le paquet est vide — l'appelant rétrograde alors en « rien ».
     *
     * Le sort d'une carte est lu à UN seul endroit, ici : ne jamais le recopier
     * ailleurs (le piège des deux copies d'une règle simple, `CLAUDE.md`).
     *
     * @return array<string, mixed>|null
     */
    public function piocherCarte(): ?array
    {
        $deck = $this->deckFouille();

        if ($deck === []) {
            return null;
        }

        $carte = array_shift($deck);

        if (DeckFouille::sortDe($carte) === DeckFouille::REMISE_SOUS_LE_PAQUET) {
            $deck[] = $carte;
        }

        $this->update(['deck_fouille' => array_values($deck)]);

        return $carte;
    }

    /**
     * Repose SOUS le paquet une carte que le Trésor caché n'a pas gardée (lot B, 2026-10-23).
     *
     * ⚠ Appelé seulement pour une carte que `piocherCarte()` a RETIRÉE du paquet : une carte
     * REMISE (monstre errant, piège) y est déjà remontée au moment du tirage, la repousser la
     * doublerait. C'est l'appelant qui le sait (`DeckFouille::sortDe()`).
     *
     * @param  array<string, mixed>  $carte
     */
    public function remettreSousLePaquet(array $carte): void
    {
        $this->update(['deck_fouille' => [...$this->deckFouille(), $carte]]);
    }

    /**
     * Salles abritant un coffre. Toujours la salle-artefact si elle existe —
     * le repli couvre les quêtes démarrées avant la colonne.
     *
     * @return list<int>
     */
    public function sallesCoffre(): array
    {
        $salles = array_map('intval', (array) ($this->salles_coffre ?? []));

        if ($this->salle_artefact !== null) {
            $salles[] = (int) $this->salle_artefact;
        }

        return array_values(array_unique($salles));
    }

    /** Cette salle abrite-t-elle un coffre (artefact ou butin) ? */
    public function estSalleCoffre(int $salle): bool
    {
        return in_array($salle, $this->sallesCoffre(), true);
    }

    /**
     * Le coffre de cette salle est-il ENCORE plein ?
     *
     * Un coffre est un objet unique : le premier qui l'ouvre le vide, pour tout
     * le groupe — comme le mobilier fouillable (décision de René, 2026-08-07).
     * La fouille restant UNE PAR HÉROS, chaque compagnon repartait sinon avec le
     * même butin : à quatre, un coffre payait quatre fois (vérifié en base sur
     * une partie réelle — même potion rendue à chaque appel).
     *
     * Lu sur `coffres_ouverts` depuis le 2026-10-02 : le coffre se fouille AU
     * CONTACT (René : « seulement quand on est adjacent et non quand on cherche
     * la salle »), si bien que fouiller la salle n'ouvre plus son coffre — l'état
     * ne peut plus se déduire de `tresors_fouilles` comme avant.
     */
    public function coffrePlein(int $salle): bool
    {
        return $this->estSalleCoffre($salle) && ! in_array($salle, $this->coffresOuverts(), true);
    }

    /** @return list<int> salles dont le coffre désigné a été ouvert */
    public function coffresOuverts(): array
    {
        return array_values(array_map('intval', (array) ($this->coffres_ouverts ?? [])));
    }

    /** Marque le coffre désigné de cette salle comme ouvert. Idempotent. */
    public function marquerCoffreOuvert(int $salle): void
    {
        $ouverts = $this->coffresOuverts();

        if (! in_array($salle, $ouverts, true)) {
            $ouverts[] = $salle;
            $this->update(['coffres_ouverts' => array_values($ouverts)]);
        }
    }

    /** Cette salle est-elle le coffre désigné (celui qui abrite l'artefact) ? */
    public function estSalleArtefact(int $salle): bool
    {
        return $this->salle_artefact !== null && (int) $this->salle_artefact === $salle;
    }

    /**
     * L'objectif du gabarit est-il accompli ?
     *
     * C'est la VRAIE condition de victoire (décision de René). Auparavant on
     * gagnait en nettoyant le donjon et `structure.objectif` n'était lu par
     * personne : atteindre l'objet du fond ou abattre le boss ne changeait
     * rien, et rien n'obligeait jamais à explorer.
     *
     * Un objectif inconnu vaut ACCOMPLI : une donnée qu'on ne sait pas
     * interpréter ne doit jamais enfermer un groupe dans son donjon.
     */
    public function objectifAccompli(): bool
    {
        return match ((string) $this->objectif()) {
            // Le boss de la rencontre finale est tombé.
            'vaincre_sous_boss' => $this->bossAbattu('sous_boss'),
            'vaincre_boss_final' => $this->bossAbattu('boss'),
            // « Atteindre et récupérer » : le coffre désigné du fond a été
            // OUVERT — c'est lui qui porte l'artefact de la quête (au contact
            // depuis le 2026-10-02, d'où `coffresOuverts()`).
            'atteindre_et_recuperer' => $this->salle_artefact !== null
                && in_array((int) $this->salle_artefact, $this->coffresOuverts(), true),
            // MISSION « SECOURIR » (chantier 3b, 2026-10-04, étendu par le
            // chantier escalier-entrée du 2026-10-05 — Gothar, Frozen Horror
            // p. 19 : « escort » ; le Prospecteur et la Princesse Millandriel,
            // même gabarit) : accompli quand le captif est LIBÉRÉ (il a
            // rejoint le groupe comme allié), encore VIVANT, ET ramené à
            // l'ESCALIER d'entrée — l'extraction, pas la seule libération (la
            // vraie SORTIE du donjon passe ensuite par le vote ordinaire,
            // exactement comme les autres objectifs). S'il est mort (`vaincu`),
            // ce n'est jamais « accompli » : voir {@see self::captifPerdu()},
            // qui échoue la quête sur-le-champ plutôt que d'attendre ce test.
            'secourir' => $this->captifLibereEtVivant(),
            // « DÉTRUIRE UN ÉLÉMENT » (René, 2026-10-09) : l'élément désigné
            // sur la carte est tombé. En pratique la quête s'est alors
            // TERMINÉE d'elle-même (`ResolveurTour`) — ce verdict sert la
            // bannière et les garde-fous, jamais un second chemin de fin.
            'detruire_element' => $this->elementObjectifDetruit(),
            // QUÊTE CONSTRUITE (mode classique, 2026-11-14) : les figures que l'auteur a DÉSIGNÉES sont toutes tombées
            // (`instances_monstres.objectif`). Une désignation, pas un palier de catalogue : la cible peut être un
            // simple gobelin. Sans aucune cible posée, rien n'est actif : accompli — la validation du constructeur
            // exige au moins une cible, et une donnée incomplète n'enferme jamais un groupe dans son donjon.
            self::OBJECTIF_VAINCRE_CIBLES => ! $this->instancesMonstres()
                ->where('objectif', true)
                ->where('etat', 'actif')
                ->exists(),
            // QUÊTE CONSTRUITE, fin « drapeau » (lot K3) : le drapeau désigné par l'auteur a été posé par une note.
            self::OBJECTIF_DRAPEAU => $this->aDrapeau((string) data_get($this->objectif_construit, 'drapeau', '')),
            // QUÊTE CONSTRUITE, fin « sortir » : accomplie en SORTANT, ce qui termine la quête — jamais avant. La sortie
            // ouverte d'emblée ne passe pas par ici (`sortieDisponible()`) : le bandeau ne dit pas « accompli » au départ.
            self::OBJECTIF_SORTIR => false,
            default => true,
        };
    }

    /**
     * Le captif de cette quête (mission « secourir ») — null hors de ce
     * gabarit, ou si aucun n'a pu être posé (aucun profil sourcé disponible
     * pour le catalogue au moment de l'assemblage).
     */
    public function captif(): BelongsTo
    {
        return $this->belongsTo(GroupeMercenaire::class, 'captif_mercenaire_id');
    }

    /**
     * Libéré ET toujours vivant ET ramené à l'ESCALIER d'entrée (chantier
     * escalier-entrée, 2026-10-05 — généralise « escort » de Gothar, Frozen
     * Horror p. 19, à une vraie extraction plutôt qu'à la seule libération).
     * `true` si cette quête n'a pas de captif désigné — un gabarit sans
     * mission de sauvetage ne doit jamais sembler en échouer une.
     *
     * DEUX MODES depuis le chantier « captifs-jetons » (2026-10-05), lus sur
     * la seule valeur de `etat` — point de passage UNIQUE, aucun second
     * calcul qui dériverait :
     *  - `etat: 'actif'` (mode **figurine**, Gothar) : c'est la position DU
     *    CAPTIF lui-même qui doit être sur l'escalier — il s'y déplace comme
     *    n'importe quel allié.
     *  - `etat: 'porte'` (mode **escorté**, le Prospecteur, la Princesse
     *    Millandriel) : le captif n'a pas de case propre — c'est son
     *    PORTEUR (`recruteur_personnage_id`) qui doit être sur l'escalier.
     *
     * ⚠ REPLI (décision 5 du plan) : une carte assemblée AVANT le chantier
     * escalier-entrée (campagne EN COURS) ne porte pas la couche `escalier` —
     * `Carte::casesEscalier()` rend alors `[]`, et l'ancien critère
     * (libération seule) s'applique, comme avant.
     */
    public function captifLibereEtVivant(): bool
    {
        $captif = $this->captif;

        if ($captif === null) {
            return true;
        }

        if (! in_array($captif->etat, ['actif', 'porte'], true)) {
            return false;
        }

        $escalier = $this->carte?->casesEscalier() ?? [];

        if ($escalier === []) {
            return true;
        }

        // ⚠ EXTRACTION : le captif — ou son porteur — doit être SUR UNE CASE de
        // l'escalier. Décision de René (2026-10-10) : NE PAS l'assouplir en « salle
        // de départ ». Seule la sortie des HÉROS (`rassemblementDepart()`) bénéficie
        // de la règle « tous dans la salle de départ » ; les deux vérifications
        // restent DISTINCTES.
        if ($captif->etat === 'porte') {
            $porteur = $this->etatsPersonnages()
                ->where('personnage_id', $captif->recruteur_personnage_id)
                ->first();

            return $porteur !== null
                && ($this->carte?->surEscalier($porteur->position_x, $porteur->position_y) ?? false);
        }

        return $this->carte?->surEscalier($captif->position_x, $captif->position_y) ?? false;
    }

    /**
     * La sortie du donjon est-elle OUVERTE (objectif accompli, ou donjon vidé
     * quand cela suffit) ? Point de passage UNIQUE : le menu (`quitter_donjon`),
     * le résolveur et le rassemblement publié lisaient chacun leur copie.
     */
    public function sortieDisponible(): bool
    {
        // Fin « sortir » : trouver la sortie EST l'objectif — elle est ouverte dès le départ.
        return $this->objectif() === self::OBJECTIF_SORTIR
            || $this->objectifAccompli()
            // REPLI anti-blocage : un donjon entièrement vidé libère la sortie
            // même si l'objectif reste hors d'atteinte. SAUF « détruire un
            // élément » (`donjonVideOuvreLaSortie()`).
            || ($this->donjonVideOuvreLaSortie()
                && ! $this->instancesMonstres()->where('etat', 'actif')->exists());
    }

    /**
     * ⚠ DIVERGENCE DÉLIBÉRÉE avec les règles officielles (décision de René,
     * 2026-10-10, qu'il ACCEPTE) : dans le livret, chaque héros quitte le donjon
     * en MARCHANT SUR L'ESCALIER ; chez nous, il suffit que tous les héros debout
     * soient dans la SALLE DE DÉPART, puis le groupe vote. Ne pas « corriger » vers
     * la règle du livret. Voir `docs/regles/exploration-et-fouille.md`.
     *
     * ⚠ Cette règle ne vaut QUE pour les HÉROS. La personne à sauver (mission
     * « secourir ») doit toujours être menée SUR une case de l'escalier
     * (`captifLibereEtVivant()`, `Carte::surEscalier()`) — René, 2026-10-10 : deux
     * vérifications distinctes, ne pas les fusionner.
     *
     * RASSEMBLEMENT DANS LA SALLE DE DÉPART (René, 2026-10-10, verdict Jungle
     * §5 : « tous dans la salle de départ » ; remplace « sur l'escalier »,
     * abandonné le même jour). Le vote de sortie ne s'ouvre que si TOUS les
     * héros DEBOUT sont dans la salle qui contient l'escalier d'entrée ; les
     * héros tombés (mode Story) ne bloquent pas. Point de passage UNIQUE :
     * lu par le menu, le résolveur ET le payload de la bannière — le client
     * ne recalcule rien.
     *
     * ⚠ REPLI : une carte sans escalier (campagne assemblée avant le chantier)
     * n'exige aucune position — `actif: false`, `rassemble: true`.
     * `battre_en_retraite` n'est JAMAIS soumis à cette règle.
     *
     * @return array{actif: bool, rassemble: bool, absents: list<array{personnage_id: int, nom: string}>}
     */
    public function rassemblementDepart(): array
    {
        $carte = $this->carte;

        // SORTIE CASE PAR CASE (mode classique) : chacun sort en personne, il n'y a pas de salle où se rassembler. Sans
        // ce repli, la situation du menu disait encore « Rejoignez la salle de départ pour quitter le donjon — il
        // manque : … » à des héros postés devant la porte de sortie (vu à l'écran le 2026-11-16).
        if ($carte === null || $carte->salleDepart() === null || $this->sortieEnPersonne()) {
            return ['actif' => false, 'rassemble' => true, 'absents' => []];
        }

        $absents = [];

        foreach ($this->etatsPersonnages()->with('personnage')->where('tombe', false)->orderBy('id')->get() as $etat) {
            if (! $carte->dansSalleDepart($etat->position_x, $etat->position_y)) {
                $absents[] = [
                    'personnage_id' => (int) $etat->personnage_id,
                    'nom' => (string) ($etat->personnage?->nom ?? 'Un héros'),
                ];
            }
        }

        return ['actif' => true, 'rassemble' => $absents === [], 'absents' => $absents];
    }

    /**
     * La consigne dite AUX JOUEURS quand la sortie est ouverte mais que des
     * héros debout ne sont pas dans la salle de départ : « Rejoignez la salle de
     * départ — il manque : X, Y. ». `null` si rien à dire (repli sans escalier,
     * ou tous réunis). Une seule phrase pour le menu, le 422 et la bannière.
     *
     * @param  array{actif: bool, rassemble: bool, absents: list<array{personnage_id: int, nom: string}>}|null  $rassemblement
     */
    /**
     * LES CASES D'OÙ L'ON QUITTE LE DONJON, et leur type — LE point de passage de la sortie en personne.
     *
     * Une quête construite les porte (`acces.sortie`, figé au démarrage) : les marches d'un escalier, ou la case
     * devant une PORTE DE SORTIE — certaines campagnes entrent et sortent par des portes, pas par un escalier (René,
     * 2026-11-14). À défaut, ce sont les marches de l'escalier de la carte.
     *
     * @return array{type: string, cases: list<array{x: int, y: int}>}
     */
    public function sortieDuDonjon(): array
    {
        $sortie = data_get($this->acces, 'sortie');

        if (is_array($sortie) && ! empty($sortie['cases'])) {
            return [
                'type' => (string) ($sortie['type'] ?? 'escalier'),
                'cases' => array_map(fn ($c): array => ['x' => (int) $c['x'], 'y' => (int) $c['y']], (array) $sortie['cases']),
            ];
        }

        return ['type' => 'escalier', 'cases' => $this->carte?->casesEscalier() ?? []];
    }

    /**
     * SORTIE CASE PAR CASE (mode classique, décision de René du 2026-11-13, Q4) : dans cette quête, chaque héros quitte
     * le donjon EN PERSONNE, depuis une case de sortie — il n'y a ni rassemblement en salle de départ ni vote de
     * groupe. LE prédicat, lu par `MenuMoteur` (l'option), `ResolveurTour` (sa revalidation) et `EtatGroupe` (la
     * consigne) : un groupe classique, dans une quête qui a des cases de sortie.
     */
    public function sortieEnPersonne(): bool
    {
        return (bool) $this->groupe?->estClassique() && $this->sortieDuDonjon()['cases'] !== [];
    }

    /**
     * Ce héros peut-il quitter le donjon MAINTENANT ? La sortie est ouverte (`sortieDisponible()` : l'objectif est
     * accompli), il est debout, et il se tient sur une case de sortie. Menu et résolveur lisent ceci.
     */
    public function peutSortirEnPersonne(EtatPersonnageQuete $etat): bool
    {
        if (! $this->sortieEnPersonne() || ! $this->sortieDisponible() || $etat->tombe) {
            return false;
        }

        foreach ($this->sortieDuDonjon()['cases'] as $case) {
            if ($case['x'] === (int) $etat->position_x && $case['y'] === (int) $etat->position_y) {
                return $etat->position_x !== null && $etat->position_y !== null;
            }
        }

        return false;
    }

    /** Le lieu de sortie, dit aux joueurs : « l'escalier » ou « la porte de sortie ». */
    public function nomDeLaSortie(): string
    {
        return $this->sortieDuDonjon()['type'] === 'porte' ? 'la porte de sortie' : 'l\'escalier';
    }

    /**
     * Les FAUSSES PORTES DE SORTIE de la quête construite (`acces.fausses_sorties`, figées au départ) — René,
     * 2026-11-16 : « une quête avait 2 portes de sortie mais l'une était une fausse ». Chacune :
     * `{type: porte, cases: [{x, y}], porte: {x, y, cote}, revelee: bool}`.
     *
     * @return list<array<string, mixed>>
     */
    public function faussesSorties(): array
    {
        return array_values((array) data_get($this->acces, 'fausses_sorties', []));
    }

    /** L'index de la fausse sortie ENCORE NON RÉVÉLÉE devant laquelle se tient ce héros, ou `null`. */
    public function fausseSortieSous(EtatPersonnageQuete $etat): ?int
    {
        foreach ($this->faussesSorties() as $i => $fausse) {
            if (! empty($fausse['revelee'])) {
                continue;
            }
            foreach ((array) $fausse['cases'] as $case) {
                if ((int) $case['x'] === (int) $etat->position_x && (int) $case['y'] === (int) $etat->position_y) {
                    return (int) $i;
                }
            }
        }

        return null;
    }

    /**
     * LA SORTIE OFFERTE à ce héros, là où il se tient — LE point de passage du menu et du résolveur :
     *  - `vraie` : il peut quitter le donjon (`peutSortirEnPersonne()`) ;
     *  - `fausse` : il se tient devant une fausse porte de sortie encore inconnue, AUX MÊMES CONDITIONS qu'une vraie
     *    (objectif accompli, debout). Le menu lui offre la MÊME option, avec le même libellé : une fausse porte qu'on
     *    reconnaîtrait au menu ne tromperait personne. Ce n'est pas offrir ce que le résolveur refusera : il ne refuse
     *    pas, il RÉVÈLE que la porte est fausse — l'issue de l'action, comme une fouille qui ne trouve rien ;
     *  - `null` : rien ici.
     */
    public function sortieOfferte(EtatPersonnageQuete $etat): ?string
    {
        if ($this->peutSortirEnPersonne($etat)) {
            return 'vraie';
        }

        if ($this->sortieEnPersonne() && $this->sortieDisponible() && ! $etat->tombe && $etat->position_x !== null
            && $this->fausseSortieSous($etat) !== null) {
            return 'fausse';
        }

        return null;
    }

    /** Le lieu de sortie que ce héros a sous les pieds, dit aux joueurs — une fausse porte se nomme comme une vraie. */
    public function nomDeLaSortieSous(EtatPersonnageQuete $etat): string
    {
        return $this->fausseSortieSous($etat) !== null ? 'la porte de sortie' : $this->nomDeLaSortie();
    }

    /** Une fausse sortie a été essayée : elle est révélée, pour tout le groupe. État durable, en colonne. */
    public function revelerFausseSortie(int $index): void
    {
        $acces = (array) $this->acces;
        if (isset($acces['fausses_sorties'][$index])) {
            $acces['fausses_sorties'][$index]['revelee'] = true;
            $this->update(['acces' => $acces]);
        }
    }

    /** La consigne dite aux joueurs quand la sortie en personne est ouverte ; `null` sinon. */
    public function consigneDeSortie(): ?string
    {
        if (! $this->sortieEnPersonne() || ! $this->sortieDisponible()) {
            return null;
        }

        // Tant qu'une fausse porte de sortie reste inconnue, la consigne ne NOMME pas le lieu : dire « par l'escalier »
        // dénoncerait la porte comme fausse.
        $inconnue = collect($this->faussesSorties())->contains(fn (array $f) => empty($f['revelee']));

        // Fin « sortir » : la sortie est ouverte d'emblée, rien n'est encore « accompli » — il faut la TROUVER.
        if ($this->objectif() === self::OBJECTIF_SORTIR) {
            return $inconnue
                ? 'Trouvez la sortie et quittez le donjon.'
                : 'Trouvez '.$this->nomDeLaSortie().' et quittez le donjon.';
        }

        // Courte : l'en-tête de la table la coupait (vu à l'écran le 2026-11-14).
        return $inconnue
            ? 'Objectif accompli : quittez le donjon.'
            : 'Objectif accompli : quittez le donjon par '.$this->nomDeLaSortie().'.';
    }

    public function consigneRassemblement(?array $rassemblement = null): ?string
    {
        $rassemblement ??= $this->rassemblementDepart();

        if (! $rassemblement['actif'] || $rassemblement['rassemble']) {
            return null;
        }

        $noms = implode(', ', array_column($rassemblement['absents'], 'nom'));

        return "Rejoignez la salle de départ pour quitter le donjon — il manque : {$noms}.";
    }

    /**
     * Consigne d'EXTRACTION (mission « secourir ») : le captif est libéré et vivant
     * mais n'est pas encore sur l'escalier — « Amenez X sur l'escalier. » En mode
     * escorté, c'est son porteur qu'il faut y mener. `null` sinon. Décidée par le
     * serveur à côté de `captifLibereEtVivant()` (même lecteur, jamais recalculée
     * côté client).
     */
    public function consigneExtraction(): ?string
    {
        $captif = $this->captif;

        if ($captif === null || ! in_array($captif->etat, ['actif', 'porte'], true) || $this->captifLibereEtVivant()) {
            return null;
        }

        $nom = $captif->mercenaire?->nom ?? 'le captif';

        return $captif->etat === 'porte'
            ? "Menez le porteur de {$nom} sur l'escalier d'entrée."
            : "Amenez {$nom} sur l'escalier d'entrée.";
    }

    /**
     * Le captif a-t-il péri (0 PV, `etat: 'vaincu'`) ? C'est ce qui fait
     * ÉCHOUER la quête, aussi sûrement qu'un TPK — le lecteur qui l'applique
     * est `ResolveurTour::verifierEchecCaptif()`, appelé après toute attaque
     * de monstre contre un allié.
     */
    public function captifPerdu(): bool
    {
        $captif = $this->captif;

        return $captif !== null && $captif->etat === 'vaincu';
    }

    /**
     * Objectif brut du gabarit (`vaincre_boss_final`…), ou `null` s'il n'en
     * déclare aucun. Même source que {@see self::objectifAccompli()} — un seul
     * lecteur de la donnée, deux usages.
     */
    /** L'objectif d'une quête construite « vaincre » : les figures désignées par l'auteur. */
    public const OBJECTIF_VAINCRE_CIBLES = 'vaincre_cibles';

    /**
     * L'objectif d'une quête construite « drapeau » : un drapeau de quête, posé par une NOTE, est levé. C'est ce qui
     * dit « trouver l'objet » (la note de fouille pose le drapeau) comme « atteindre ce point » (la note d'entrée le
     * pose) : une seule condition de fin, écrite par l'auteur.
     */
    public const OBJECTIF_DRAPEAU = 'drapeau_pose';

    /**
     * L'objectif d'une quête construite « sortir » (René, 2026-11-16, après la relecture de *Kellar's Keep* : « find the
     * wooden exit door ») : trouver la sortie et quitter le donjon. La sortie est ouverte DÈS LE DÉPART
     * ({@see sortieDisponible()}) ; l'objectif, lui, n'est jamais « accompli » en cours de quête — il l'est quand le
     * dernier héros debout est sorti, et la quête se termine alors (`ResolveurTour::resoudreSortieEnPersonne()`).
     */
    public const OBJECTIF_SORTIR = 'sortir_donjon';

    /** Type de `objectif_construit` (le format du constructeur) → objectif lu par le moteur. Vocabulaire fermé. */
    public const OBJECTIFS_CONSTRUITS = [
        'vaincre' => self::OBJECTIF_VAINCRE_CIBLES,
        'drapeau' => self::OBJECTIF_DRAPEAU,
        'sortir' => self::OBJECTIF_SORTIR,
    ];

    public function objectif(): ?string
    {
        // QUÊTE CONSTRUITE : l'objectif vient de l'auteur, pas d'un gabarit (elle n'en a pas). Il passe en premier —
        // une quête construite n'a ni élément désigné par l'assembleur ni gabarit à consulter.
        $construit = data_get($this->objectif_construit, 'type');
        if (is_string($construit) && isset(self::OBJECTIFS_CONSTRUITS[$construit])) {
            return self::OBJECTIFS_CONSTRUITS[$construit];
        }

        // L'élément à détruire est désigné par la CARTE (clé `objectif` d'une
        // entrée de mobilier, posée à l'assemblage) et PRIME sur l'objectif du
        // gabarit : le gabarit « Confrontation finale » est commun à tous les
        // thèmes, seule la quête finale de Morcar se gagne sur le Haut Autel.
        // Une carte sans élément désigné (campagne EN COURS) garde l'objectif
        // de son gabarit — repli écrit, jamais une quête rendue injouable.
        if ($this->elementObjectif() !== null) {
            return 'detruire_element';
        }

        $objectif = data_get($this->gabarit?->structure, 'objectif');

        return is_string($objectif) && $objectif !== '' ? $objectif : null;
    }

    /**
     * L'objectif dit AUX JOUEURS, sans vocabulaire de jeu.
     *
     * Il était lu par le moteur depuis toujours et montré à personne : en
     * campagne réelle (2026-08-20) le groupe a exploré huit salles sur dix en
     * s'éloignant jusqu'à trente-huit cases du boss, sans jamais savoir qu'il
     * fallait le chercher. Un objectif inconnu ne rend rien plutôt que
     * d'inventer une consigne — même prudence que `objectifAccompli()`, qui
     * tient l'inconnu pour accompli.
     */
    public function objectifLibelle(): ?string
    {
        return match ($this->objectif()) {
            'vaincre_sous_boss' => 'Débusquer et abattre le gardien des lieux.',
            'vaincre_boss_final' => 'Trouver le maître de ce donjon et le mettre à terre.',
            'atteindre_et_recuperer' => 'Atteindre la salle la plus profonde et en ramener ce qu’elle garde.',
            'quitter_donjon' => 'Ressortir vivants.',
            // Libellé du type générique « détruire un élément » : le nom du
            // catalogue (« Détruire : Haut Autel. »).
            'detruire_element' => 'Détruire : '.($this->elementObjectif()['nom'] ?? 'l\'élément désigné').'.',
            // Mission « secourir » (chantier 3b, texte adapté par le chantier
            // escalier-entrée du 2026-10-05) : le nom du captif quand il est
            // déjà connu (l'IA peut l'avoir habillé), sinon générique — jamais
            // un libellé muet qui dirait seulement « quelqu'un ». « L'escalier »,
            // pas « la sortie » : c'est lui, précisément, qu'il faut atteindre
            // (voir `captifLibereEtVivant()`), la sortie du donjon suit ensuite
            // le vote ordinaire.
            'secourir' => 'Retrouver '.($this->captif?->mercenaire?->nom ?? 'le captif')
                .' et le ramener vivant à l\'escalier.',
            // QUÊTE CONSTRUITE : la consigne est celle que l'AUTEUR a écrite. Le moteur n'en déduit rien — nommer
            // lui-même les cibles révélerait une figure que le groupe n'a pas encore vue.
            self::OBJECTIF_VAINCRE_CIBLES, self::OBJECTIF_DRAPEAU, self::OBJECTIF_SORTIR => ((string) data_get($this->objectif_construit, 'libelle', '')) ?: null,
            default => null,
        };
    }

    /**
     * L'élément-objectif de la carte (type `detruire_element`), ou `null`.
     * Lit `MoteurMobilier::elementObjectif()` — point de passage unique de la
     * désignation — et y ajoute le nom du catalogue pour le libellé.
     *
     * @return array{index: int, entree: array<string, mixed>, nom: string, detruit: bool}|null
     */
    public function elementObjectif(): ?array
    {
        $trouve = MoteurMobilier::elementObjectif((array) ($this->carte?->grille ?? []));

        if ($trouve === null) {
            return null;
        }

        return [
            ...$trouve,
            'nom' => (string) (Mobilier::find((int) ($trouve['entree']['mobilier_id'] ?? 0))?->nom ?? 'l\'élément désigné'),
            'detruit' => MoteurMobilier::estDetruite($trouve['entree']),
        ];
    }

    private function elementObjectifDetruit(): bool
    {
        return $this->elementObjectif()['detruit'] ?? true;
    }

    /**
     * Un donjon VIDÉ de ses monstres ouvre-t-il la sortie à lui seul ? Oui,
     * partout, SAUF quand l'objectif est de détruire un élément : le livret ne
     * connaît alors pas d'autre victoire (« Destroy the High Altar to complete
     * this quest », G1504 p. 39), et le repli anti-blocage « mieux vaut rentrer
     * bredouille » porterait le groupe à la sortie, autel debout. Point de
     * passage UNIQUE — `MenuMoteur` (l'offre) et `ResolveurTour` (la garde).
     */
    public function donjonVideOuvreLaSortie(): bool
    {
        return $this->objectif() !== 'detruire_element';
    }

    /** Plus aucune instance ACTIVE de ce tier — le boss désigné est vaincu. */
    private function bossAbattu(string $tier): bool
    {
        return ! $this->instancesMonstres()
            ->where('etat', 'actif')
            ->whereHas('monstre', fn ($q) => $q->where('tier', $tier))
            ->exists();
    }

    /**
     * Y a-t-il, n'importe où sur le plateau de cette quête, un monstre ACTIF
     * ET RÉVÉLÉ ?
     *
     * C'est la « menace » dont dépend *Unthreatened Movement* (FL-Q p. 7,
     * First Light, 2026-09-30) : sans elle, chaque dé rouge de mouvement
     * compte 4 au lieu d'être lancé (`App\Engine\Deplacement::calculer()`,
     * seul appelé par `MenuMoteur::deplacementDuTour()`). Même filtre que
     * l'ambiance sonore de la table (`EtatGroupe::sceneAmbiance()`, qui
     * retombe sur « exploration » dans exactement ce cas) : un monstre
     * dormant derrière une porte jamais ouverte n'est pas une menace, un
     * monstre actif mais pas encore révélé non plus — c'est la même
     * distinction, pas une seconde définition du mot.
     */
    public function monstreActifRevele(): bool
    {
        return $this->instancesMonstres()->where('etat', 'actif')->where('revele', true)->exists();
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupe_id');
    }

    public function gabarit(): BelongsTo
    {
        return $this->belongsTo(GabaritQuete::class, 'gabarit_id');
    }

    public function carte(): HasOne
    {
        return $this->hasOne(Carte::class, 'quete_id');
    }

    public function instancesMonstres(): HasMany
    {
        return $this->hasMany(InstanceMonstre::class, 'quete_id');
    }

    public function evenements(): HasMany
    {
        return $this->hasMany(Evenement::class, 'quete_id');
    }

    /** Positions & statuts de tour des personnages (runtime). */
    public function etatsPersonnages(): HasMany
    {
        return $this->hasMany(EtatPersonnageQuete::class, 'quete_id');
    }
}
