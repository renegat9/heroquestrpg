<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Piege extends Model
{
    protected $table = 'pieges';

    protected $fillable = [
        'nom',
        'detectable',
        'desarmable',
        'usage',
        'effet',
    ];

    protected function casts(): array
    {
        return [
            'detectable' => 'boolean',
            'effet' => 'array',
        ];
    }
}
