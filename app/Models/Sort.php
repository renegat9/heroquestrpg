<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Sort extends Model
{
    protected $table = 'sorts';

    protected $fillable = [
        'element',
        'nom',
        'type',
        'difficulte_parchemin',
        'effet',
    ];

    protected function casts(): array
    {
        return [
            'effet' => 'array',
        ];
    }

    public function personnages(): BelongsToMany
    {
        return $this->belongsToMany(Personnage::class, 'personnage_sorts', 'sort_id', 'personnage_id')
            ->withPivot('disponible');
    }
}
