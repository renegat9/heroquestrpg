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
        // Boîte d'extension qui rend seule cet effet UTILE (2026-09-24,
        // `DeckFouille::choisirArtefact()`) ; null = utilisable dans toute
        // campagne, quel que soit son thème.
        'boite',
    ];

    protected function casts(): array
    {
        return [
            'metallique' => 'boolean',
            'effet' => 'array',
        ];
    }

    public function lignesInventaire(): HasMany
    {
        return $this->hasMany(Inventaire::class, 'objet_id');
    }
}
