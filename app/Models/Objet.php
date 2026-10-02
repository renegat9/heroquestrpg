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
        // Lien DÉCLARÉ vers l'arme ordinaire dont cette ligne est la copie en
        // OS (Against the Ogre Horde p. 8) — son `nom` ; null = pas une copie.
        'os_de',
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
