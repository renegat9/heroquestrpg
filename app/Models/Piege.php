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
        // `null` = toutes les boîtes (migration `boite_pieges_et_mobiliers`,
        // même convention que `Terrain::boite`) ; une valeur réserve le
        // piège au thème de bestiaire correspondant (ex. `horde_ogre`).
        'boite',
    ];

    protected function casts(): array
    {
        return [
            'detectable' => 'boolean',
            'effet' => 'array',
        ];
    }
}
