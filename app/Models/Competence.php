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
        'description',
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

    /**
     * Résistance de condition (mécanique `resistance_condition`, ex. Sang
     * robuste du Nain vs Empoisonné, CompetenceSeeder) : le personnage a-t-il
     * acquis un nœud qui résiste nommément à `$nomCondition` ?
     */
    public static function resisteA(Personnage $personnage, string $nomCondition): bool
    {
        return $personnage->competences()
            ->get(['competences.id', 'competences.effet'])
            ->contains(fn (self $c) => ($c->effet['mecanique'] ?? null) === 'resistance_condition'
                && ($c->effet['condition_nom'] ?? null) === $nomCondition);
    }
}
