<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Snapshot extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'snapshots';

    protected $fillable = [
        'groupe_id',
        'sequence_evenement',
        'etat',
    ];

    protected function casts(): array
    {
        return [
            'etat' => 'array',
        ];
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupe_id');
    }
}
