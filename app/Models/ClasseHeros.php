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
    ];
}
