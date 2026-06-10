<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ForgeAmelioration extends Model
{
    protected $table = 'forge_ameliorations';

    protected $fillable = [
        'nom',
        'cible',
        'effet',
        'prix',
    ];

    protected function casts(): array
    {
        return [
            'effet' => 'array',
        ];
    }
}
