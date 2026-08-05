<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalogue de référence du mobilier de salle (doc 17) — 8 types dont
 * l'emprise a été mesurée sur les cartes de quête officielles. Données
 * seedées, jamais modifiées en jeu (même statut que Piege/Tuile).
 *
 * `bloque_mouvement` et `bloque_vue` sont DEUX propriétés indépendantes
 * (migration `separer_bloque_mouvement_bloque_vue_mobilier`) : une table
 * bloque le passage mais on voit par-dessus, une bibliothèque bloque les
 * deux. Ne JAMAIS les refusionner en un seul drapeau — c'est exactement le
 * bug corrigé par cette séparation (une case occupée coupait aussi la ligne
 * de vue de toute arme à distance, cf. `FabriqueGrille`/`Grille::occulter()`).
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
        'bloque_mouvement',
        'bloque_vue',
        'fouillable',
        'effet',
    ];

    protected function casts(): array
    {
        return [
            'bloque_mouvement' => 'boolean',
            'bloque_vue' => 'boolean',
            'fouillable' => 'boolean',
            'effet' => 'array',
        ];
    }
}
