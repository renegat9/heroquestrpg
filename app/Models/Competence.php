<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Competence extends Model
{
    protected $table = 'competences';

    protected $fillable = [
        'classe',
        'nom',
        'type',
        'effet',
        'prerequis_id',
    ];

    protected function casts(): array
    {
        return [
            'effet' => 'array',
        ];
    }

    /** Nœud parent dans l'arbre. */
    public function prerequis(): BelongsTo
    {
        return $this->belongsTo(Competence::class, 'prerequis_id');
    }

    /** Nœuds débloqués par celui-ci. */
    public function suivantes(): HasMany
    {
        return $this->hasMany(Competence::class, 'prerequis_id');
    }

    public function personnages(): BelongsToMany
    {
        return $this->belongsToMany(Personnage::class, 'personnage_competences', 'competence_id', 'personnage_id');
    }
}
