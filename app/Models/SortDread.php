<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SortDread extends Model
{
    protected $table = 'sorts_dread';

    protected $fillable = [
        'nom',
        'palier',
        'type',
        'effet',
    ];

    protected function casts(): array
    {
        return [
            'effet' => 'array',
        ];
    }
}
