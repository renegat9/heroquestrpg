<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EtatPersonnageQuete extends Model
{
    protected $table = 'etat_personnage_quete';

    public $timestamps = false;

    protected $fillable = [
        'personnage_id',
        'quete_id',
        'position_x',
        'position_y',
        'a_joue',
        'a_deplace',
        'a_agi',
        'deplacement_tour',
        'tombe',
    ];

    protected function casts(): array
    {
        return [
            'a_joue' => 'boolean',
            'a_deplace' => 'boolean',
            'a_agi' => 'boolean',
            'tombe' => 'boolean',
            'deplacement_tour' => 'integer',
        ];
    }

    public function personnage(): BelongsTo
    {
        return $this->belongsTo(Personnage::class, 'personnage_id');
    }

    public function quete(): BelongsTo
    {
        return $this->belongsTo(Quete::class, 'quete_id');
    }
}
