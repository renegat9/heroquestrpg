<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Personnage extends Model
{
    protected $table = 'personnages';

    protected $fillable = [
        'joueur_id',
        'groupe_actif_id',
        'nom',
        'classe',
        'niveau',
        'attribut_body',
        'attribut_mind',
        'pv_body_max',
        'pv_body',
        'pv_mind_max',
        'pv_mind',
        'des_attaque',
        'des_defense',
        'deplacement_base',
        'or',
    ];

    /** Propriétaire (roster). */
    public function joueur(): BelongsTo
    {
        return $this->belongsTo(Joueur::class, 'joueur_id');
    }

    /** Groupe où le personnage est engagé (un seul actif à la fois). */
    public function groupeActif(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupe_actif_id');
    }

    /** Tous les groupes (composition & initiative). */
    public function groupes(): BelongsToMany
    {
        return $this->belongsToMany(Groupe::class, 'groupe_personnages', 'personnage_id', 'groupe_id')
            ->withPivot(['ordre_initiative', 'actif']);
    }

    /** Nœuds d'arbre acquis. */
    public function competences(): BelongsToMany
    {
        return $this->belongsToMany(Competence::class, 'personnage_competences', 'personnage_id', 'competence_id');
    }

    /**
     * Points de compétence disponibles — JAMAIS stockés, toujours dérivés
     * (contrat) : 1 point par niveau gagné, moins les nœuds déjà acquis.
     */
    public function pointsCompetence(): int
    {
        return max(0, ((int) $this->niveau - 1) - $this->competences()->count());
    }

    /** Lignes d'inventaire (équipé + sac + consommables). */
    public function inventaire(): HasMany
    {
        return $this->hasMany(Inventaire::class, 'personnage_id');
    }

    /** Sorts connus, avec disponibilité (épuisé/dispo par quête). */
    public function sorts(): BelongsToMany
    {
        return $this->belongsToMany(Sort::class, 'personnage_sorts', 'personnage_id', 'sort_id')
            ->withPivot('disponible');
    }

    /** États temporaires (durée + source). */
    public function conditions(): BelongsToMany
    {
        return $this->belongsToMany(Condition::class, 'personnage_conditions', 'personnage_id', 'condition_id')
            ->withPivot(['duree', 'source']);
    }

    /** Résumés des campagnes terminées (survit au nettoyage du groupe). */
    public function historique(): HasMany
    {
        return $this->hasMany(PersonnageHistorique::class, 'personnage_id');
    }

    /** Position & statut de tour par quête (runtime). */
    public function etatsQuete(): HasMany
    {
        return $this->hasMany(EtatPersonnageQuete::class, 'personnage_id');
    }
}
