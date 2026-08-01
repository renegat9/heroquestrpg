<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClasseHeros extends Model
{
    protected $table = 'classes_heros';

    protected $fillable = [
        'nom',
        'pv_body',
        'pv_mind',
        'attr_body',
        'attr_mind',
        'des_attaque',
        'des_defense',
        'deplacement_base',
        'bonus_sac',
        'tags_equipement',
    ];

    protected function casts(): array
    {
        return [
            // Maîtrises d'équipement accessibles SANS nœud (doc 01 §7).
            'tags_equipement' => 'array',
        ];
    }
}
