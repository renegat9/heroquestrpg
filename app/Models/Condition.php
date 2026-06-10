<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Condition extends Model
{
    protected $table = 'conditions';

    protected $fillable = [
        'nom',
        'type',
        'effet',
        'duree_defaut',
    ];

    protected function casts(): array
    {
        return [
            'effet' => 'array',
        ];
    }

    public function personnages(): BelongsToMany
    {
        return $this->belongsToMany(Personnage::class, 'personnage_conditions', 'condition_id', 'personnage_id')
            ->withPivot(['duree', 'source']);
    }
}
