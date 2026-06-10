<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventaire extends Model
{
    protected $table = 'inventaire';

    protected $fillable = [
        'personnage_id',
        'objet_id',
        'emplacement',
        'quantite',
        'ameliorations',
    ];

    protected function casts(): array
    {
        return [
            'ameliorations' => 'array',
        ];
    }

    public function personnage(): BelongsTo
    {
        return $this->belongsTo(Personnage::class, 'personnage_id');
    }

    public function objet(): BelongsTo
    {
        return $this->belongsTo(Objet::class, 'objet_id');
    }
}
