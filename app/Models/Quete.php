<?php

namespace App\Models;

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
        'branche_active',
        'salles_decouvertes',
        'tresors_fouilles',
        'recits',
        'deck_fouille',
        'salle_artefact',
        'salles_coffre',
        'artefact_objet_id',
        'etat',
        'or_initial',
    ];

    protected function casts(): array
    {
        return [
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

    /** Ce héros a-t-il déjà fouillé cette salle ? */
    public function aFouille(int $salle, int $personnageId): bool
    {
        $faites = $this->fouillesFaites();

        return in_array("{$salle}:{$personnageId}", $faites, true);
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

        $faites = $this->fouillesFaites();
        $faites[] = "{$salle}:{$personnageId}";
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
     * Dérivé de `tresors_fouilles` plutôt que d'un nouveau marqueur : une salle
     * déjà fouillée par QUI QUE CE SOIT a vu son coffre ouvert. Aucune colonne
     * en plus, et l'état suit les snapshots tout seul.
     *
     * ⚠ À interroger AVANT `marquerTresorFouille()`, qui inscrit justement la
     * salle dans cette liste.
     */
    public function coffrePlein(int $salle): bool
    {
        return $this->estSalleCoffre($salle) && ! in_array($salle, $this->tresorsFouilles(), true);
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
        return match ((string) data_get($this->gabarit?->structure, 'objectif')) {
            // Le boss de la rencontre finale est tombé.
            'vaincre_sous_boss' => $this->bossAbattu('sous_boss'),
            'vaincre_boss_final' => $this->bossAbattu('boss'),
            // « Atteindre et récupérer » : le coffre désigné du fond a été
            // fouillé — c'est lui qui porte l'artefact de la quête.
            'atteindre_et_recuperer' => $this->salle_artefact !== null
                && in_array((int) $this->salle_artefact, $this->tresorsFouilles(), true),
            default => true,
        };
    }

    /**
     * Objectif brut du gabarit (`vaincre_boss_final`…), ou `null` s'il n'en
     * déclare aucun. Même source que {@see self::objectifAccompli()} — un seul
     * lecteur de la donnée, deux usages.
     */
    public function objectif(): ?string
    {
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
            default => null,
        };
    }

    /** Plus aucune instance ACTIVE de ce tier — le boss désigné est vaincu. */
    private function bossAbattu(string $tier): bool
    {
        return ! $this->instancesMonstres()
            ->where('etat', 'actif')
            ->whereHas('monstre', fn ($q) => $q->where('tier', $tier))
            ->exists();
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
