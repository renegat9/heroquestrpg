<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClasseHeros extends Model
{
    protected $table = 'classes_heros';

    protected $fillable = [
        'nom',
        // `humain` · `nain` · `elfe` · `halfling` — porte le SOCLE de mouvement
        // (doc 01 §4bis-2) et s'affiche sur la fiche de classe du guide.
        'race',
        'pv_body',
        'pv_mind',
        'attr_body',
        'attr_mind',
        'des_attaque',
        'des_defense',
        'deplacement_base',
        'bonus_sac',
        'tags_equipement',
        // Liste blanche NOMINATIVE d'objets (Moine). `null` = les tags font foi.
        'objets_autorises',
    ];

    protected function casts(): array
    {
        return [
            'objets_autorises' => 'array',
            // Maîtrises d'équipement accessibles SANS nœud (doc 01 §7).
            'tags_equipement' => 'array',
        ];
    }
}
