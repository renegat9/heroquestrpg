<?php

namespace App\Models;

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
        ];
    }

    /**
     * Index des salles déjà découvertes. La salle 0 (départ) l'est toujours :
     * elle est semée par DemarreurQuete et ne dépend d'aucune révélation.
     *
     * @return list<int>
     */
    public function sallesDecouvertes(): array
    {
        $vues = array_map('intval', (array) ($this->salles_decouvertes ?? []));

        return array_values(array_unique([0, ...$vues]));
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
     * Pioche la carte du dessus SANS REMISE et persiste le reste. `null` si la
     * pioche est épuisée (l'appelant rétrograde alors en « rien »).
     *
     * @return array<string, mixed>|null
     */
    public function piocherCarte(): ?array
    {
        $deck = $this->deckFouille();

        if ($deck === []) {
            return null;
        }

        // La carte repart SOUS le paquet (règle du plateau) : le deck ne
        // s'épuise jamais, il cycle. Avec une fouille par héros ET par salle,
        // un donjon de 6 salles à 4 joueurs produit jusqu'à 24 tirages — soit
        // exactement la taille du deck.
        $carte = array_shift($deck);
        $deck[] = $carte;
        $this->update(['deck_fouille' => array_values($deck)]);

        return $carte;
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
    public function objectif(): ?string
    {
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
