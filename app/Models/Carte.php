<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Carte extends Model
{
    protected $table = 'cartes';

    protected $fillable = [
        'quete_id',
        'largeur',
        'hauteur',
        'grille',
    ];

    protected function casts(): array
    {
        return [
            'grille' => 'array',
        ];
    }

    public function quete(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_id');
    }
}
