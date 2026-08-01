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
        'deck_fouille',
        'salle_artefact',
        'artefact_objet_id',
        'budget_errant',
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
            // Deck de fouille : pioche ordonnée, index 0 = sommet.
            'deck_fouille' => 'array',
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
     * Index des salles dont le trésor a déjà été fouillé (anti-farm).
     *
     * @return list<int>
     */
    public function tresorsFouilles(): array
    {
        return array_values(array_unique(array_map('intval', (array) ($this->tresors_fouilles ?? []))));
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

    /** Marque le trésor d'une salle comme fouillé. Idempotent. */
    public function marquerTresorFouille(int $salle): void
    {
        $fouilles = $this->tresorsFouilles();

        if (in_array($salle, $fouilles, true)) {
            return;
        }

        $fouilles[] = $salle;
        $this->update(['tresors_fouilles' => array_values($fouilles)]);
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

        $carte = array_shift($deck);
        $this->update(['deck_fouille' => array_values($deck)]);

        return $carte;
    }

    /** Cette salle est-elle le coffre désigné (celui qui abrite l'artefact) ? */
    public function estSalleArtefact(int $salle): bool
    {
        return $this->salle_artefact !== null && (int) $this->salle_artefact === $salle;
    }

    /**
     * Budget de monstres errants restant. Le repli sur le gabarit couvre les
     * quêtes démarrées AVANT la migration, dont la colonne est nulle (le budget
     * vivait alors en cache).
     */
    public function budgetErrant(): int
    {
        return (int) ($this->budget_errant ?? data_get($this->gabarit?->structure, 'budget_errant', 0));
    }

    public function consommerBudgetErrant(int $cout): void
    {
        $this->update(['budget_errant' => max(0, $this->budgetErrant() - max(0, $cout))]);
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
