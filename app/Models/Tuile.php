<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tuile extends Model
{
    protected $table = 'tuiles';

    protected $fillable = [
        'type',
        'theme',
        'grille',
    ];

    protected function casts(): array
    {
        return [
            'grille' => 'array',
        ];
    }
}
