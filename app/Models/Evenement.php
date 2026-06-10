<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Evenement extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'evenements';

    protected $fillable = [
        'groupe_id',
        'quete_id',
        'sequence',
        'type',
        'acteur',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'acteur' => 'array',
            'payload' => 'array',
        ];
    }

    public function groupe(): BelongsTo
    {
        return $this->belongsTo(Groupe::class, 'groupe_id');
    }

    public function quete(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_id');
    }
}
