<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalogue de référence du mobilier de salle (doc 17) — 8 types dont
 * l'emprise a été mesurée sur les cartes de quête officielles. Données
 * seedées, jamais modifiées en jeu (même statut que Piege/Tuile).
 *
 * `fouillable` n'a AUCUN lecteur pour l'instant : la fouille du mobilier est
 * un chantier séparé (doc 17 §4, `DeckFouille` raisonne en salle, pas en
 * case). Le drapeau existe pour ne pas ré-ouvrir la migration le jour venu.
 */
class Mobilier extends Model
{
    protected $table = 'mobiliers';

    protected $fillable = [
        'nom',
        'nom_anglais',
        'largeur',
        'hauteur',
        'bloquant',
        'fouillable',
        'effet',
    ];

    protected function casts(): array
    {
        return [
            'bloquant' => 'boolean',
            'fouillable' => 'boolean',
            'effet' => 'array',
        ];
    }
}
