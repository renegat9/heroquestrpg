<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceMonstre extends Model
{
    protected $table = 'instances_monstres';

    protected $fillable = [
        'quete_id',
        'monstre_id',
        'pv_body',
        'pv_mind',
        'position_x',
        'position_y',
        'etat',
        'revele',
        'habillage',
    ];

    protected function casts(): array
    {
        return [
            'habillage' => 'array',
            'revele' => 'boolean',
        ];
    }

    public function quete(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_id');
    }

    /** Bloc de stats du catalogue. */
    public function monstre(): BelongsTo
    {
        return $this->belongsTo(Monstre::class, 'monstre_id');
    }
}
