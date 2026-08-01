<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Objet extends Model
{
    protected $table = 'objets';

    protected $fillable = [
        'nom',
        'categorie',
        'rarete',
        'prix_base',
        'emplacement',
        // Maîtrise requise pour ÉQUIPER la pièce (doc 01 §7) ; null = aucune.
        'tag_equipement',
        'effet',
    ];

    protected function casts(): array
    {
        return [
            'effet' => 'array',
        ];
    }

    public function lignesInventaire(): HasMany
    {
        return $this->hasMany(Inventaire::class, 'objet_id');
    }
}
