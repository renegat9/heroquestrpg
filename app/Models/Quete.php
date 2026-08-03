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
        'salles_coffre',
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
     * Entrées brutes de fouille. Deux formats coexistent :
     *  · `"{salle}:{personnage}"` — une fouille par HÉROS et par salle, comme
     *    au plateau où chaque héros tire sa propre carte de trésor ;
     *  · un entier nu — ancien format « salle fouillée pour tout le groupe »,
     *    conservé pour les quêtes démarrées avant ce changement.
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

        // Ancien format : la salle était close pour tout le monde.
        return in_array("{$salle}:{$personnageId}", $faites, true)
            || in_array((string) $salle, $faites, true);
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
